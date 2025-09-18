<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/GitHelper.php';

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
    $stacks_result = $conn->query("SELECT stack_name, compose_file_path FROM application_stacks");
    if ($stacks_result->num_rows === 0) {
        echo json_encode(['status' => 'success', 'message' => 'No application stacks found to sync.']);
        $git->cleanup($repo_path);
        exit;
    }

    $synced_count = 0;
    // 5. Loop through stacks and copy their compose files to the repo
    while ($stack = $stacks_result->fetch_assoc()) {
        $stack_name = $stack['stack_name'];
        $compose_filename = $stack['compose_file_path'];

        $source_compose_file = rtrim($base_compose_path, '/') . "/{$stack_name}/{$compose_filename}";

        if (file_exists($source_compose_file)) {
            $destination_dir_in_repo = "{$repo_path}/{$stack_name}";
            $destination_file_in_repo = "{$destination_dir_in_repo}/{$compose_filename}";
            //print($destination_file_in_repo) ;
            // Explicitly check if a file with the same name as the directory exists
            if (file_exists($destination_dir_in_repo) && !is_dir($destination_dir_in_repo)) {
                throw new \RuntimeException(sprintf('Cannot create directory for stack "%s" because a file with that name already exists in the repository.', $stack_name));
            }

            // Atomically check and create directory, suppressing warnings for race conditions.
            if (!is_dir($destination_dir_in_repo) && !@mkdir($destination_dir_in_repo, 0755, true) && !is_dir($destination_dir_in_repo)) {
                throw new \RuntimeException(sprintf('Directory "%s" could not be created. Please check server permissions.', $destination_dir_in_repo));
            }

            copy($source_compose_file, $destination_file_in_repo);
            $synced_count++;
        } else {
            error_log("Sync to Git: Could not find compose file for stack '{$stack_name}' at '{$source_compose_file}'. Skipping.");
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