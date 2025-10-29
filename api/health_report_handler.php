<?php
// File: /var/www/html/config-manager/api/health_report_handler.php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/DockerClient.php';

header('Content-Type: application/json');

// --- Security Check ---
$api_key_header = $_SERVER['HTTP_X_API_KEY'] ?? '';
$expected_api_key = get_setting('health_agent_api_token');

if (empty($expected_api_key) || empty($api_key_header) || !hash_equals($expected_api_key, $api_key_header)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized: Invalid API Key.']);
    exit;
}

$request_body = file_get_contents('php://input');
$data = json_decode($request_body, true);

if (json_last_error() !== JSON_ERROR_NONE || !isset($data['host_id']) || !isset($data['reports'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON payload.']);
    exit;
}

$conn = Database::getInstance()->getConnection();

// --- Check if host was previously marked as down ---
$host_id = (int)$data['host_id'];
$stmt_check_down = $conn->prepare("SELECT name, is_down_notified FROM docker_hosts WHERE id = ?");
$stmt_check_down->bind_param("i", $host_id);
$stmt_check_down->execute();
$host_info = $stmt_check_down->get_result()->fetch_assoc();
$stmt_check_down->close();

$was_down = $host_info && $host_info['is_down_notified'];

// --- Update Host's Last Report Time & Reset Down Flag ---
// This is the primary indicator that the host is online.
// We also reset the 'is_down_notified' flag here.
$host_uptime_seconds = $data['host_uptime_seconds'] ?? null;
$agent_version = $data['agent_version'] ?? null;
$stmt_update_host = $conn->prepare(
    "UPDATE docker_hosts SET last_report_at = NOW(), agent_status = 'Running', is_down_notified = 0, host_uptime_seconds = ?, agent_version = ? WHERE id = ?"
);
$stmt_update_host->bind_param("isi", $host_uptime_seconds, $agent_version, $host_id);
$stmt_update_host->execute();

if ($stmt_update_host->affected_rows === 0) {
    // This might happen if the agent reports for a host ID that doesn't exist in the DB.
    http_response_code(404);
    die(json_encode(['status' => 'error', 'message' => 'Host ID not found or no update was necessary.']));
}
$stmt_update_host->close();

// --- Send Recovery Notification if it was previously down ---
if ($was_down && (bool)get_setting('notification_host_down_enabled', true)) {
    // Send notification only if enabled
    if ((bool)get_setting('notification_host_down_enabled', true)) {
        send_notification(
            "Host Recovered: {$host_info['name']}",
            "Host '{$host_info['name']}' is back online and reporting health status.",
            'success',
            ['host_id' => $host_id, 'host_name' => $host_info['name']]
        );

        // Resolve any open incident for this host
        $stmt_resolve_incident = $conn->prepare("
            UPDATE incident_reports 
            SET status = 'Resolved', end_time = NOW(), duration_seconds = TIMESTAMPDIFF(SECOND, start_time, NOW())
            WHERE target_id = ? AND incident_type = 'host' AND status IN ('Open', 'Investigating')
        ");
        $stmt_resolve_incident->bind_param("s", $host_id);
        $stmt_resolve_incident->execute();
    }
}

try {
    $conn->begin_transaction();
    $reports = $data['reports'];
    $running_container_ids = $data['running_container_ids'] ?? [];
    $container_stats = $data['container_stats'] ?? [];
    $host_cpu_usage_percent = $data['host_cpu_usage_percent'] ?? null;

    // --- [SLA LOGIC - REFACTORED] Get the last recorded history status for all containers on this host in one query ---
    // This is more efficient than querying inside the loop.
    $stmt_get_last_histories = $conn->prepare("
        SELECT h.container_id, h.status
        FROM container_health_history h
        INNER JOIN (
            SELECT container_id, MAX(id) as max_id
            FROM container_health_history
            WHERE host_id = ?
            GROUP BY container_id
        ) hm ON h.id = hm.max_id
    ");
    $stmt_get_last_histories->bind_param("i", $host_id);
    $stmt_get_last_histories->execute();
    $last_history_statuses = $stmt_get_last_histories->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_get_last_histories->close();
    // Convert to a more accessible map: [container_id => status]
    $last_history_status_map = array_column($last_history_statuses, 'status', 'container_id');

    // --- Prepare statement for updating container health ---
    $stmt_update = $conn->prepare(
        "INSERT INTO container_health_status (container_id, host_id, container_name, status, consecutive_failures, consecutive_successes, last_checked_at, last_log)
         VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)
         ON DUPLICATE KEY UPDATE 
            container_name = VALUES(container_name), 
            status = VALUES(status), 
            consecutive_failures = VALUES(consecutive_failures),
            consecutive_successes = VALUES(consecutive_successes),
            last_checked_at = VALUES(last_checked_at), 
            host_id = VALUES(host_id),
            last_log = VALUES(last_log)"
    );
    
    // --- [SLA LOGIC] Prepare statements for history logging ---
    $stmt_update_history = $conn->prepare(
        "UPDATE container_health_history SET end_time = NOW(), duration_seconds = TIMESTAMPDIFF(SECOND, start_time, NOW()) 
         WHERE container_id = ? AND host_id = ? AND end_time IS NULL"
    );

    $stmt_insert_history = $conn->prepare(
        "INSERT INTO container_health_history (host_id, container_id, container_name, status, start_time) VALUES (?, ?, ?, ?, NOW())"
    );

    $stmt_create_incident = $conn->prepare("
        INSERT INTO incident_reports (incident_type, target_id, target_name, host_id, start_time, monitoring_snapshot, severity, status)
        SELECT 'container', ?, ?, ?, NOW(), ?, 'High', 'Open' /* The values for the new incident */
        FROM DUAL WHERE NOT EXISTS (
            /* This subquery checks for existing duplicates */
            SELECT 1 FROM incident_reports 
            WHERE target_id = ? AND incident_type = 'container' AND DATE(start_time) = CURDATE() AND status IN ('Open', 'Investigating')
        ) 
    ");


    // Get global thresholds from settings
    $healthy_threshold = (int)get_setting('health_check_default_healthy_threshold', 2);
    $unhealthy_threshold = (int)get_setting('health_check_default_unhealthy_threshold', 3);

    foreach ($reports as $report) {
        if (!isset($report['container_id'], $report['container_name'], $report['is_healthy'])) {
            continue; // Skip malformed reports
        }

        // Fetch current status from DB to apply threshold logic
        $stmt_get_status = $conn->prepare("SELECT status, consecutive_failures, consecutive_successes FROM container_health_status WHERE container_id = ?");
        $stmt_get_status->bind_param("s", $report['container_id']);
        $stmt_get_status->execute();
        $current_status = $stmt_get_status->get_result()->fetch_assoc() ?: ['status' => 'unknown', 'consecutive_failures' => 0, 'consecutive_successes' => 0];
        $stmt_get_status->close();

        $new_status = $current_status['status'] ?? 'unknown';
        $failures = (int)$current_status['consecutive_failures'];
        $successes = (int)$current_status['consecutive_successes'];

        if ($report['is_healthy'] === true) { // Check was successful
            $successes++; $failures = 0;
            if ($successes >= $healthy_threshold) $new_status = 'healthy';
            else if ($new_status !== 'healthy') $new_status = 'unknown'; // Stay unknown until threshold is met
        } elseif ($report['is_healthy'] === false) { // Check failed
            $failures++; $successes = 0;
            if ($failures >= $unhealthy_threshold) $new_status = 'unhealthy';
        } elseif ($report['is_healthy'] === 'starting') { // Check is in progress
            $new_status = 'starting';
        } elseif ($report['is_healthy'] === 'stopped') { // NEW: Container was manually stopped
            $new_status = 'stopped';
        } else { // is_healthy is null (unknown)
            $new_status = 'unknown';
        }

        if ($new_status === 'healthy' && $current_status['status'] === 'unhealthy') {
            // Status just changed back to healthy, resolve any open incident
            $stmt_resolve_incident = $conn->prepare("
                UPDATE incident_reports 
                SET status = 'Resolved', end_time = NOW(), duration_seconds = TIMESTAMPDIFF(SECOND, start_time, NOW())
                WHERE target_id = ? AND status IN ('Open', 'Investigating')
            ");
            $stmt_resolve_incident->bind_param("s", $report['container_id']);
            $stmt_resolve_incident->execute();
        } elseif ($new_status === 'unhealthy' && $current_status['status'] !== 'unhealthy') {
            // --- CORRECT PLACEMENT for Incident Creation Logic ---
            // Status just changed to unhealthy, create a new incident if no open one exists
            $snapshot = json_encode($report['log_message'] ?? 'No log available.');
            $stmt_create_incident->bind_param("ssiss", $report['container_id'], $report['container_name'], $host_id, $snapshot, $report['container_id']);
            $stmt_create_incident->execute();

            // Send notification for the new incident if enabled
            if ($stmt_create_incident->affected_rows > 0 && (bool)get_setting('notification_incident_created_enabled', true)) {
                send_notification(
                    "New Incident: " . $report['container_name'],
                    "A new incident has been opened for container '{$report['container_name']}' on host '{$host_info['name']}'. Status: UNHEALTHY.",
                    'error',
                    ['incident_type' => 'container', 'target_name' => $report['container_name'], 'host_id' => $host_id]
                );
            }
        } 

        // --- [SLA LOGIC - REFACTORED] ---
        // Only create a history record if the new status is definitive (not 'unknown')
        // and it's different from the last recorded definitive status from our pre-fetched map.
        if ($new_status !== 'unknown' && $new_status !== 'starting') {
            $last_history_status = $last_history_status_map[$report['container_id']] ?? 'nonexistent';

            if ($new_status !== $last_history_status) {
                // A real, definitive status change has occurred.
                // 1. Close the previous status event in history.
                $stmt_update_history->bind_param("si", $report['container_id'], $host_id);
                $stmt_update_history->execute();
                // 2. Open a new status event in history for the new definitive state.
                $stmt_insert_history->bind_param("isss", $host_id, $report['container_id'], $report['container_name'], $new_status);
                $stmt_insert_history->execute();
            }
        }


        $stmt_update->bind_param(
            "sissiis",
            $report['container_id'],
            $host_id,
            $report['container_name'],
            $new_status,
            $failures,
            $successes,
            $report['log_message']
        );
        $stmt_update->execute();
    }
    $stmt_update->close();
    $stmt_update_history->close();
    $stmt_insert_history->close();
    $stmt_create_incident->close();

    // --- NEW: Insert container stats ---
    if (!empty($container_stats)) {
        $stmt_stats = $conn->prepare(
            "INSERT INTO container_stats (host_id, container_id, container_name, cpu_usage, memory_usage)
             VALUES (?, ?, ?, ?, ?)"
        );
        foreach ($container_stats as $stat) {
            $stmt_stats->bind_param(
                "issdi",
                $host_id, $stat['container_id'], $stat['container_name'],
                $stat['cpu_usage'], $stat['memory_usage']
            );
            $stmt_stats->execute();
        }
        $stmt_stats->close();
    }

    // --- NEW: Calculate and insert aggregated host stats ---
    if (!empty($container_stats) && $host_id > 0) {
        $total_container_cpu_usage = 0;
        $total_memory_usage = 0;
        foreach ($container_stats as $stat) {
            $total_container_cpu_usage += (float)($stat['cpu_usage'] ?? 0);
            $total_memory_usage += (int)($stat['memory_usage'] ?? 0);
        }

        // Get host total memory to calculate percentage
        $stmt_host_info = $conn->prepare("SELECT docker_api_url, tls_enabled, ca_cert_path, client_cert_path, client_key_path FROM docker_hosts WHERE id = ?");
        $stmt_host_info->bind_param("i", $host_id);
        $stmt_host_info->execute();
        $host_details = $stmt_host_info->get_result()->fetch_assoc();
        $stmt_host_info->close();

        $dockerClient = new DockerClient($host_details);
        $dockerInfo = $dockerClient->getInfo();
        $host_total_memory = $dockerInfo['MemTotal'] ?? 0;

        $stmt_history = $conn->prepare("INSERT INTO host_stats_history (host_id, container_cpu_usage_percent, host_cpu_usage_percent, memory_usage_bytes, memory_limit_bytes) VALUES (?, ?, ?, ?, ?)");
        $stmt_history->bind_param("idddi", $host_id, $total_container_cpu_usage, $host_cpu_usage_percent, $total_memory_usage, $host_total_memory);
        $stmt_history->execute();
        $stmt_history->close();
    }

    // --- Cleanup Stale Container Records ---
    if (!empty($running_container_ids)) {
        // Create placeholders for the IN clause
        $placeholders = implode(',', array_fill(0, count($running_container_ids), '?'));
        $types = str_repeat('s', count($running_container_ids));

        // --- [SLA LOGIC] Find which containers are actually being removed ---
        $stmt_get_stale = $conn->prepare("SELECT container_id, container_name FROM container_health_status WHERE host_id = ? AND container_id NOT IN ({$placeholders})");
        $stmt_get_stale->bind_param("i" . $types, $host_id, ...$running_container_ids);
        $stmt_get_stale->execute();
        $stale_containers = $stmt_get_stale->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt_get_stale->close();
        // The logic for creating 'stopped' records is now handled by the agent reporting 'stopped' status.
        // We only need to delete the status from the current health status table.

        // The query deletes records for this host that are NOT in the list of running containers.
        $sql_delete = "DELETE FROM container_health_status WHERE host_id = ? AND container_id NOT IN ({$placeholders})";
        
        $stmt_delete = $conn->prepare($sql_delete);
        $stmt_delete->bind_param("i" . $types, $host_id, ...$running_container_ids); // NOSONAR
        $stmt_delete->execute();
        $deleted_count = $stmt_delete->affected_rows;
        $stmt_delete->close();

        //log_activity('SYSTEM', 'DB Cleanup', "Removed {$deleted_count} stale container record(s) for host ID {$host_id}.");
    }

    $conn->commit();
    echo json_encode(['status' => 'success', 'message' => 'Health report processed successfully.']);

} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    log_activity('SYSTEM', 'Health Report Error', 'Error processing health report for host ' . $host_id . ': ' . $e->getMessage(), $host_id);
    echo json_encode(['status' => 'error', 'message' => 'Server error: ' . $e->getMessage()]);
}

$conn->close();