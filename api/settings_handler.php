<?php
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');
$conn = Database::getInstance()->getConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

try {
    $settings_to_update = [
        'active_traefik_host_id' => $_POST['active_traefik_host_id'] ?? 1,
        'yaml_output_path' => trim($_POST['yaml_output_path'] ?? ''),
        'default_group_id' => $_POST['default_group_id'] ?? 1,
        'history_cleanup_days' => $_POST['history_cleanup_days'] ?? 30,
        'default_router_middleware' => $_POST['default_router_middleware'] ?? 0,
        'default_router_prefix' => $_POST['default_router_prefix'] ?? 'router-',
        'default_service_prefix' => $_POST['default_service_prefix'] ?? 'service-',
        'default_compose_path' => trim($_POST['default_compose_path'] ?? ''),
        'default_git_compose_path' => trim($_POST['default_git_compose_path'] ?? ''),
        'git_repository_url' => trim($_POST['git_repository_url'] ?? ''),
        'git_branch' => trim($_POST['git_branch'] ?? 'main'),
        'git_user_name' => trim($_POST['git_user_name'] ?? 'Config Manager'),
        'git_user_email' => trim($_POST['git_user_email'] ?? 'bot@config-manager.local'),
        'git_ssh_key_path' => trim($_POST['git_ssh_key_path'] ?? ''),
        'git_pat' => trim($_POST['git_pat'] ?? ''),
        'temp_directory_path' => rtrim(trim($_POST['temp_directory_path'] ?? sys_get_temp_dir()), '/'), // Already handled by form
        'git_persistent_repo_path' => rtrim(trim($_POST['git_persistent_repo_path'] ?? ''), '/'), // Already handled by form
        'cron_log_path' => rtrim(trim($_POST['cron_log_path'] ?? '/var/log'), '/'), // Already handled by form
        'host_stats_history_cleanup_days' => (int)($_POST['host_stats_history_cleanup_days'] ?? 7),
        'container_stats_cleanup_days' => (int)($_POST['container_stats_cleanup_days'] ?? 1),
        'cron_log_retention_days' => (int)($_POST['cron_log_retention_days'] ?? 7),
        'log_cleanup_days' => (int)($_POST['log_cleanup_days'] ?? 7),
        'sla_history_cleanup_days' => (int)($_POST['sla_history_cleanup_days'] ?? 90),
        'git_integration_enabled' => isset($_POST['git_integration_enabled']) ? 1 : 0, // Handles unchecked case
        'health_check_default_healthy_threshold' => (int)($_POST['health_check_default_healthy_threshold'] ?? 2),
        'health_check_default_unhealthy_threshold' => (int)($_POST['health_check_default_unhealthy_threshold'] ?? 3),
        'health_agent_api_token' => trim($_POST['health_agent_api_token'] ?? ''),
        'app_base_url' => trim($_POST['app_base_url'] ?? ''),
        'auto_healing_enabled' => isset($_POST['auto_healing_enabled']) ? 1 : 0,
        'agent_log_levels' => implode(',', $_POST['agent_log_levels'] ?? []),
        'health_agent_image' => trim($_POST['health_agent_image'] ?? ''),
        'webhook_cooldown_period' => (int)($_POST['webhook_cooldown_period'] ?? 300),
        'webhook_build_image_enabled' => isset($_POST['webhook_build_image_enabled']) ? 1 : 0,
        'minimum_sla_percentage' => (float)($_POST['minimum_sla_percentage'] ?? 99.9),
        'maintenance_window_enabled' => isset($_POST['maintenance_window_enabled']) ? 1 : 0,
        'maintenance_window_day' => $_POST['maintenance_window_day'] ?? 'Sunday',
        'maintenance_window_start_time' => $_POST['maintenance_window_start_time'] ?? '02:00',
        'maintenance_window_end_time' => $_POST['maintenance_window_end_time'] ?? '04:00',
    ];
    $settings_to_update['auto_deploy_enabled'] = isset($_POST['auto_deploy_enabled']) ? 1 : 0;
    $settings_to_update['notification_enabled'] = isset($_POST['notification_enabled']) ? 1 : 0;
    $settings_to_update['notification_host_down_enabled'] = isset($_POST['notification_host_down_enabled']) ? 1 : 0;
    $settings_to_update['notification_server_url'] = trim($_POST['notification_server_url'] ?? '');
    $settings_to_update['host_down_threshold_minutes'] = (int)($_POST['host_down_threshold_minutes'] ?? 5);
    $settings_to_update['notification_incident_created_enabled'] = isset($_POST['notification_incident_created_enabled']) ? 1 : 0;
    $settings_to_update['backup_enabled'] = isset($_POST['backup_enabled']) ? 1 : 0;
    $settings_to_update['backup_path'] = trim($_POST['backup_path'] ?? '/var/www/html/config-manager/backups');
    $settings_to_update['backup_retention_days'] = (int)($_POST['backup_retention_days'] ?? 7);
    $settings_to_update['notification_secret_token'] = trim($_POST['notification_secret_token'] ?? '');
    $settings_to_update['header_notification_interval'] = (int)($_POST['header_notification_interval'] ?? 30);

    // Use INSERT ... ON DUPLICATE KEY UPDATE for a safe upsert
    $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");

    foreach ($settings_to_update as $key => $value) {
        $stmt->bind_param("ss", $key, $value);
        if (!$stmt->execute()) {
            throw new Exception("Failed to update setting: {$key}");
        }
    }
    $stmt->close();

    // --- Auto-create directories if they don't exist ---
    $folder_creation_warnings = [];
    $paths_to_create = [
        'Traefik Dynamic Config Base Path' => $settings_to_update['yaml_output_path'],
        'Default Standalone Compose Path' => $settings_to_update['default_compose_path'],
        'Git Persistent Repo Path' => $settings_to_update['git_persistent_repo_path'],
        'Temporary Directory Path' => $settings_to_update['temp_directory_path'],
        'Cron Job Log Path' => $settings_to_update['cron_log_path'],
        'Backup Storage Path' => $settings_to_update['backup_path']
    ];

    foreach ($paths_to_create as $label => $path) {
        if (!empty($path)) {
            if (is_dir($path)) {
                // Directory already exists, check if it's writable
                if (!is_writable($path)) {
                    $folder_creation_warnings[] = "Directory for '{$label}' at '{$path}' exists but is not writable.";
                }
            } else {
                // Directory does not exist, try to create it
                if (!@mkdir($path, 0777, true) && !is_dir($path)) {
                    $folder_creation_warnings[] = "Failed to create directory for '{$label}' at '{$path}'. Please check permissions of the parent directory.";
                } else {
                    // If mkdir succeeded or the directory was created by a concurrent process,
                    // now explicitly set permissions and ownership.
                    @chmod($path, 0777); // Explicitly set permissions to rwxrwxrwx
                    @chown($path, 'www-data');
                    @chgrp($path, 'www-data');
                }
            }
        }
    }

    $success_message = 'General settings have been successfully updated.';
    if (!empty($folder_creation_warnings)) {
        $success_message .= " Warning: " . implode(' ', $folder_creation_warnings);
    }

    log_activity($_SESSION['username'], 'Settings Updated', "General settings have been updated.");
    echo json_encode(['status' => 'success', 'message' => $success_message]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();
?>