<?php
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

$script_name = $_GET['script'] ?? '';

if (empty($script_name) || !in_array($script_name, ['collect_stats', 'autoscaler'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid script name provided.']);
    exit;
}

try {
    $log_path = get_setting('cron_log_path', '/var/log');
    $log_file_path = rtrim($log_path, '/') . "/{$script_name}.log";

    if (!file_exists($log_file_path) || !is_readable($log_file_path)) {
        echo json_encode(['status' => 'success', 'log_content' => "Log file not found or is not readable at the configured path:\n{$log_file_path}"]);
        exit;
    }

    // Use `tail` command for efficiency, especially with large log files.
    // This is more robust than reading the whole file into PHP memory.
    $lines_to_show = 200;
    $command = "tail -n " . $lines_to_show . " " . escapeshellarg($log_file_path) . " 2>&1";
    $log_content = shell_exec($command);

    // Check if the file exists but is empty
    if (empty(trim($log_content))) {
        $log_content = "The log file is currently empty.\n\nThis could mean:\n1. The cron job has not run yet.\n2. The script ran successfully but produced no output.\n3. The output is being redirected elsewhere.";
    }

    echo json_encode(['status' => 'success', 'log_content' => $log_content]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>