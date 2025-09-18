<?php
require_once __DIR__ . '/../includes/bootstrap.php';
// Sesi dan otentikasi/otorisasi sudah ditangani oleh Router.

header('Content-Type: application/json');
$conn = Database::getInstance()->getConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Metode permintaan tidak diizinkan.']);
    exit;
}

// Check if it's a delete request
if (str_ends_with(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/delete')) {
    $id = $_POST['id'] ?? null;
    if (empty($id)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Server ID is required.']);
        exit;
    }

    $conn->begin_transaction();
    try {
        // 1. Dapatkan info server yang akan dihapus untuk logging dan validasi
        $stmt_get_info = $conn->prepare("SELECT s.url, s.service_id, sv.name as service_name FROM servers s JOIN services sv ON s.service_id = sv.id WHERE s.id = ?");
        $stmt_get_info->bind_param("i", $id);
        $stmt_get_info->execute();
        $result = $stmt_get_info->get_result();
        if ($result->num_rows === 0) {
            throw new Exception("Server tidak ditemukan.");
        }
        $server_info = $result->fetch_assoc();
        $stmt_get_info->close();

        // 2. Hitung jumlah server yang tersisa untuk service tersebut
        $stmt_count = $conn->prepare("SELECT COUNT(*) as server_count FROM servers WHERE service_id = ?");
        $stmt_count->bind_param("i", $server_info['service_id']);
        $stmt_count->execute();
        $server_count = $stmt_count->get_result()->fetch_assoc()['server_count'];
        $stmt_count->close();

        // 3. Jika ini adalah server terakhir, jangan izinkan penghapusan
        if ($server_count <= 1) {
            throw new Exception("Tidak dapat menghapus server terakhir. Sebuah service harus memiliki minimal satu server.");
        }

        // 4. Jika aman, lanjutkan penghapusan
        $stmt_delete = $conn->prepare("DELETE FROM servers WHERE id = ?");
        $stmt_delete->bind_param("i", $id);
        if (!$stmt_delete->execute()) {
            throw new Exception("Gagal menghapus server: " . $stmt_delete->error);
        }
        $stmt_delete->close();

        $conn->commit();
        log_activity($_SESSION['username'], 'Server Deleted', "Server '{$server_info['url']}' from service '{$server_info['service_name']}' has been deleted.");
        echo json_encode(['status' => 'success', 'message' => 'Server berhasil dihapus.']);
    } catch (Exception $e) {
        $conn->rollback();
        http_response_code(400); // Bad Request
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    $conn->close();
    exit;
}

$id = $_POST['id'] ?? null;
$service_id = $_POST['service_id'] ?? '';
$url = trim($_POST['url'] ?? '');
$is_edit = !empty($id);

if (empty($service_id) || empty($url)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Service ID dan URL wajib diisi.']);
    exit;
}

if ($is_edit) {
    $stmt = $conn->prepare("UPDATE servers SET url = ? WHERE id = ?");
    $stmt->bind_param("si", $url, $id);
    $message = "Server berhasil diperbarui.";
    $log_action = 'Server Edited';
} else {
    $stmt = $conn->prepare("INSERT INTO servers (service_id, url) VALUES (?, ?)");
    $stmt->bind_param("is", $service_id, $url);
    $message = "Server berhasil ditambahkan.";
    $log_action = 'Server Added';
}

if ($stmt->execute()) {
    log_activity($_SESSION['username'], $log_action, "Server '{$url}' for service ID {$service_id}.");
    echo json_encode(['status' => 'success', 'message' => $message]);
} else {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Gagal menyimpan server: ' . $stmt->error]);
}

$stmt->close();
$conn->close();