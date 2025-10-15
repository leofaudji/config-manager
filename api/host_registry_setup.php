<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/DockerClient.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden.']);
    exit;
}

$host_id = $_POST['host_id'] ?? null;
if (!$host_id) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Host ID is required.']);
    exit;
}

$conn = Database::getInstance()->getConnection();

try {
    // 1. Fetch host details
    $stmt = $conn->prepare("SELECT * FROM docker_hosts WHERE id = ?");
    $stmt->bind_param("i", $host_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if (!($host = $result->fetch_assoc())) {
        throw new Exception("Host not found.");
    }
    $stmt->close();

    $dockerClient = new DockerClient($host);

    // 2. Define registry container details
    $registry_image = 'registry:2';
    $registry_container_name = 'local-registry';

    // 3. Pull the registry image
    $dockerClient->pullImage($registry_image);

    // 4. Remove existing container with the same name, if any, to ensure a clean start
    try {
        $dockerClient->removeContainer($registry_container_name, true);
    } catch (Exception $e) {
        // Ignore 404 error if container doesn't exist
        if (strpos($e->getMessage(), '404') === false) throw $e;
    }

    // 5. Create and start the registry container
    $config = [
        'Image' => $registry_image,
        'HostConfig' => [
            'PortBindings' => ['5000/tcp' => [['HostPort' => '5000']]],
            'RestartPolicy' => ['Name' => 'always']
        ]
    ];
    $dockerClient->request("/containers/create?name={$registry_container_name}", 'POST', $config);
    $dockerClient->startContainer($registry_container_name);

    // 6. Update the host's registry_url in the database
    preg_match('/tcp:\/\/([^:]+):/', $host['docker_api_url'], $ip_matches);
    $host_ip = $ip_matches[1] ?? 'localhost';
    $new_registry_url = "http://{$host_ip}:5000";

    $stmt_update = $conn->prepare("UPDATE docker_hosts SET registry_url = ? WHERE id = ?");
    $stmt_update->bind_param("si", $new_registry_url, $host_id);
    $stmt_update->execute();
    $stmt_update->close();

    log_activity($_SESSION['username'], 'Local Registry Setup', "Successfully set up a local registry on host '{$host['name']}'.");
    echo json_encode(['status' => 'success', 'message' => "Local registry successfully deployed on '{$host['name']}' at {$new_registry_url}. Please configure other hosts' daemon.json to trust this insecure registry."]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
} finally {
    if (isset($conn)) $conn->close();
}