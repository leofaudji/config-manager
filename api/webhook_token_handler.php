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

$conn = Database::getInstance()->getConnection();

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $type = $input['type'] ?? 'webhook_token';

    $setting_key = ($type === 'agent_token') ? 'health_agent_api_token' : 'webhook_secret_token';

    $new_token = bin2hex(random_bytes(32));

    $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $stmt->bind_param("ss", $setting_key, $new_token);
    $stmt->execute();
    $stmt->close();

    log_activity($_SESSION['username'], 'Token Regenerated', "A new token was generated for: {$setting_key}.");
    echo json_encode(['status' => 'success', 'token' => $new_token]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to regenerate token: ' . $e->getMessage()]);
} finally {
    if (isset($conn)) $conn->close();
}