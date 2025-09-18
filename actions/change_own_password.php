<?php
require_once '../includes/db.php';
require_once '../includes/session_check.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $user_id = $_SESSION['user_id'];

    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'All fields are required.']);
        exit;
    }

    if ($new_password !== $confirm_password) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'New passwords do not match.']);
        exit;
    }

    // Get current password from DB
    $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    // Verify current password
    if (!password_verify($current_password, $user['password'])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Incorrect current password.']);
        exit;
    }

    // Hash the new password
    $password_hash = password_hash($new_password, PASSWORD_DEFAULT);

    // Update user's password
    $stmt_update = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
    $stmt_update->bind_param("si", $password_hash, $user_id);

    if ($stmt_update->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'Your password has been updated successfully.']);
    } else {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Failed to update password: ' . $stmt_update->error]);
    }
    $stmt_update->close();
    $conn->close();
} else {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
}
?>