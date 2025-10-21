<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/reports/SlaReportGenerator.php';

header('Content-Type: application/json');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
    exit;
}

$host_id = filter_input(INPUT_GET, 'host_id', FILTER_VALIDATE_INT);
$container_id = filter_input(INPUT_GET, 'container_id', FILTER_SANITIZE_STRING);
$month_year = filter_input(INPUT_GET, 'month_year', FILTER_SANITIZE_STRING);

if (!$host_id || !$container_id || !$month_year || !preg_match('/^\d{4}-\d{2}$/', $month_year)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Host, Container, and a valid Month (YYYY-MM) are required.']);
    exit;
}

list($year, $month) = explode('-', $month_year);

$conn = Database::getInstance()->getConnection();

try {
    $reportGenerator = new SlaReportGenerator($conn);
    $params = [
        'host_id'      => $host_id,
        'container_id' => $container_id,
        'year'         => (int)$year,
        'month'        => (int)$month,
    ];
    $data = $reportGenerator->getDailySlaForMonth($params);
    echo json_encode(['status' => 'success', 'data' => $data]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server error: ' . $e->getMessage()]);
} finally {
    $conn->close();
}
?>