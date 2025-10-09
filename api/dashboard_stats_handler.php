<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/DockerClient.php';

header('Content-Type: application/json');

try {
    $conn = Database::getInstance()->getConnection();

    // --- Aggregate Stats ---
    $total_routers = $conn->query("SELECT COUNT(*) as count FROM routers")->fetch_assoc()['count'];
    $total_services = $conn->query("SELECT COUNT(*) as count FROM services")->fetch_assoc()['count'];
    $total_middlewares = $conn->query("SELECT COUNT(*) as count FROM middlewares")->fetch_assoc()['count'];
    $total_hosts = $conn->query("SELECT COUNT(*) as count FROM docker_hosts")->fetch_assoc()['count'];
    $total_users = $conn->query("SELECT COUNT(*) as count FROM users")->fetch_assoc()['count'];

    // --- Unhealthy Items Count ---
    $unhealthy_services = $conn->query("SELECT COUNT(*) as count FROM service_health_status WHERE status = 'unhealthy'")->fetch_assoc()['count'];
    $unhealthy_containers = $conn->query("SELECT COUNT(*) as count FROM container_health_status WHERE status = 'unhealthy'")->fetch_assoc()['count'];
    $total_unhealthy = $unhealthy_services + $unhealthy_containers;

    // --- Recent Activity (excluding health-agent) ---
    $recent_activity_stmt = $conn->prepare("
        SELECT username, action, details, created_at 
        FROM activity_log 
        WHERE username != 'health-agent' 
        ORDER BY id DESC 
        LIMIT 5
    ");
    $recent_activity_stmt->execute();
    $recent_activity = $recent_activity_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // --- System Status Checks ---
    $db_connection_status = ($conn->ping()) ? 'OK' : 'Error';
    $config_path = get_setting('yaml_output_path', '/var/www/html/config-manager/traefik-configs');
    $config_writable_status = (is_writable($config_path)) ? 'OK' : 'Error';
    $php_version_status = phpversion();

    // --- Cron Job Status ---
    $cron_output = shell_exec('crontab -l');
    $cron_stats_status = (strpos($cron_output, 'collect_stats.php') !== false && strpos($cron_output, '#') !== 0) ? 'Enabled' : 'Disabled';
    $cron_autoscaler_status = (strpos($cron_output, 'autoscaler.php') !== false && strpos($cron_output, '#') !== 0) ? 'Enabled' : 'Disabled';
    $cron_health_monitor_status = (strpos($cron_output, 'health_monitor.php') !== false && strpos($cron_output, '#') !== 0) ? 'Enabled' : 'Disabled';

    // --- Per-Host Stats ---
    $per_host_stats = [];
    $hosts_result = $conn->query("SELECT * FROM docker_hosts ORDER BY name ASC");
    $total_images = 0;
    $total_volumes = 0;
    $agg_total_containers = 0;
    $agg_running_containers = 0;
    $agg_stopped_containers = 0;

    while ($host = $hosts_result->fetch_assoc()) {
        try {
            $dockerClient = new DockerClient($host);
            $info = $dockerClient->getInfo();
            $containers = $dockerClient->listContainers();
            $images = $dockerClient->listImages();
            $volumes = $dockerClient->listVolumes();

            $running = array_filter($containers, fn($c) => $c['State'] === 'running');
            $stopped = count($containers) - count($running);

            $per_host_stats[] = [
                'id' => $host['id'],
                'name' => $host['name'],
                'status' => 'Reachable',
                'total_containers' => count($containers),
                'running_containers' => count($running),
                'cpus' => $info['NCPU'] ?? 'N/A',
                'memory' => $info['MemTotal'] ?? 'N/A',
                'docker_version' => $info['ServerVersion'] ?? 'N/A',
                'os' => $info['OperatingSystem'] ?? 'N/A',
            ];
            $total_images += count($images);
            $total_volumes += count($volumes['Volumes'] ?? []);
            $agg_total_containers += count($containers);
            $agg_running_containers += count($running);
            $agg_stopped_containers += $stopped;
        } catch (Exception $e) {
            $per_host_stats[] = [
                'id' => $host['id'],
                'name' => $host['name'],
                'status' => 'Unreachable',
                'total_containers' => 'N/A',
                'running_containers' => 'N/A',
                'cpus' => 'N/A',
                'memory' => 'N/A',
                'docker_version' => 'N/A',
                'os' => 'N/A',
            ];
        }
    }

    echo json_encode([
        'status' => 'success',
        'data' => [
            'total_routers' => $total_routers,
            'total_services' => $total_services,
            'total_unhealthy' => $total_unhealthy,
            'recent_activity' => $recent_activity,
            'system_status' => [
                'db_connection' => $db_connection_status,
                'config_writable' => $config_writable_status,
                'php_version' => $php_version_status,
                'cron_stats_collector' => $cron_stats_status,
                'cron_autoscaler' => $cron_autoscaler_status,
                'cron_health_monitor' => $cron_health_monitor_status,
            ],
            'per_host_stats' => $per_host_stats,
            'total_images' => $total_images,
            'total_volumes' => $total_volumes,
            'agg_stats' => [
                'total_containers' => $agg_total_containers,
                'running_containers' => $agg_running_containers,
                'stopped_containers' => $agg_stopped_containers,
            ]
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();
?>