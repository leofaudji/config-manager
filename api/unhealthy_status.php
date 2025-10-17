<?php
// File: /var/www/html/config-manager/api/unhealthy_status.php

require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');

if (!isset($_SESSION['role'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
    exit;
}

$conn = Database::getInstance()->getConnection();

try {
    $unhealthy_alerts = [];

    // 1. Check for unhealthy containers
    $sql_containers = "
        SELECT chs.container_name, h.name as location
        FROM container_health_status chs
        JOIN docker_hosts h ON chs.host_id = h.id
        WHERE chs.status = 'unhealthy'
    ";
    $container_result = $conn->query($sql_containers);
    while ($row = $container_result->fetch_assoc()) {
        $unhealthy_alerts[] = [
            'type' => 'Container',
            'name' => $row['container_name'],
            'location' => $row['location'],
            'link' => base_url('/health-status?status_filter=unhealthy')
        ];
    }

    // 2. Check for unhealthy services
    $sql_services = "
        SELECT s.name, g.name as location
        FROM service_health_status shs
        JOIN services s ON shs.service_id = s.id
        LEFT JOIN `groups` g ON s.group_id = g.id
        WHERE shs.status = 'unhealthy'
    ";
    $service_result = $conn->query($sql_services);
    while ($row = $service_result->fetch_assoc()) {
        $unhealthy_alerts[] = [
            'type' => 'Service',
            'name' => $row['name'],
            'location' => $row['location'] ?? 'Global',
            'link' => base_url('/health-status?status_filter=unhealthy')
        ];
    }

    // 3. Check for down hosts (that haven't reported in a while)
    $down_host_threshold_minutes = 3; // Consider a host down if no report for 3 minutes
    $sql_hosts = "
        SELECT id, name
        FROM docker_hosts
        WHERE last_report_at < NOW() - INTERVAL ? MINUTE
           OR last_report_at IS NULL
    ";
    $host_result = $conn->prepare($sql_hosts);
    $host_result->bind_param("i", $down_host_threshold_minutes);
    $host_result->execute();
    $down_hosts = $host_result->get_result();
    while ($row = $down_hosts->fetch_assoc()) {
        $unhealthy_alerts[] = [
            'type' => 'Host',
            'name' => $row['name'],
            'location' => 'Connection Lost',
            'link' => base_url('/host-overview')
        ];
    }
    $host_result->close();

    echo json_encode(['status' => 'success', 'alerts' => $unhealthy_alerts, 'count' => count($unhealthy_alerts)]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server error: ' . $e->getMessage()]);
} finally {
    $conn->close();
}
?>