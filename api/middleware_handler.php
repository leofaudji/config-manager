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
        echo json_encode(['status' => 'error', 'message' => 'Middleware ID is required.']);
        exit;
    }

    $conn->begin_transaction();
    try {
        // Check if middleware is in use by routers
        $stmt_check = $conn->prepare("SELECT COUNT(*) as count FROM router_middleware WHERE middleware_id = ?");
        $stmt_check->bind_param("i", $id);
        $stmt_check->execute();
        $usage_count = $stmt_check->get_result()->fetch_assoc()['count'];
        $stmt_check->close();

        if ($usage_count > 0) {
            throw new Exception("Middleware cannot be deleted because it is still in use by {$usage_count} router(s).");
        }

        // Get name for logging before deleting
        $stmt_get_name = $conn->prepare("SELECT name FROM middlewares WHERE id = ?");
        $stmt_get_name->bind_param("i", $id);
        $stmt_get_name->execute();
        $mw_name = $stmt_get_name->get_result()->fetch_assoc()['name'] ?? 'N/A';
        $stmt_get_name->close();

        $stmt_delete = $conn->prepare("DELETE FROM middlewares WHERE id = ?");
        $stmt_delete->bind_param("i", $id);
        if (!$stmt_delete->execute()) {
            throw new Exception("Failed to delete middleware: " . $stmt_delete->error);
        }
        $stmt_delete->close();

        $conn->commit();
        log_activity($_SESSION['username'], 'Middleware Deleted', "Middleware '{$mw_name}' (ID: #{$id}) has been deleted.");
        echo json_encode(['status' => 'success', 'message' => 'Middleware successfully deleted.']);
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
$type = trim($_POST['type'] ?? '');
$config_json = $_POST['config_json'] ?? '';
$description = trim($_POST['description'] ?? '');
$is_edit = !empty($id);

if (empty($name) || empty($type) || empty($config_json)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Name, Type, and Configuration are required.']);
    exit;
}

// Validate JSON
json_decode($config_json);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Configuration is not valid JSON. Error: ' . json_last_error_msg()]);
    exit;
}

try {
    // Check for duplicate name
    if ($is_edit) {
        $stmt_check = $conn->prepare("SELECT id FROM middlewares WHERE name = ? AND id != ?");
        $stmt_check->bind_param("si", $name, $id);
    } else {
        $stmt_check = $conn->prepare("SELECT id FROM middlewares WHERE name = ?");
        $stmt_check->bind_param("s", $name);
    }
    $stmt_check->execute();
    if ($stmt_check->get_result()->num_rows > 0) {
        throw new Exception('Middleware name already exists.');
    }
    $stmt_check->close();

    if ($is_edit) {
        $stmt = $conn->prepare("UPDATE middlewares SET name = ?, type = ?, config_json = ?, description = ? WHERE id = ?");
        $stmt->bind_param("ssssi", $name, $type, $config_json, $description, $id);
        $log_action = 'Middleware Edited';
        $log_details = "Middleware '{$name}' (ID: {$id}) has been updated.";
        $success_message = 'Middleware successfully updated.';
    } else {
        $stmt = $conn->prepare("INSERT INTO middlewares (name, type, config_json, description) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $name, $type, $config_json, $description);
        $log_action = 'Middleware Added';
        $log_details = "New middleware '{$name}' has been created.";
        $success_message = 'Middleware successfully created.';
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