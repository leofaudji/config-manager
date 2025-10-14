<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/DockerClient.php';

$host_id = $_GET['id'] ?? null;
$container_id = $_GET['container_id'] ?? null;
$tail = isset($_GET['tail']) ? (int)$_GET['tail'] : 200;
$search = trim($_GET['search'] ?? '');
$is_download_request = isset($_GET['download']) && $_GET['download'] === 'true';

if ($is_download_request) {
    // Headers for file download
    header('Content-Type: text/plain');
} else {
    // Standard JSON response
    header('Content-Type: application/json');
}

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
        throw new RuntimeException("Host not found.");
    }
    $stmt->close();

    $dockerClient = new DockerClient($host);
    $logs = $dockerClient->getContainerLogs($container_id, $tail);

    // Filter logs on the server-side if a search term is provided
    if (!empty($search)) {
        $lines = explode("\n", $logs);
        $filtered_lines = array_filter($lines, function($line) use ($search) {
            return stripos($line, $search) !== false;
        });
        $logs = implode("\n", $filtered_lines);
    }

    if ($is_download_request) {
        $container_details = $dockerClient->inspectContainer($container_id);
        $container_name = ltrim($container_details['Name'] ?? 'container', '/');
        $filename = "logs-{$container_name}-" . date('Ymd-His') . ".txt";
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo $logs;
    } else {
        echo json_encode(['status' => 'success', 'logs' => $logs]);
    }

} catch (Exception $e) {
    http_response_code($e instanceof RuntimeException ? 404 : 500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();
?>