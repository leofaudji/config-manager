<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/GitHelper.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

try {
    $git_url = trim($_POST['git_url'] ?? '');
    $ssh_key_path = trim($_POST['ssh_key_path'] ?? null); // Get optional SSH key from POST

    if (empty($git_url)) {
        throw new InvalidArgumentException("Git URL is required.");
    }

    // Validate Git URL format
    $is_ssh = str_starts_with($git_url, 'git@');
    $is_https = str_starts_with($git_url, 'https://');
    if (!$is_ssh && !$is_https) {
        throw new InvalidArgumentException("Invalid Git URL format. Please use SSH (git@...) or HTTPS (https://...) format.");
    }

    $git = new GitHelper();
    // Pass the key path from the form to the test function
    $git->testConnection($git_url, $ssh_key_path);

    echo json_encode(['status' => 'success', 'message' => 'Successfully connected to the repository.']);

} catch (Exception $e) {
    // It's better to return a 400 Bad Request for user input errors and 500 for server errors.
    // The exception from GitHelper will be a server-side issue (e.g., permissions, network).
    $isUserInputError = $e instanceof InvalidArgumentException;
    http_response_code($isUserInputError ? 400 : 500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

?>