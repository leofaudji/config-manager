<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/DockerClient.php';

// --- FIX: Prevent script timeout during long operations like pulling images ---
set_time_limit(0); // 0 = no time limit

// --- NEW: Extract path from request URI ---
$request_uri_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$basePath = BASE_PATH;
if ($basePath && strpos($request_uri_path, $basePath) === 0) {
    $request_uri_path = substr($request_uri_path, strlen($basePath));
}
if (!preg_match('/^\/api\/hosts\/\d+\/helper\/(.+)$/', $request_uri_path, $matches)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid helper API endpoint format.']);
    exit;
}
$path = $matches[1]; // This will be 'agent-action', 'falco-status', etc.

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
} else {
    header('Content-Type: application/json');
}

function stream_message($message, $type = 'INFO') {
    echo date('[Y-m-d H:i:s]') . " [{$type}] " . htmlspecialchars(trim($message)) . "\n";
}

$host_id = $_GET['id'] ?? null;

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
$agent_type = 'unknown';
if (str_contains($path, 'agent-')) {
    $agent_type = 'agent';
} elseif (str_contains($path, 'falco-')) {
    $agent_type = 'falco';
} elseif (str_contains($path, 'falcosidekick-')) {
    $agent_type = 'falcosidekick';
}

if ($agent_type === 'unknown') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid helper type in path.']);
    exit;
}

