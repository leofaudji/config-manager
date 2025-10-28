<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/GitHelper.php';
require_once __DIR__ . '/../includes/AppLauncherHelper.php'; // For applyFormSettings
require_once __DIR__ . '/../includes/DockerComposeParser.php'; // For YAMLLoad
require_once __DIR__ . '/../includes/Spyc.php'; // For YAMLDump

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

$conn = Database::getInstance()->getConnection();
$git = new GitHelper();
$repo_path = null;

try {
    // 1. Check if Git integration is enabled
    $git_enabled = (bool)get_setting('git_integration_enabled', false);
    if (!$git_enabled) {
        throw new Exception("Git integration is not enabled in settings.");
    }

    // 2. Check if the base compose path is configured
    $base_compose_path = get_setting('default_compose_path');
    if (empty($base_compose_path) || !is_dir($base_compose_path)) {
        throw new Exception("Default Standalone Compose Path is not configured or does not exist on the server.");
    }

    // 3. Setup the Git repository (clone or pull)
    $repo_path = $git->setupRepository();

    // 4. Get all managed application stacks from the database
    $stacks_result = $conn->query("SELECT s.stack_name, s.compose_file_path, s.deployment_details, h.name as host_name FROM application_stacks s JOIN docker_hosts h ON s.host_id = h.id");
    if ($stacks_result->num_rows === 0) {
        echo json_encode(['status' => 'success', 'message' => 'No application stacks found to sync.']);
        if (!$git->isPersistentPath($repo_path)) {
            $git->cleanup($repo_path);
        }
        exit;
    }

    $synced_count = 0;
    // 5. Loop through stacks and copy their compose files to the repo
    while ($stack = $stacks_result->fetch_assoc()) {
        try {
            $host_name = $stack['host_name'];
            $stack_name = $stack['stack_name'];
            $compose_filename = $stack['compose_file_path'];
            $deployment_details = json_decode($stack['deployment_details'], true);

            if (!$deployment_details) {
                error_log("Sync to Git: Could not decode deployment_details for stack '{$stack_name}'. Skipping.");
                continue;
            }

            // --- NEW LOGIC: Regenerate the compose file from deployment_details ---
            $source_type = $deployment_details['source_type'] ?? 'unknown';
            $compose_content_to_save = '';

            if ($source_type === 'git' || $source_type === 'image' || $source_type === 'hub') {
                // For these types, we can reconstruct the base YAML and apply settings.
                $base_compose_data = [];
                if ($source_type === 'git') {
                    // This is a simplification. For full accuracy, we might need to clone the repo.
                    // But for syncing, using a minimal base is often sufficient.
                    $base_compose_data = ['version' => '3.8', 'services' => [$stack_name => ['image' => 'placeholder:latest']]];
                } else { // image or hub
                    $image_name = ($source_type === 'image') ? ($deployment_details['image_name_local'] ?? '') : ($deployment_details['image_name_hub'] ?? '');
                    $base_compose_data = ['version' => '3.8', 'services' => [$stack_name => ['image' => $image_name]]];
                }
                // We don't need host or swarm status for this regeneration, so we pass dummy values.
                AppLauncherHelper::applyFormSettings($base_compose_data, $deployment_details, [], false);
                $compose_content_to_save = Spyc::YAMLDump($base_compose_data, 2, 0);
            } elseif ($source_type === 'editor') {
                // For editor, the source of truth IS the content in the details.
                $compose_content_to_save = $deployment_details['compose_content_editor'] ?? '';
            }

            if (!empty($compose_content_to_save)) {
                $safe_host_name = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $host_name);
                $destination_dir_in_repo = "{$repo_path}/{$safe_host_name}/{$stack_name}";
                $destination_file_in_repo = "{$destination_dir_in_repo}/{$compose_filename}";

                if (!is_dir($destination_dir_in_repo) && !@mkdir($destination_dir_in_repo, 0755, true) && !is_dir($destination_dir_in_repo)) {
                    throw new \RuntimeException(sprintf('Directory "%s" could not be created. Please check server permissions.', $destination_dir_in_repo));
                }

                file_put_contents($destination_file_in_repo, $compose_content_to_save);
                $synced_count++;
            }
        } catch (Exception $e) {
            error_log("Sync to Git: Failed to process stack '{$stack['stack_name']}'. Error: " . $e->getMessage());
        }
    }

    // 6. Commit and push all changes
    $commit_message = "Sync all application stacks from Config Manager by " . ($_SESSION['username'] ?? 'system');
    $git->commitAndPush($repo_path, $commit_message);

    $log_details = "Synced {$synced_count} application stack compose files to the Git repository.";
    log_activity($_SESSION['username'], 'Stacks Synced to Git', $log_details);

    echo json_encode(['status' => 'success', 'message' => "Successfully synced {$synced_count} stack configuration(s) to the Git repository."]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => "Failed to sync stacks to Git: " . $e->getMessage()]);
} finally {
    // 7. Clean up the repository directory only if it's a temporary one.
    // If a persistent path is configured, we don't want to delete it.
    if (isset($git) && isset($repo_path) && !$git->isPersistentPath($repo_path)) {
        $git->cleanup($repo_path);
    }
    if (isset($conn)) {
        $conn->close();
    }
}