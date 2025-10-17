<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/DockerClient.php';
require_once __DIR__ . '/../includes/reports/SlaReportGenerator.php';

header('Content-Type: application/json');
$conn = Database::getInstance()->getConnection();

function format_uptime(int $seconds): string {
    if ($seconds <= 0) {
        return 'N/A';
    }

    $days = floor($seconds / 86400);
    $seconds %= 86400;
    $hours = floor($seconds / 3600);
    $seconds %= 3600;
    $minutes = floor($seconds / 60);

    $parts = [];
    if ($days > 0) $parts[] = "{$days}d";
    if ($hours > 0) $parts[] = "{$hours}h";
    if ($minutes > 0) $parts[] = "{$minutes}m";

    return empty($parts) ? '< 1m' : implode(' ', $parts);
}

function get_system_status($conn): array {
    $status = [];

    // 1. Database Connection
    $status['db_connection'] = ($conn && $conn->ping()) ? 'OK' : 'Error';

    // 2. Config File Writable (Check the base path where configs are stored)
    $yaml_path = get_setting('yaml_output_path', PROJECT_ROOT . '/traefik-configs');
    $status['config_writable'] = (is_dir($yaml_path) && is_writable($yaml_path)) ? 'OK' : 'Error';

    // 3. PHP Version
    $status['php_version'] = phpversion();

    // 4. Cron Job Status (a simplified check based on whether they are enabled in settings)
    // This is a proxy for whether the user intends for them to be running.
    $cron_jobs = ['collect_stats', 'autoscaler', 'health_monitor'];
    $all_cron_enabled = true;
    foreach ($cron_jobs as $job) {
        // This assumes settings are stored in a format like 'collect_stats_enabled' => '1'
        if (!get_setting($job . '_enabled', 0)) {
            $all_cron_enabled = false;
            break;
        }
    }
    $status['cron_status'] = $all_cron_enabled ? 'Enabled' : 'Partial/Disabled';

    return $status;
}

try {
    $response = ['status' => 'success', 'data' => []];

    // --- Get High-Level Counts ---
    $response['data']['total_routers'] = $conn->query("SELECT COUNT(*) as count FROM routers")->fetch_assoc()['count'] ?? 0;
    $response['data']['total_services'] = $conn->query("SELECT COUNT(*) as count FROM services")->fetch_assoc()['count'] ?? 0;
    $response['data']['total_hosts'] = $conn->query("SELECT COUNT(*) as count FROM docker_hosts")->fetch_assoc()['count'] ?? 0;
    $response['data']['total_stacks'] = $conn->query("SELECT COUNT(*) as count FROM application_stacks")->fetch_assoc()['count'] ?? 0;

    // --- Get Unhealthy Items Count ---
    $unhealthy_services = $conn->query("SELECT COUNT(*) as count FROM service_health_status WHERE status = 'unhealthy'")->fetch_assoc()['count'] ?? 0;
    $unhealthy_containers = $conn->query("SELECT COUNT(*) as count FROM container_health_status WHERE status = 'unhealthy'")->fetch_assoc()['count'] ?? 0;
    $response['data']['total_unhealthy'] = $unhealthy_services + $unhealthy_containers;

    // --- Get Aggregated Container Stats & Per-Host Stats ---
    $hosts_result = $conn->query("SELECT * FROM docker_hosts ORDER BY name ASC");
    $all_hosts = $hosts_result->fetch_all(MYSQLI_ASSOC);

    $agg_stats = ['total_containers' => 0, 'running_containers' => 0, 'stopped_containers' => 0];
    $per_host_stats = [];
    $swarm_info = null;

    // --- NEW: Initialize SLA Generator ---
    $slaReportGenerator = new SlaReportGenerator($conn);

    foreach ($all_hosts as $host) {
        $host_stat = [
            'id' => $host['id'],
            'name' => $host['name'],
            'status' => 'Unreachable',
            'total_containers' => 'N/A',
            'running_containers' => 'N/A',
            'cpus' => 'N/A',
            'memory' => 'N/A',
            'disk_usage' => 'N/A',
            'docker_version' => 'N/A',
            'os' => 'N/A',
            'uptime' => 'N/A',
            'uptime_timestamp' => 0,
            // --- NEW: Add SLA fields ---
            'sla_percentage' => 'N/A',
            'sla_percentage_raw' => null,
        ];

        try {
            $dockerClient = new DockerClient($host);
            $info = $dockerClient->getInfo();
            $containers = $dockerClient->listContainers();

            $host_stat['status'] = 'Reachable';
            $host_stat['total_containers'] = count($containers);
            $host_stat['running_containers'] = count(array_filter($containers, fn($c) => $c['State'] === 'running'));
            $host_stat['cpus'] = $info['NCPU'] ?? 'N/A';
            $host_stat['memory'] = $info['MemTotal'] ?? 'N/A';
            $host_stat['docker_version'] = $info['ServerVersion'] ?? 'N/A';
            $host_stat['os'] = $info['OperatingSystem'] ?? 'N/A';
            $host_stat['uptime'] = format_uptime($host['host_uptime_seconds'] ?? 0);
            $host_stat['uptime_timestamp'] = $host['host_uptime_seconds'] ?? 0;

            $agg_stats['total_containers'] += $host_stat['total_containers'];
            $agg_stats['running_containers'] += $host_stat['running_containers'];
            $agg_stats['stopped_containers'] += ($host_stat['total_containers'] - $host_stat['running_containers']);

            // --- NEW: Calculate SLA for the last 30 days ---
            $sla_params = [
                'host_id'      => $host['id'],
                'container_id' => 'all', // For host summary
                'start_date'   => date('Y-m-d 00:00:00', strtotime('-30 days')),
                'end_date'     => date('Y-m-d 23:59:59'),
                'dates'        => [date('Y-m-d', strtotime('-30 days')), date('Y-m-d')],
            ];
            $sla_data = $slaReportGenerator->getSlaData($sla_params);
            if ($sla_data['report_type'] === 'host_summary') {
                $host_stat['sla_percentage'] = $sla_data['overall_host_sla'];
                $host_stat['sla_percentage_raw'] = $sla_data['overall_host_sla_raw'];
            }

            // --- Swarm Info Logic ---
            if ($swarm_info === null && isset($info['Swarm']['LocalNodeState']) && $info['Swarm']['LocalNodeState'] !== 'inactive') {
                $nodes = $dockerClient->listNodes();
                $swarm_info = ['total_nodes' => 0, 'managers' => 0, 'workers' => 0];
                foreach ($nodes as $node) {
                    $swarm_info['total_nodes']++;
                    if (isset($node['Spec']['Role']) && $node['Spec']['Role'] === 'manager') {
                        $swarm_info['managers']++;
                    } else {
                        $swarm_info['workers']++;
                    }
                }
            }
        } catch (Exception $e) {
            // Host is unreachable, keep default 'N/A' values
        }
        $per_host_stats[] = $host_stat;
    }

    $response['data']['agg_stats'] = $agg_stats;
    $response['data']['per_host_stats'] = $per_host_stats;
    if ($swarm_info !== null) {
        $response['data']['swarm_info'] = $swarm_info;
    }

    // --- Get Recent Activity ---
    $activity_result = $conn->query("SELECT username, action, details, created_at FROM activity_log WHERE username != 'health-agent' ORDER BY id DESC LIMIT 5");
    $response['data']['recent_activity'] = $activity_result->fetch_all(MYSQLI_ASSOC);

    // --- Get System Status ---
    $response['data']['system_status'] = get_system_status($conn);

    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to fetch dashboard stats: ' . $e->getMessage()]);
}

$conn->close();
?>