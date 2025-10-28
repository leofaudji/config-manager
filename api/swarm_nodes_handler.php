<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/DockerClient.php';

header('Content-Type: application/json');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
    exit;
}

$conn = Database::getInstance()->getConnection();

try {
    // Find the first available Swarm manager to query
    $hosts_result = $conn->query("SELECT * FROM docker_hosts ORDER BY id ASC");
    $manager_host = null;
    $dockerClient = null;

    while ($host = $hosts_result->fetch_assoc()) {
        try {
            $client = new DockerClient($host);
            $info = $client->getInfo();
            if (isset($info['Swarm']['ControlAvailable']) && $info['Swarm']['ControlAvailable']) {
                $dockerClient = $client;
                $manager_host = $host;
                break;
            }
        } catch (Exception $e) {
            // Ignore unreachable hosts and try the next one
            continue;
        }
    }

    if (!$dockerClient) {
        throw new Exception("No reachable Docker Swarm manager found among the configured hosts.");
    }

    // Get the list of nodes from the manager
    $nodes = $dockerClient->listNodes();

    echo json_encode(['status' => 'success', 'data' => $nodes]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

if (isset($conn)) {
    $conn->close();
}
?>