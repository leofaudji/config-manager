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

try {
    // --- 1. Cleanup Archived History ---
    $history_cleanup_days = (int)get_setting('history_cleanup_days', 30);
    echo "INFO: Menghapus riwayat konfigurasi yang diarsipkan lebih dari {$history_cleanup_days} hari...\n";
    $stmt_history = $conn->prepare("DELETE FROM config_history WHERE status = 'archived' AND created_at < NOW() - INTERVAL ? DAY");
    $stmt_history->bind_param("i", $history_cleanup_days);
    $stmt_history->execute();
    $deleted_history = $stmt_history->affected_rows;
    $stmt_history->close();
    echo "SUKSES: {$deleted_history} entri riwayat konfigurasi dihapus.\n";
    log_activity('SYSTEM', 'History Cleanup', "Cron job removed {$deleted_history} archived history entries older than {$history_cleanup_days} days.");

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

    // --- 4. Cleanup Cron Job Log File Entries ---
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