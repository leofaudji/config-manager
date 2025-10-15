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
// --- NEW: Handle registry URL from dropdown or custom input ---
$registry_url_from_select = $_POST['registry_url'] ?? '';
$registry_url = ($registry_url_from_select === 'other') 
    ? trim($_POST['registry_url_other'] ?? '') 
    : trim($registry_url_from_select);

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

    $folder_creation_warning = '';
    if ($stmt->execute()) {
            
        if (!$is_edit) {
            $base_compose_path = get_setting('default_compose_path');
            if (!empty($base_compose_path)) {
                // Sanitize host name to be safe for directory creation
                $safe_host_name = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $name);
                if (!empty($safe_host_name)) {
                    $host_dir = rtrim($base_compose_path, '/') . '/' . $safe_host_name;
                    if (!is_dir($host_dir)) { 
                        // Attempt to create the directory. Suppress errors as we will handle them.
                        if (!@mkdir($host_dir, 0755, true) && !is_dir($host_dir)) {
                            // If mkdir fails, and the directory still doesn't exist, create a warning.
                            $folder_creation_warning = " Warning: Failed to create host directory at '{$host_dir}'. Please check permissions of the parent directory.";
                            error_log("Config Manager: Failed to create host directory at {$host_dir}. Please check permissions of the parent directory '{$base_compose_path}'.");
                        } else {
                            // If mkdir succeeded, now set permissions and ownership.
                            @chmod($host_dir, 0777); // Apply a+rwx as requested
                            @chown($host_dir, 'www-data');
                            @chgrp($host_dir, 'www-data');
                        }
                    }
                }
            }
        }

        log_activity($_SESSION['username'], $log_action, $log_details);
        echo json_encode(['status' => 'success', 'message' => $success_message . $folder_creation_warning]);
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