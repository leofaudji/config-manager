<?php
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden.']);
    exit;
}

try {
    $cache_dir = PROJECT_ROOT . '/cache';
    $cleared_count = 0;
    $error_count = 0;

    if (is_dir($cache_dir)) {
        $files = glob($cache_dir . '/*.json');
        foreach ($files as $file) {
            if (is_file($file)) {
                if (@unlink($file)) {
                    $cleared_count++;
                } else {
                    $error_count++;
                }
            }
        }
    }

    if ($error_count > 0) {
        throw new Exception("Could not clear {$error_count} cache file(s). Please check file permissions in '{$cache_dir}'.");
    }

    log_activity($_SESSION['username'], 'Cache Cleared', "Cleared {$cleared_count} search cache files.");
    echo json_encode(['status' => 'success', 'message' => "Successfully cleared {$cleared_count} search cache files."]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}