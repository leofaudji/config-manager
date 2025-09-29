<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/GitHelper.php';
require_once __DIR__ . '/../includes/DockerClient.php';
require_once __DIR__ . '/../includes/DockerComposeParser.php';
require_once __DIR__ . '/../includes/AppLauncherHelper.php';
require_once __DIR__ . '/../includes/Spyc.php';

$conn = Database::getInstance()->getConnection();
$repo_path = null;
$temp_dir = null;
$docker_config_dir = null;
$git = new GitHelper();

// --- Streaming Setup ---
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

function stream_message($message, $type = 'INFO') {
    echo date('[Y-m-d H:i:s]') . " [{$type}] " . htmlspecialchars(trim($message)) . "\n";
}

function stream_exec($command, &$return_var) {
    $handle = popen($command . ' 2>&1', 'r');
    if ($handle === false) {
        stream_message("FATAL: Failed to execute command.", 'ERROR');
        $return_var = -1;
        return;
    }
    while (($line = fgets($handle)) !== false) {
        echo rtrim($line) . "\n";
    }
    $return_var = pclose($handle);
}

try {
    stream_message("Deployment process initiated...");

    // --- Input Validation ---
    $host_id = $_POST['host_id'] ?? null;
    $stack_name = strtolower(trim($_POST['stack_name'] ?? ''));
    $source_type = $_POST['source_type'] ?? 'git';
    $git_url = trim($_POST['git_url'] ?? '');
    $git_branch = trim($_POST['git_branch'] ?? 'main');
    $compose_path = trim($_POST['compose_path'] ?? '');
    $build_from_dockerfile = isset($_POST['build_from_dockerfile']) && $_POST['build_from_dockerfile'] === '1';

    // Resource settings
    $replicas = !empty($_POST['deploy_replicas']) ? (int)$_POST['deploy_replicas'] : null;
    $cpu = !empty($_POST['deploy_cpu']) ? $_POST['deploy_cpu'] : null;
    $memory = !empty($_POST['deploy_memory']) ? $_POST['deploy_memory'] : null;
    $network = !empty($_POST['network_name']) ? $_POST['network_name'] : null;
    $volume_paths = isset($_POST['volume_paths']) && is_array($_POST['volume_paths']) ? $_POST['volume_paths'] : [];

    // Port mapping settings
    $host_port = !empty($_POST['host_port']) ? (int)$_POST['host_port'] : null;
    $container_port = !empty($_POST['container_port']) ? (int)$_POST['container_port'] : null;
    $container_ip = !empty($_POST['container_ip']) ? trim($_POST['container_ip']) : null;
    $deploy_placement_constraint = !empty($_POST['deploy_placement_constraint']) ? trim($_POST['deploy_placement_constraint']) : null;
    $is_update = isset($_POST['update_stack']) && $_POST['update_stack'] === 'true';

    // Autoscaling settings
    $autoscaling_enabled = isset($_POST['autoscaling_enabled']) ? 1 : 0;
    $autoscaling_min_replicas = !empty($_POST['autoscaling_min_replicas']) ? (int)$_POST['autoscaling_min_replicas'] : 1;
    $autoscaling_max_replicas = !empty($_POST['autoscaling_max_replicas']) ? (int)$_POST['autoscaling_max_replicas'] : 1;
    $autoscaling_cpu_threshold_up = !empty($_POST['autoscaling_cpu_threshold_up']) ? (int)$_POST['autoscaling_cpu_threshold_up'] : 80;
    $autoscaling_cpu_threshold_down = !empty($_POST['autoscaling_cpu_threshold_down']) ? (int)$_POST['autoscaling_cpu_threshold_down'] : 20;

    if (empty($host_id) || empty($stack_name)) {
        throw new Exception("Host and Stack Name are required.");
    }
    stream_message("Validating stack name '{$stack_name}'...");

    // Validate stack name format to match Docker's project name constraints
    if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $stack_name)) {
        throw new Exception("Invalid Stack Name. It must start with a letter or number and can only contain letters, numbers, underscores, periods, or hyphens.");
    }

    // Validate that if volume mappings are provided, the container path is not empty
    if (!empty($volume_paths)) {
        foreach ($volume_paths as $index => $volume_map) {
            if (empty(trim($volume_map['container'] ?? ''))) {
                throw new Exception("Container Path is required for all volume mappings. Please check volume mapping #" . ($index + 1) . ".");
            }
        }
    }

    $form_params = [
        'stack_name' => $stack_name,
        'replicas' => $replicas,
        'cpu' => $cpu,
        'memory' => $memory,
        'network' => $network,
        'volume_paths' => $volume_paths,
        'host_port' => $host_port,
        'container_port' => $container_port,
        'container_ip' => $container_ip,
        'deploy_placement_constraint' => $deploy_placement_constraint,
    ];

    $compose_content = '';

    stream_message("Fetching details for host ID: {$host_id}...");
    // --- Get Host Details ---
    $stmt = $conn->prepare("SELECT * FROM docker_hosts WHERE id = ?");
    $stmt->bind_param("i", $host_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if (!($host = $result->fetch_assoc())) {
        throw new Exception("Host not found.");
    }
    $stmt->close();

    stream_message("Pinging host '{$host['name']}' to check type (Swarm/Standalone)...");
    // --- Check Host Type (before generating compose file) ---
    $dockerClient = new DockerClient($host);
    $dockerInfo = $dockerClient->getInfo();
    $is_swarm_manager = (isset($dockerInfo['Swarm']['ControlAvailable']) && $dockerInfo['Swarm']['ControlAvailable'] === true);
    stream_message("Host is a " . ($is_swarm_manager ? "Swarm Manager" : "Standalone Host") . ".");

    stream_message("Checking for existing stacks with the same name...");
    // --- Pre-deployment check: Verify stack name is not already in use ---
    $stackExists = false;
    if ($is_swarm_manager) {
        $services = $dockerClient->listServices();
        foreach ($services as $service) {
            if (($service['Spec']['Labels']['com.docker.stack.namespace'] ?? null) === $stack_name) {
                $stackExists = true;
                break;
            }
        }
    } else {
        $containers = $dockerClient->listContainers();
        foreach ($containers as $container) {
            if (($container['Labels']['com.docker.compose.project'] ?? null) === $stack_name) {
                $stackExists = true;
                break;
            }
        }
    }

    if ($stackExists && !$is_update) {
        throw new Exception("A stack with the name '{$stack_name}' already exists on the host '{$host['name']}'. Please choose a different name.");
    }
    stream_message("Stack name is available.");

    // --- Pre-deployment check for Swarm: Check for ingress network if a port is being published ---
    if ($is_swarm_manager && $host_port) {
        stream_message("Checking for Swarm ingress network...");
        $networks = $dockerClient->listNetworks();
        $ingress_found = false;
        foreach ($networks as $network) {
            if (isset($network['Ingress']) && $network['Ingress'] === true) {
                $ingress_found = true;
                break;
            }
        }
        if (!$ingress_found) {
            throw new Exception("Swarm ingress network not found. This is required for publishing ports. Please ensure your Swarm cluster is healthy. You may need to run 'docker swarm init --force-new-cluster' on your manager node to recreate it.");
        }
        stream_message("Swarm ingress network found.");
    }

    // --- Pre-deployment check for Swarm: Verify host port is not already in use by another service ---
    if ($is_swarm_manager && $host_port) {
        stream_message("Checking for port conflicts on Swarm ingress network...");
        $all_services = $dockerClient->listServices();
        foreach ($all_services as $service) {
            // Skip services belonging to the stack we are currently updating
            if (($service['Spec']['Labels']['com.docker.stack.namespace'] ?? null) === $stack_name) {
                continue;
            }
            if (isset($service['Spec']['EndpointSpec']['Ports'])) {
                foreach ($service['Spec']['EndpointSpec']['Ports'] as $port_spec) {
                    if (isset($port_spec['PublishedPort']) && $port_spec['PublishedPort'] == $host_port) {
                        $conflicting_service_name = $service['Spec']['Name'];
                        throw new Exception("Deployment failed: Port {$host_port} is already in use by service '{$conflicting_service_name}' on the Swarm ingress network. Please choose a different host port.");
                    }
                }
            }
        }
        stream_message("No port conflicts found.");
    }

    // --- Generate Compose Content ---
    stream_message("Generating compose file content based on source type: {$source_type}...");
    if ($source_type === 'git') {
        if (empty($git_url)) throw new Exception("Git URL is required for Git-based deployment.");
        $is_ssh = str_starts_with($git_url, 'git@');
        $is_https = str_starts_with($git_url, 'https://');
        if (!$is_ssh && !$is_https) throw new Exception("Invalid Git URL format. Please use SSH (git@...) or HTTPS (https://...) format.");

        stream_message("Cloning repository '{$git_url}' (branch: {$git_branch})...");
        $repo_path = $git->cloneOrPull($git_url, $git_branch);

        // --- Find the correct compose file with fallback ---
        $final_compose_path = '';
        $paths_to_try = [];
        // 1. Path from form input
        if (!empty($compose_path)) $paths_to_try[] = $compose_path;
        // 2. Path from global settings
        if (!empty(get_setting('default_git_compose_path'))) $paths_to_try[] = get_setting('default_git_compose_path');
        // 3. Final fallback
        $paths_to_try[] = 'docker-compose.yml';
        $paths_to_try = array_unique($paths_to_try);

        foreach ($paths_to_try as $path) {
            if (file_exists($repo_path . '/' . $path)) {
                $final_compose_path = $path;
                break; // Found it
            }
        }
        stream_message("Using compose file: '{$final_compose_path}'");

        if (empty($final_compose_path)) {
            throw new Exception("Compose file not found. Tried: " . implode(', ', array_map(fn($p) => "'$p'", $paths_to_try)));
        }
        // --- End of find logic ---
        $compose_file_full_path = $repo_path . '/' . $final_compose_path;
        $base_compose_content = file_get_contents($compose_file_full_path);
        if ($base_compose_content === false) throw new Exception("Could not read compose file '{$final_compose_path}'.");

        $compose_data = DockerComposeParser::YAMLLoad($base_compose_content);
        AppLauncherHelper::applyFormSettings($compose_data, $form_params, $host, $is_swarm_manager);

        $compose_content = Spyc::YAMLDump($compose_data, 2, 0);
    } elseif ($source_type === 'image' || $source_type === 'hub') {
        $image_name = '';
        if ($source_type === 'image') {
            $image_name = $_POST['image_name_local'] ?? '';
            if (empty($image_name)) throw new Exception("Image Name from local host is required.");
        } else { // hub
            $image_name = $_POST['image_name_hub'] ?? '';
            if (empty($image_name)) throw new InvalidArgumentException("Image Name from Docker Hub is required.");
        }
        
        $form_params['image_name'] = $image_name;

        $compose_data = [
            'version' => '3.8',
            'services' => [
                $stack_name => ['image' => $image_name]
            ]
        ];
        AppLauncherHelper::applyFormSettings($compose_data, $form_params, $host, $is_swarm_manager);
        if (empty($compose_data['networks'])) unset($compose_data['networks']);

        $compose_content = Spyc::YAMLDump($compose_data, 2, 0);
    } elseif ($source_type === 'editor') {
        $compose_content_from_editor = $_POST['compose_content_editor'] ?? '';
        if (empty($compose_content_from_editor)) {
            throw new Exception("Compose content from editor is required.");
        }
        stream_message("Processing YAML from editor...");

        // For standalone hosts, we need to ensure a restart policy exists for reliability.
        // Instead of parsing and re-dumping (which can break formatting), we'll inject it via string manipulation.
        $lines = explode("\n", $compose_content_from_editor);
        $num_lines = count($lines);
        $processed_lines = [];
        $i = 0;
        while ($i < $num_lines) {
            $line = $lines[$i];
            
            // Find the start of the services block
            if (preg_match('/^services:/', ltrim($line))) {
                $processed_lines[] = $line;
                $i++;
                $service_indent = strlen($line) - strlen(ltrim($line));
                
                // Now process all services until we leave the block
                while ($i < $num_lines) {
                    $service_line = $lines[$i];
                    $service_line_indent = strlen($service_line) - strlen(ltrim($service_line));
                    
                    // If we are out of the services block, break this inner loop
                    if ($service_line_indent <= $service_indent && trim($service_line) !== '') {
                        break;
                    }
                    
                    // Is this a service definition?
                    if ($service_line_indent > $service_indent && preg_match('/^\s*([a-zA-Z0-9_-]+):/', $service_line)) {
                        // Yes. Scan its block to see if a restart policy exists.
                        $service_block = [$service_line];
                        $has_restart = false;
                        $j = $i + 1;
                        while ($j < $num_lines) {
                            $lookahead_line = $lines[$j];
                            $lookahead_indent = strlen($lookahead_line) - strlen(ltrim($lookahead_line));
                            
                            if ($lookahead_indent <= $service_line_indent && trim($lookahead_line) !== '') break;
                            if (preg_match('/^\s+restart:/', $lookahead_line)) $has_restart = true;
                            
                            $service_block[] = $lookahead_line;
                            $j++;
                        }
                        
                        // Add the service name line
                        $processed_lines[] = $service_block[0];
                        // Inject restart policy if it wasn't found and host is standalone
                        if (!$has_restart && !$is_swarm_manager) {
                            $processed_lines[] = str_repeat(' ', $service_line_indent + 2) . 'restart: unless-stopped';
                        }
                        // Add the rest of the original block
                        for ($k = 1; $k < count($service_block); $k++) {
                            $processed_lines[] = $service_block[$k];
                        }
                        
                        // Advance the main counter past this block
                        $i = $j;
                    } else {
                        // Not a service definition, just a line inside the services block (e.g., a comment)
                        $processed_lines[] = $service_line;
                        $i++;
                    }
                }
            } else {
                // Not in services block yet, just copy the line
                $processed_lines[] = $line;
                $i++;
            }
        }
        $compose_content = implode("\n", $processed_lines);
    } else {
        throw new Exception("Invalid source type specified.");
    }
    stream_message("Compose file content generated successfully.");

    // --- Validate Container Names for Standalone Host ---
    if (!$is_swarm_manager) {
        stream_message("Validating container names for standalone host...");
        // Use the $compose_data array directly instead of re-parsing the YAML string.
        // This is more efficient and avoids potential parsing issues.
        $services_to_deploy = $compose_data['services'] ?? [];

        if (!empty($services_to_deploy)) {
            $existing_containers = $dockerClient->listContainers();
            $existing_container_names = [];
            foreach ($existing_containers as $container) {
                $name = ltrim($container['Names'][0] ?? '', '/');
                $project = $container['Labels']['com.docker.compose.project'] ?? null;
                $existing_container_names[$name] = $project;
            }

            foreach ($services_to_deploy as $service_data) {
                $target_container_name = $service_data['container_name'] ?? null;
                if ($target_container_name && isset($existing_container_names[$target_container_name]) && $existing_container_names[$target_container_name] !== $stack_name) {
                    throw new Exception("Container name '{$target_container_name}' is already in use on this host by another project ('{$existing_container_names[$target_container_name]}'). Please choose a different stack name.");
                }
            }
        }
        stream_message("Container names are valid.");
    }

    // --- Deployment ---
    if ($is_swarm_manager) {
        $deployment_dir = '';
        $compose_file_name = ''; // Relative path to the compose file within the deployment_dir
        $base_compose_path = get_setting('default_compose_path', '');
        $is_persistent_path = !empty($base_compose_path);

        stream_message("Preparing deployment directory...");
        if ($source_type === 'git') {
            // For Git source, we need the entire repository content, not just the compose file.
            // $repo_path holds the path to the temporarily cloned repo.
            if ($is_persistent_path) {
                $safe_host_name = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $host['name']);
                $deployment_dir = rtrim($base_compose_path, '/') . '/' . $safe_host_name . '/' . $stack_name;
                // Ensure a clean state by removing the old directory if it exists.
                if (is_dir($deployment_dir)) {
                    shell_exec("rm -rf " . escapeshellarg($deployment_dir));
                }
                // Use a recursive copy for cross-device compatibility, as rename() can fail.
                // First, create the destination directory.
                if (!mkdir($deployment_dir, 0755, true) && !is_dir($deployment_dir)) {
                    throw new \RuntimeException(sprintf('Deployment directory "%s" could not be created.', $deployment_dir));
                }
                if (file_exists($deployment_dir) && !is_dir($deployment_dir)) {
                    throw new \RuntimeException(sprintf('Cannot create deployment directory "%s" because a file with that name already exists.', $deployment_dir));
                }
                if (!is_dir($deployment_dir) && !@mkdir($deployment_dir, 0755, true) && !is_dir($deployment_dir)) {
                    throw new \RuntimeException(sprintf('Deployment directory "%s" could not be created. Check permissions.', $deployment_dir));
                }
                // Then, copy the contents of the cloned repo.
                exec("cp -a " . escapeshellarg($repo_path . '/.') . " " . escapeshellarg($deployment_dir), $output, $return_var);
                if ($return_var !== 0) throw new Exception("Failed to copy repository to deployment directory. Output: " . implode("\n", $output));
                // The original temporary repo at $repo_path will be cleaned up by the 'finally' logic.
            } else {
                // Use the temporary cloned repo path directly for deployment.
                $deployment_dir = $repo_path; // This is a temporary path
                $temp_dir = $deployment_dir; // Mark it for cleanup.
                $repo_path = null; // Prevent double cleanup.
            }
            // The compose file is inside the deployment directory. We just need its full path.
            $compose_file_name = $final_compose_path; // Use the path that was actually found
            $compose_file_full_path = $deployment_dir . '/' . $compose_file_name;
            // Overwrite the original compose file with the one modified by the form settings.
            if (file_put_contents($compose_file_full_path, $compose_content) === false) throw new Exception("Could not write modified compose file to: " . $compose_file_full_path);

        } else {
            // For 'image', 'hub', or 'editor' source, we only need to create a directory with a single compose file.
            if ($is_persistent_path) {
                $safe_host_name = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $host['name']);
                $deployment_dir = rtrim($base_compose_path, '/') . '/' . $safe_host_name . '/' . $stack_name;
            } else {
                $deployment_dir = rtrim(sys_get_temp_dir(), '/') . '/app_launcher_' . uniqid();
                $temp_dir = $deployment_dir; // Mark for cleanup.
            }
            if (!is_dir($deployment_dir) && !mkdir($deployment_dir, 0755, true)) throw new Exception("Could not create deployment directory: {$deployment_dir}.");
            if (file_exists($deployment_dir) && !is_dir($deployment_dir)) {
                throw new Exception("Cannot create deployment directory '{$deployment_dir}' because a file with that name already exists.");
            }
            if (!is_dir($deployment_dir) && !@mkdir($deployment_dir, 0755, true) && !is_dir($deployment_dir)) {
                throw new Exception("Could not create deployment directory: {$deployment_dir}. Check permissions.");
            }
            $compose_file_name = 'docker-compose.yml';
            $compose_file_full_path = $deployment_dir . '/' . $compose_file_name;
            if (file_put_contents($compose_file_full_path, $compose_content) === false) throw new Exception("Could not write compose file to: " . $compose_file_full_path);
        }

        stream_message("Configuring remote Docker environment...");
        // This logic is now shared between Standalone and Swarm
        $env_vars = "DOCKER_HOST=" . escapeshellarg($host['docker_api_url']);
        if ($host['tls_enabled']) {
            $env_vars .= " DOCKER_TLS_VERIFY=1";
            $cert_path_dir = $deployment_dir . '/certs';
            if (!is_dir($cert_path_dir) && !mkdir($cert_path_dir, 0700, true)) throw new Exception("Could not create cert directory in {$deployment_dir}.");
            if (!is_dir($cert_path_dir) && !@mkdir($cert_path_dir, 0700, true) && !is_dir($cert_path_dir)) {
                throw new Exception("Could not create cert directory in {$deployment_dir}.");
            }
            
            if (!file_exists($host['ca_cert_path'])) throw new Exception("CA certificate not found at: {$host['ca_cert_path']}");
            if (!file_exists($host['client_cert_path'])) throw new Exception("Client certificate not found at: {$host['client_cert_path']}");
            if (!file_exists($host['client_key_path'])) throw new Exception("Client key not found at: {$host['client_key_path']}");

            // Copy certs to the deployment directory
            copy($host['ca_cert_path'], $cert_path_dir . '/ca.pem');
            copy($host['client_cert_path'], $cert_path_dir . '/cert.pem');
            copy($host['client_key_path'], $cert_path_dir . '/key.pem');

            $env_vars .= " DOCKER_CERT_PATH=" . escapeshellarg($cert_path_dir);
        }

        // Set compose to non-interactive mode to prevent it from hanging on prompts.
        $env_vars .= " COMPOSE_NONINTERACTIVE=1";

        stream_message("Checking for private registry credentials...");
        // Prepare docker login command if registry credentials are provided
        $login_command = '';
        if (!empty($host['registry_username']) && !empty($host['registry_password'])) {
            // Use a persistent path from .env if available, otherwise use a temporary one.
            $docker_config_path_from_env = Config::get('DOCKER_CONFIG_PATH');
            if (!empty($docker_config_path_from_env)) {
                if (!is_dir($docker_config_path_from_env) && !mkdir($docker_config_path_from_env, 0755, true)) {
                    throw new Exception("Could not create specified DOCKER_CONFIG_PATH: {$docker_config_path_from_env}. Check permissions.");
                }
                if (file_exists($docker_config_path_from_env) && !is_dir($docker_config_path_from_env)) {
                    throw new Exception("Cannot create DOCKER_CONFIG_PATH '{$docker_config_path_from_env}' because a file with that name already exists.");
                }
                if (!is_dir($docker_config_path_from_env) && !@mkdir($docker_config_path_from_env, 0755, true) && !is_dir($docker_config_path_from_env)) {
                    throw new Exception("Could not create specified DOCKER_CONFIG_PATH: {$docker_config_path_from_env}. Check permissions on the parent directory.");
                }
                $docker_config_dir = $docker_config_path_from_env;
            } else {
                $docker_config_dir = rtrim(sys_get_temp_dir(), '/') . '/docker_config_' . uniqid();
                if (!mkdir($docker_config_dir, 0700, true)) throw new Exception("Could not create temporary docker config directory.");
                if (!is_dir($docker_config_dir) && !@mkdir($docker_config_dir, 0700, true) && !is_dir($docker_config_dir)) {
                    throw new Exception("Could not create temporary docker config directory.");
                }
            }
            $env_vars .= " DOCKER_CONFIG=" . escapeshellarg($docker_config_dir);

            $registry_url = !empty($host['registry_url']) ? escapeshellarg($host['registry_url']) : '';
            // The login command is chained before the pull command.
            $login_command = "echo " . escapeshellarg($host['registry_password']) . " | docker login {$registry_url} -u " . escapeshellarg($host['registry_username']) . " --password-stdin 2>&1 && ";
        }

        stream_message("Preparing docker-compose commands...");
        // Change directory to the deployment dir before running docker-compose
        // This ensures relative paths (like `build: .`) in the compose file work correctly.
        $cd_command = "cd " . escapeshellarg($deployment_dir);

        stream_message("Checking for volume creation request...");
        $mkdir_command = '';
        // This logic is only for standalone hosts. Swarm manages volumes differently.
        if (!empty($volume_paths) && is_array($volume_paths) && !$is_swarm_manager) {
            $mkdir_commands = [];
            $default_volume_path_on_host = $host['default_volume_path'] ?? null;

            if (empty($default_volume_path_on_host)) {
                stream_message("Skipping remote volume directory creation because 'Default Volume Path' is not set for this host.", 'WARN');
            } else {
                foreach ($volume_paths as $volume_map) {
                    $host_path = $volume_map['host'] ?? null;
                    if ($host_path) {
                        // Ensure the host path is within the allowed default volume path for security
                        if (strpos($host_path, $default_volume_path_on_host) !== 0) {
                            stream_message("Volume path '{$host_path}' is outside the host's default volume path '{$default_volume_path_on_host}'. Skipping creation for security.", 'WARN');
                            continue;
                        }
                        // This is the new, more robust command. It mounts the configured base path and creates the full subdirectory structure inside.
                        $mkdir_commands[] = "docker run --rm -v " . escapeshellarg($default_volume_path_on_host . ':/data') . " alpine mkdir -p " . escapeshellarg('/data/' . ltrim(substr($host_path, strlen($default_volume_path_on_host)), '/'));
                    }
                }
            }
            if (!empty($mkdir_commands)) {
                // Use array_unique to prevent running the same mkdir command multiple times if paths overlap
                $mkdir_command = implode(' 2>&1 && ', array_unique($mkdir_commands)) . ' 2>&1 && ';
            }
        }

        // --- Auto-detect if a build is needed by inspecting the final compose content ---
        $compose_data_for_build_check = DockerComposeParser::YAMLLoad($compose_content);
        $is_build_required = false;
        if (isset($compose_data_for_build_check['services']) && is_array($compose_data_for_build_check['services'])) {
            foreach ($compose_data_for_build_check['services'] as $service_config) {
                if (isset($service_config['build'])) {
                    $is_build_required = true;
                    break;
                }
            }
        }

        if ($is_swarm_manager) {
            stream_message("Host is a Swarm Manager. Preparing 'docker stack deploy' command...");
            $main_compose_command = "docker stack deploy -c " . escapeshellarg($compose_file_name) . " " . escapeshellarg($stack_name) . " --with-registry-auth --prune 2>&1";
            // For Swarm, we don't need to explicitly pull. The --with-registry-auth flag handles it.
            // However, if a build is required, we must build first.
            if ($is_build_required) {
                stream_message("Build directive detected. Running 'docker-compose build' before deployment...");
                $build_command = "docker-compose -f " . escapeshellarg($compose_file_name) . " build --pull 2>&1";
                $main_compose_command = $build_command . " && " . $main_compose_command;
            }
        } else {
            // --- Standalone Host Deployment ---
            stream_message("Host is Standalone. Preparing 'docker-compose' commands...");
            $compose_up_command = "docker-compose -p " . escapeshellarg($stack_name) . " -f " . escapeshellarg($compose_file_name) . " up -d --force-recreate --remove-orphans --renew-anon-volumes 2>&1";
            // For Standalone, we always try to pull first to get the latest image.
            $main_compose_command = "docker-compose -p " . escapeshellarg($stack_name) . " -f " . escapeshellarg($compose_file_name) . " pull 2>&1 && " . $compose_up_command;
        }

        $script_to_run = $cd_command . ' && ' . $login_command . $mkdir_command . $main_compose_command;
        $full_command = 'env ' . $env_vars . ' sh -c ' . escapeshellarg($script_to_run);

        stream_message("Executing deployment script on remote host...");
        stream_exec($full_command, $return_var);

        // Cleanup temporary docker config directory immediately after use
        if (empty(Config::get('DOCKER_CONFIG_PATH')) && isset($docker_config_dir) && is_dir($docker_config_dir)) {
             shell_exec("rm -rf " . escapeshellarg($docker_config_dir));
        }

        stream_message("Checking deployment result...");
        if ($return_var !== 0) {
            throw new Exception("Docker-compose deployment failed. Check log for details.");
        }

        stream_message("Deployment command executed successfully.");
    }

    // --- Prepare deployment details for saving ---
    stream_message("Saving deployment configuration to database...");
    $log_details = "Launched app '{$stack_name}' on host '{$host['name']}'. Source: {$source_type}.";

    $deployment_details_to_save = $_POST; // Start with all POST data
    // Unset fields we don't want to store or that are large/irrelevant for re-deployment
    unset($deployment_details_to_save['host_id']);
    unset($deployment_details_to_save['volume_paths']); // Unset the volume paths array to prevent conversion warnings
    unset($deployment_details_to_save['env_vars']); // Unset environment variables array
    $deployment_details_json = json_encode($deployment_details_to_save, JSON_UNESCAPED_SLASHES);

    // --- Record deployment in the database ---
    // This query now handles both create and update scenarios for autoscaling settings.
    $compose_file_to_save = '';
    if ($source_type === 'git') {
        // $final_compose_path is defined in the git source type block
        $compose_file_to_save = $final_compose_path ?? 'docker-compose.yml';
    } else { // image or hub
        $compose_file_to_save = 'docker-compose.yml';
    }

    $stmt_stack = $conn->prepare(
        "INSERT INTO application_stacks (host_id, stack_name, source_type, compose_file_path, deployment_details, autoscaling_enabled, autoscaling_min_replicas, autoscaling_max_replicas, autoscaling_cpu_threshold_up, autoscaling_cpu_threshold_down, deploy_placement_constraint) 
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE 
            source_type = VALUES(source_type), 
            compose_file_path = VALUES(compose_file_path), 
            deployment_details = VALUES(deployment_details), 
            autoscaling_enabled = VALUES(autoscaling_enabled), autoscaling_min_replicas = VALUES(autoscaling_min_replicas), autoscaling_max_replicas = VALUES(autoscaling_max_replicas), 
            autoscaling_cpu_threshold_up = VALUES(autoscaling_cpu_threshold_up), autoscaling_cpu_threshold_down = VALUES(autoscaling_cpu_threshold_down),
            deploy_placement_constraint = VALUES(deploy_placement_constraint),
            updated_at = NOW()"
    );
    // Ensure that a null value for placement constraint is handled correctly.
    // bind_param doesn't like nulls for 's' type, so we provide an empty string as a fallback.
    $placement_to_save = $deploy_placement_constraint ?? '';

    $stmt_stack->bind_param("issssiiiiis", $host_id, $stack_name, $source_type, $compose_file_to_save, $deployment_details_json, $autoscaling_enabled, $autoscaling_min_replicas, $autoscaling_max_replicas, $autoscaling_cpu_threshold_up, $autoscaling_cpu_threshold_down, $placement_to_save);
    $stmt_stack->execute();
    $stmt_stack->close();

    // Log the change to the new stack_change_log table
    $change_type = $is_update ? 'updated' : 'created';
    
    // Build a more descriptive details string
    $details_parts = [];
    if ($source_type === 'git') {
        $details_parts[] = "Source: Git repo '{$git_url}' (branch: {$git_branch})";
        if ($build_from_dockerfile) {
            $details_parts[] = "Built from Dockerfile.";
        }
    } elseif ($source_type === 'image') {
        $details_parts[] = "Source: Host image '" . ($_POST['image_name_local'] ?? 'N/A') . "'";
    } elseif ($source_type === 'hub') {
        $details_parts[] = "Source: Docker Hub image '" . ($_POST['image_name_hub'] ?? 'N/A') . "'";
    } elseif ($source_type === 'editor') {
        $details_parts[] = "Source: From Editor";
    }

    if ($replicas) $details_parts[] = "Replicas: {$replicas}";
    if ($cpu) $details_parts[] = "CPU: {$cpu}";
    if ($memory) $details_parts[] = "Memory: {$memory}";
    if ($network) $details_parts[] = "Network: {$network}";
    if ($host_port && $container_port) $details_parts[] = "Port: {$host_port}:{$container_port}";

    if (!empty($volume_paths)) {
        $vol_count = count($volume_paths);
        $details_parts[] = "{$vol_count} volume(s) mapped.";
    }

    // Sanitize details for logging to prevent array to string conversion
    $log_details_for_stack_change = array_map(function($item) {
        return is_array($item) ? json_encode($item) : $item;
    }, $details_parts);
    $log_details_for_stack_change = implode(' | ', $log_details_for_stack_change);
    if (empty($log_details_for_stack_change)) {
        $log_details_for_stack_change = ($is_update ? "Stack configuration updated." : "New stack deployed.");
    }

    $stmt_log_change = $conn->prepare(
        "INSERT INTO stack_change_log (host_id, stack_name, change_type, details, changed_by) VALUES (?, ?, ?, ?, ?)"
    );
    $changed_by = $_SESSION['username'] ?? 'system';
    $stmt_log_change->bind_param("issss", $host_id, $stack_name, $change_type, $log_details_for_stack_change, $changed_by);
    $stmt_log_change->execute();
    $stmt_log_change->close();
    stream_message("Stack change logged to history.");

    stream_message("Database record saved.");

    // --- Finalize ---
    stream_message("Cleaning up temporary files...");
    if (isset($git) && isset($repo_path)) $git->cleanup($repo_path);
    // Only cleanup if it was a temporary directory (i.e., $temp_dir was set)
    if (isset($temp_dir) && is_dir($temp_dir)) {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            shell_exec("rmdir /s /q " . escapeshellarg($temp_dir));
        } else {
            shell_exec("rm -rf " . escapeshellarg($temp_dir));
        }
    }
    stream_message("Cleanup complete.");

    log_activity($_SESSION['username'], 'App Launched', $log_details);
    stream_message("---");
    stream_message("Deployment finished successfully!", "SUCCESS");
    echo "_DEPLOYMENT_COMPLETE_";

} catch (Exception $e) {
    stream_message($e->getMessage(), 'ERROR');
    echo "_DEPLOYMENT_FAILED_";
} finally {
    if (isset($git) && isset($repo_path)) $git->cleanup($repo_path);
    // Only cleanup if it was a temporary directory
    if (isset($temp_dir) && is_dir($temp_dir)) {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            shell_exec("rmdir /s /q " . escapeshellarg($temp_dir));
        } else {
            shell_exec("rm -rf " . escapeshellarg($temp_dir)); // This was already here, good.
        }
    }
    if (empty(Config::get('DOCKER_CONFIG_PATH')) && isset($docker_config_dir) && is_dir($docker_config_dir)) {
         shell_exec("rm -rf " . escapeshellarg($docker_config_dir));
    }
    if (isset($conn)) $conn->close();
}
?>