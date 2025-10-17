<?php
// File: /var/www/html/config-manager/api/sla_alert_status.php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/reports/SlaReportGenerator.php';

header('Content-Type: application/json');

if (!isset($_SESSION['role'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
    exit;
}

$conn = Database::getInstance()->getConnection();

try {
    $minimum_sla_target = (float)get_setting('minimum_sla_percentage', 99.9);
    $sla_alerts = [];

    $hosts_stmt = $conn->prepare("SELECT * FROM docker_hosts ORDER BY name ASC");
    $hosts_stmt->execute();
    $hosts = $hosts_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $hosts_stmt->close();

    $slaReportGenerator = new SlaReportGenerator($conn);

    foreach ($hosts as $host) {
        $host_id = $host['id'];

        // Calculate SLA for the last 30 days
        $sla_params = [
            'host_id'      => $host_id,
            'container_id' => 'all', // For host summary
            'start_date'   => date('Y-m-d 00:00:00', strtotime('-30 days')),
            'end_date'     => date('Y-m-d 23:59:59'),
            'dates'        => [date('Y-m-d', strtotime('-30 days')), date('Y-m-d')],
        ];
        $sla_data = $slaReportGenerator->getSlaData($sla_params);
        
        if ($sla_data['report_type'] === 'host_summary') {
            // Check individual service/container SLAs on that host
            $is_swarm_node = ($host['swarm_status'] === 'manager' || $host['swarm_status'] === 'worker');
            $item_type = $is_swarm_node ? 'Service' : 'Container';

            foreach ($sla_data['container_slas'] as $container_sla) {
                if ($container_sla['sla_percentage_raw'] < $minimum_sla_target) {
                    $sla_alerts[] = [
                        'type' => $item_type,
                        'name' => $container_sla['container_name'],
                        'host_name' => $host['name'],
                        'architecture' => $is_swarm_node ? 'Swarm' : 'Standalone',
                        'sla'  => $container_sla['sla_percentage'],
                        'sla_raw' => $container_sla['sla_percentage_raw'], // Add raw value for sorting
                        'link' => base_url('/sla-report')
                    ];
                }
            }
        }
    }

    // Sort alerts by SLA percentage descending, then by host name ascending
    usort($sla_alerts, function ($a, $b) {
        // Primary sort: SLA percentage DESCENDING
        if ($a['sla_raw'] !== $b['sla_raw']) {
            return $b['sla_raw'] <=> $a['sla_raw'];
        }
        // Secondary sort: Host name ASCENDING
        return $a['host_name'] <=> $b['host_name'];
    });

    echo json_encode(['status' => 'success', 'alerts' => $sla_alerts, 'count' => count($sla_alerts)]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server error: ' . $e->getMessage()]);
} finally {
    $conn->close();
}
?>