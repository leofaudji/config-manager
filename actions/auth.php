<?php
// Router sudah memuat bootstrap.php dan memulai sesi.
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn = Database::getInstance()->getConnection();
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Username and password are required.']);
        exit();
    }

    $stmt = $conn->prepare("SELECT id, password, role FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        if (password_verify($password, $user['password'])) {
            // Password is correct, start a new session
            session_regenerate_id();
            log_activity($username, 'User Login', 'Login successful.');
            $_SESSION['loggedin'] = true;
            $_SESSION['username'] = $username;
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role'] = $user['role'];
            echo json_encode(['status' => 'success', 'redirect' => base_url('/')]);
            exit();
        }
    }

    // If we reach here, something went wrong
    log_activity($username, 'Failed Login Attempt', 'Invalid username or password provided.');
    http_response_code(401); // Unauthorized
    echo json_encode(['status' => 'error', 'message' => 'Invalid username or password.']);
    $stmt->close();
    $conn->close();
} else {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit();
}