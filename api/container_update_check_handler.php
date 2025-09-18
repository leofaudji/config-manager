<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/DockerClient.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

$request_uri_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$basePath = BASE_PATH;
if ($basePath && strpos($request_uri_path, $basePath) === 0) {
    $request_uri_path = substr($request_uri_path, strlen($basePath));
}

if (!preg_match('/^\/api\/hosts\/(\d+)\/containers\/([a-zA-Z0-9]+)\/check-update$/', $request_uri_path, $matches)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid API endpoint format for container update check.']);
    exit;
}

$host_id = $matches[1];
$container_id = $matches[2];

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

    // 1. Get the current container's image name and digest
    $container_details = $dockerClient->inspectContainer($container_id);
    $current_image_tag = $container_details['Config']['Image'] ?? null;
    $current_image_digest = $container_details['Image'] ?? null;

    if (!$current_image_tag || !$current_image_digest) {
        throw new Exception("Could not determine image details for the container.");
    }

    // 2. Pull the image from the registry to get the latest version
    $pull_output = $dockerClient->pullImage($current_image_tag);

    // 3. Inspect the (potentially new) image on the host to get its digest
    $image_details = $dockerClient->inspectImage($current_image_tag);
    $remote_image_digest = null;
    if (isset($image_details['RepoDigests']) && is_array($image_details['RepoDigests']) && !empty($image_details['RepoDigests'])) {
        $repo_digest_parts = explode('@', $image_details['RepoDigests'][0]);
        $remote_image_digest = end($repo_digest_parts);
    }

    if (!$remote_image_digest) {
        throw new Exception("Could not determine remote image digest after pulling.");
    }
    
    $update_available = ($current_image_digest !== $remote_image_digest);

    echo json_encode([
        'status' => 'success',
        'update_available' => $update_available,
        'image_tag' => $current_image_tag
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();
?>