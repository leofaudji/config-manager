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
    $containers = $dockerClient->listContainers();

    echo json_encode(['status' => 'success', 'data' => $containers]);

} catch (Exception $e) {
    http_response_code($e instanceof RuntimeException ? 404 : 500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
} finally {
    $conn->close();
}