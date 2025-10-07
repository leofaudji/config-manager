<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/DockerClient.php';
require_once __DIR__ . '/../includes/GitHelper.php';
require_once __DIR__ . '/../includes/YamlGenerator.php';

header('Content-Type: application/json');
$conn = Database::getInstance()->getConnection();

/**
 * Runs a series of checks on the current database configuration to find potential issues.
 * @param mysqli $conn The database connection object.
 * @return array An array containing 'errors' and 'warnings'.
 */
function runConfigurationLinter(mysqli $conn): array
{
    $errors = [];
    $warnings = [];

    // --- Data Fetching ---
    $routers_res = $conn->query("SELECT name, service_name FROM routers")->fetch_all(MYSQLI_ASSOC);
    $services_res = $conn->query("SELECT name FROM services")->fetch_all(MYSQLI_ASSOC);

    $service_names = array_column($services_res, 'name');

    // --- Run Checks ---

    // 1. Router points to a non-existent service
    foreach ($routers_res as $router) {
        if (!in_array($router['service_name'], $service_names)) {
            $errors[] = "Router <strong>" . htmlspecialchars($router['name']) . "</strong> points to a non-existent service: <code>" . htmlspecialchars($router['service_name']) . "</code>.";
        }
    }

    return ['errors' => $errors, 'warnings' => $warnings];
}

/**
 * Parses a size string (e.g., "10.5 GB", "500MB") and returns the value in bytes.
 * @param string $sizeStr The size string to parse.
 * @return float The size in bytes.
 */
function parseSizeStringToBytes(string $sizeStr): float {
    $sizeStr = trim($sizeStr);
    if (empty($sizeStr)) {
        return 0;
    }
    preg_match('/([0-9\.]+)\s*([a-zA-Z]+)?/', $sizeStr, $matches);
    if (count($matches) < 2) {
        return 0;
    }
    $value = (float)$matches[1];
    $unit = strtoupper($matches[2] ?? '');

    switch ($unit) {
        case 'TB': case 'T': return $value * pow(1024, 4);
        case 'GB': case 'G': return $value * pow(1024, 3);
        case 'MB': case 'M': return $value * pow(1024, 2);
        case 'KB': case 'K': return $value * 1024;
        default: return $value;
    }
}


