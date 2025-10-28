<?php
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
    exit;
}

$host_id = $_POST['host_id'] ?? null;
$action = $_POST['action'] ?? '';

if (empty($host_id) || !in_array($action, ['promote', 'demote'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Host ID and a valid action (promote/demote) are required.']);
    exit;
}

$conn = Database::getInstance()->getConnection();

try {
    // We need the hostname of the target node for the docker command.
    $stmt = $conn->prepare("SELECT name FROM docker_hosts WHERE id = ?");
    $stmt->bind_param("i", $host_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if (!($host = $result->fetch_assoc())) {
        throw new Exception("Target host not found in database.");
    }
    $stmt->close();
    $node_hostname = $host['name'];

    // The command is executed on the manager node where this app is running.
    // It uses sudo, which must be configured in the sudoers file.
    $command = "/usr/bin/sudo /usr/bin/docker node {$action} " . escapeshellarg($node_hostname) . " 2>&1";
    
    exec($command, $output, $return_var);

    if ($return_var !== 0) {
        throw new Exception("Failed to {$action} node. Output: " . implode("\n", $output));
    }

    log_activity($_SESSION['username'], 'Node Role Changed', "Node '{$node_hostname}' was successfully {$action}d.");
    echo json_encode(['status' => 'success', 'message' => "Node '{$node_hostname}' has been {$action}d."]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();
?>