<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/DockerClient.php';

header('Content-Type: application/json');

$path = $_GET['path'] ?? '';

// --- Streaming Setup for Deployment ---
if (isset($_POST['action']) && $_POST['action'] === 'deploy') {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-cache');
    @ini_set('zlib.output_compression', 0);
    if (ob_get_level() > 0) {
        for ($i = 0; $i < ob_get_level(); $i++) {
            ob_end_flush();
        }
    }
    ob_implicit_flush(1);
}

function stream_message($message, $type = 'INFO') {
    echo date('[Y-m-d H:i:s]') . " [{$type}] " . htmlspecialchars(trim($message)) . "\n";
}

$host_id = $_GET['id'] ?? null;
$action = $_POST['action'] ?? null;

if (!$host_id) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Host ID is required.']);
    exit;
}

$conn = Database::getInstance()->getConnection();
$stmt = $conn->prepare("SELECT * FROM docker_hosts WHERE id = ?");
$stmt->bind_param("i", $host_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Host not found.']);
    exit;
}
$host = $result->fetch_assoc();
$stmt->close();

// --- Determine which helper container we are working with ---
$container_name = '';
// Check for the more specific 'cpu-reader' first to avoid incorrect matching with 'agent'.
if (str_contains($path, 'cpu-reader')) {
    $container_name = 'host-cpu-reader';
} elseif (str_contains($path, 'agent')) {
    $container_name = 'cm-health-agent';
}

if (empty($container_name)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid helper type in path.']);
    exit;
}

