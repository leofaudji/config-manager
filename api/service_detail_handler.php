<?php
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');
$conn = Database::getInstance()->getConnection();

$service_id = $_GET['id'] ?? null;

if (!$service_id) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Service ID is required.']);
    exit;
}

try {
    $stmt = $conn->prepare("SELECT url FROM servers WHERE service_id = ? ORDER BY url ASC");
    $stmt->bind_param("i", $service_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $servers = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    echo json_encode([
        'status' => 'success',
        'data' => [
            'servers' => $servers
        ]
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to fetch service details: ' . $e->getMessage()]);
}

$conn->close();