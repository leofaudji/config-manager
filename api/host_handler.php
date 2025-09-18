<?php
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');
$conn = Database::getInstance()->getConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

// --- Delete Logic ---
if (str_ends_with(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/delete')) {
    $id = $_POST['id'] ?? null;
    if (empty($id)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Host ID is required.']);
        exit;
    }

    $stmt_delete = $conn->prepare("DELETE FROM docker_hosts WHERE id = ?");
    $stmt_delete->bind_param("i", $id);
    if ($stmt_delete->execute()) {
        log_activity($_SESSION['username'], 'Host Deleted', "Host ID #{$id} has been deleted.");
        echo json_encode(['status' => 'success', 'message' => 'Host successfully deleted.']);
    } else {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Failed to delete host.']);
    }
    $stmt_delete->close();
    $conn->close();
    exit;
}

// --- Add/Edit Logic ---
$id = $_POST['id'] ?? null;
$name = trim($_POST['name'] ?? '');
$docker_api_url = trim($_POST['docker_api_url'] ?? '');
$description = trim($_POST['description'] ?? '');
$tls_enabled = isset($_POST['tls_enabled']) && $_POST['tls_enabled'] == '1' ? 1 : 0;
$ca_cert_path = trim($_POST['ca_cert_path'] ?? '');
$client_cert_path = trim($_POST['client_cert_path'] ?? '');
$client_key_path = trim($_POST['client_key_path'] ?? '');
$default_volume_path = trim($_POST['default_volume_path'] ?? '/opt/stacks');
$registry_url = trim($_POST['registry_url'] ?? '');
$registry_username = trim($_POST['registry_username'] ?? '');
$registry_password = $_POST['registry_password'] ?? ''; // Don't trim password
$is_edit = !empty($id);

if (empty($name) || empty($docker_api_url)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Host name and Docker API URL are required.']);
    exit;
}

if ($tls_enabled && (empty($ca_cert_path) || empty($client_cert_path) || empty($client_key_path))) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'When TLS is enabled, all certificate paths are required.']);
    exit;
}

try {
    // Check for duplicate name or URL
    if ($is_edit) {
        $stmt_check = $conn->prepare("SELECT id FROM docker_hosts WHERE (name = ? OR docker_api_url = ?) AND id != ?");
        $stmt_check->bind_param("ssi", $name, $docker_api_url, $id);
    } else {
        $stmt_check = $conn->prepare("SELECT id FROM docker_hosts WHERE name = ? OR docker_api_url = ?");
        $stmt_check->bind_param("ss", $name, $docker_api_url);
    }
    $stmt_check->execute();
    if ($stmt_check->get_result()->num_rows > 0) {
        throw new Exception('A host with this name or API URL already exists.');
    }
    $stmt_check->close();

    if ($is_edit) {
        $stmt = $conn->prepare("UPDATE docker_hosts SET name = ?, docker_api_url = ?, description = ?, tls_enabled = ?, ca_cert_path = ?, client_cert_path = ?, client_key_path = ?, default_volume_path = ?, registry_url = ?, registry_username = ?, registry_password = ? WHERE id = ?");
        $stmt->bind_param("sssisssssssi", $name, $docker_api_url, $description, $tls_enabled, $ca_cert_path, $client_cert_path, $client_key_path, $default_volume_path, $registry_url, $registry_username, $registry_password, $id);
        $log_action = 'Host Edited';
        $log_details = "Host '{$name}' (ID: {$id}) has been updated.";
        $success_message = 'Host successfully updated.';
    } else {
        $stmt = $conn->prepare("INSERT INTO docker_hosts (name, docker_api_url, description, tls_enabled, ca_cert_path, client_cert_path, client_key_path, default_volume_path, registry_url, registry_username, registry_password) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssisssssss", $name, $docker_api_url, $description, $tls_enabled, $ca_cert_path, $client_cert_path, $client_key_path, $default_volume_path, $registry_url, $registry_username, $registry_password);
        $log_action = 'Host Added';
        $log_details = "New host '{$name}' has been created.";
        $success_message = 'Host successfully created.';
    }

    if ($stmt->execute()) {
        log_activity($_SESSION['username'], $log_action, $log_details);
        echo json_encode(['status' => 'success', 'message' => $success_message]);
    } else {
        throw new Exception('Database operation failed: ' . $stmt->error);
    }
    $stmt->close();

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();
?>