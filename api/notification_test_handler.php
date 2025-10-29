<?php
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

try {
    $recipient_email = $_POST['recipient_email'] ?? '';
    $customer_id = $_POST['customer_id'] ?? '';
    $server_url = $_POST['server_url'] ?? '';

    if (empty($recipient_email) || empty($customer_id) || empty($server_url)) {
        throw new Exception("Recipient Email, Customer ID, and Notification Server URL are required for a test.");
    }

    send_notification(
        "Test Notification",
        "This is a test notification from Config Manager. If you received this, your email notification settings are working!",
        'info',
        ['source' => 'test_button', 'override_recipient_emails' => $recipient_email, 'override_customer_id' => $customer_id, 'override_url' => $server_url]
    );

    echo json_encode(['status' => 'success', 'message' => 'Test email sent successfully. Check your inbox.']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to send test email: ' . $e->getMessage()]);
}