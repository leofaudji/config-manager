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
        echo json_encode(['status' => 'error', 'message' => 'Traefik Host ID is required.']);
        exit;
    }

    if ($id == 1) { // Prevent deleting the default 'Global' host
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Cannot delete the default "Global" host.']);
        exit;
    }

    $conn->begin_transaction();
    try {
        // Check if host is in use by groups
        $stmt_check = $conn->prepare("SELECT COUNT(*) as count FROM `groups` WHERE traefik_host_id = ?");
        $stmt_check->bind_param("i", $id);
        $stmt_check->execute();
        $usage_count = $stmt_check->get_result()->fetch_assoc()['count'];
        $stmt_check->close();

        if ($usage_count > 0) {
            throw new Exception("Host cannot be deleted because it is still assigned to {$usage_count} group(s). Please reassign them first.");
        }

        $stmt_delete = $conn->prepare("DELETE FROM `traefik_hosts` WHERE id = ?");
        $stmt_delete->bind_param("i", $id);
        if (!$stmt_delete->execute()) {
            throw new Exception("Failed to delete Traefik host: " . $stmt_delete->error);
        }
        $stmt_delete->close();

        $conn->commit();
        log_activity($_SESSION['username'], 'Traefik Host Deleted', "Traefik Host ID #{$id} has been deleted.");
        echo json_encode(['status' => 'success', 'message' => 'Traefik host successfully deleted.']);
    } catch (Exception $e) {
        $conn->rollback();
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    $conn->close();
    exit;
}

// --- Add/Edit Logic ---
$id = $_POST['id'] ?? null;
$name = trim($_POST['name'] ?? '');
$description = trim($_POST['description'] ?? '');
$is_edit = !empty($id);

if (empty($name)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Host name is required.']);
    exit;
}

try {
    $folder_creation_warning = ''; // Initialize warning message

    // Check for duplicate name
    if ($is_edit) {
        $stmt_check = $conn->prepare("SELECT id FROM `traefik_hosts` WHERE name = ? AND id != ?");
        $stmt_check->bind_param("si", $name, $id);
    } else {
        $stmt_check = $conn->prepare("SELECT id FROM `traefik_hosts` WHERE name = ?");
        $stmt_check->bind_param("s", $name);
    }
    $stmt_check->execute();
    if ($stmt_check->get_result()->num_rows > 0) {
        throw new Exception('A Traefik host with this name already exists.');
    }
    $stmt_check->close();

    if ($is_edit) {
        if ($id == 1 && strtolower($name) !== 'global') { // Prevent renaming the default 'Global' host
            throw new Exception('Cannot rename the default "Global" host.');
        }
        $stmt = $conn->prepare("UPDATE `traefik_hosts` SET name = ?, description = ? WHERE id = ?");
        $stmt->bind_param("ssi", $name, $description, $id);
        $log_action = 'Traefik Host Edited';
        $log_details = "Traefik Host '{$name}' (ID: {$id}) has been updated.";
        $success_message = 'Traefik host successfully updated.';
    } else {
        $stmt = $conn->prepare("INSERT INTO `traefik_hosts` (name, description) VALUES (?, ?)");
        $stmt->bind_param("ss", $name, $description);
        $log_action = 'Traefik Host Added';
        $log_details = "New Traefik host '{$name}' has been created.";
        $success_message = 'Traefik host successfully created.';
    }

    if ($stmt->execute()) {
        if (!$is_edit) {
            $base_output_path = get_setting('yaml_output_path');
            if (!empty($base_output_path)) {
                if (!is_dir($base_output_path) || !is_writable($base_output_path)) {
                    $folder_creation_warning = " Warning: The base path '{$base_output_path}' does not exist or is not writable. Please create it or fix permissions.";
                } else {
                    // Base path is OK, proceed to create the host-specific directory
                    $host_dir_name = strtolower(preg_replace('/[^a-zA-Z0-9_.-]/', '_', $name));
                    if (!empty($host_dir_name)) {
                        $final_output_dir = rtrim($base_output_path, '/') . '/' . $host_dir_name;
                        if (!is_dir($final_output_dir)) {
                            if (!@mkdir($final_output_dir, 0777, true)) {
                                $folder_creation_warning = " Warning: Failed to create directory at '{$final_output_dir}'. Please check permissions.";
                            } else {
                                // If mkdir succeeded, now attempt to set ownership.
                                @chown($final_output_dir, 'www-data');
                                @chgrp($final_output_dir, 'www-data');
                            }
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