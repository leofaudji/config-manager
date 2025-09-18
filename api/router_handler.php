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
        echo json_encode(['status' => 'error', 'message' => 'Router ID is required.']);
        exit;
    }

    $stmt = $conn->prepare("DELETE FROM routers WHERE id = ?");
    $stmt->bind_param("i", $id);

    if ($stmt->execute()) {
        log_activity($_SESSION['username'], 'Router Deleted', "Router ID #{$id} has been deleted.");
        echo json_encode(['status' => 'success', 'message' => 'Router berhasil dihapus.']);
    } else {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Gagal menghapus router: ' . $stmt->error]);
    }
    $stmt->close();
    $conn->close();
    exit;
}

$id = $_POST['id'] ?? null;
$name = trim($_POST['router_name'] ?? $_POST['name'] ?? '');
$rule = trim($_POST['router_rule'] ?? $_POST['rule'] ?? '');
$entry_points = trim($_POST['router_entry_points'] ?? $_POST['entry_points'] ?? '');
$service_name = trim($_POST['existing_service_name'] ?? $_POST['service_name'] ?? ''); // Accommodate both forms
$tls = isset($_POST['tls']) && $_POST['tls'] == '1' ? 1 : 0;
$cert_resolver = trim($_POST['cert_resolver'] ?? '');
$group_id = $_POST['group_id'] ?? getDefaultGroupId();
$is_edit = !empty($id);

// --- Validasi Input Ketat ---
if (empty($name) || empty($rule) || empty($entry_points)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Semua field wajib diisi: Nama, Rule, Entry Points, dan Service.']);
    exit;
}

if (!preg_match('/^[a-zA-Z0-9\-_]+$/', $name)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Nama router hanya boleh berisi huruf, angka, tanda hubung (-), dan garis bawah (_).']);
    exit;
}

// If TLS is not enabled, cert_resolver should be null
if (!$tls) {
    $cert_resolver = null;
} elseif (empty($cert_resolver)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Certificate Resolver wajib diisi jika TLS diaktifkan.']);
    exit;
}

// Validasi untuk nama duplikat
if ($is_edit) {
    $stmt_check = $conn->prepare("SELECT id FROM routers WHERE name = ? AND id != ?");
    $stmt_check->bind_param("si", $name, $id);
} else { // Mode Tambah
    $stmt_check = $conn->prepare("SELECT id FROM routers WHERE name = ?");
    $stmt_check->bind_param("s", $name);
}
$stmt_check->execute();
$stmt_check->store_result();

if ($stmt_check->num_rows > 0) {
    $stmt_check->close();
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => "Error: Nama router '" . htmlspecialchars($name) . "' sudah digunakan."]);
    exit;
}
$stmt_check->close();

