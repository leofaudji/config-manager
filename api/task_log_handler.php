<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/DockerClient.php';

$host_id = $_GET['host_id'] ?? null;
$task_id = $_GET['task_id'] ?? null;
$tail = isset($_GET['tail']) ? (int)$_GET['tail'] : 300;

if (!$host_id || !$task_id) {
    http_response_code(400);
    die('Host ID and Task ID are required.');
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
    
    // The Docker API for task logs is different from container logs
    $path = "/tasks/{$task_id}/logs?stdout=true&stderr=true&timestamps=true&tail={$tail}";
    // This is a raw request, not expecting JSON
    $logs = $dockerClient->rawRequest($path);

    echo json_encode(['status' => 'success', 'logs' => $logs]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();
?>