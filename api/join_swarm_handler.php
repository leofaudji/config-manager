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
    echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
    exit;
}

$target_host_id = $_POST['target_host_id'] ?? null;
$manager_host_id = $_POST['manager_host_id'] ?? null;

if (empty($target_host_id) || empty($manager_host_id)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Target host and Manager host IDs are required.']);
    exit;
}

$conn = Database::getInstance()->getConnection();

try {
    // --- Get Manager Host Details (to find its IP address) ---
    $stmt_manager = $conn->prepare("SELECT docker_api_url FROM docker_hosts WHERE id = ?");
    $stmt_manager->bind_param("i", $manager_host_id);
    $stmt_manager->execute();
    $result_manager = $stmt_manager->get_result();
    if (!($manager_host_details = $result_manager->fetch_assoc())) {
        throw new Exception("Manager host not found in database.");
    }
    $stmt_manager->close();

    // --- Get Target Host Details (to connect to it) ---
    $stmt_target = $conn->prepare("SELECT * FROM docker_hosts WHERE id = ?");
    $stmt_target->bind_param("i", $target_host_id);
    $stmt_target->execute();
    $result_target = $stmt_target->get_result();
    if (!($target_host_details = $result_target->fetch_assoc())) {
        throw new Exception("Target host not found in database.");
    }
    $stmt_target->close();

    // --- Get Join Token from the Manager ---
    // This command is executed locally on the server where the app is running.
    $token_command = "/usr/bin/sudo -n /usr/bin/docker swarm join-token -q worker 2>&1";
    exec($token_command, $token_output, $token_return_var);

    if ($token_return_var !== 0) {
        $error_string = implode("\n", $token_output);
        if (stripos($error_string, 'a password is required') !== false || stripos($error_string, 'terminal is required') !== false) {
            $error_string .= "\n\nHint: This error is caused by the 'requiretty' setting on your server. To fix, edit your /etc/sudoers file (using `sudo visudo`) and add the line `Defaults:www-data !requiretty` before the other rules for the 'www-data' user.";
        }
        throw new Exception("Failed to get swarm join token. Output: " . $error_string);
    }
    $join_token = trim($token_output[0] ?? '');
    if (empty($join_token)) {
        throw new Exception("Failed to parse join token from command output.");
    }

    // --- Execute the join command on the Target Host ---
    $target_docker_client = new DockerClient($target_host_details);
    
    // Extract IP from manager's API URL and assume default Swarm port 2377
    preg_match('/tcp:\/\/([^:]+):/', $manager_host_details['docker_api_url'], $ip_matches);
    $manager_ip = $ip_matches[1] ?? null;
    if (!$manager_ip) {
        throw new Exception("Could not extract a valid IP address from the manager's API URL.");
    }
    $manager_addr = "{$manager_ip}:2377";

    $join_command = "docker swarm join --token {$join_token} " . escapeshellarg($manager_addr);
    
    // Use a helper container on the target host to run the join command.
    $output = $target_docker_client->exec('docker:latest', $join_command, true, true);

    // If join is successful, update the worker's record in the database
    $stmt_update_worker = $conn->prepare("UPDATE docker_hosts SET swarm_manager_id = ? WHERE id = ?");
    $stmt_update_worker->bind_param("ii", $manager_host_id, $target_host_id);
    $stmt_update_worker->execute();
    $stmt_update_worker->close();

    log_activity($_SESSION['username'], 'Node Joined Swarm', "Host '{$target_host_details['name']}' attempted to join the swarm.");
    echo json_encode(['status' => 'success', 'message' => "Join command sent to host '{$target_host_details['name']}'. Output: " . $output]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();
?>