try {
    $dockerClient = new DockerClient($host);

    // Handle status check request
    if (str_ends_with($path, '-status')) {
        $status_column = 'agent_status';
        $container_name = 'cm-health-agent';
        if ($agent_type === 'falco') {
            $status_column = 'falco_status';
            $container_name = 'falco-sensor';
        } elseif ($agent_type === 'falcosidekick') {
            $status_column = 'falcosidekick_status';
            $container_name = 'falcosidekick';
        }

        try {
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

        // --- FIX: Always include last_report_at for the health agent ---
        $response_data = ['status' => 'success', 'agent_status' => $status, 'last_report_at' => null];
        if ($agent_type === 'agent') {
            // The $host variable already contains the latest data from the DB, including last_report_at.
            // This ensures we send the correct value back to the UI.
            $response_data['last_report_at'] = $host['last_report_at'];
        }

        // Update the database with the latest known status
        $stmt_update = $conn->prepare("UPDATE docker_hosts SET {$status_column} = ? WHERE id = ?");
        $stmt_update->bind_param("si", $status, $host_id);
        $stmt_update->execute();
        // --- FIX: Close the statement immediately after use ---
        $stmt_update->close();

        echo json_encode($response_data);
        exit;
    }

    // Handle action requests
    $action = $_POST['action'] ?? null;
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$action) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid action or request method.']);
        exit;
    }

    $status_column = 'agent_status'; // Default
    if ($agent_type === 'falco') $status_column = 'falco_status';
    if ($agent_type === 'falcosidekick') $status_column = 'falcosidekick_status';

    switch ($action) { 
        case 'deploy':
            $container_name = '';
            if ($agent_type === 'agent') $container_name = 'cm-health-agent';
            if ($agent_type === 'falco') $container_name = 'falco-sensor';
            if ($agent_type === 'falcosidekick') $container_name = 'falcosidekick';

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

            if ($agent_type === 'agent') {
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
                    // 1. Install necessary tools (ping) for health checks. This is crucial for Swarm ICMP checks.
                    "apk update && apk add --no-cache iputils && " .
                    // 2. Create the wrapper script. It explicitly exports the necessary environment variables.
                    "echo '#!/bin/sh' > {$wrapper_path} && " .
                    "echo \"export CONFIG_MANAGER_URL='{$app_base_url}'\" >> {$wrapper_path} && " .
                    "echo \"export API_KEY='{$agent_api_token}'\" >> {$wrapper_path} && " .
                    "echo \"export HOST_ID='{$host['id']}'\" >> {$wrapper_path} && " .
                    "echo \"export AUTO_HEALING_ENABLED='{$auto_healing_enabled}'\" >> {$wrapper_path} && " .
                    // The final line of the wrapper script executes the PHP agent.
                    "echo 'php /usr/src/app/agent.php' >> {$wrapper_path} && " .
                    "chmod +x {$wrapper_path} && " .
                    // 3. Create the crontab entry to call the wrapper script.
                    "echo '{$crontab_entry}' > /etc/crontabs/root && " .
                    // 4. Start crond in the foreground to keep the container running.
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
            } elseif ($agent_type === 'falco') {
                // --- Deployment logic for Falco ---
                // FIX: Use the correct driver-loader image for pulling
                $falco_image = 'falcosecurity/falco-driver-loader:latest';
                stream_message("Pulling Falco image '{$falco_image}'...");
                $dockerClient->pullImage($falco_image);
                stream_message("Image pull completed.");
 
                $volumes = [
                    '/var/run/docker.sock:/host/var/run/docker.sock:ro', '/dev:/host/dev:ro',
                    '/proc:/host/proc:ro', '/boot:/host/boot:ro',
                    '/lib/modules:/host/lib/modules:ro', '/usr:/host/usr:ro',
                    '/sys/fs/bpf:/sys/fs/bpf', // FIX: Mount the BPF filesystem for eBPF probe interaction
                    '/root/.falco:/root/.falco:ro', // FIX: Mount the driver location for the main Falco container
                    '/etc:/host/etc:ro' // FIX: Add /etc mount for scap_init to succeed
                ];

                // --- FIX: Create falco_rules.local.yaml and copy it into the container ---
                // This avoids bind mount issues where the source file doesn't exist.
                $falco_rules_content = <<<YAML
- macro: container_entrypoint
  condition: (proc.name=docker-entrypoi or proc.name=tini)

- rule: The program "docker" is run inside a container
  desc: An event will be generated when the "docker" program is run inside a container.
  # FIX: Added 'evt.type = execve' to scope the rule to execution events, resolving the LOAD_NO_EVTTYPE error.
  # This is a critical performance and stability fix.
  condition: evt.type = execve and container.id != host and proc.name = docker and not container_entrypoint
  output: "Docker client run inside container (user=%user.name container_id=%container.id container_name=%container.name image=%container.image.repository:%container.image.tag command=%proc.cmdline)"
  priority: WARNING
YAML;
                $temp_rules_file = tempnam(sys_get_temp_dir(), 'falco_rules');
                file_put_contents($temp_rules_file, $falco_rules_content);
                stream_message("Created temporary Falco rules file.");

                // --- FIX: Implement a two-stage Falco deployment ---
                // Stage 1: Run the driver-loader to install the kernel module/eBPF probe.
                // This container will run, install the driver, and then exit.
                stream_message("Stage 1: Running falco-driver-loader to install kernel driver...");
                // --- DEFINITIVE FIX: The driver-loader needs the *exact same* volumes as the main container ---
                // It especially needs /etc to correctly identify the host OS and find kernel headers.
                $driver_loader_volumes = [
                    '/var/run/docker.sock:/host/var/run/docker.sock:ro', '/dev:/host/dev:ro',
                    '/proc:/host/proc:ro', '/boot:/host/boot:ro',
                    '/lib/modules:/host/lib/modules:ro', '/usr:/host/usr:ro',
                    '/etc:/host/etc:ro'
                ];
                $driver_loader_config = [
                    'Image' => 'falcosecurity/falco-driver-loader:latest',
                    'HostConfig' => ['Binds' => $driver_loader_volumes, 'Privileged' => true],
                    'Env' => ['DRIVER_REPO=https://download.falco.org/driver'],
                ];

                // --- FIX: Replace undefined 'runContainerAndWait' with explicit create, start, wait, and remove logic ---
                $loader_container_name = 'falco-driver-loader-init';
                try {
                    // 1. Create and start the container
                    $loader_container_id = $dockerClient->createAndStartContainer($loader_container_name, $driver_loader_config);
                    stream_message("Driver loader container '{$loader_container_name}' (ID: " . substr($loader_container_id, 0, 12) . ") started. Waiting for it to complete...");

                    // 2. Wait for the container to finish and get its exit code
                    $wait_result = $dockerClient->waitContainer($loader_container_id);
                    if ($wait_result['StatusCode'] !== 0) {
                        throw new Exception("Falco driver loader failed with exit code " . $wait_result['StatusCode'] . ". Check container logs for details.");
                    }
                    stream_message("Stage 1: Driver installation completed successfully.");
                } finally {
                    // 3. Always remove the temporary loader container, whether it succeeded or failed.
                    $dockerClient->removeContainer($loader_container_name, true);
                }

                // --- NEW: Create a dedicated network for Falco components ---
                $falco_network_name = 'falco-net'; 
                stream_message("Ensuring Falco network '{$falco_network_name}' exists...");
                $dockerClient->ensureNetworkExists($falco_network_name, false);
                stream_message("Network '{$falco_network_name}' is ready.");

                // Stage 2: Run the main Falco sensor container which now has the driver available.
                stream_message("Creating and starting Falco container...");
                // --- DEFINITIVE FIX: Use the official 'no-driver' image directly and mount the rules file ---
                // Mount the temporary rules file to a unique path inside the container to avoid conflicts
                // with existing files in the base image.
                $volumes[] = $temp_rules_file . ':/etc/falco/custom_rules.yaml:ro';

                $falco_config = [
                    'Image' => 'falcosecurity/falco-no-driver:0.38.1',
                    'Entrypoint' => ['/usr/bin/falco'], // FIX: Explicitly define the executable for the Cmd arguments.
                    // --- FIX: Provide the essential command-line arguments for Falco ---
                    // These arguments tell Falco which rule files to load and are critical for a stable startup.
                    'Cmd' => [
                        '--cri',
                        '/host/var/run/docker.sock', // Path to container runtime socket
                        '-r',
                        '/etc/falco/falco_rules.yaml', // Default rules
                        '-r',
                        '/etc/falco/custom_rules.yaml' // Our custom rules, loaded from the mounted file
                    ],
                    'HostConfig' => [
                        'Binds' => $volumes,
                        'Privileged' => true,
                        'NetworkMode' => $falco_network_name // FIX: Attach Falco to its dedicated network
                    ],
                    'Env' => ['FALCO_GRPC_ENABLED=true', 'FALCO_GRPC_BIND_ADDRESS=0.0.0.0:5060'],
                ];
                $dockerClient->createAndStartContainer($container_name, $falco_config);
            } elseif ($agent_type === 'falcosidekick') {
                // --- Deployment logic for Falcosidekick ---
                // FIX: Use a specific version for stability
                $sidekick_image = 'falcosecurity/falcosidekick:2.28.0';
                $config_manager_url = get_setting('app_base_url');
                $api_token = get_setting('health_agent_api_token');

                if (empty($config_manager_url) || empty($api_token)) {
                    throw new Exception("Application Base URL and Health Agent API Token must be configured in General Settings to deploy Falcosidekick.");
                }

                stream_message("Pulling Falcosidekick image '{$sidekick_image}'...");
                $dockerClient->pullImage($sidekick_image);
                stream_message("Image pull completed.");
                
                // --- NEW FIX: Create the config file locally and copy it into the container ---
                // This is now part of a build process to ensure the file exists before the container starts.
                $webui_url = rtrim($config_manager_url, '/') . '/api/security/ingest';
                $sidekick_config_content = <<<YAML
grpc:
  checkcert: false # Simplifies connection to Falco within the same Docker network
webui:
  url: "{$webui_url}"
  customheaders:
    X-API-KEY: "{$api_token}"
    X-FALCO-HOSTNAME: "{$host['name']}"
YAML;
                // Create a temporary directory for the build context
                $build_context_dir = rtrim(sys_get_temp_dir(), '/') . '/falcosidekick_build_' . uniqid();
                if (!mkdir($build_context_dir, 0700, true)) {
                    throw new Exception("Could not create temporary build directory.");
                }
                // Place the generated config.yaml inside the build context
                file_put_contents($build_context_dir . '/config.yaml', $sidekick_config_content);
                // --- FIX: Create a temporary file for the config instead of a directory ---
                $temp_config_file = tempnam(sys_get_temp_dir(), 'sidekick_config');
                file_put_contents($temp_config_file, $sidekick_config_content);

                // Get the Dockerfile content
                $dockerfile_path = PROJECT_ROOT . '/includes/Dockerfile.falcosidekick';
                if (!file_exists($dockerfile_path)) throw new Exception("Dockerfile for Falcosidekick not found.");
                $dockerfile_content = file_get_contents($dockerfile_path);

                // Define a unique tag for the custom image on the target host
                $custom_image_tag = 'config-manager/falcosidekick-custom:' . $host['id'];

                // Build the custom image on the target host
                stream_message("Building custom Falcosidekick image '{$custom_image_tag}' on host '{$host['name']}'...");
                $build_output = $dockerClient->buildImage($dockerfile_content, $build_context_dir, $custom_image_tag);
                // --- FIX: Pass only the Dockerfile content and the single config file path ---
                // This avoids creating a tarball of the entire temp directory.
                $build_output = $dockerClient->buildImage($dockerfile_content, $temp_config_file, $custom_image_tag);
                stream_message("Image build completed.");
                
                // --- FIX: Replace insecure shell_exec with a robust PHP-native recursive delete ---
                // This avoids spawning a shell from the web server process, which is a security risk.
                $files = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($build_context_dir, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($files as $fileinfo) {
                    $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
                    $todo($fileinfo->getRealPath());
                }
                rmdir($build_context_dir);
                stream_message("Cleaned up temporary build directory.");
                unlink($temp_config_file); // Clean up the temp config file

                // FIX: Define network name here as well to ensure it's always available.
                $falco_network_name = 'falco-net';

                stream_message("Creating and starting Falcosidekick container...");
                $sidekick_config = [
                    'Image' => $custom_image_tag, // Use the newly built image
                    'Env' => ['FALCO_GRPC_HOSTNAME=falco-sensor'], // Tell falcosidekick where to find falco
                    'Cmd' => ['-c', '/config.yaml'], // Point to the new, reliable path in the root directory
                    'HostConfig' => ['NetworkMode' => $falco_network_name]
                ];
                $dockerClient->createAndStartContainer($container_name, $sidekick_config);

                stream_message("Falcosidekick configuration complete.");

            }
            log_activity($_SESSION['username'], 'Helper Deployed', "{$container_name} deployed to host '{$host['name']}'.");
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
            $container_name = '';
            if ($agent_type === 'agent') $container_name = 'cm-health-agent';
            if ($agent_type === 'falco') $container_name = 'falco-sensor';
            if (empty($container_name)) throw new Exception("Restart is not supported for this agent type.");

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
            $container_name = '';
            if ($agent_type === 'agent') $container_name = 'cm-health-agent';
            if ($agent_type === 'falco') $container_name = 'falco-sensor';
            if ($agent_type === 'falcosidekick') $container_name = 'falcosidekick';
            if (empty($container_name)) throw new Exception("Remove is not supported for this agent type.");

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
            if ($agent_type === 'agent') {
                // Skrip sudah ada di dalam image, jadi kita bisa langsung menjalankannya.
                // Ini akan memicu satu siklus pengecekan di luar jadwal cron.
                $dockerClient->execInContainer($container_name, ['php', '/usr/src/app/agent.php']);
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
    // FIX: Define $action for GET requests (status checks) to prevent undefined variable notice
    $action = $_POST['action'] ?? 'status check';
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