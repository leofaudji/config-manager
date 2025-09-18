<?php
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');
$conn = Database::getInstance()->getConnection();

$request_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// --- GET Template Details Logic ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && preg_match('/\/api\/templates\/(\d+)/', $request_path, $matches)) {
    $id = $matches[1];
    $stmt = $conn->prepare("SELECT * FROM configuration_templates WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($template = $result->fetch_assoc()) {
        $template['config_data'] = json_decode($template['config_data'], true);
        echo json_encode(['status' => 'success', 'data' => $template]);
    } else {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Template not found.']);
    }
    $stmt->close();
    $conn->close();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

// --- Delete Logic ---
if (str_ends_with($request_path, '/delete')) {
    $id = $_POST['id'] ?? null;
    if (empty($id)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Template ID is required.']);
        exit;
    }

    $stmt_delete = $conn->prepare("DELETE FROM configuration_templates WHERE id = ?");
    $stmt_delete->bind_param("i", $id);
    if ($stmt_delete->execute()) {
        log_activity($_SESSION['username'], 'Template Deleted', "Template ID #{$id} has been deleted.");
        echo json_encode(['status' => 'success', 'message' => 'Template successfully deleted.']);
    } else {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Failed to delete template.']);
    }
    $stmt_delete->close();
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
    echo json_encode(['status' => 'error', 'message' => 'Template name is required.']);
    exit;
}

// Build the config_data JSON
$config_data = [
    'entry_points' => trim($_POST['entry_points'] ?? 'web'),
    'tls' => isset($_POST['tls']) && $_POST['tls'] == '1' ? 1 : 0,
    'cert_resolver' => trim($_POST['cert_resolver'] ?? ''),
    'middlewares' => $_POST['middlewares'] ?? []
];
$config_json = json_encode($config_data);

try {
    // Check for duplicate name
    if ($is_edit) {
        $stmt_check = $conn->prepare("SELECT id FROM configuration_templates WHERE name = ? AND id != ?");
        $stmt_check->bind_param("si", $name, $id);
    } else {
        $stmt_check = $conn->prepare("SELECT id FROM configuration_templates WHERE name = ?");
        $stmt_check->bind_param("s", $name);
    }
    $stmt_check->execute();
    if ($stmt_check->get_result()->num_rows > 0) {
        throw new Exception('Template name already exists.');
    }
    $stmt_check->close();

    if ($is_edit) {
        $stmt = $conn->prepare("UPDATE configuration_templates SET name = ?, description = ?, config_data = ? WHERE id = ?");
        $stmt->bind_param("sssi", $name, $description, $config_json, $id);
        $log_action = 'Template Edited';
        $log_details = "Template '{$name}' (ID: {$id}) has been updated.";
        $success_message = 'Template successfully updated.';
    } else {
        $stmt = $conn->prepare("INSERT INTO configuration_templates (name, description, config_data) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $name, $description, $config_json);
        $log_action = 'Template Added';
        $log_details = "New template '{$name}' has been created.";
        $success_message = 'Template successfully created.';
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