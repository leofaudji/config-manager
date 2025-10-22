<?php
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
    exit;
}

$log_base_path = get_setting('default_compose_path');
if (empty($log_base_path)) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Default Compose File Path is not configured in settings.']);
    exit;
}

try {
    // Handle request for a specific log file content
    if (isset($_GET['file'])) {
        $relative_path = $_GET['file'];
        // Security check: Prevent directory traversal attacks
        if (str_contains($relative_path, '..')) {
            throw new Exception("Invalid file path specified (directory traversal detected).");
        }

        $full_path = rtrim($log_base_path, '/') . '/' . $relative_path;

        // Normalize paths for comparison to prevent bypasses
        $normalized_base = realpath($log_base_path);
        $normalized_full_path = realpath(dirname($full_path)); // Check directory existence

        if ($normalized_base === false || $normalized_full_path === false || strpos($normalized_full_path, $normalized_base) !== 0) {
            throw new Exception("Invalid file path specified (outside of allowed directory).");
        }

        if (!file_exists($full_path) || !is_readable($full_path)) {
            throw new Exception("Log file not found or is not readable.");
        }
        $content = file_get_contents($full_path);
        echo json_encode(['status' => 'success', 'content' => $content]);
        exit;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>