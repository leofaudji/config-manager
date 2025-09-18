<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/GitHelper.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

$repo_path = null;
$git = new GitHelper();

try {
    $git_url = trim($_POST['git_url'] ?? '');
    $git_branch = trim($_POST['git_branch'] ?? 'main');
    $compose_path = trim($_POST['compose_path'] ?? '');

    if (empty($git_url) || empty($compose_path)) {
        throw new InvalidArgumentException("Git URL and Compose Path are required.");
    }

    // Validate Git URL format
    $is_ssh = str_starts_with($git_url, 'git@');
    $is_https = str_starts_with($git_url, 'https://');
    if (!$is_ssh && !$is_https) {
        throw new InvalidArgumentException("Invalid Git URL format. Please use SSH (git@...) or HTTPS (https://...) format.");
    }

    // Clone the repo
    $repo_path = $git->cloneOrPull($git_url, $git_branch);

    // Check if the file exists
    $full_path = $repo_path . '/' . $compose_path;
    if (file_exists($full_path)) {
        echo json_encode(['status' => 'success', 'message' => "Compose file '{$compose_path}' found successfully in the repository."]);
    } else {
        throw new Exception("Compose file '{$compose_path}' not found in the repository.");
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
} finally {
    // Always clean up the cloned repo
    if (isset($git) && isset($repo_path)) {
        $git->cleanup($repo_path);
    }
}
?>