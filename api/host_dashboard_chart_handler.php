<?php
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');

$request_uri_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$basePath = BASE_PATH;
if ($basePath && strpos($request_uri_path, $basePath) === 0) {
    $request_uri_path = substr($request_uri_path, strlen($basePath));
}

if (!preg_match('/^\/api\/hosts\/(\d+)\/chart-data$/', $request_uri_path, $matches)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid API endpoint format.']);
    exit;
}
$host_id = $matches[1];

$conn = Database::getInstance()->getConnection();

try {
    // Fetch data for the last 24 hours, aggregated by hour
    $stmt = $conn->prepare("
        SELECT 
            DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00') as hour_slot,
            AVG(cpu_usage_percent) as avg_cpu,
            AVG(memory_usage_bytes) as avg_mem_usage,
            AVG(memory_limit_bytes) as avg_mem_limit
        FROM host_stats_history
        WHERE host_id = ? AND created_at >= NOW() - INTERVAL 24 HOUR
        GROUP BY hour_slot
        ORDER BY hour_slot ASC
    ");
    $stmt->bind_param("i", $host_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $labels = [];
    $cpu_data = [];
    $mem_data = [];

    while ($row = $result->fetch_assoc()) {
        $labels[] = date('H:i', strtotime($row['hour_slot']));
        $cpu_data[] = round($row['avg_cpu'], 2);
        $mem_percent = ($row['avg_mem_limit'] > 0) ? ($row['avg_mem_usage'] / $row['avg_mem_limit']) * 100 : 0;
        $mem_data[] = round($mem_percent, 2);
    }
    $stmt->close();

    echo json_encode(['status' => 'success', 'data' => ['labels' => $labels, 'cpu_usage' => $cpu_data, 'memory_usage' => $mem_data]]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();
?>