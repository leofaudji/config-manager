#!/usr/bin/php
<?php
// File: /var/www/html/config-manager/system_backup.php
// This script is intended to be run by a cron job.

set_time_limit(300); // 5 minutes

if (!defined('PROJECT_ROOT')) {
    define('PROJECT_ROOT', __DIR__);
}

require_once PROJECT_ROOT . '/../includes/bootstrap.php';

echo "Memulai Automatic Backup pada " . date('Y-m-d H:i:s') . "\n";

$conn = Database::getInstance()->getConnection();

try {
    // Check if automatic backups are enabled
    $is_enabled = (bool)get_setting('backup_enabled', false);
    if (!$is_enabled) {
        echo "INFO: Automatic backups are disabled in settings. Exiting.\n";
        exit;
    }

    $backup_path = get_setting('backup_path', '/var/www/html/config-manager/backups');
    if (!is_dir($backup_path)) {
        if (!@mkdir($backup_path, 0755, true)) {
            throw new Exception("Backup path '{$backup_path}' does not exist and could not be created. Please check permissions.");
        }
    }
    if (!is_writable($backup_path)) {
        throw new Exception("Backup path '{$backup_path}' is not writable.");
    }

    // --- Create Backup ---
    $tables_to_backup = [
        'settings', 'users', 'groups', 'traefik_hosts', 'docker_hosts', 
        'application_stacks', 'middlewares', 'services', 'servers', 'routers', 
        'router_middleware', 'configuration_templates', 'transports'
    ];
    $backup_data = [];
    foreach ($tables_to_backup as $table) {
        $result = $conn->query("SELECT * FROM `{$table}`");
        $backup_data[$table] = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }

    $filename = 'config-manager-backup-' . date('Y-m-d_H-i-s') . '.json';
    $file_path = rtrim($backup_path, '/') . '/' . $filename;

    if (file_put_contents($file_path, json_encode($backup_data, JSON_PRETTY_PRINT))) {
        echo "SUKSES: Backup berhasil dibuat di: {$file_path}\n";
    } else {
        throw new Exception("Gagal menulis file backup ke: {$file_path}");
    }

    // --- Cleanup Old Backups ---
    $retention_days = (int)get_setting('backup_retention_days', 7);
    echo "INFO: Menghapus backup yang lebih lama dari {$retention_days} hari...\n";
    $files = glob(rtrim($backup_path, '/') . '/config-manager-backup-*.json');
    $deleted_count = 0;
    foreach ($files as $file) {
        if (is_file($file) && (time() - filemtime($file)) >= ($retention_days * 86400)) {
            if (unlink($file)) {
                $deleted_count++;
                echo "  -> Dihapus: " . basename($file) . "\n";
            } else {
                echo "  -> GAGAL menghapus: " . basename($file) . "\n";
            }
        }
    }
    echo "SUKSES: {$deleted_count} file backup lama dihapus.\n";
    log_activity('SYSTEM', 'Automatic Backup Success', "Backup created at {$file_path}. {$deleted_count} old backups removed.");

} catch (Exception $e) {
    $error_message = "ERROR: Terjadi kesalahan saat backup otomatis: " . $e->getMessage() . "\n";
    echo $error_message;
    // Log to activity log as well
    if (function_exists('log_activity')) {
        log_activity('SYSTEM', 'Automatic Backup Error', $error_message);
    }
}

$conn->close();
echo "Automatic Backup selesai pada " . date('Y-m-d H:i:s') . "\n";
?>