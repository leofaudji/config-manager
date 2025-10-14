<?php
// File: /var/www/html/config-manager/api/health_report_handler.php

require_once __DIR__ . '/../includes/bootstrap.php';

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
$conn->begin_transaction();

try {
    $host_id = (int)$data['host_id'];
    $reports = $data['reports'];
    $running_container_ids = $data['running_container_ids'] ?? [];
    $container_stats = $data['container_stats'] ?? [];
    $host_uptime_seconds = $data['host_uptime_seconds'] ?? null;

    // --- Update Host Status (last_report_at and uptime) ---
    $stmt_host = $conn->prepare("UPDATE docker_hosts SET last_report_at = NOW(), host_uptime_seconds = ? WHERE id = ?");
    $stmt_host->bind_param("ii", $host_uptime_seconds, $host_id);
    $stmt_host->execute();
    $stmt_host->close();

    // --- Prepare statement for updating container health ---
    $stmt_update = $conn->prepare(
        "INSERT INTO container_health_status (container_id, host_id, container_name, status, last_checked_at, last_log)
         VALUES (?, ?, ?, ?, NOW(), ?)
         ON DUPLICATE KEY UPDATE 
            container_name = VALUES(container_name), 
            status = VALUES(status), 
            last_checked_at = VALUES(last_checked_at),
            last_log = VALUES(last_log)"
    );

    foreach ($reports as $report) {
        if (!isset($report['container_id'], $report['container_name'], $report['is_healthy'])) {
            continue; // Skip malformed reports
        }

        $status = 'unknown';
        if ($report['is_healthy'] === true) {
            $status = 'healthy';
        } elseif ($report['is_healthy'] === false) {
            $status = 'unhealthy';
        }

        $stmt_update->bind_param(
            "sisss",
            $report['container_id'],
            $host_id,
            $report['container_name'],
            $status,
            $report['log_message']
        );
        $stmt_update->execute();
    }
    $stmt_update->close();

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

    // --- Cleanup Stale Container Records ---
    if (!empty($running_container_ids)) {
        // Create placeholders for the IN clause
        $placeholders = implode(',', array_fill(0, count($running_container_ids), '?'));
        $types = str_repeat('s', count($running_container_ids));

        // The query deletes records for this host that are NOT in the list of running containers.
        $sql_delete = "DELETE FROM container_health_status WHERE host_id = ? AND container_id NOT IN ({$placeholders})";
        
        $stmt_delete = $conn->prepare($sql_delete);
        $stmt_delete->bind_param("i" . $types, $host_id, ...$running_container_ids);
        $stmt_delete->execute();
        $deleted_count = $stmt_delete->affected_rows;
        $stmt_delete->close();

        log_activity('SYSTEM', 'DB Cleanup', "Removed {$deleted_count} stale container record(s) for host ID {$host_id}.");
    }

    $conn->commit();
    echo json_encode(['status' => 'success', 'message' => 'Report processed.']);

} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    log_activity('SYSTEM', 'Health Report Error', "Failed to process health report: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Server error: ' . $e->getMessage()]);
}

$conn->close();