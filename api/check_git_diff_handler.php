<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/GitHelper.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
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
        // Not an error, just nothing to do.
        echo json_encode(['status' => 'success', 'changes_count' => 0, 'diff' => '']);
        exit;
    }

    // 2. Check if the base compose path is configured
    $base_compose_path = get_setting('default_compose_path');
    if (empty($base_compose_path) || !is_dir($base_compose_path)) {
        throw new Exception("Default Standalone Compose Path is not configured or does not exist on the server.");
    }

    // 3. Setup the Git repository (clone or pull)
    $repo_path = $git->setupRepository();

    // 4. Get all managed application stacks from the database
    $stacks_result = $conn->query("SELECT s.stack_name, s.compose_file_path, h.name as host_name FROM application_stacks s JOIN docker_hosts h ON s.host_id = h.id");

    // 5. Loop through stacks and copy their compose files to the repo
    while ($stack = $stacks_result->fetch_assoc()) {
        $host_name = $stack['host_name'];
        $stack_name = $stack['stack_name'];
        $compose_filename = $stack['compose_file_path'];
        
        // Sanitize host name for filesystem safety, consistent with other handlers
        $safe_host_name = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $host_name);
        $source_compose_file = rtrim($base_compose_path, '/') . "/{$safe_host_name}/{$stack_name}/{$compose_filename}";

        if (file_exists($source_compose_file)) {
            // Also group by host in the git repo for clarity
            $destination_dir_in_repo = "{$repo_path}/{$safe_host_name}/{$stack_name}";
            $destination_file_in_repo = "{$destination_dir_in_repo}/{$compose_filename}";
            
            if (!is_dir($destination_dir_in_repo)) {
                @mkdir($destination_dir_in_repo, 0755, true);
            }
            copy($source_compose_file, $destination_file_in_repo);
        }
    }

    // 6. Add all changes to the staging area to correctly calculate diff for new/deleted files
    $git->execute("add -A", $repo_path);

    // 7. Get the status and diff from the staging area
    $status_output = $git->getStatus($repo_path);
    $diff_output = $git->getDiff($repo_path, true); // Pass true for staged diff

    $changes = array_filter(explode("\n", $status_output));
    $changes_count = count($changes);

    echo json_encode([
        'status' => 'success',
        'changes_count' => $changes_count,
        'diff' => $diff_output
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => "Failed to check for Git changes: " . $e->getMessage()]);
} finally {
    if (isset($git) && isset($repo_path) && !$git->isPersistentPath($repo_path)) {
        $git->cleanup($repo_path);
    }
    if (isset($conn)) {
        $conn->close();
    }
}