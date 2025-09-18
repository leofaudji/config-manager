<?php
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');
$conn = Database::getInstance()->getConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

$router_ids = $_POST['router_ids'] ?? [];

if (empty($router_ids) || !is_array($router_ids)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'No router IDs provided.']);
    exit;
}

// Sanitize all IDs to be integers
$sanitized_router_ids = array_map('intval', $router_ids);

$request_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

try {
    $conn->begin_transaction();

    // --- Bulk Delete Logic ---
    if (str_ends_with($request_path, '/bulk-delete')) {
        $in_clause = implode(',', array_fill(0, count($sanitized_router_ids), '?'));
        $types = str_repeat('i', count($sanitized_router_ids));

        $stmt = $conn->prepare("DELETE FROM routers WHERE id IN ($in_clause)");
        $stmt->bind_param($types, ...$sanitized_router_ids);

        if (!$stmt->execute()) {
            throw new Exception('Failed to delete routers: ' . $stmt->error);
        }
        $affected_rows = $stmt->affected_rows;
        $stmt->close();

        $log_details = "Bulk deleted {$affected_rows} router(s). IDs: " . implode(', ', $sanitized_router_ids);
        log_activity($_SESSION['username'], 'Routers Bulk Deleted', $log_details);
        $message = "Successfully deleted {$affected_rows} router(s).";

    // --- Bulk Move Logic ---
    } elseif (str_ends_with($request_path, '/bulk-move')) {
        $target_group_id = (int)($_POST['target_group_id'] ?? 0);
        if (empty($target_group_id)) {
            throw new Exception('Target group ID is required.');
        }

        $in_clause = implode(',', array_fill(0, count($sanitized_router_ids), '?'));
        $types = 'i' . str_repeat('i', count($sanitized_router_ids));
        $params = array_merge([$target_group_id], $sanitized_router_ids);

        $stmt = $conn->prepare("UPDATE routers SET group_id = ? WHERE id IN ($in_clause)");
        $stmt->bind_param($types, ...$params);

        if (!$stmt->execute()) {
            throw new Exception('Failed to move routers: ' . $stmt->error);
        }
        $affected_rows = $stmt->affected_rows;
        $stmt->close();

        $log_details = "Bulk moved {$affected_rows} router(s) to group ID {$target_group_id}. Router IDs: " . implode(', ', $sanitized_router_ids);
        log_activity($_SESSION['username'], 'Routers Bulk Moved', $log_details);
        $message = "Successfully moved {$affected_rows} router(s).";
    } else {
        throw new Exception('Invalid bulk action specified.');
    }

    $conn->commit();
    echo json_encode(['status' => 'success', 'message' => $message]);

} catch (Exception $e) {
    $conn->rollback();
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();
?>