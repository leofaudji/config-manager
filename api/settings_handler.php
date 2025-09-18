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
        'default_group_id' => $_POST['default_group_id'] ?? 1,
        'history_cleanup_days' => $_POST['history_cleanup_days'] ?? 30,
        'default_router_middleware' => $_POST['default_router_middleware'] ?? 0,
        'default_router_prefix' => $_POST['default_router_prefix'] ?? 'router-',
        'default_service_prefix' => $_POST['default_service_prefix'] ?? 'service-',
        'default_compose_path' => trim($_POST['default_compose_path'] ?? ''),
        'default_git_compose_path' => trim($_POST['default_git_compose_path'] ?? ''),
        'git_integration_enabled' => $_POST['git_integration_enabled'] ?? 0,
        'git_repository_url' => trim($_POST['git_repository_url'] ?? ''),
        'git_branch' => trim($_POST['git_branch'] ?? 'main'),
        'git_user_name' => trim($_POST['git_user_name'] ?? 'Config Manager'),
        'git_user_email' => trim($_POST['git_user_email'] ?? 'bot@config-manager.local'),
        'git_ssh_key_path' => trim($_POST['git_ssh_key_path'] ?? ''),
        'git_pat' => trim($_POST['git_pat'] ?? ''),
        'temp_directory_path' => rtrim(trim($_POST['temp_directory_path'] ?? sys_get_temp_dir()), '/'),
        'git_persistent_repo_path' => rtrim(trim($_POST['git_persistent_repo_path'] ?? ''), '/'),
    ];

    // Use INSERT ... ON DUPLICATE KEY UPDATE for a safe upsert
    $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");

    foreach ($settings_to_update as $key => $value) {
        $stmt->bind_param("ss", $key, $value);
        if (!$stmt->execute()) {
            throw new Exception("Failed to update setting: {$key}");
        }
    }
    $stmt->close();
    log_activity($_SESSION['username'], 'Settings Updated', "General settings have been updated.");
    echo json_encode(['status' => 'success', 'message' => 'General settings have been successfully updated.']);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();
?>