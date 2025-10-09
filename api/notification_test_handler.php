<?php
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method.');
    }

    $is_enabled = (int)get_setting('notification_enabled', 0);
    $url = get_setting('notification_server_url');

    if (!$is_enabled) {
        throw new Exception('Notifications are not enabled in the settings.');
    }

    if (empty($url)) {
        throw new Exception('Notification Server URL is not configured.');
    }

    send_notification(
        'Test Notification from Config Manager',
        'This is a test message to verify your notification server integration is working correctly.',
        'info',
        ['test_event' => true, 'source' => 'manual_test']
    );

    echo json_encode(['status' => 'success', 'message' => 'Test notification sent. Please check your notification server to confirm receipt.']);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}