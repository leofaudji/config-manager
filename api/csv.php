<?php
// File: /var/www/html/config-manager/api/csv.php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/reports/SlaReportGenerator.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    die('Forbidden');
}

// --- Input Validation ---
$host_id = filter_input(INPUT_POST, 'host_id', FILTER_VALIDATE_INT);
$container_id = filter_input(INPUT_POST, 'container_id', FILTER_SANITIZE_STRING);
$date_range = filter_input(INPUT_POST, 'date_range', FILTER_SANITIZE_STRING); 
$report_type = filter_input(INPUT_POST, 'report_type', FILTER_SANITIZE_STRING);

if (!$report_type || !$host_id || !$container_id || !$date_range) {
    http_response_code(400);
    die('Report Type, Host ID, Container ID, and Date Range are required.');
}

$dates = explode(' - ', $date_range);
if (count($dates) !== 2) {
    http_response_code(400);
    die('Invalid date range format.');
}

$conn = Database::getInstance()->getConnection();

// --- Report Dispatcher ---
$reportGenerators = [
    'sla_report' => SlaReportGenerator::class,
];

try {
    if (!isset($reportGenerators[$report_type])) {
        throw new InvalidArgumentException('Invalid report_type specified.');
    }

    $generatorClass = $reportGenerators[$report_type];
    $reportGenerator = new $generatorClass($conn);

    $params = [
        'host_id'      => $host_id,
        'container_id' => $container_id,
        'start_date'   => date('Y-m-d 00:00:00', strtotime($dates[0])),
        'end_date'     => date('Y-m-d 23:59:59', strtotime($dates[1])),
    ];

    $reportGenerator->generateCsv($params);

} catch (Exception $e) {
    header('Content-Type: text/plain');
    http_response_code(500);
    die('Server error: ' . $e->getMessage());
} finally {
    $conn->close();
}
?>