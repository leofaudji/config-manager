#!/usr/bin/php
<?php
// File: /var/www/html/config-manager/system_cleanup.php
// Jalankan via cron job, misalnya setiap hari pada jam 3 pagi:
// 0 3 * * * /usr/bin/php /var/www/html/config-manager/system_cleanup.php >> /var/log/system_cleanup.log 2>&1

set_time_limit(300); // 5 minutes

if (!defined('PROJECT_ROOT')) {
    define('PROJECT_ROOT', __DIR__);
}

require_once PROJECT_ROOT . '/includes/bootstrap.php';

echo "Memulai System Cleanup pada " . date('Y-m-d H:i:s') . "\n";

$conn = Database::getInstance()->getConnection();

// Helper function to log activity if not running from CLI
function cleanup_log_activity($user, $action, $details, $host_id = null) {
    if (function_exists('log_activity')) log_activity($user, $action, $details, $host_id);
}

try {
    // --- 1. Cleanup Archived History ---
    $history_cleanup_days = (int)get_setting('history_cleanup_days', 30);
    echo "INFO: Menghapus riwayat konfigurasi yang diarsipkan lebih dari {$history_cleanup_days} hari...\n";
    $stmt_history = $conn->prepare("DELETE FROM config_history WHERE status = 'archived' AND created_at < NOW() - INTERVAL ? DAY");
    $stmt_history->bind_param("i", $history_cleanup_days);
    $stmt_history->execute();
    $deleted_history = $stmt_history->affected_rows;
    $stmt_history->close();
    echo "SUKSES: {$deleted_history} entri riwayat konfigurasi yang diarsipkan dihapus.\n";
    //log_activity('SYSTEM', 'History Cleanup', "Cron job removed {$deleted_history} archived history entries older than {$history_cleanup_days} days.");

    // --- 2. Cleanup Activity Logs ---
    $log_cleanup_days = (int)get_setting('log_cleanup_days', 7);
    echo "INFO: Menghapus log aktivitas lebih dari {$log_cleanup_days} hari...\n";
    $stmt_logs = $conn->prepare("DELETE FROM activity_log WHERE created_at < NOW() - INTERVAL ? DAY");
    $stmt_logs->bind_param("i", $log_cleanup_days);
    $stmt_logs->execute();
    $deleted_logs = $stmt_logs->affected_rows;
    $stmt_logs->close();
    echo "SUKSES: {$deleted_logs} entri log aktivitas dihapus.\n";

    // --- 3. Cleanup Host Stats History ---
    $stats_cleanup_days = (int)get_setting('host_stats_history_cleanup_days', 7);
    echo "INFO: Menghapus riwayat statistik host lebih dari {$stats_cleanup_days} hari...\n";
    $stmt_stats = $conn->prepare("DELETE FROM host_stats_history WHERE created_at < NOW() - INTERVAL ? DAY");
    $stmt_stats->bind_param("i", $stats_cleanup_days);
    $stmt_stats->execute();
    $deleted_stats = $stmt_stats->affected_rows;
    $stmt_stats->close();
    echo "SUKSES: {$deleted_stats} entri riwayat statistik host dihapus.\n";
    
    // --- 4. Cleanup Container Stats (for Resource Hotspots) ---
    $container_stats_cleanup_days = (int)get_setting('container_stats_cleanup_days', 1);
    if ($container_stats_cleanup_days > 0) {
        echo "INFO: Menghapus statistik kontainer lebih dari {$container_stats_cleanup_days} hari...\n";
        $stmt_container_stats = $conn->prepare("DELETE FROM container_stats WHERE created_at < NOW() - INTERVAL ? DAY");
        $stmt_container_stats->bind_param("i", $container_stats_cleanup_days);
        $stmt_container_stats->execute();
        $deleted_container_stats = $stmt_container_stats->affected_rows;
        $stmt_container_stats->close();
        echo "SUKSES: {$deleted_container_stats} entri statistik kontainer dihapus.\n";
    }

    // --- 5. Cleanup Container Health History (SLA data) ---
    $sla_history_cleanup_days = (int)get_setting('sla_history_cleanup_days', 90);
    if ($sla_history_cleanup_days > 0) {
        echo "INFO: Menghapus riwayat kesehatan kontainer (SLA) lebih dari {$sla_history_cleanup_days} hari...\n";
        $stmt_sla_history = $conn->prepare("DELETE FROM container_health_history WHERE start_time < NOW() - INTERVAL ? DAY");
        $stmt_sla_history->bind_param("i", $sla_history_cleanup_days);
        $stmt_sla_history->execute();
        $deleted_sla_history = $stmt_sla_history->affected_rows;
        $stmt_sla_history->close();
        echo "SUKSES: {$deleted_sla_history} entri riwayat kesehatan kontainer dihapus.\n";
    }

    // --- NEW: 6. Handle Stale/Down Hosts to ensure SLA accuracy ---
    $host_down_threshold_minutes = (int)get_setting('host_down_threshold_minutes', 5);
    echo "INFO: Mencari host yang tidak melapor selama lebih dari {$host_down_threshold_minutes} menit...\n";

    $cutoff_time = date('Y-m-d H:i:s', strtotime("-{$host_down_threshold_minutes} minutes"));
    $stmt_down_hosts = $conn->prepare("SELECT id, name FROM docker_hosts WHERE last_report_at < ? AND is_down_notified = 0");
    $stmt_down_hosts->bind_param("s", $cutoff_time);
    $stmt_down_hosts->execute();
    $newly_down_hosts = $stmt_down_hosts->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_down_hosts->close();

    if (empty($newly_down_hosts)) {
        echo "INFO: Tidak ada host yang down ditemukan.\n";
    } else {
        $stmt_get_open_events = $conn->prepare("
            SELECT h.container_id, h.container_name, h.status
            FROM container_health_history h
            INNER JOIN (
                SELECT container_id, MAX(id) as max_id
                FROM container_health_history
                WHERE host_id = ?
                GROUP BY container_id
            ) hm ON h.id = hm.max_id
            WHERE h.end_time IS NULL AND h.status != 'unhealthy'
        ");

        $stmt_close_event = $conn->prepare("UPDATE container_health_history SET end_time = NOW(), duration_seconds = TIMESTAMPDIFF(SECOND, start_time, NOW()) WHERE container_id = ? AND end_time IS NULL");
        $stmt_create_unhealthy_event = $conn->prepare("INSERT INTO container_health_history (host_id, container_id, container_name, status, start_time) VALUES (?, ?, ?, 'unhealthy', NOW())");
        $stmt_mark_notified = $conn->prepare("UPDATE docker_hosts SET is_down_notified = 1 WHERE id = ?");
        $stmt_create_incident = $conn->prepare("
            INSERT INTO incident_reports (incident_type, target_id, target_name, host_id, start_time, monitoring_snapshot, severity)
            SELECT 'host', ?, ?, ?, NOW(), ?, 'Critical'
            FROM DUAL WHERE NOT EXISTS (
                SELECT 1 FROM incident_reports 
                WHERE target_id = ? AND incident_type = 'host' AND status IN ('Open', 'Investigating')
            )
        ");


        foreach ($newly_down_hosts as $host) {
            echo "  -> Host '{$host['name']}' (ID: {$host['id']}) dianggap down. Memproses kontainer...\n";
            cleanup_log_activity('SYSTEM', 'Host Down Detected', "Host '{$host['name']}' is considered down. Creating synthetic downtime for SLA.", $host['id']);

            // Send notification only if enabled
            if ((bool)get_setting('notification_host_down_enabled', true)) {
                send_notification(
                    "Host Down: {$host['name']}",
                    "Host '{$host['name']}' is not reporting and is considered down. Synthetic downtime records are being created for SLA accuracy.",
                    'error',
                    ['host_id' => $host['id'], 'host_name' => $host['name']]
                );
            }

            // Create a new incident for the host going down
            $snapshot = json_encode(['message' => "Host failed to report in within the {$host_down_threshold_minutes} minute threshold."]);
            $target_id_str = (string)$host['id'];
            $stmt_create_incident->bind_param("ssisi", $target_id_str, $host['name'], $host['id'], $snapshot, $target_id_str);
            $stmt_create_incident->execute();

            // Send notification for the new incident if enabled
            if ($stmt_create_incident->affected_rows > 0 && (bool)get_setting('notification_incident_created_enabled', true)) {
                send_notification(
                    "New Incident (Host Down): {$host['name']}",
                    "A new incident has been opened for host '{$host['name']}' which is considered down.",
                    'error',
                    ['incident_type' => 'host', 'target_name' => $host['name'], 'host_id' => $host['id']]
                );
            }

            // Mark as notified to prevent spam
            $stmt_mark_notified->bind_param("i", $host['id']);
            $stmt_mark_notified->execute();

            $stmt_get_open_events->bind_param("i", $host['id']);
            $stmt_get_open_events->execute();
            $open_events = $stmt_get_open_events->get_result()->fetch_all(MYSQLI_ASSOC);

            foreach ($open_events as $event) {
                echo "    - Menandai '{$event['container_name']}' sebagai unhealthy.\n";
                // 1. Close the last open event
                $stmt_close_event->bind_param("s", $event['container_id']);
                $stmt_close_event->execute();
                // 2. Create a new 'unhealthy' event
                $stmt_create_unhealthy_event->bind_param("iss", $host['id'], $event['container_id'], $event['container_name']);
                $stmt_create_unhealthy_event->execute();
            }
        }
        $stmt_get_open_events->close();
        $stmt_close_event->close();
        $stmt_create_unhealthy_event->close();
        $stmt_mark_notified->close();
        $stmt_create_incident->close();
    }

    // --- 5. Cleanup Cron Job Log File Entries ---
    $cron_log_retention_days = (int)get_setting('cron_log_retention_days', 7);
    if ($cron_log_retention_days > 0) {
        echo "INFO: Menghapus entri log cron yang lebih lama dari {$cron_log_retention_days} hari...\n";
        $cron_log_path = get_setting('cron_log_path', '/var/log');
        $cron_scripts = ['collect_stats', 'autoscaler', 'health_monitor', 'system_cleanup'];
        $total_lines_removed = 0;
        $cutoff_timestamp = time() - ($cron_log_retention_days * 86400);

        foreach ($cron_scripts as $script) {
            $log_file = rtrim($cron_log_path, '/') . "/{$script}.log";
            if (file_exists($log_file) && is_writable($log_file)) {
                $lines = file($log_file, FILE_IGNORE_NEW_LINES);
                if ($lines === false) continue;

                $lines_to_keep = [];
                foreach ($lines as $line) {
                    // Try to parse timestamp like [YYYY-MM-DD HH:MM:SS]
                    if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $matches)) {
                        $line_timestamp = strtotime($matches[1]);
                        if ($line_timestamp >= $cutoff_timestamp) {
                            $lines_to_keep[] = $line;
                        }
                    } else {
                        // Keep lines that don't have a timestamp (e.g., multi-line error messages)
                        $lines_to_keep[] = $line;
                    }
                }

                $lines_removed = count($lines) - count($lines_to_keep);
                if ($lines_removed > 0) {
                    file_put_contents($log_file, implode("\n", $lines_to_keep) . "\n");
                    echo "  -> Dihapus {$lines_removed} baris lama dari '{$script}.log'.\n";
                    $total_lines_removed += $lines_removed;
                }
            }
        }
        echo "SUKSES: Total {$total_lines_removed} baris log lama dihapus.\n";
    } else {
        echo "INFO: Pembersihan file log cron dinonaktifkan (periode 0 hari).\n";
    }
} catch (Exception $e) {
    echo "ERROR: Terjadi kesalahan saat cleanup: " . $e->getMessage() . "\n";
    log_activity('SYSTEM', 'System Cleanup Error', "Cron job failed: " . $e->getMessage());
}

$conn->close();
echo "System Cleanup selesai pada " . date('Y-m-d H:i:s') . "\n";
?>