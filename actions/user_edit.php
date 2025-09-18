<?php
require_once '../includes/db.php';
require_once '../includes/session_check.php';

// Admin check
if ($_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Access Denied.']);
    exit;
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? '';
    $username = $_POST['username'] ?? '';
    $role = $_POST['role'] ?? '';

    if (empty($id) || empty($username) || empty($role)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'All fields are required.']);
        exit;
    }

    if (!in_array($role, ['admin', 'viewer'])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid role specified.']);
        exit;
    }

    // Check if username already exists for another user
    $stmt_check = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
    $stmt_check->bind_param("si", $username, $id);
    $stmt_check->execute();
    if ($stmt_check->get_result()->num_rows > 0) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Username already exists.']);
        exit;
    }
    $stmt_check->close();

    // Update user
    $stmt = $conn->prepare("UPDATE users SET username = ?, role = ? WHERE id = ?");
    $stmt->bind_param("ssi", $username, $role, $id);

    if ($stmt->execute()) {
        // If the admin edits their own username, update the session
        if ($_SESSION['user_id'] == $id) { // Note: We need to add user_id to session
            $_SESSION['username'] = $username;
            $_SESSION['role'] = $role;
        }
        echo json_encode(['status' => 'success', 'message' => 'User updated successfully.']);
    } else {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Failed to update user: ' . $stmt->error]);
    }
    $stmt->close();
    $conn->close();
} else {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
}
?>