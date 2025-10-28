<?php
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

$json_data = file_get_contents('php://input');
$data = json_decode($json_data, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON payload.']);
    exit;
}

// Relay the notification using the central function
send_notification($data['title'] ?? 'Agent Alert', $data['message'] ?? 'No message.', $data['level'] ?? 'info', $data['context'] ?? []);

echo json_encode(['status' => 'success', 'message' => 'Notification relayed.']);
?>