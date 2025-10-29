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

    // 1. Database Connection (from health_check_handler.php)
    $db_ok = ($conn && $conn->ping());
    $status[] = [
        'check' => 'Database Connection',
        'status' => $db_ok ? 'OK' : 'Error',
        'message' => $db_ok ? 'Connected.' : 'Failed to connect.'
    ];

    // 2. Required PHP Extensions (from health_check_handler.php)
    $required_extensions = ['mysqli', 'curl', 'json', 'mbstring'];
    $missing_extensions = [];
    foreach ($required_extensions as $ext) {
        if (!extension_loaded($ext)) {
            $missing_extensions[] = $ext;
        }
    }
    $extensions_ok = empty($missing_extensions);
    $status[] = [
        'check' => 'PHP Extensions',
        'status' => $extensions_ok ? 'OK' : 'Error',
        'message' => $extensions_ok ? 'All loaded.' : 'Missing: ' . implode(', ', $missing_extensions) . '.'
    ];

    // 3. Traefik Dynamic Config Path Writable (from health_check_handler.php)
    $yaml_path = get_setting('yaml_output_path', PROJECT_ROOT . '/traefik-configs');
    $config_path_ok = is_dir($yaml_path) && is_writable($yaml_path);
    $status[] = [
        'check' => 'Traefik Config Path',
        'status' => $config_path_ok ? 'OK' : 'Error',
        'message' => $config_path_ok ? 'Writable.' : 'Not writable.'
    ];

    // 4. PHP Version (from health_check_handler.php)
    $php_version_ok = version_compare(PHP_VERSION, '7.4.0', '>=');
    $status[] = [
        'check' => 'PHP Version',
        'status' => $php_version_ok ? 'OK' : 'Error',
        'message' => 'v' . PHP_VERSION . ($php_version_ok ? '' : ' (>=7.4 required)')
    ];

    // 5. Cron Job Log Path Writable (from health_check_handler.php)
    $cron_log_path = get_setting('cron_log_path', '/var/log');
    $cron_log_path_ok = is_dir($cron_log_path) && is_writable($cron_log_path);
    $status[] = [
        'check' => 'Cron Log Path',
        'status' => $cron_log_path_ok ? 'OK' : 'Error',
        'message' => $cron_log_path_ok ? 'Writable.' : 'Not writable.'
    ];

    // 6. Health Monitor Log File Writable (from health_check_handler.php)
    $health_monitor_log_file = rtrim($cron_log_path, '/') . '/health_monitor.log';
    $health_monitor_log_ok = false;
    $health_monitor_log_message = '';
    if (file_exists($health_monitor_log_file)) {
        $health_monitor_log_ok = is_writable($health_monitor_log_file);
        $health_monitor_log_message = $health_monitor_log_ok ? 'Writable.' : 'Not writable.';
    } else {
        $health_monitor_log_ok = $cron_log_path_ok; // If dir is writable, file can be created
        $health_monitor_log_message = $health_monitor_log_ok ? 'Will be created.' : 'Dir not writable.';
    }
    $status[] = [
        'check' => 'Health Monitor Log',
        'status' => $health_monitor_log_ok ? 'OK' : 'Error',
        'message' => $health_monitor_log_message
    ];

    // 7. Cron User Shell (from health_check_handler.php)
    $web_user = exec('whoami');
    $user_shell_ok = false;
    $user_shell_message = "Unknown.";
    if ($web_user && file_exists('/etc/passwd') && is_readable('/etc/passwd')) {
        $passwd_content = file_get_contents('/etc/passwd');
        if (preg_match('/^' . preg_quote($web_user, '/') . ':x:\d+:\d+:.*:.*:(.*)$/m', $passwd_content, $matches)) {
            $shell = $matches[1];
            if ($shell !== '/usr/sbin/nologin' && $shell !== '/bin/false' && $shell !== '/sbin/nologin') {
                $user_shell_ok = true;
                $user_shell_message = "User '{$web_user}' has valid shell.";
            } else {
                $user_shell_message = "User '{$web_user}' has invalid shell: {$shell}.";
            }
        } else {
            $user_shell_message = "User '{$web_user}' not found.";
        }
    }
    $status[] = [
        'check' => 'Cron User Shell',
        'status' => $user_shell_ok ? 'OK' : 'Error',
        'message' => $user_shell_message
    ];

    // 8. Cron Scripts Executable (from health_check_handler.php)
    $cron_scripts_to_check = [
        'collect_stats.php',
        'autoscaler.php',
        'health_monitor.php',
        'system_backup.php',
        'scheduled_deployment_runner.php'
    ];
    $all_cron_scripts_executable = true;
    $failed_scripts = [];
    foreach ($cron_scripts_to_check as $script) {
        $script_path = PROJECT_ROOT . '/' . $script;
        if (!is_executable(PROJECT_ROOT . '/jobs/' . $script)) {
            $all_cron_scripts_executable = false;
            $failed_scripts[] = $script;
        }
    }
    $status[] = [
        'check' => 'Cron Scripts Executable',
        'status' => $all_cron_scripts_executable ? 'OK' : 'Error',
        'message' => $all_cron_scripts_executable ? 'All executable.' : 'Not executable: ' . implode(', ', $failed_scripts)
    ];

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