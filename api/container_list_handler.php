<?php
// File: /var/www/html/config-manager/api/container_list_handler.php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/DockerClient.php';

header('Content-Type: application/json');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
    exit;
}

$host_id = $_GET['host_id'] ?? null;
if (!$host_id) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Host ID is required.']);
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
    $info = $dockerClient->getInfo();
    $is_swarm_node = (isset($info['Swarm']['LocalNodeState']) && $info['Swarm']['LocalNodeState'] !== 'inactive');

    $items = [];
    if ($is_swarm_node) {
        // For Swarm, list services
        $services = $dockerClient->listServices();
        foreach ($services as $service) {
            $items[] = [
                'Id' => $service['ID'],
                // Use Spec.Name which is the user-defined name
                'Name' => $service['Spec']['Name'],
                'Type' => 'service'
            ];
        }
    } else {
        // For Standalone, list containers
        $containers = $dockerClient->listContainers();
        foreach ($containers as $container) {
            $items[] = [
                'Id' => $container['Id'],
                // Clean up the container name
                'Name' => ltrim($container['Names'][0] ?? $container['Id'], '/'),
                'Type' => 'container'
            ];
        }
    }

    echo json_encode(['status' => 'success', 'data' => $items]);

} catch (Exception $e) {
    http_response_code($e instanceof RuntimeException ? 404 : 500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
} finally {
    $conn->close();
}