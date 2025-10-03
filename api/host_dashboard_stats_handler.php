<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/DockerClient.php';

header('Content-Type: application/json');

$request_uri_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$basePath = BASE_PATH;
if ($basePath && strpos($request_uri_path, $basePath) === 0) {
    $request_uri_path = substr($request_uri_path, strlen($basePath));
}

if (!preg_match('/^\/api\/hosts\/(\d+)\/stats$/', $request_uri_path, $matches)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid API endpoint format.']);
    exit;
}
$host_id = $matches[1];

$conn = Database::getInstance()->getConnection();

try {
    // --- Get Host Details ---
    $stmt = $conn->prepare("SELECT * FROM docker_hosts WHERE id = ?");
    $stmt->bind_param("i", $host_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if (!($host = $result->fetch_assoc())) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Host not found.']);
        exit;
    }
    $stmt->close();

    $stats = [];

    // --- Get Live Stats from Docker Host ---
    try {
        $dockerClient = new DockerClient($host);
        $containers = $dockerClient->listContainers();
        $info = $dockerClient->getInfo();
        $networks = $dockerClient->listNetworks();

        $stats['total_containers'] = count($containers);
        $stats['running_containers'] = count(array_filter($containers, fn($c) => $c['State'] === 'running'));
        $stats['stopped_containers'] = count(array_filter($containers, fn($c) => $c['State'] === 'exited'));
        $stats['total_networks'] = count($networks);
        $stats['total_images'] = $info['Images'] ?? 0;

    } catch (Exception $e) {
        // If host is unreachable, we can still serve chart data, but widget data will be 'N/A'
        // Throw the exception to be caught by the main handler, ensuring a consistent error response.
        throw new Exception("Failed to connect to host '{$host['name']}': " . $e->getMessage());
    }

    // --- Get Stack Count from DB ---
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM application_stacks WHERE host_id = ?");
    $stmt->bind_param("i", $host_id);
    $stmt->execute();
    $stats['total_stacks'] = $stmt->get_result()->fetch_assoc()['count'] ?? 0;
    $stmt->close();

    // --- Get Chart Data (from host_dashboard_chart_handler.php) ---
    $chart_stmt = $conn->prepare("
        SELECT 
            DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00') as hour_slot,
            AVG(container_cpu_usage_percent) as avg_container_cpu,
            AVG(host_cpu_usage_percent) as avg_host_cpu,
            (AVG(memory_usage_bytes) / AVG(memory_limit_bytes)) * 100 as avg_mem_usage
        FROM host_stats_history
        WHERE host_id = ? AND created_at >= NOW() - INTERVAL 24 HOUR
        GROUP BY hour_slot
        ORDER BY hour_slot ASC
    ");
    $chart_stmt->bind_param("i", $host_id);
    $chart_stmt->execute();
    $chart_result = $chart_stmt->get_result();

    $chart_data = [
        'labels' => [],
        'host_cpu_usage' => [],
        'container_cpu_usage' => [],
        'memory_usage' => [],
    ];

    while ($row = $chart_result->fetch_assoc()) {
        $chart_data['labels'][] = date('H:i', strtotime($row['hour_slot']));
        $chart_data['container_cpu_usage'][] = round($row['avg_container_cpu'] ?? 0, 2);
        $chart_data['host_cpu_usage'][] = round($row['avg_host_cpu'] ?? 0, 2);
        $chart_data['memory_usage'][] = round($row['avg_mem_usage'] ?? 0, 2);
    }
    $chart_stmt->close();
    $stats['chart_data'] = $chart_data;

    echo json_encode(['status' => 'success', 'data' => $stats]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to fetch host dashboard data: ' . $e->getMessage()]);
}

$conn->close();