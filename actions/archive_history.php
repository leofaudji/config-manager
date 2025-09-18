<?php
require_once __DIR__ . '/../includes/bootstrap.php';
// Sesi dan otentikasi/otorisasi sudah ditangani oleh Router.

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['id']) || !isset($_POST['status'])) {
    $conn = Database::getInstance()->getConnection();
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid request.']);
    exit();
}

$id = (int)$_POST['id'];
$new_status_code = (int)$_POST['status']; // 0 for draft, 1 for archived

// Determine the new status string
$new_status = ($new_status_code === 1) ? 'archived' : 'draft';

$stmt = $conn->prepare("UPDATE config_history SET status = ? WHERE id = ? AND status != 'active'");
$stmt->bind_param("si", $new_status, $id);

if ($stmt->execute()) {
    $action_text = ($new_status === 'archived') ? 'archived' : 'unarchived';
    log_activity($_SESSION['username'], 'History Status Changed', "History record #{$id} has been {$action_text}.");
    echo json_encode(['status' => 'success', 'message' => "History record #{$id} has been {$action_text}."]);
} else {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to update history record.']);
}

$stmt->close();
$conn->close();
?>