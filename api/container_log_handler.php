<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/DockerClient.php';

header('Content-Type: application/json');

$host_id = $_GET['id'] ?? null;
$container_id = $_GET['container_id'] ?? null;
$tail = isset($_GET['tail']) ? (int)$_GET['tail'] : 200;

if (!$host_id || !$container_id) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Host ID and Container ID are required.']);
    exit;
}

$conn = Database::getInstance()->getConnection();

try {
    $stmt = $conn->prepare("SELECT * FROM docker_hosts WHERE id = ?");
    $stmt->bind_param("i", $host_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if (!($host = $result->fetch_assoc())) {
        throw new Exception("Host not found.");
    }
    $stmt->close();

    $dockerClient = new DockerClient($host);
    $logs = $dockerClient->getContainerLogs($container_id, $tail);

    echo json_encode(['status' => 'success', 'logs' => $logs]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();
?>