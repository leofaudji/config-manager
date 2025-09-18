<?php
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

$conn = Database::getInstance()->getConnection();

try {
    $new_token = bin2hex(random_bytes(32));

    $stmt = $conn->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'webhook_secret_token'");
    $stmt->bind_param("s", $new_token);
    if (!$stmt->execute()) {
        throw new Exception("Failed to update webhook token in database.");
    }
    $stmt->close();

    log_activity($_SESSION['username'], 'Webhook Token Regenerated', 'A new webhook secret token was generated.');

    echo json_encode(['status' => 'success', 'new_token' => $new_token]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();
?>