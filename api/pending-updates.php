<?php
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden.']);
    exit;
}

try {
    $conn = Database::getInstance()->getConnection();
    
    $sql = "
        SELECT s.id, s.stack_name, s.host_id, h.name as host_name, s.webhook_schedule_time, s.webhook_update_policy, s.webhook_pending_since
        FROM application_stacks s
        JOIN docker_hosts h ON s.host_id = h.id
        WHERE s.webhook_pending_update = 1
        ORDER BY s.stack_name ASC
    ";
    
    $result = $conn->query($sql);
    $stacks = $result->fetch_all(MYSQLI_ASSOC);
    
    echo json_encode(['status' => 'success', 'count' => count($stacks), 'stacks' => $stacks]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to fetch pending updates: ' . $e->getMessage()]);
}