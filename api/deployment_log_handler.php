<?php
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
    exit;
}

$log_id = $_GET['id'] ?? null;
if (!$log_id) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Log ID is required.']);
    exit;
}

$conn = Database::getInstance()->getConnection();

try {
    // Find the log file path from the database
    $stmt = $conn->prepare("SELECT log_file_path FROM activity_log WHERE id = ?");
    $stmt->bind_param("i", $log_id);
    $stmt->execute();
    $log_file_path = $stmt->get_result()->fetch_assoc()['log_file_path'] ?? null;
    $stmt->close();

    if (!$log_file_path || !file_exists($log_file_path) || !is_readable($log_file_path)) {
        throw new Exception("Log file not found, not readable, or the process has not started yet.");
    }

    $log_content = file_get_contents($log_file_path);
    echo json_encode(['status' => 'success', 'content' => $log_content]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();
?>