<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/DockerClient.php';
require_once __DIR__ . '/../includes/Spyc.php';

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

$stack_id = $_POST['stack_id'] ?? null;
$compose_content = $_POST['compose_content'] ?? '';

if (empty($stack_id) || empty($compose_content)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Stack ID and compose content are required.']);
    exit;
}

$conn = Database::getInstance()->getConnection();

try {
    // Fetch stack and host details
    $stmt = $conn->prepare("SELECT s.*, h.name as host_name, h.docker_api_url, h.tls_enabled, h.ca_cert_path, h.client_cert_path, h.client_key_path FROM application_stacks s JOIN docker_hosts h ON s.host_id = h.id WHERE s.id = ?");
    $stmt->bind_param("i", $stack_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if (!($stack = $result->fetch_assoc())) {
        throw new Exception("Stack not found.");
    }
    $stmt->close();

    // Validate YAML content
    try {
        $compose_data = Spyc::YAMLLoad($compose_content);
    } catch (Exception $e) {
        throw new Exception("Invalid YAML format: " . $e->getMessage());
    }

    // --- NEW: Auto-correction for common 'network' vs 'networks' typo ---
    if (isset($compose_data['network']) && !isset($compose_data['networks'])) {
        $compose_data['networks'] = $compose_data['network'];
        unset($compose_data['network']);
        // Re-dump the corrected content
        $compose_content = Spyc::YAMLDump($compose_data, 2, 0);
        // Re-load to ensure the data structure is consistent for the next step
        $compose_data = Spyc::YAMLLoad($compose_content);
    }

    // --- NEW: Auto-add 'expose' key based on 'ports' for health agent compatibility ---
    if (isset($compose_data['services']) && is_array($compose_data['services'])) {
        foreach ($compose_data['services'] as $service_name => &$service_config) {
            if (isset($service_config['ports']) && is_array($service_config['ports'])) {
                if (!isset($service_config['expose'])) {
                    $service_config['expose'] = [];
                }
                foreach ($service_config['ports'] as $port_mapping) {
                    // Extract the container port (the part after the colon, or the whole string if no colon)
                    $parts = explode(':', $port_mapping);
                    $container_port = end($parts);
                    if (is_numeric($container_port) && !in_array($container_port, $service_config['expose'])) {
                        $service_config['expose'][] = (string)$container_port;
                    }
                }
            }
        }
        unset($service_config); // Unset reference
        $compose_content = Spyc::YAMLDump($compose_data, 2, 0); // Re-dump the final, corrected content
    }

    // --- NEW: Auto-add driver_opts to default network for ping compatibility ---
    if (isset($compose_data['networks']['default'])) {
        // Check if driver_opts are missing or incomplete.
        // This handles cases where 'default' is null or an empty object.
        if (!isset($compose_data['networks']['default']['driver_opts'])) {
            $compose_data['networks']['default']['driver_opts'] = [];
        }
        // Ensure both options are set to true for reliable communication.
        //$compose_data['networks']['default']['driver_opts']['com.docker.network.bridge.enable_icc'] = true;
        //$compose_data['networks']['default']['driver_opts']['com.docker.network.bridge.enable_ip_masquerade'] = true;
        
        // Re-dump the content with the added driver_opts.
        $compose_content = Spyc::YAMLDump($compose_data, 2, 0);
    }

    // --- NEW: Sync manual edits back to the database deployment_details ---
    // This ensures the "Update Stack" form reflects the latest changes.
    try {
        // Re-parse the final, corrected compose data
        $final_compose_data = Spyc::YAMLLoad($compose_content);

        // Fetch existing details from DB
        $stmt_get_details = $conn->prepare("SELECT deployment_details FROM application_stacks WHERE id = ?");
        $stmt_get_details->bind_param("i", $stack_id);
        $stmt_get_details->execute();
        $existing_details_json = $stmt_get_details->get_result()->fetch_assoc()['deployment_details'];
        $stmt_get_details->close();
        $existing_details = json_decode($existing_details_json, true) ?: [];

        // Extract key values from the new compose file
        $services = (is_array($final_compose_data) && isset($final_compose_data['services']) && is_array($final_compose_data['services'])) ? $final_compose_data['services'] : null;
        
        // Always update the source type and raw content

        // Only update other details if we can parse the first service
        if ($services && ($first_service_name = array_key_first($services))) {
            $first_service = $services[$first_service_name];

            // Update only if the key exists in the parsed YAML.
            // Do NOT change the source_type here.
            if (isset($first_service['image'])) $existing_details['image_name_hub'] = $first_service['image'];
            if (isset($first_service['deploy']['replicas'])) $existing_details['deploy_replicas'] = $first_service['deploy']['replicas'];
            if (isset($first_service['deploy']['resources']['limits']['cpus'])) $existing_details['deploy_cpu'] = $first_service['deploy']['resources']['limits']['cpus'];
            if (isset($first_service['deploy']['resources']['limits']['memory'])) $existing_details['deploy_memory'] = $first_service['deploy']['resources']['limits']['memory'];
            if (isset($first_service['deploy']['placement']['constraints'][0])) $existing_details['deploy_placement_constraint'] = $first_service['deploy']['placement']['constraints'][0];
            // Handle standalone resource limits
            if (isset($first_service['cpus'])) $existing_details['deploy_cpu'] = $first_service['cpus'];
            if (isset($first_service['mem_limit'])) $existing_details['deploy_memory'] = $first_service['mem_limit'];

            // Update port mapping
            if (empty($first_service['ports'])) {
                unset($existing_details['host_port'], $existing_details['container_port']);
            } elseif (isset($first_service['ports'][0])) {
                $port_parts = explode(':', $first_service['ports'][0]);
                $existing_details['host_port'] = count($port_parts) > 1 ? $port_parts[0] : '';
                $existing_details['container_port'] = end($port_parts);
            }
        // This part is outside the if block to ensure source_type and compose_content are always saved.
        }
        // Update the database with the potentially modified details
        $updated_details_json = json_encode($existing_details, JSON_UNESCAPED_SLASHES);
        $stmt_update_details = $conn->prepare("UPDATE application_stacks SET deployment_details = ? WHERE id = ?");
        $stmt_update_details->bind_param("si", $updated_details_json, $stack_id);
        $stmt_update_details->execute();
        $stmt_update_details->close();

    } catch (Exception $sync_e) {
        // Log the error but don't stop the deployment process
        error_log("Config Manager: Failed to sync compose edits to DB for stack ID {$stack_id}. Error: " . $sync_e->getMessage());
    }

    // Construct the path to the docker-compose.yml file
    $base_compose_path = get_setting('default_compose_path', '');
    $safe_host_name = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $stack['host_name']);
    $deployment_dir = rtrim($base_compose_path, '/') . '/' . $safe_host_name . '/' . $stack['stack_name'];
    $compose_file_path = $deployment_dir . '/docker-compose.yml';

    if (!is_dir($deployment_dir) || !is_writable($deployment_dir)) {
        throw new Exception("Deployment directory is not writable or does not exist: " . $deployment_dir);
    }

    // Overwrite the file
    if (file_put_contents($compose_file_path, $compose_content) === false) {
        throw new Exception("Failed to save the compose file.");
    }

    // --- Redeploy the stack ---
    $dockerClient = new DockerClient($stack); // Pass the whole array which includes host details
    $dockerInfo = $dockerClient->getInfo();
    $is_swarm_manager = (isset($dockerInfo['Swarm']['ControlAvailable']) && $dockerInfo['Swarm']['ControlAvailable'] === true);

    // Prepare environment variables for the remote docker-compose command
    $env_vars = "DOCKER_HOST=" . escapeshellarg($stack['docker_api_url']) . " COMPOSE_NONINTERACTIVE=1";
    if ($stack['tls_enabled']) {
        $cert_path_dir = $deployment_dir . '/certs';
        if (!is_dir($cert_path_dir)) {
            // If certs dir doesn't exist, try to create it and copy certs
            if (!mkdir($cert_path_dir, 0700, true)) throw new Exception("Could not create cert directory in {$deployment_dir}.");
            copy($stack['ca_cert_path'], $cert_path_dir . '/ca.pem');
            copy($stack['client_cert_path'], $cert_path_dir . '/cert.pem');
            copy($stack['client_key_path'], $cert_path_dir . '/key.pem');
        }
        $env_vars .= " DOCKER_TLS_VERIFY=1 DOCKER_CERT_PATH=" . escapeshellarg($cert_path_dir);
    }

    $cd_command = "cd " . escapeshellarg($deployment_dir);

    if ($is_swarm_manager) {
        $main_compose_command = "docker stack deploy -c docker-compose.yml " . escapeshellarg($stack['stack_name']) . " --with-registry-auth --prune 2>&1";
    } else {
        $main_compose_command = "docker compose -p " . escapeshellarg($stack['stack_name']) . " up -d --force-recreate --remove-orphans 2>&1";
    }

    $full_command = 'env ' . $env_vars . ' sh -c ' . escapeshellarg($cd_command . ' && ' . $main_compose_command);

    exec($full_command, $output, $return_var);

    if ($return_var !== 0) {
        throw new Exception("Redeployment failed. Output: " . implode("\n", $output));
    }

    // Log the change
    log_activity($_SESSION['username'], 'Stack Compose Edited', "Manually edited and redeployed stack '{$stack['stack_name']}' on host '{$stack['host_name']}'.");

    echo json_encode([
        'status' => 'success', 
        'message' => "Stack '{$stack['stack_name']}' has been successfully saved and redeployed.",
        'redirect' => base_url('/hosts/' . $stack['host_id'] . '/stacks')
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>