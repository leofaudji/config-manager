<?php
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');

try {
    // Authenticate the request via API Key in header
    $provided_api_key = $_SERVER['HTTP_X_API_KEY'] ?? '';
    $stored_api_key = get_setting('health_agent_api_token'); // Reuse the same token for simplicity

    if (empty($provided_api_key) || empty($stored_api_key) || !hash_equals($stored_api_key, $provided_api_key)) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized: Invalid or missing API Key.']);
        exit;
    }

    $json_payload = file_get_contents('php://input');
    $data = json_decode($json_payload, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON payload received.");
    }

    $source = $data['source'] ?? null;
    $host_id = $data['host_id'] ?? null;
    $logs = $data['logs'] ?? [];

    if (!$source || !$host_id || !is_array($logs) || empty($logs)) {
        throw new Exception("Invalid log format. 'source', 'host_id', and 'logs' array are required.");
    }

    // Get log file path from settings
    $log_dir = get_setting('cron_log_path', '/var/log');
    if (!is_dir($log_dir) || !is_writable($log_dir)) {
        // Log to system log if configured path is not writable
        error_log("Config Manager Log Ingest: Log directory '{$log_dir}' is not writable.");
        throw new Exception("Log directory is not writable on the server.");
    }

    // Construct a unique log file name, e.g., host-1-health-agent.log
    $log_file_name = "host-{$host_id}-{$source}.log";
    $log_file_path = rtrim($log_dir, '/') . '/' . $log_file_name;

    // Append logs to the file
    file_put_contents($log_file_path, implode("\n", $logs) . "\n", FILE_APPEND | LOCK_EX);

    echo json_encode(['status' => 'success', 'message' => 'Logs received.']);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}