try {
    $stats = [];

    // --- Consolidated COUNT queries for efficiency ---
    $counts_sql = "
        SELECT 
            (SELECT COUNT(*) FROM routers) as total_routers,
            (SELECT COUNT(*) FROM services) as total_services,
            (SELECT COUNT(*) FROM docker_hosts) as total_hosts,
            (SELECT COUNT(*) FROM config_history) as total_history
    ";
    $counts_result = $conn->query($counts_sql)->fetch_assoc();
    $stats = array_merge($stats, $counts_result);

    // Get total unhealthy items from both services and containers
    $unhealthy_services_count = $conn->query("SELECT COUNT(*) as count FROM service_health_status WHERE status = 'unhealthy'")->fetch_assoc()['count'] ?? 0;
    $unhealthy_containers_count = $conn->query("SELECT COUNT(*) as count FROM container_health_status WHERE status = 'unhealthy'")->fetch_assoc()['count'] ?? 0;
    $stats['total_unhealthy'] = $unhealthy_services_count + $unhealthy_containers_count;

    // Get active config ID
    $result = $conn->query("SELECT id FROM config_history WHERE status = 'active' LIMIT 1");
    $stats['active_config_id'] = $result->fetch_assoc()['id'] ?? 'N/A';

    // Perform a quick health check (is the main config file writable?)
    $base_config_path = get_setting('yaml_output_path', PROJECT_ROOT . '/traefik-configs');
    $stats['health_status'] = is_writable($base_config_path) ? 'OK' : 'Error';

    // --- Aggregate Remote Host Stats ---
    $hosts_result = $conn->query("SELECT * FROM docker_hosts");

    $agg_stats = [
        'total_containers' => 0,
        'running_containers' => 0,
        'stopped_containers' => 0,
        'reachable_hosts' => 0,
        'total_cpus' => 0,
        'total_memory' => 0,
        'total_hosts_scanned' => 0,
        'total_images' => 0,
        'total_networks' => 0,
        'total_volumes' => 0,
    ];
    $per_host_stats = [];

    $swarm_info = null;
    while ($host = $hosts_result->fetch_assoc()) {
        $agg_stats['total_hosts_scanned']++;
        try {
            $dockerClient = new DockerClient($host);
            $dockerInfo = $dockerClient->getInfo(); // Get system info first
            $containers = $dockerClient->listContainers();

            // Check for Swarm info, but only if not already found (assume one cluster)
            if (!$swarm_info && isset($dockerInfo['Swarm']['ControlAvailable']) && $dockerInfo['Swarm']['ControlAvailable']) {
                $nodes = $dockerClient->listNodes();
                $swarm_info = [
                    'total_nodes' => count($nodes),
                    'managers' => 0,
                    'workers' => 0,
                ];
                foreach ($nodes as $node) {
                    if ($node['Spec']['Role'] === 'manager') $swarm_info['managers']++;
                    else $swarm_info['workers']++;
                }
            }
            
            $host_running_containers = count(array_filter($containers, fn($c) => $c['State'] === 'running'));
            $host_total_containers = count($containers);

            // Calculate Disk Usage
            $disk_usage_percent = 'N/A';
            if (isset($dockerInfo['DriverStatus'])) {
                $driver_status = array_column($dockerInfo['DriverStatus'], 1, 0);
                $data_used_str = $driver_status['Data Space Used'] ?? '0';
                $metadata_used_str = $driver_status['Metadata Space Used'] ?? '0';
                $data_total_str = $driver_status['Data Space Total'] ?? '0';

                $total_used_bytes = parseSizeStringToBytes($data_used_str);
                $total_space_bytes = parseSizeStringToBytes($data_total_str);

                // For overlay2 driver, total usage is data + metadata
                if (strtolower($dockerInfo['Driver'] ?? '') === 'overlay2') {
                    $total_used_bytes += parseSizeStringToBytes($metadata_used_str);
                }

                if ($total_space_bytes > 0) $disk_usage_percent = round(($total_used_bytes / $total_space_bytes) * 100, 2);
            }

            // Get uptime from oldest running container (if any)
            $uptime_status = 'N/A';
            $uptime_timestamp = null;
            $running_containers_list = array_filter($containers, fn($c) => $c['State'] === 'running');
            if (!empty($running_containers_list)) {
                $oldest_container_creation = PHP_INT_MAX;
                $oldest_container = null;
                foreach ($running_containers_list as $container) {
                    if ($container['Created'] < $oldest_container_creation) {
                        $oldest_container_creation = $container['Created'];
                        $oldest_container = $container;
                    }
                }
                if ($oldest_container) {
                    $uptime_status = $oldest_container['Status'];
                    $uptime_timestamp = $oldest_container_creation;
                }
            }

            // Fetch volumes and images here, after DockerClient is initialized
            $volumes = $dockerClient->listVolumes();

            $agg_stats['total_containers'] += count($containers);
            $agg_stats['running_containers'] += $host_running_containers;
            $agg_stats['stopped_containers'] += count(array_filter($containers, fn($c) => $c['State'] === 'exited'));
            $agg_stats['reachable_hosts']++;
            $agg_stats['total_cpus'] += $dockerInfo['NCPU'] ?? 0;
            $agg_stats['total_memory'] += $dockerInfo['MemTotal'] ?? 0;
            $agg_stats['total_images'] += $dockerInfo['Images'] ?? 0;
            $agg_stats['total_volumes'] += count($volumes['Volumes'] ?? []);

            $per_host_stats[] = [
                'id' => $host['id'],
                'name' => $host['name'],
                'status' => 'Reachable',
                'running_containers' => $host_running_containers,
                'total_containers' => $host_total_containers,
                'cpus' => $dockerInfo['NCPU'] ?? 0,
                'memory' => $dockerInfo['MemTotal'] ?? 0,
                'disk_usage' => $disk_usage_percent,
                'docker_version' => $dockerInfo['ServerVersion'] ?? 'N/A',
                'os' => $dockerInfo['OperatingSystem'] ?? 'N/A',
                'uptime' => $uptime_status,
                'uptime_timestamp' => $uptime_timestamp,
            ];
        } catch (Exception $e) {
            // Log error but don't stop the process for other hosts
            error_log("Dashboard stats: Failed to connect to host '{$host['name']}'. Error: " . $e->getMessage());
            $per_host_stats[] = [
                'id' => $host['id'],
                'name' => $host['name'],
                'status' => 'Unreachable',
                'running_containers' => 'N/A',
                'total_containers' => 'N/A',
                'cpus' => 'N/A',
                'memory' => 'N/A',
                'disk_usage' => 'N/A',
                'docker_version' => 'N/A',
                'os' => 'N/A',
                'uptime' => 'N/A',
                'uptime_timestamp' => null,
            ];
        }
    }
    $stats['agg_stats'] = $agg_stats;
    $stats['per_host_stats'] = $per_host_stats;
    $stats['swarm_info'] = $swarm_info;

    // Add total_images and total_volumes to the main stats array for the new widgets
    $stats['total_images'] = $agg_stats['total_images'];
    $stats['total_volumes'] = $agg_stats['total_volumes'];

    // Get recent activity logs
    $activity_logs = [];
    $activity_result = $conn->query("SELECT username, action, details, created_at FROM activity_log ORDER BY created_at DESC LIMIT 5");
    if ($activity_result) {
        while ($log = $activity_result->fetch_assoc()) {
            $activity_logs[] = $log;
        }
    }
    $stats['recent_activity'] = $activity_logs;

    // --- System Status ---
    $cron_jobs_setting = get_setting('cron_jobs', '{}');
    $cron_jobs = json_decode($cron_jobs_setting, true);

    $stats['system_status'] = [
        'db_connection' => 'OK', // If we got this far, the DB is connected
        'config_writable' => is_writable(get_setting('yaml_output_path', PROJECT_ROOT . '/traefik-configs')) ? 'OK' : 'Error',
        'php_version' => phpversion(),
        'cron_stats_collector' => (isset($cron_jobs['collect_stats']) && $cron_jobs['collect_stats']['enabled']) ? 'Enabled' : 'Disabled',
        'cron_autoscaler' => (isset($cron_jobs['autoscaler']) && $cron_jobs['autoscaler']['enabled']) ? 'Enabled' : 'Disabled',
        'cron_health_monitor' => (isset($cron_jobs['health_monitor']) && $cron_jobs['health_monitor']['enabled']) ? 'Enabled' : 'Disabled',
    ];


    echo json_encode(['status' => 'success', 'data' => $stats]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to fetch dashboard stats: ' . $e->getMessage()]);
}

$conn->close();
?>