try {
    $conn->begin_transaction();

    $service_choice = $_POST['service_choice'] ?? 'existing';
    $final_service_name = '';

    if ($service_choice === 'new') {
        $new_service_name = $_POST['new_service_name'];
        $pass_host_header = isset($_POST['pass_host_header']) && $_POST['pass_host_header'] == '1' ? 1 : 0;
        $load_balancer_method = $_POST['load_balancer_method'] ?? 'roundRobin';
        $server_input_type = $_POST['server_input_type'] ?? 'individual';
        $server_urls = [];

        if ($server_input_type === 'cidr') {
            $cidr_address = trim($_POST['cidr_address'] ?? '');
            $cidr_port = trim($_POST['cidr_port'] ?? '');
            $cidr_prefix = trim($_POST['cidr_protocol_prefix'] ?? 'http://');
            if (empty($cidr_address) || empty($cidr_port)) {
                throw new Exception("Alamat CIDR dan Port wajib diisi untuk metode rentang jaringan.");
            }
            $ips = expandCidrToIpRange($cidr_address);
            if (empty($ips)) {
                throw new Exception("Rentang CIDR tidak menghasilkan alamat IP yang valid atau rentang tersebut kosong.");
            }
            foreach ($ips as $ip) {
                $server_urls[] = $cidr_prefix . $ip . ':' . $cidr_port;
            }
        } else { // individual
            $server_urls = array_filter($_POST['server_urls'] ?? []);
        }

        if (empty($new_service_name) || empty($server_urls)) {
            throw new Exception("Nama service baru dan minimal satu server wajib diisi.");
        }

        $stmt_check_svc = $conn->prepare("SELECT id FROM services WHERE name = ?");
        $stmt_check_svc->bind_param("s", $new_service_name);
        $stmt_check_svc->execute();
        if ($stmt_check_svc->get_result()->num_rows > 0) {
            throw new Exception("Nama service '" . htmlspecialchars($new_service_name) . "' sudah digunakan.");
        }
        $stmt_check_svc->close();

        $stmt_service = $conn->prepare("INSERT INTO services (name, pass_host_header, load_balancer_method, group_id) VALUES (?, ?, ?, ?)");
        $stmt_service->bind_param("sisi", $new_service_name, $pass_host_header, $load_balancer_method, $group_id);
        if (!$stmt_service->execute()) throw new Exception("Gagal menyimpan service baru: " . $stmt_service->error);
        $new_service_id = $conn->insert_id;
        $stmt_service->close();

        $stmt_server = $conn->prepare("INSERT INTO servers (service_id, url) VALUES (?, ?)");
        foreach ($server_urls as $url) {
            if (!empty($url)) {
                $stmt_server->bind_param("is", $new_service_id, $url);
                if (!$stmt_server->execute()) throw new Exception("Gagal menyimpan server URL: " . $stmt_server->error);
            }
        }
        $stmt_server->close();
        $final_service_name = $new_service_name;
    } else { // 'existing'
        $final_service_name = $_POST['existing_service_name'];
        if (empty($final_service_name)) {
            throw new Exception("Anda harus memilih service yang sudah ada.");
        }
    }

    if ($is_edit) { // Proses Update
        $stmt = $conn->prepare("UPDATE routers SET name = ?, rule = ?, entry_points = ?, service_name = ?, tls = ?, cert_resolver = ?, group_id = ? WHERE id = ?");
        $stmt->bind_param("ssssisii", $name, $rule, $entry_points, $final_service_name, $tls, $cert_resolver, $group_id, $id);
        if (!$stmt->execute()) {
            throw new Exception('Gagal memperbarui router: ' . $stmt->error);
        }
        $stmt->close();

        // Handle middlewares
        $middlewares = $_POST['middlewares'] ?? [];
        // First, remove all existing associations for this router
        $stmt_delete_mw = $conn->prepare("DELETE FROM router_middleware WHERE router_id = ?");
        $stmt_delete_mw->bind_param("i", $id);
        $stmt_delete_mw->execute();
        $stmt_delete_mw->close();

        // Then, add the new associations with priority
        if (!empty($middlewares)) {
            $stmt_add_mw = $conn->prepare("INSERT INTO router_middleware (router_id, middleware_id, priority) VALUES (?, ?, ?)");
            // The priority is now the array index + 1
            foreach ($middlewares as $index => $middleware_id) {
                $priority = $index + 1;
                $stmt_add_mw->bind_param("iii", $id, $middleware_id, $priority);
                $stmt_add_mw->execute();
            }
            $stmt_add_mw->close();
        }

        $log_action = 'Router Edited';
        $log_details = "Router '{$name}' (ID: {$id}) telah diubah dan terhubung ke service '{$final_service_name}'.";
        $message = "Router berhasil diperbarui.";
    } else { // Proses Tambah
        $stmt = $conn->prepare("INSERT INTO routers (name, rule, entry_points, service_name, tls, cert_resolver, group_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssisi", $name, $rule, $entry_points, $final_service_name, $tls, $cert_resolver, $group_id);
        if (!$stmt->execute()) {
            throw new Exception('Gagal menyimpan router: ' . $stmt->error);
        }
        $new_router_id = $stmt->insert_id;
        $stmt->close();

        // Handle middlewares
        $middlewares = $_POST['middlewares'] ?? [];
        if (!empty($middlewares)) {
            $stmt_add_mw = $conn->prepare("INSERT INTO router_middleware (router_id, middleware_id, priority) VALUES (?, ?, ?)");
            // The priority is now the array index + 1
            foreach ($middlewares as $index => $middleware_id) {
                $priority = $index + 1;
                $stmt_add_mw->bind_param("iii", $new_router_id, $middleware_id, $priority);
                $stmt_add_mw->execute();
            }
            $stmt_add_mw->close();
        }

        $log_action = 'Router Added';
        $log_details = "Router baru '{$name}' telah dibuat dan terhubung ke service '{$final_service_name}'.";
        $message = "Router berhasil ditambahkan.";
    }
    
    $conn->commit();
    log_activity($_SESSION['username'], $log_action, $log_details);
    echo json_encode(['status' => 'success', 'message' => $message]);

} catch (Exception $e) {
    $conn->rollback();
    // Use 400 for user-facing errors, 500 for internal ones
    $is_db_error = strpos(strtolower($e->getMessage()), 'gagal') !== false;
    http_response_code($is_db_error ? 500 : 400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();