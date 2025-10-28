<?php
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
    exit;
}

$conn = Database::getInstance()->getConnection();

try {
    $filter = $_GET['filter'] ?? 'all'; // 'all', 'managers', 'standalone'

    $sql = "SELECT id, name FROM docker_hosts";
    $params = [];
    $types = '';

    if ($filter === 'managers') {
        $sql .= " WHERE swarm_status = ?";
        $params[] = 'manager';
        $types .= 's';
    } elseif ($filter === 'standalone') {
        $sql .= " WHERE swarm_status IN ('standalone', 'unreachable')";
    }

    $sql .= " ORDER BY name ASC";

    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $hosts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    echo json_encode(['status' => 'success', 'data' => $hosts]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();
?>