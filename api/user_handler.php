<?php
require_once __DIR__ . '/../includes/bootstrap.php';
// Sesi dan otentikasi/otorisasi sudah ditangani oleh Router.

header('Content-Type: application/json');
$conn = Database::getInstance()->getConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

// Check if it's a delete request by inspecting the URL path
if (str_ends_with(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/delete')) {
    $id = $_POST['id'] ?? null;

    if (empty($id)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'User ID is required.']);
        exit;
    }

    // Get username and role to prevent self-deletion and last admin deletion
    $stmt_get_user = $conn->prepare("SELECT username, role FROM users WHERE id = ?");
    $stmt_get_user->bind_param("i", $id);
    $stmt_get_user->execute();
    $result = $stmt_get_user->get_result();
    if ($result->num_rows === 0) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'User not found.']);
        exit;
    }
    $user_to_delete_data = $result->fetch_assoc();
    $user_to_delete = $user_to_delete_data['username'];
    $stmt_get_user->close();

    if ($_SESSION['username'] === $user_to_delete) {
        http_response_code(403); // Forbidden
        echo json_encode(['status' => 'error', 'message' => 'You cannot delete your own account.']);
        exit;
    }

    // Prevent deleting the last admin
    if ($user_to_delete_data['role'] === 'admin') {
        $stmt_admin_count = $conn->prepare("SELECT COUNT(*) as admin_count FROM users WHERE role = 'admin'");
        $stmt_admin_count->execute();
        $admin_count = $stmt_admin_count->get_result()->fetch_assoc()['admin_count'];
        $stmt_admin_count->close();
        if ($admin_count <= 1) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Cannot delete the last administrator.']);
            exit;
        }
    }

    $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
    $stmt->bind_param("i", $id);

    if ($stmt->execute()) {
        $log_details = "Pengguna '{$user_to_delete}' (ID: {$id}) telah dihapus.";
        log_activity($_SESSION['username'], 'User Deleted', $log_details);
        echo json_encode(['status' => 'success', 'message' => 'User berhasil dihapus.']);
    } else {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Failed to delete user: ' . $stmt->error]);
    }
    $stmt->close();
    $conn->close();
    exit;
}
elseif (str_ends_with(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/change-password')) {
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

    $password_hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
    $stmt->bind_param("si", $password_hash, $id);

    if ($stmt->execute()) {
        $stmt_get_user = $conn->prepare("SELECT username FROM users WHERE id = ?");
        $stmt_get_user->bind_param("i", $id);
        $stmt_get_user->execute();
        $username_to_log = $stmt_get_user->get_result()->fetch_assoc()['username'] ?? 'Unknown';
        $stmt_get_user->close();

        log_activity($_SESSION['username'], 'User Password Changed', "Password for user '{$username_to_log}' (ID: {$id}) was changed by an admin.");
        echo json_encode(['status' => 'success', 'message' => 'Password pengguna berhasil diperbarui.']);
    } else {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Failed to update password: ' . $stmt->error]);
    }
    $stmt->close();
    $conn->close();
    exit;
}

// --- Data dari request body ---
$id = $_POST['id'] ?? null;
$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? ''; // Hanya relevan untuk user baru
$role = $_POST['role'] ?? 'viewer';

$is_edit = !empty($id);

// --- Validasi Input ---
if (empty($username) || empty($role)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Username dan role wajib diisi.']);
    exit;
}

if (!$is_edit && empty($password)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Password wajib diisi untuk pengguna baru.']);
    exit;
}

if (!in_array($role, ['admin', 'viewer'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Role tidak valid.']);
    exit;
}

try {
    // --- Validasi Duplikasi Username ---
    if ($is_edit) {
        $stmt_check = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $stmt_check->bind_param("si", $username, $id);
    } else {
        $stmt_check = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $stmt_check->bind_param("s", $username);
    }
    $stmt_check->execute();
    if ($stmt_check->get_result()->num_rows > 0) {
        throw new Exception('Username sudah digunakan.');
    }
    $stmt_check->close();

    // --- Operasi Database ---
    if ($is_edit) {
        // Update pengguna yang ada
        $stmt = $conn->prepare("UPDATE users SET username = ?, role = ? WHERE id = ?");
        $stmt->bind_param("ssi", $username, $role, $id);
        $log_action = 'User Edited';
        $log_details = "Pengguna '{$username}' (ID: {$id}) telah diubah.";
        $success_message = 'Pengguna berhasil diperbarui.';
    } else {
        // Buat pengguna baru
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $username, $password_hash, $role);
        $log_action = 'User Added';
        $log_details = "Pengguna baru '{$username}' dibuat dengan role '{$role}'.";
        $success_message = 'Pengguna berhasil dibuat.';
    }

    if ($stmt->execute()) {
        if ($is_edit && $_SESSION['user_id'] == $id) {
            $_SESSION['username'] = $username;
            $_SESSION['role'] = $role;
        }
        log_activity($_SESSION['username'], $log_action, $log_details);
        echo json_encode(['status' => 'success', 'message' => $success_message]);
    } else {
        throw new Exception('Operasi database gagal: ' . $stmt->error);
    }
    $stmt->close();

} catch (Exception $e) {
    $is_db_error = strpos(strtolower($e->getMessage()), 'database') !== false;
    http_response_code($is_db_error ? 500 : 400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();