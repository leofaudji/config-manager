<?php
require_once __DIR__ . '/../includes/bootstrap.php';
// Sesi dan otentikasi/otorisasi sudah ditangani oleh Router.

header('Content-Type: application/json');
$conn = Database::getInstance()->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? '';
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($id) || empty($password) || empty($confirm_password)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'All fields are required.']);
        exit;
    }

    if ($password !== $confirm_password) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Passwords do not match.']);
        exit;
    }

    // Hash the new password
    $password_hash = password_hash($password, PASSWORD_DEFAULT);

    // Update user's password
    $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
    $stmt->bind_param("si", $password_hash, $id);

    if ($stmt->execute()) {
        // Get username for logging
        $stmt_get_user = $conn->prepare("SELECT username FROM users WHERE id = ?");
        $stmt_get_user->bind_param("i", $id);
        $stmt_get_user->execute();
        $username_to_log = $stmt_get_user->get_result()->fetch_assoc()['username'] ?? 'Unknown';
        $stmt_get_user->close();

        $log_details = "Password untuk pengguna '{$username_to_log}' (ID: {$id}) telah diubah oleh admin.";
        log_activity($_SESSION['username'], 'User Password Changed', $log_details);
        echo json_encode(['status' => 'success', 'message' => 'Password pengguna berhasil diperbarui.']);
    } else {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Failed to update password: ' . $stmt->error]);
    }
    $stmt->close();
    $conn->close();
} else {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
}
?>