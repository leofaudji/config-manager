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
        echo json_encode(['status' => 'error', 'message' => 'Group ID is required.']);
        exit;
    }

    if ($id == 1) { // Prevent deleting the default 'General' group
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Cannot delete the default "General" group.']);
        exit;
    }

    $conn->begin_transaction();
    try {
        // Check if group is in use by routers
        $stmt_check_routers = $conn->prepare("SELECT COUNT(*) as count FROM routers WHERE group_id = ?");
        $stmt_check_routers->bind_param("i", $id);
        $stmt_check_routers->execute();
        $router_count = $stmt_check_routers->get_result()->fetch_assoc()['count'];
        $stmt_check_routers->close();

        // Check if group is in use by services
        $stmt_check_services = $conn->prepare("SELECT COUNT(*) as count FROM services WHERE group_id = ?");
        $stmt_check_services->bind_param("i", $id);
        $stmt_check_services->execute();
        $service_count = $stmt_check_services->get_result()->fetch_assoc()['count'];
        $stmt_check_services->close();

        if ($router_count > 0 || $service_count > 0) {
            throw new Exception("Group cannot be deleted because it is still in use by {$router_count} router(s) and {$service_count} service(s).");
        }

        $stmt_delete = $conn->prepare("DELETE FROM `groups` WHERE id = ?");
        $stmt_delete->bind_param("i", $id);
        if (!$stmt_delete->execute()) {
            throw new Exception("Failed to delete group: " . $stmt_delete->error);
        }
        $stmt_delete->close();

        $conn->commit();
        log_activity($_SESSION['username'], 'Group Deleted', "Group ID #{$id} has been deleted.");
        echo json_encode(['status' => 'success', 'message' => 'Group successfully deleted.']);
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
    echo json_encode(['status' => 'error', 'message' => 'Group name is required.']);
    exit;
}

try {
    // Check for duplicate name
    if ($is_edit) {
        $stmt_check = $conn->prepare("SELECT id FROM `groups` WHERE name = ? AND id != ?");
        $stmt_check->bind_param("si", $name, $id);
    } else {
        $stmt_check = $conn->prepare("SELECT id FROM `groups` WHERE name = ?");
        $stmt_check->bind_param("s", $name);
    }
    $stmt_check->execute();
    if ($stmt_check->get_result()->num_rows > 0) {
        throw new Exception('Group name already exists.');
    }
    $stmt_check->close();

    if ($is_edit) {
        if ($id == 1) { // Prevent renaming the default 'General' group
            throw new Exception('Cannot rename the default "General" group.');
        }
        $stmt = $conn->prepare("UPDATE `groups` SET name = ?, description = ? WHERE id = ?");
        $stmt->bind_param("ssi", $name, $description, $id);
        $log_action = 'Group Edited';
        $log_details = "Group '{$name}' (ID: {$id}) has been updated.";
        $success_message = 'Group successfully updated.';
    } else {
        $stmt = $conn->prepare("INSERT INTO `groups` (name, description) VALUES (?, ?)");
        $stmt->bind_param("ss", $name, $description);
        $log_action = 'Group Added';
        $log_details = "New group '{$name}' has been created.";
        $success_message = 'Group successfully created.';
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