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
        echo json_encode(['status' => 'error', 'message' => 'Service ID is required.']);
        exit;
    }

    $conn->begin_transaction();
    try {
        // 1. Ambil nama service dari ID untuk pengecekan asosiasi
        $stmt_get_name = $conn->prepare("SELECT name FROM services WHERE id = ?");
        $stmt_get_name->bind_param("i", $id);
        $stmt_get_name->execute();
        $result = $stmt_get_name->get_result();
        if ($result->num_rows === 0) {
            throw new Exception("Service tidak ditemukan.");
        }
        $service_name = $result->fetch_assoc()['name'];
        $stmt_get_name->close();

        // 2. Cek apakah ada router yang masih menggunakan service ini
        $stmt_check = $conn->prepare("SELECT COUNT(*) as router_count FROM routers WHERE service_name = ?");
        $stmt_check->bind_param("s", $service_name);
        $stmt_check->execute();
        $router_count = $stmt_check->get_result()->fetch_assoc()['router_count'];
        $stmt_check->close();

        if ($router_count > 0) {
            throw new Exception("Service '" . htmlspecialchars($service_name) . "' tidak dapat dihapus karena masih digunakan oleh " . $router_count . " router.");
        }

        // 3. Jika tidak ada asosiasi, lanjutkan penghapusan
        $stmt_delete = $conn->prepare("DELETE FROM services WHERE id = ?");
        $stmt_delete->bind_param("i", $id);
        if (!$stmt_delete->execute()) {
            throw new Exception("Gagal menghapus service: " . $stmt_delete->error);
        }
        $stmt_delete->close();

        $conn->commit();
        log_activity($_SESSION['username'], 'Service Deleted', "Service '{$service_name}' (ID: {$id}) has been deleted.");
        echo json_encode(['status' => 'success', 'message' => 'Service berhasil dihapus.']);
    } catch (Exception $e) {
        $conn->rollback();
        http_response_code(400); // Bad Request
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    $conn->close();
    exit;
}

$id = $_POST['id'] ?? null;
$name = trim($_POST['name'] ?? '');
$pass_host_header = isset($_POST['pass_host_header']) && $_POST['pass_host_header'] == '1' ? 1 : 0;
$load_balancer_method = $_POST['load_balancer_method'] ?? 'roundRobin';
$group_id = $_POST['group_id'] ?? getDefaultGroupId();
$server_input_type = $_POST['server_input_type'] ?? 'individual';
$server_urls = [];

if ($server_input_type === 'cidr') {
    $cidr_address = trim($_POST['cidr_address'] ?? '');
    $cidr_port = trim($_POST['cidr_port'] ?? '');
    $cidr_prefix = trim($_POST['cidr_protocol_prefix'] ?? 'http://');
    if (empty($cidr_address) || empty($cidr_port)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Alamat CIDR dan Port wajib diisi untuk metode rentang jaringan.']);
        exit;
    }
    $ips = expandCidrToIpRange($cidr_address);
    if (empty($ips)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Rentang CIDR tidak menghasilkan alamat IP yang valid atau rentang tersebut kosong.']);
        exit;
    }
    foreach ($ips as $ip) {
        $server_urls[] = $cidr_prefix . $ip . ':' . $cidr_port;
    }
} else { // individual
    $server_urls = array_filter($_POST['server_urls'] ?? []);
}
$is_edit = !empty($id);

// Validasi untuk nama duplikat
if ($is_edit) {
    $stmt_check = $conn->prepare("SELECT id FROM services WHERE name = ? AND id != ?");
    $stmt_check->bind_param("si", $name, $id);
} else {
    $stmt_check = $conn->prepare("SELECT id FROM services WHERE name = ?");
    $stmt_check->bind_param("s", $name);
}
$stmt_check->execute();
$stmt_check->store_result();

if ($stmt_check->num_rows > 0) {
    $stmt_check->close();
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => "Error: Nama service '" . htmlspecialchars($name) . "' sudah digunakan."]);
    exit;
}
$stmt_check->close();

if (!$is_edit) {
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("INSERT INTO services (name, pass_host_header, load_balancer_method, group_id) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("sisi", $name, $pass_host_header, $load_balancer_method, $group_id);
        if (!$stmt->execute()) throw new Exception('Gagal menyimpan service: ' . $stmt->error);
        $new_service_id = $conn->insert_id;
        $stmt->close();

        if (!empty($server_urls)) {
            $stmt_server = $conn->prepare("INSERT INTO servers (service_id, url) VALUES (?, ?)");
            foreach ($server_urls as $url) {
                $stmt_server->bind_param("is", $new_service_id, $url);
                if (!$stmt_server->execute()) {
                    throw new Exception("Gagal menambah server baru: " . $stmt_server->error);
                }
            }
            $stmt_server->close();
        }

        $conn->commit();
        log_activity($_SESSION['username'], 'Service Added', "Service baru '{$name}' telah ditambahkan.");
        echo json_encode(['status' => 'success', 'message' => 'Service berhasil ditambahkan.']);

    } catch (Exception $e) {
        $conn->rollback();
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
} else {
    // Proses Update dengan transaksi untuk menjaga konsistensi data
    $conn->begin_transaction();
    try {
        $stmt_old_name = $conn->prepare("SELECT name FROM services WHERE id = ?");
        $stmt_old_name->bind_param("i", $id);
        $stmt_old_name->execute();
        $old_service_name = $stmt_old_name->get_result()->fetch_assoc()['name'];
        $stmt_old_name->close();

        $stmt_update_service = $conn->prepare("UPDATE services SET name = ?, pass_host_header = ?, load_balancer_method = ?, group_id = ? WHERE id = ?");
        $stmt_update_service->bind_param("sisii", $name, $pass_host_header, $load_balancer_method, $group_id, $id);
        $stmt_update_service->execute();
        $stmt_update_service->close();

        if ($old_service_name !== $name) {
            $stmt_update_routers = $conn->prepare("UPDATE routers SET service_name = ? WHERE service_name = ?");
            $stmt_update_routers->bind_param("ss", $name, $old_service_name);
            $stmt_update_routers->execute();
            $stmt_update_routers->close();
        }

        // --- Server Management (Replace All) ---
        // 1. Delete all existing servers for this service
        $stmt_delete = $conn->prepare("DELETE FROM servers WHERE service_id = ?");
        $stmt_delete->bind_param("i", $id);
        $stmt_delete->execute();
        $stmt_delete->close();

        // 2. Insert the new servers from the form
        if (!empty($server_urls)) {
            $stmt_insert = $conn->prepare("INSERT INTO servers (service_id, url) VALUES (?, ?)");
            foreach ($server_urls as $url) {
                $stmt_insert->bind_param("is", $id, $url);
                if (!$stmt_insert->execute()) throw new Exception("Gagal menyimpan server baru: " . $stmt_insert->error);
            }
            $stmt_insert->close();
        }

        $conn->commit();
        log_activity($_SESSION['username'], 'Service Edited', "Service '{$old_service_name}' (ID: {$id}) telah diubah menjadi '{$name}'.");
        echo json_encode(['status' => 'success', 'message' => 'Service dan router terkait berhasil diperbarui.']);
    } catch (Exception $e) {
        $conn->rollback();
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Gagal memperbarui service: ' . $e->getMessage()]);
    }
}
$conn->close();