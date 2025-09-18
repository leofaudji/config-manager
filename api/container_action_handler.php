<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/DockerClient.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

// Manually parse the URL to ensure parameters are captured correctly.
// This is a more robust approach if the router has issues with POST URL parameters.
$request_uri_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Manually strip base path, similar to how Router.php does it.
$basePath = BASE_PATH;
if ($basePath && strpos($request_uri_path, $basePath) === 0) {
    $request_uri_path = substr($request_uri_path, strlen($basePath));
}

// The regex now matches from the start of the path (after base path is stripped).
if (!preg_match('/^\/api\/hosts\/(\d+)\/containers\/([a-zA-Z0-9]+)\/(\w+)/', $request_uri_path, $matches)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid API endpoint format for container action.']);
    exit;
}

$host_id = $matches[1];
$container_id = $matches[2];
$action = $matches[3];

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
    $message = '';

    switch ($action) {
        case 'start':
            $dockerClient->startContainer($container_id);
            $message = "Container successfully started.";
            break;
        case 'stop':
            $dockerClient->stopContainer($container_id);
            $message = "Container successfully stopped.";
            break;
        case 'restart':
            $dockerClient->restartContainer($container_id);
            $message = "Container successfully restarted.";
            break;
        default:
            throw new InvalidArgumentException("Invalid action: {$action}");
    }

    log_activity($_SESSION['username'], "Container Action: " . ucfirst($action), "Performed '{$action}' on container ID " . substr($container_id, 0, 12) . " on host '{$host['name']}'.");
    echo json_encode(['status' => 'success', 'message' => $message]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();
?>