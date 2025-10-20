<?php
// File: /var/www/html/config-manager/api/sidebar/status.php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/YamlGenerator.php';
require_once __DIR__ . '/../../includes/reports/SlaReportGenerator.php';

header('Content-Type: application/json');

if (!isset($_SESSION['role'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
    exit;
}

try {
    $conn = Database::getInstance()->getConnection();
    $response_data = [];

    // 1. Get Unhealthy Items (Count and Details)
    $unhealthy_stmt = $conn->prepare("
        SELECT s.container_name, s.last_log, h.name as host_name, h.id as host_id
        FROM container_health_status s
        JOIN docker_hosts h ON s.host_id = h.id
        WHERE s.status = 'unhealthy'
        ORDER BY h.name, s.container_name
        LIMIT 20
    ");
    $unhealthy_stmt->execute();
    $unhealthy_alerts = $unhealthy_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $unhealthy_stmt->close();

    // Process alerts to add health_check_type
    foreach ($unhealthy_alerts as &$alert) {
        $log_data = json_decode($alert['last_log'], true);
        $type = 'unknown';
        if (is_array($log_data)) {
            // Find the first step that was not skipped
            $first_check = array_values(array_filter($log_data, fn($step) => $step['status'] !== 'skipped'))[0] ?? null;
            if ($first_check) {
                $type = str_replace(' Check', '', $first_check['step']);
            }
        }
        $alert['health_check_type'] = $type;
        unset($alert['last_log']); // Clean up the response
    }

    $response_data['unhealthy_items'] = [
        'count' => count($unhealthy_alerts),
        'alerts' => $unhealthy_alerts
    ];

    // 2. Get Down Hosts Count
    $down_hosts_result = $conn->query("SELECT COUNT(*) as count FROM docker_hosts WHERE is_down_notified = 1");
    $response_data['down_hosts_count'] = (int)($down_hosts_result->fetch_assoc()['count'] ?? 0);

    // 3. Check for Pending Traefik Changes
    $auto_deploy_enabled = (bool)get_setting('auto_deploy_enabled', true);
    if (!$auto_deploy_enabled) {
        $yamlGenerator = new YamlGenerator($conn);
        $current_config_content = $yamlGenerator->generate();
        $current_hash = md5($current_config_content);

        $active_history_stmt = $conn->prepare("SELECT yaml_content FROM config_history WHERE status = 'active' ORDER BY id DESC LIMIT 1");
        $active_history_stmt->execute();
        $active_history_result = $active_history_stmt->get_result();
        $active_config_content = $active_history_result->fetch_assoc()['yaml_content'] ?? '';
        $active_hash = md5($active_config_content);

        $response_data['config_dirty'] = ($current_hash !== $active_hash);
    } else {
        $response_data['config_dirty'] = false;
    }

    // 4. Get SLA Violations (items with SLA < target in the last 30 days)
    $sla_target = (float)get_setting('minimum_sla_percentage', 99.9);
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
            $is_swarm_node = ($host['swarm_status'] === 'manager' || $host['swarm_status'] === 'worker');

            foreach ($sla_data['container_slas'] as $container_sla) {
                if ($container_sla['sla_percentage_raw'] < $sla_target) {
                    $sla_alerts[] = [
                        'container_name' => $container_sla['container_name'],
                        'host_name' => $host['name'],
                        'host_id' => $host['id'],
                        'swarm_status' => $host['swarm_status'],
                        'sla_percentage' => $container_sla['sla_percentage'],
                        'sla_percentage_raw' => $container_sla['sla_percentage_raw'],
                    ];
                }
            }
        }
    }

    // Sort SLA alerts by the lowest percentage first
    usort($sla_alerts, function ($a, $b) {
        // Sort by sla_percentage_raw in ascending order
        return $a['sla_percentage_raw'] <=> $b['sla_percentage_raw'];
    });

    $response_data['sla_violations'] = [
        'count' => count($sla_alerts),
        'alerts' => array_slice($sla_alerts, 0, 20) // Limit to 20 items for UI performance
    ];


    echo json_encode(['status' => 'success', 'data' => $response_data]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Server error while fetching status: ' . $e->getMessage()
    ]);
}

?>