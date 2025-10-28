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

function getHostSwarmStatus(array $host): string {
    try {
        $dockerClient = new DockerClient($host);
        $info = $dockerClient->getInfo();
        if (isset($info['Swarm']['LocalNodeState']) && $info['Swarm']['LocalNodeState'] !== 'inactive') {
            return (isset($info['Swarm']['ControlAvailable']) && $info['Swarm']['ControlAvailable']) ? 'manager' : 'worker';
        }
        return 'standalone';
    } catch (Exception $e) {
        return 'unreachable';
    }
}

try {
    $filter = $_GET['filter'] ?? 'all'; // 'all', 'managers', 'standalone'

    $hosts_result = $conn->query("SELECT id, name, docker_api_url, tls_enabled, ca_cert_path, client_cert_path, client_key_path, default_volume_path FROM docker_hosts ORDER BY name ASC");

    $hosts = [];
    while ($host = $hosts_result->fetch_assoc()) {
        $status = getHostSwarmStatus($host);

        $should_add = false;
        if ($filter === 'managers' && $status === 'manager') {
            $should_add = true;
        } elseif ($filter === 'standalone' && ($status === 'standalone' || $status === 'unreachable')) {
            // Show unreachable hosts as potential standalone targets
            $should_add = true;
        } elseif ($filter === 'all') {
            $should_add = true;
        }

        if ($should_add) {
            $hosts[] = [
                'id' => $host['id'], 
                'name' => $host['name'],
                'default_volume_path' => $host['default_volume_path']
            ];
        }
    }

    echo json_encode(['status' => 'success', 'data' => $hosts]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();
?>