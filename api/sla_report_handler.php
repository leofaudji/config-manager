<?php
// File: /var/www/html/config-manager/api/sla_report_handler.php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/reports/SlaReportGenerator.php';

header('Content-Type: application/json');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
    exit;
} 

$host_id_raw = $_GET['host_id'] ?? null;
$host_id = ($host_id_raw === 'all') ? 'all' : filter_var($host_id_raw, FILTER_VALIDATE_INT);
$container_id = filter_input(INPUT_GET, 'container_id', FILTER_SANITIZE_STRING); // Can be a specific ID or 'all'
$date_range = filter_input(INPUT_GET, 'date_range', FILTER_SANITIZE_STRING);
$show_only_downtime = filter_input(INPUT_GET, 'show_only_downtime', FILTER_VALIDATE_BOOLEAN);

// If host_id is 'all', container_id is not required for validation.
if (!$host_id || (!$container_id && $host_id !== 'all') || !$date_range) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Host ID, Container ID, and Date Range are required.']);
    exit;
}

// Parse date range
$dates = explode(' - ', $date_range);
if (count($dates) !== 2) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid date range format.']);
    exit;
}
$start_date_str = $dates[0];
$end_date_str = $dates[1];

$conn = Database::getInstance()->getConnection();

try {
    $reportGenerator = new SlaReportGenerator($conn);
    $params = [
        'host_id'      => $host_id,
        'container_id' => $container_id,
        'start_date'   => date('Y-m-d 00:00:00', strtotime($start_date_str)),
        'end_date'     => date('Y-m-d 23:59:59', strtotime($end_date_str)),
        'show_only_downtime' => $show_only_downtime,
        'dates'        => $dates,
    ];
    $data = $reportGenerator->getSlaData($params);
    echo json_encode(['status' => 'success', 'data' => $data]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server error: ' . $e->getMessage()]);
} finally {
    $conn->close();
}
