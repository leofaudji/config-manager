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
    echo json_encode(['status' => 'success', 'data' => []]);
    exit;
}

try {
    // Handle request for a specific log file content
    if (isset($_GET['file'])) {
        $file_path = realpath($log_base_path . '/' . $_GET['file']);
        // Security check: ensure the requested file is within the allowed base path
        if ($file_path === false || strpos($file_path, realpath($log_base_path)) !== 0) {
            throw new Exception("Invalid file path specified.");
        }
        if (!file_exists($file_path) || !is_readable($file_path)) {
            throw new Exception("Log file not found or is not readable.");
        }
        $content = file_get_contents($file_path);
        echo json_encode(['status' => 'success', 'content' => $content]);
        exit;
    }

    // Handle request for the list of all log files
    $log_files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($log_base_path, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getFilename() === 'deployment.log') {
            $full_path = $file->getPathname();
            $relative_path = str_replace($log_base_path . '/', '', $full_path);
            $parts = explode('/', $relative_path);
            
            // Expecting path like {host_name}/{stack_name}/deployment.log
            if (count($parts) === 3) {
                $log_files[] = [
                    'host' => $parts[0],
                    'stack' => $parts[1],
                    'last_modified' => date("Y-m-d H:i:s", $file->getMTime()),
                    'file_path' => $relative_path
                ];
            }
        }
    }

    // Sort by last modified date, descending
    usort($log_files, function($a, $b) {
        return strtotime($b['last_modified']) <=> strtotime($a['last_modified']);
    });

    echo json_encode(['status' => 'success', 'data' => $log_files]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>