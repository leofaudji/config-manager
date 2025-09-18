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
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Username and password are required.']);
        exit;
    }

    $role = $_POST['role'] ?? 'viewer';
    if (!in_array($role, ['admin', 'viewer'])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid role specified.']);
        exit;
    }

    // Check if username already exists
    $stmt_check = $conn->prepare("SELECT id FROM users WHERE username = ?");
    $stmt_check->bind_param("s", $username);
    $stmt_check->execute();
    if ($stmt_check->get_result()->num_rows > 0) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Username already exists.']);
        exit;
    }
    $stmt_check->close();

    // Hash the password
    $password_hash = password_hash($password, PASSWORD_DEFAULT);

    // Insert new user
    $stmt = $conn->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $username, $password_hash, $role);

    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'User successfully created.']);
    } else {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Failed to create user: ' . $stmt->error]);
    }
    $stmt->close();
    $conn->close();
} else {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
}
?>