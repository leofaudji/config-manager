<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/DockerClient.php';

header('Content-Type: application/json');
$conn = Database::getInstance()->getConnection();

try {
    $stats = [];

    // Get total routers
    $result = $conn->query("SELECT COUNT(*) as count FROM routers");
    $stats['total_routers'] = $result->fetch_assoc()['count'] ?? 0;

    // Get total services
    $result = $conn->query("SELECT COUNT(*) as count FROM services");
    $stats['total_services'] = $result->fetch_assoc()['count'] ?? 0;

    // Get total middlewares
    $result = $conn->query("SELECT COUNT(*) as count FROM middlewares");
    $stats['total_middlewares'] = $result->fetch_assoc()['count'] ?? 0;

    // Get total hosts
    $result = $conn->query("SELECT COUNT(*) as count FROM docker_hosts");
    $stats['total_hosts'] = $result->fetch_assoc()['count'] ?? 0;

    // Get total users
    $result = $conn->query("SELECT COUNT(*) as count FROM users");
    $stats['total_users'] = $result->fetch_assoc()['count'] ?? 0;

    // Get total deployment history records
    $result = $conn->query("SELECT COUNT(*) as count FROM config_history");
    $stats['total_history'] = $result->fetch_assoc()['count'] ?? 0;

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
    ];
    $per_host_stats = [];

    while ($host = $hosts_result->fetch_assoc()) {
        $agg_stats['total_hosts_scanned']++;
        try {
            $dockerClient = new DockerClient($host);
            $dockerInfo = $dockerClient->getInfo(); // Get system info first
            $containers = $dockerClient->listContainers(); // Then get container info

            // Find oldest running container for uptime
            $oldest_container_uptime = 'N/A';
            $oldest_container_creation = PHP_INT_MAX;
            $running_containers_list = array_filter($containers, fn($c) => $c['State'] === 'running');

            if (!empty($running_containers_list)) {
                $oldest_container = null;
                foreach ($running_containers_list as $container) {
                    if ($container['Created'] < $oldest_container_creation) {
                        $oldest_container_creation = $container['Created'];
                        $oldest_container = $container;
                    }
                }
                if ($oldest_container) {
                    $oldest_container_uptime = $oldest_container['Status'];
                }
            }

            $host_running_containers = count(array_filter($containers, fn($c) => $c['State'] === 'running'));
            $host_total_containers = count($containers);

            $agg_stats['total_containers'] += count($containers);
            $agg_stats['running_containers'] += $host_running_containers;
            $agg_stats['stopped_containers'] += count(array_filter($containers, fn($c) => $c['State'] === 'exited'));
            $agg_stats['reachable_hosts']++;
            $agg_stats['total_cpus'] += $dockerInfo['NCPU'] ?? 0;
            $agg_stats['total_memory'] += $dockerInfo['MemTotal'] ?? 0;

            $per_host_stats[] = [
                'id' => $host['id'],
                'name' => $host['name'],
                'status' => 'Reachable',
                'running_containers' => $host_running_containers,
                'total_containers' => $host_total_containers,
                'cpus' => $dockerInfo['NCPU'] ?? 0,
                'memory' => $dockerInfo['MemTotal'] ?? 0,
                'docker_version' => $dockerInfo['ServerVersion'] ?? 'N/A',
                'os' => $dockerInfo['OperatingSystem'] ?? 'N/A',
                'uptime' => $oldest_container_uptime,
                'uptime_timestamp' => ($oldest_container_creation !== PHP_INT_MAX) ? $oldest_container_creation : null,
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
                'docker_version' => 'N/A',
                'os' => 'N/A',
            ];
            $per_host_stats[count($per_host_stats) - 1]['uptime'] = 'N/A';
            $per_host_stats[count($per_host_stats) - 1]['uptime_timestamp'] = null;
        }
    }
    $stats['agg_stats'] = $agg_stats;
    $stats['per_host_stats'] = $per_host_stats;

    echo json_encode(['status' => 'success', 'data' => $stats]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to fetch dashboard stats.']);
}

$conn->close();
?>