try {
    $dockerClient = new DockerClient($host);

    // Handle status check request
    if (str_ends_with($path, '-status')) {
        try {
            $status_column = ($container_name === 'cm-health-agent') ? 'agent_status' : 'cpu_reader_status';

            $details = $dockerClient->inspectContainer($container_name);
            $status = ($details['State']['Running'] ?? false) ? 'Running' : 'Stopped';
        } catch (Exception $e) {
            if (strpos($e->getMessage(), '404') !== false) {
                $status = 'Not Deployed';
            } else {
                // For other errors (e.g., unreachable host), mark status accordingly
                $status = 'Unreachable';
                $stmt_update = $conn->prepare("UPDATE docker_hosts SET {$status_column} = ? WHERE id = ?");
                $stmt_update->bind_param("si", $status, $host_id);
                $stmt_update->execute();
                $stmt_update->close();
                throw $e; // Re-throw other errors
            }
        }
        $response_data = ['status' => 'success', 'agent_status' => $status];
        if ($container_name === 'cm-health-agent') {
            $response_data['last_report_at'] = $host['last_report_at'] ?? null;
        } elseif ($container_name === 'host-cpu-reader') {
            $response_data['last_report_at'] = $host['last_cpu_report_at'] ?? null;
        }

        // Update the database with the latest known status
        $stmt_update = $conn->prepare("UPDATE docker_hosts SET {$status_column} = ? WHERE id = ?");
        $stmt_update->bind_param("si", $status, $host_id);
        $stmt_update->execute();
        $stmt_update->close();

        echo json_encode($response_data);
        exit;
    }

    // Handle action requests
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$action) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid action or request method.']);
        exit;
    }

    $status_column = ($container_name === 'cm-health-agent') ? 'agent_status' : 'cpu_reader_status';

    switch ($action) { 
        case 'deploy':
            stream_message("Deployment process initiated for '{$container_name}' on host '{$host['name']}'...");
            // Remove existing container first to ensure a clean deploy
            try {
                stream_message("Attempting to remove existing container '{$container_name}'...");
                $dockerClient->removeContainer($container_name, true);
                stream_message("Existing container removed or was not present.");
            } catch (Exception $e) {
                // Ignore 404 error if container doesn't exist
                if (strpos($e->getMessage(), '404') === false) throw $e;
                stream_message("Container did not exist, proceeding with deployment.");
            }

            if ($container_name === 'cm-health-agent') {
                // --- Deployment logic for Health Agent ---
                $agent_image = get_setting('health_agent_image');
                $agent_api_token = get_setting('health_agent_api_token');
                $app_base_url = get_setting('app_base_url');
                $auto_healing_enabled = get_setting('auto_healing_enabled', '0'); // Default to '0' if not set
                if (empty($agent_image) || empty($agent_api_token) || empty($app_base_url)) {
                    throw new Exception("Health Agent settings (Image Name, API Token, Base URL) are not configured in Settings.");
                }

                // 1. Pull the specified agent image. This will fetch it if it doesn't exist on the host.
                // This will now fail loudly if the image is not found, which is the desired behavior.
                stream_message("Pulling agent image '{$agent_image}' from registry...");
                $dockerClient->pullImage($agent_image);
                stream_message("Image pull completed.");
                
                // --- NEW: Intelligent Network Handling for Agent ---
                $dockerInfo = $dockerClient->getInfo();
                $is_swarm_node = (isset($dockerInfo['Swarm']['LocalNodeState']) && $dockerInfo['Swarm']['LocalNodeState'] === 'active');
                $network_to_attach = null;
                
                // This logic is triggered by the frontend when deploying to a Swarm host.
                if (isset($_POST['create_agent_network']) && $_POST['create_agent_network'] === 'true' && $is_swarm_node) {
                    $agent_network_name = 'cm-agent-net';
                    stream_message("Host is a Swarm node. Ensuring '{$agent_network_name}' overlay network exists...");
                    try {
                        // Pass 'true' to create an overlay network
                        $dockerClient->ensureNetworkExists($agent_network_name, true);
                        $network_to_attach = $agent_network_name;
                        stream_message("Agent network '{$agent_network_name}' is ready.");
                    } catch (Exception $e) {
                        stream_message("Failed to ensure agent network exists: " . $e->getMessage(), 'ERROR');
                        // Do not halt deployment, but log the error. The agent might still work for some checks.
                    }
                }

                // Add the new auto-healing setting to the environment variables
                $env_vars = ['CONFIG_MANAGER_URL=' . $app_base_url, 'API_KEY=' . $agent_api_token, 'HOST_ID=' . $host['id'], 'AUTO_HEALING_ENABLED=' . $auto_healing_enabled, 'HOSTNAME=cm-health-agent'];

                // --- BULLETPROOF CRON EXECUTION (Definitive) --- (Final Version)
                // We create a wrapper script that explicitly exports the necessary environment variables.
                // Cron calls this wrapper, ensuring the PHP script runs in the correct environment.
                $wrapper_path = "/usr/local/bin/run-agent.sh";
                $crontab_entry = "* * * * * {$wrapper_path} > /proc/1/fd/1 2>/proc/1/fd/2";

                $command = [
                    "/bin/sh", "-c",
                    // 1. Create the wrapper script. It explicitly exports the necessary variables.
                    "echo '#!/bin/sh' > {$wrapper_path} && " .
                    "echo \"export CONFIG_MANAGER_URL='{$app_base_url}'\" >> {$wrapper_path} && " .
                    "echo \"export API_KEY='{$agent_api_token}'\" >> {$wrapper_path} && " .
                    "echo \"export HOST_ID='{$host['id']}'\" >> {$wrapper_path} && " .
                    "echo \"export AUTO_HEALING_ENABLED='{$auto_healing_enabled}'\" >> {$wrapper_path} && " .
                    // The final line of the wrapper script executes the PHP agent.
                    "echo 'php /usr/src/app/agent.php' >> {$wrapper_path} && " .
                    "chmod +x {$wrapper_path} && " .
                    // 2. Create the crontab entry to call the wrapper script.
                    "echo '{$crontab_entry}' > /etc/crontabs/root && " .
                    // 3. Start crond in the foreground to keep the container running.
                    "crond -f -d 8"
                ];

                // 2. Create and start the agent container. It will use the CMD from the Dockerfile.
                stream_message("Creating and starting new agent container from image '{$agent_image}'...");
                $containerId = $dockerClient->createAndStartHealthAgentContainer($container_name, $agent_image, $env_vars, $command, $network_to_attach);

                stream_message("Container '{$container_name}' (ID: " . substr($containerId, 0, 12) . ") started successfully.");

                // --- NEW: Copy the agent script into the running container ---
                stream_message("Injecting agent script into the container...");
                $localAgentPath = PROJECT_ROOT . '/agent.php';
                if (!file_exists($localAgentPath)) {
                    throw new Exception("Local agent script not found at '{$localAgentPath}'.");
                }
                $agentScriptContent = file_get_contents($localAgentPath);

                // Create the directory and write the file in a single, reliable exec call.
                // This is more robust than using the /archive API endpoint.
                $remoteScriptPath = '/usr/src/app/agent.php';
                $injectionCommand = "mkdir -p /usr/src/app && echo " . escapeshellarg($agentScriptContent) . " > " . escapeshellarg($remoteScriptPath) . " && chmod +x " . escapeshellarg($remoteScriptPath);

                // We must wait for this command to complete.
                $dockerClient->execInContainer($containerId, ['/bin/sh', '-c', $injectionCommand], true);
                stream_message("Agent script copied successfully.");

                $log_message = "Health Agent deployed to host '{$host['name']}'.";
            } elseif ($container_name === 'host-cpu-reader') {
                // --- Deployment logic for CPU Reader ---
                $helper_image = 'alpine:latest'; // This helper uses a minimal image.
                stream_message("Pulling helper image '{$helper_image}'...");
                $pull_output = $dockerClient->pullImage($helper_image);
                stream_message("Image pull process finished.");

                stream_message("Creating and starting new CPU reader container...");
                $dockerClient->createAndStartCpuReaderContainer($container_name, $helper_image);
                stream_message("Container '{$container_name}' started successfully.");
                $log_message = "Host CPU Reader deployed to host '{$host['name']}'.";
            } else {
                throw new Exception("Unknown container type for deployment.");
            }

            log_activity($_SESSION['username'], 'Helper Deployed', $log_message);
            // Update status in DB after successful deployment
            $stmt_update = $conn->prepare("UPDATE docker_hosts SET {$status_column} = 'Running' WHERE id = ?");
            $stmt_update->bind_param("i", $host_id);
            $stmt_update->execute();
            $stmt_update->close();

            stream_message("---");
            stream_message("Deployment finished successfully!", "SUCCESS");
            echo "_DEPLOYMENT_COMPLETE_";
            break;

        case 'restart':
            $dockerClient->restartContainer($container_name);
            $log_message = "Helper container '{$container_name}' restarted on host '{$host['name']}'.";
            log_activity($_SESSION['username'], 'Helper Restarted', $log_message);
            // Update status in DB
            $stmt_update = $conn->prepare("UPDATE docker_hosts SET {$status_column} = 'Running' WHERE id = ?");
            $stmt_update->bind_param("i", $host_id);
            $stmt_update->execute();
            $stmt_update->close();
            echo json_encode(['status' => 'success', 'message' => "Restart command sent for '{$container_name}'."]);
            break;

        case 'remove':
            $dockerClient->removeContainer($container_name, true);
            $log_message = "Helper container '{$container_name}' removed from host '{$host['name']}'.";
            log_activity($_SESSION['username'], 'Helper Removed', $log_message);
            // Update status in DB
            $stmt_update = $conn->prepare("UPDATE docker_hosts SET {$status_column} = 'Not Deployed' WHERE id = ?");
            $stmt_update->bind_param("i", $host_id);
            $stmt_update->execute();
            $stmt_update->close();

            echo json_encode(['status' => 'success', 'message' => "Helper container '{$container_name}' has been removed."]);
            break;
        
        case 'run':
            if ($container_name === 'cm-health-agent') {
                // Skrip sudah ada di dalam image, jadi kita bisa langsung menjalankannya.
                // Ini akan memicu satu siklus pengecekan di luar jadwal cron.
                $dockerClient->execInContainer($container_name, ['php', '/usr/src/app/agent.php']);
                $message = "Manual health check has been triggered successfully.";
            } elseif ($container_name === 'host-cpu-reader') {
                // Directly execute the check inside the helper container
                $dockerClient->execInContainer($container_name, ['php', '/usr/src/app/collect_stats_single.php']);
                $message = "Manual host stats collection has been triggered.";
            }
            echo json_encode(['status' => 'success', 'message' => $message]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid action specified.']);
            break;
    }
} catch (Exception $e) {
    $error_message = "Failed to perform action '{$action}' on host '{$host['name']}': " . $e->getMessage();
    log_activity('SYSTEM', 'Health Agent Error', $error_message);

    if ($action === 'deploy') {
        stream_message($e->getMessage(), 'ERROR');
        echo "_DEPLOYMENT_FAILED_";
    } else {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
}

$conn->close();