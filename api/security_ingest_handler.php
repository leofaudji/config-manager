<?php
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');

// --- Security Check ---
// We reuse the health agent token for simplicity.
$api_key_header = $_SERVER['HTTP_X_API_KEY'] ?? '';
$expected_api_key = get_setting('health_agent_api_token');

if (empty($expected_api_key) || empty($api_key_header) || !hash_equals($expected_api_key, $api_key_header)) {
    http_response_code(401);
    log_activity('falco_ingest', 'Ingest Failed', 'A Falco event was rejected due to an invalid token. IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'));
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized: Invalid API Key.']);
    exit;
}

$request_body = file_get_contents('php://input');
$data = json_decode($request_body, true);

if (json_last_error() !== JSON_ERROR_NONE || !isset($data['output'], $data['rule'], $data['priority'], $data['time'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON payload. Required fields: output, rule, priority, time.']);
    exit;
}

$conn = Database::getInstance()->getConnection();

try {
    $host_id = null;
    // Falcosidekick can add headers. We'll use one to identify the host.
    $host_name_header = $_SERVER['HTTP_X_FALCO_HOSTNAME'] ?? null;
    if ($host_name_header) {
        $stmt_host = $conn->prepare("SELECT id FROM docker_hosts WHERE name = ?");
        $stmt_host->bind_param("s", $host_name_header);
        $stmt_host->execute();
        $host_id = $stmt_host->get_result()->fetch_assoc()['id'] ?? null;
        $stmt_host->close();
    }

    $stmt = $conn->prepare("
        INSERT INTO security_events (host_id, priority, rule, output, source, container_id, container_name, image_name, event_time, raw_event)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $priority = $data['priority'] ?? 'Unknown';
    $rule = $data['rule'] ?? 'Unknown';
    $output = $data['output'] ?? 'No output.';
    $source = $data['source'] ?? 'syscall';
    $container_id = $data['output_fields']['container.id'] ?? null;
    $container_name = $data['output_fields']['container.name'] ?? null;
    $image_name = $data['output_fields']['container.image.repository'] ?? null;
    $event_time = date('Y-m-d H:i:s', strtotime($data['time']));
    $raw_event = $request_body;

    $stmt->bind_param("isssssssss", $host_id, $priority, $rule, $output, $source, $container_id, $container_name, $image_name, $event_time, $raw_event);
    $stmt->execute();
    $stmt->close();

    // --- Send Notification for High Priority Events ---
    $high_priority_levels = ['Emergency', 'Alert', 'Critical', 'Error'];
    if (in_array($priority, $high_priority_levels)) {
        send_notification(
            "Falco Alert ({$priority}): {$rule}",
            $output,
            'error', // Use 'error' level for high priority security alerts
            ['falco_rule' => $rule, 'host_id' => $host_id, 'container_name' => $container_name]
        );
    }

    echo json_encode(['status' => 'success', 'message' => 'Falco event ingested.']);

} catch (Exception $e) {
    http_response_code(500);
    log_activity('falco_ingest', 'Ingest Error', 'Server error during Falco event ingestion: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Server error: ' . $e->getMessage()]);
}

$conn->close();
?>