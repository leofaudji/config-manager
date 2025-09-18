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
    $cleanup_days = (int)get_setting('history_cleanup_days', 30);

    $stmt = $conn->prepare("DELETE FROM config_history WHERE status = 'archived' AND created_at < NOW() - INTERVAL ? DAY");
    $stmt->bind_param("i", $cleanup_days);
    if (!$stmt->execute()) {
        throw new Exception("Database query failed: " . $stmt->error);
    }

    $deleted_count = $stmt->affected_rows;
    $stmt->close();

    $log_details = "Cleanup process deleted {$deleted_count} archived deployment history records older than {$cleanup_days} days.";
    log_activity($_SESSION['username'], 'Deployment History Cleanup', $log_details);

    echo json_encode(['status' => 'success', 'message' => "Cleanup successful. {$deleted_count} records were deleted."]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'An error occurred during cleanup: ' . $e->getMessage()]);
}

$conn->close();
?>