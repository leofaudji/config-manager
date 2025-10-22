<?php
// File: /var/www/html/config-manager/api/sidebar/status.php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/YamlGenerator.php';
require_once __DIR__ . '/../../includes/reports/SlaReportGenerator.php';
require_once __DIR__ . '/../../includes/GitHelper.php';

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
    $host_down_threshold_minutes = (int)get_setting('host_down_threshold_minutes', 5);
    $cutoff_time = date('Y-m-d H:i:s', strtotime("-{$host_down_threshold_minutes} minutes"));
    $stmt_down_hosts = $conn->prepare("
        SELECT COUNT(*) as count 
        FROM docker_hosts 
        WHERE is_down_notified = 1 AND last_report_at < ?
    ");
    $stmt_down_hosts->bind_param("s", $cutoff_time);
    $stmt_down_hosts->execute();
    $response_data['down_hosts_count'] = (int)($stmt_down_hosts->get_result()->fetch_assoc()['count'] ?? 0);
    $stmt_down_hosts->close();

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

    // 5. Git Diff Status for Stack Sync
    $git_sync_status = [
        'changes_count' => 0,
        'diff' => ''
    ];
    $repo_path = null;
    try {
        $git_enabled = (bool)get_setting('git_integration_enabled', false);
        if ($git_enabled) {
            $git = new GitHelper();
            $base_compose_path = get_setting('default_compose_path');
            
            if (!empty($base_compose_path) && is_dir($base_compose_path)) {
                $repo_path = $git->setupRepository();
                $stacks_result = $conn->query("SELECT s.stack_name, s.compose_file_path, h.name as host_name FROM application_stacks s JOIN docker_hosts h ON s.host_id = h.id");

                while ($stack = $stacks_result->fetch_assoc()) {
                    $safe_host_name = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $stack['host_name']);
                    $source_compose_file = rtrim($base_compose_path, '/') . "/{$safe_host_name}/{$stack['stack_name']}/{$stack['compose_file_path']}";

                    if (file_exists($source_compose_file)) {
                        $destination_dir_in_repo = "{$repo_path}/{$safe_host_name}/{$stack['stack_name']}";
                        if (!is_dir($destination_dir_in_repo)) @mkdir($destination_dir_in_repo, 0755, true);
                        copy($source_compose_file, "{$destination_dir_in_repo}/{$stack['compose_file_path']}");
                    }
                }

                $git->execute("add -A", $repo_path);
                $diff_output = $git->getDiff($repo_path, true);
                $changes_count = count(array_filter(explode("\n", $git->getStatus($repo_path))));

                $git_sync_status['changes_count'] = $changes_count;
                $git_sync_status['diff'] = $changes_count > 0 ? $diff_output : '';
            }
        }
    } catch (Exception $e) {
        // Ignore Git errors in this context to not break the entire status update. Log it instead.
        error_log("Sidebar Status - Git Diff Check Error: " . $e->getMessage());
    } finally {
        if (isset($git) && isset($repo_path) && !$git->isPersistentPath($repo_path)) $git->cleanup($repo_path);
    }
    $response_data['git_sync_status'] = $git_sync_status;

    // 6. Get Open Incidents
    $incident_stmt = $conn->prepare("
        SELECT id, incident_type, target_name, status 
        FROM incident_reports 
        WHERE status IN ('Open', 'Investigating') 
        ORDER BY start_time DESC 
        LIMIT 20
    ");
    $incident_stmt->execute();
    $open_incidents = $incident_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $incident_stmt->close();
    $response_data['open_incidents'] = ['count' => count($open_incidents), 'alerts' => $open_incidents];

    //echo json_encode(['status' => 'success', 'data' => $response_data]);
    
    // --- 7. Get Auto Backup Status for Today ---
    $backup_status_today = ['status' => 'pending', 'details' => 'No backup has run today.'];
    $backup_enabled = (bool)get_setting('backup_enabled', false);

    if ($backup_enabled) {
        $backup_path = get_setting('backup_path', '/var/www/html/config-manager/backups');
        $today_str = date('Y-m-d');
        $backup_files_today = glob(rtrim($backup_path, '/') . "/config-manager-backup-{$today_str}_*.json");

        if (!empty($backup_files_today)) {
            // Found at least one backup file for today
            $backup_status_today['status'] = 'success';
            $latest_file = end($backup_files_today);
            $backup_status_today['details'] = 'Latest backup created at ' . date('H:i:s', filemtime($latest_file)) . ' (' . basename($latest_file) . ')';
        } else {
            // No backup file found, check if there was an error log for today
            $stmt_error = $conn->prepare("SELECT details FROM activity_log WHERE action = 'Automatic Backup Error' AND DATE(created_at) = CURDATE() ORDER BY id DESC LIMIT 1");
            $stmt_error->execute();
            $error_log = $stmt_error->get_result()->fetch_assoc();
            $stmt_error->close();

            if ($error_log) {
                $backup_status_today['status'] = 'error';
                $backup_status_today['details'] = 'Backup failed today. Last error: ' . $error_log['details'];
            }
        }
    }
    $response_data['latest_backup_status'] = $backup_status_today;

    // --- 8. Get Pending Webhook Updates ---
    $pending_updates_stmt = $conn->prepare("
        SELECT s.id, s.stack_name, h.name as host_name, s.host_id
        FROM application_stacks s
        JOIN docker_hosts h ON s.host_id = h.id
        WHERE s.webhook_pending_update = 1
        ORDER BY s.stack_name ASC
        LIMIT 20
    ");
    $pending_updates_stmt->execute();
    $pending_updates_alerts = $pending_updates_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $pending_updates_stmt->close();
    
    $total_pending_count_stmt = $conn->query("SELECT COUNT(*) as count FROM application_stacks WHERE webhook_pending_update = 1");
    $total_pending_count = (int)($total_pending_count_stmt->fetch_assoc()['count'] ?? 0);
    
    $response_data['pending_updates'] = ['count' => $total_pending_count, 'alerts' => $pending_updates_alerts];

    echo json_encode(['status' => 'success', 'data' => $response_data]); 

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Server error while fetching status: ' . $e->getMessage()
    ]);
}

?>