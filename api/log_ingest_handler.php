<?php
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');

// --- Security Check: Verify API Key ---
$provided_api_key = $_SERVER['HTTP_X_API_KEY'] ?? '';
$stored_api_key = get_setting('health_agent_api_token');

if (empty($provided_api_key) || empty($stored_api_key) || !hash_equals($stored_api_key, $provided_api_key)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized: Invalid or missing API key.']);
    exit;
}

$json_data = file_get_contents('php://input');
$data = json_decode($json_data, true);

if (json_last_error() !== JSON_ERROR_NONE || !isset($data['logs']) || !is_array($data['logs'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON payload.']);
    exit;
}

try {
    $source = $data['source'] ?? 'unknown-agent';
    $host_id = isset($data['host_id']) ? (int)$data['host_id'] : null; // Get host_id from payload
    $logs_json = json_encode($data['logs'], JSON_PRETTY_PRINT);

    // Pass the host_id to the log_activity function
    log_activity($source, 'Agent Log Batch', $logs_json, $host_id);

    echo json_encode(['status' => 'success', 'message' => 'Logs received.']);

} catch (Exception $e) {
    http_response_code(500);
    log_activity('SYSTEM', 'Log Ingest Error', 'Failed to process log batch: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Internal server error while processing logs.']);
}
?>