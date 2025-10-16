<?php

require_once __DIR__ . '/GitHelper.php';
require_once __DIR__ . '/DockerClient.php';
require_once __DIR__ . '/DockerComposeParser.php';
require_once __DIR__ . '/Spyc.php';

class AppLauncherHelper
{
    /**
     * Modifies the compose data array with settings from the form.
     *
     * @param array &$compose_data The compose data array (passed by reference).
     * @param array $params An array of parameters from the form (replicas, cpu, memory, etc.).
     * @param array $host The host details from the database.
     * @param bool $is_swarm_manager Whether the host is a swarm manager.
     */
    public static function applyFormSettings(array &$compose_data, array $params, array $host, bool $is_swarm_manager): void
    {
        // The 'version' attribute is obsolete in modern docker-compose.
        unset($compose_data['version']);
        // Extract params for easier access
        $replicas = $params['replicas'] ?? null;
        $cpu = $params['cpu'] ?? null;
        $memory = $params['memory'] ?? null;
        $network = $params['network'] ?? null;
        $volume_paths = $params['volume_paths'] ?? [];
        $host_port = $params['host_port'] ?? null;
        $container_port = $params['container_port'] ?? null;
        $container_ip = $params['container_ip'] ?? null;
        $privileged = $params['privileged'] ?? false;
        $placement_constraint = $params['deploy_placement_constraint'] ?? null;
        $stack_name = $params['stack_name'] ?? '';

        if (!isset($compose_data['services']) || !is_array($compose_data['services'])) {
            return;
        }

        $is_first_service = true;
        $num_services = count($compose_data['services']);
        foreach (array_keys($compose_data['services']) as $service_key) {
            // Apply universal settings to all services
            if ($is_swarm_manager) {
                // Ensure deploy section exists
                $compose_data['services'][$service_key]['deploy'] = $compose_data['services'][$service_key]['deploy'] ?? [];

                // --- FIX: Swarm does not support container_name. Unset it if it exists. ---
                unset($compose_data['services'][$service_key]['container_name']);

                // Unset standalone resource keys to prevent conflicts
                unset($compose_data['services'][$service_key]['cpus']);
                unset($compose_data['services'][$service_key]['mem_limit']);

                // Set/replace resource limits from form
                if ($cpu || $memory) {
                    $compose_data['services'][$service_key]['deploy']['resources']['limits'] = [];
                    if ($cpu) {
                        // Explicitly cast to string to satisfy Docker Swarm's requirement (e.g., "1.5" not 1.5)
                        $compose_data['services'][$service_key]['deploy']['resources']['limits']['cpus'] = (string)$cpu;
                    }
                    if ($memory) $compose_data['services'][$service_key]['deploy']['resources']['limits']['memory'] = $memory;
                }

                // Set/replace restart policy
                $compose_data['services'][$service_key]['deploy']['restart_policy'] = [
                    'condition' => 'any'
                ];

                // Apply placement constraint if specified
                if ($placement_constraint) {
                    $compose_data['services'][$service_key]['deploy']['placement']['constraints'] = [$placement_constraint];
                }
            } else { // Standalone host
                // Unset Swarm deploy key to prevent conflicts
                unset($compose_data['services'][$service_key]['deploy']);

                // For standalone, resource limits are at the top level of the service
                if ($cpu) {
                    $compose_data['services'][$service_key]['cpus'] = (float)$cpu;
                }
                if ($memory) {
                    $compose_data['services'][$service_key]['mem_limit'] = $memory;
                }

                // Set restart policy only if it's not already defined in the compose file.
                if (!isset($compose_data['services'][$service_key]['restart'])) {
                    $compose_data['services'][$service_key]['restart'] = 'unless-stopped';
                }
            }
            
            // Add privileged mode if requested. Convert boolean to string for YAML compatibility.
            if ($privileged) {
                $compose_data['services'][$service_key]['privileged'] = 'true';
            }

            // For standalone hosts, explicitly set the container name.
            // This avoids the default "{project}_{service}_1" naming convention.
            // This is not recommended for Swarm as it conflicts with scaling.
            if (!$is_swarm_manager) {
                // Always set the container name based on the stack and service key to ensure uniqueness and clarity.
                $compose_data['services'][$service_key]['container_name'] = $stack_name . '_' . $service_key;
            }

            // Also set the hostname. This is generally safe.
            // For single service apps, it matches the stack name. For multi-service, it matches the service key to avoid conflicts.
            if ($num_services === 1) {
                $compose_data['services'][$service_key]['hostname'] = $stack_name;
            } else {
                $compose_data['services'][$service_key]['hostname'] = $service_key;
            }

            // Ensure that if a hostname is set, the service is attached to at least one network.
            // If no specific network is chosen, attach it to the default network to support the alias.
            if (isset($compose_data['services'][$service_key]['hostname'])) {
                // Only create a default network if NO other network is specified by the user or in the compose file.
                // Also, do not create a default network if the user intended to use 'ingress' or the default 'bridge'.
                if (empty($network) && !isset($compose_data['services'][$service_key]['networks'])) {
                    if (!isset($compose_data['networks']['default'])) {
                        // Define a default network that allows inter-container communication (including ping)
                        /*$compose_data['networks']['default'] = [
                            'driver_opts' => [
                                'com.docker.network.bridge.enable_icc' => true,
                                'com.docker.network.bridge.enable_ip_masquerade' => true,
                            ]
                        ];*/
                    }
                    // Explicitly attach the service to the default network to support the hostname alias.
                    $compose_data['services'][$service_key]['networks'] = ['default'];
                }
                // --- NEW: Also attach to the agent network if it exists on a Swarm host ---
                if ($is_swarm_manager) {
                    $compose_data['services'][$service_key]['networks'][] = 'cm-agent-net';
                }
            }

            // --- NEW: Automatically add a generic HEALTHCHECK if one doesn't exist ---
            // This makes containers more observable by the health agent.
            if (!isset($compose_data['services'][$service_key]['healthcheck'])) {
                $port_to_check = null;
                // Prioritize the port from the form for the first service
                if ($is_first_service && $container_port) {
                    $port_to_check = $container_port;
                } 
                // Otherwise, try to find the first exposed port for the current service
                elseif (isset($compose_data['services'][$service_key]['ports'][0])) {
                    $port_mapping = $compose_data['services'][$service_key]['ports'][0];
                    if (is_string($port_mapping)) {
                        $parts = explode(':', $port_mapping);
                        $port_to_check = end($parts);
                    } elseif (is_array($port_mapping) && isset($port_mapping['target'])) {
                        $port_to_check = $port_mapping['target'];
                    }
                }

                if ($port_to_check) {
                    $compose_data['services'][$service_key]['healthcheck'] = [
                        'test' => ["CMD-SHELL", "curl -f http://localhost:{$port_to_check}/ || exit 1"],
                        'interval' => '30s',
                        'timeout' => '30s', // Give the check a bit more time
                        'retries' => 5,     // Increase retries for more tolerance
                        'start_period' => '90s' // Give the app a full minute to start up
                    ];
                }
            }
            // Apply network attachment to all services
            // Only apply network settings if a specific, non-default network is chosen.
            if ($network && $network !== 'bridge' && $network !== 'ingress') {
                $network_key = preg_replace('/[^\w.-]+/', '_', $network);

                if (!isset($compose_data['services'][$service_key]['networks'])) {
                    $compose_data['services'][$service_key]['networks'] = [];
                }
                $current_networks =& $compose_data['services'][$service_key]['networks'];

                // Ensure we are working with a map (associative array) for consistency.
                // If the original compose file used a list format, convert it.
                if (is_array($current_networks) && array_is_list($current_networks)) {
                    $map = [];
                    foreach ($current_networks as $net_name) {
                        $map[$net_name] = null;
                    }
                    $current_networks = $map;
                }

                // Now we can safely assume it's a map. Check if the network is already attached.
                if (!array_key_exists($network_key, $current_networks)) {
                    // For the first service, if an IP is provided, use the complex object format.
                    if ($is_first_service && !empty($container_ip)) {
                        $current_networks[$network_key] = ['ipv4_address' => $container_ip];
                    } else {
                        // For subsequent services or if no IP is given, use the map format with a null value.
                        $current_networks[$network_key] = null;
                    }
                }
                unset($current_networks); // Unset reference
            }

            // Apply singular settings only to the FIRST service
            if ($is_first_service) {
                if ($is_swarm_manager && $replicas) {
                    if (!isset($compose_data['services'][$service_key]['deploy'])) $compose_data['services'][$service_key]['deploy'] = [];
                    $compose_data['services'][$service_key]['deploy']['replicas'] = $replicas;
                }

                // --- Volume Mapping ---
                if (!empty($volume_paths) && is_array($volume_paths)) {
                    foreach ($volume_paths as $volume_map) {
                        $container_path = $volume_map['container'] ?? null;
                        $host_path = $volume_map['host'] ?? null;

                        if ($container_path && $host_path) {
                            if (!str_starts_with($container_path, '/')) {
                                $container_path = '/' . $container_path;
                            }
                            // Generate volume name based on container path only. Docker-compose will prefix it with the project name.
                            $volume_name = preg_replace('/[^\w.-]+/', '_', trim($container_path, '/')) ?: 'data';

                            if (!isset($compose_data['services'][$service_key]['volumes'])) $compose_data['services'][$service_key]['volumes'] = [];
                            $compose_data['services'][$service_key]['volumes'][] = $volume_name . ':' . $container_path;

                            if (!isset($compose_data['volumes'])) $compose_data['volumes'] = [];
                            $compose_data['volumes'][$volume_name] = [
                                'driver' => 'local',
                                'driver_opts' => [
                                    'type' => 'none',
                                    'o' => 'bind',
                                    'device' => $host_path,
                                ],
                            ];
                        }
                    }
                }

                $is_first_service = false;
            }
        }

        // --- NEW: Definitive Port & Expose Mapping Logic ---
        // This logic now correctly targets ONLY the first service in the compose file
        // for applying the port settings from the App Launcher form. This prevents
        // settings from being incorrectly applied or wiped by other services in the file.
        if ($container_port) {
            // Get the key of the very first service defined in the compose file.
            $first_service_key = array_key_first($compose_data['services']);

            if ($first_service_key) {
                // Ensure the 'ports' array exists for the first service.
                $compose_data['services'][$first_service_key]['ports'] = $compose_data['services'][$first_service_key]['ports'] ?? [];
                $ports_array = &$compose_data['services'][$first_service_key]['ports'];
                
                // For Swarm, we must use the long syntax for reliable ingress routing.
                if ($is_swarm_manager) {
                    // Convert any existing short-syntax ports to long syntax to avoid mixing formats.
                    foreach ($ports_array as $index => $port_mapping) {
                        if (is_string($port_mapping)) {
                            $parts = explode(':', $port_mapping);
                            $ports_array[$index] = [
                                'published' => (int)$parts[0],
                                'target' => (int)end($parts),
                                'protocol' => 'tcp',
                                'mode' => 'ingress'
                            ];
                        }
                    }

                    $port_mapping_object = [
                        'target' => (int)$container_port,
                        'protocol' => 'tcp',
                        'mode' => 'ingress'
                    ];
                    if (!empty($host_port)) {
                        $port_mapping_object['published'] = (int)$host_port;
                    }

                    // Check for duplicates before adding
                    $is_duplicate = false;
                    foreach ($ports_array as $existing_port) {
                        if (is_array($existing_port) && ($existing_port['target'] ?? null) == $container_port && ($existing_port['published'] ?? null) == $host_port) {
                            $is_duplicate = true;
                            break;
                        }
                    }
                    if (!$is_duplicate) {
                        $ports_array[] = $port_mapping_object;
                    }
                } else {
                    // For Standalone, the short syntax is sufficient and common.
                    $port_mapping_string = !empty($host_port) ? "{$host_port}:{$container_port}" : (string)$container_port;
                    if (!in_array($port_mapping_string, $ports_array)) {
                        $ports_array[] = $port_mapping_string;
                    }
                }

                // Also add to 'expose' for better health agent discovery.
                /*$compose_data['services'][$first_service_key]['expose'] = $compose_data['services'][$first_service_key]['expose'] ?? [];
                if (!in_array((string)$container_port, $compose_data['services'][$first_service_key]['expose'])) {
                    //$compose_data['services'][$first_service_key]['expose'][] = (string)$container_port;
                }*/
            }
        }

        // Add top-level network definition
        // Only add the top-level network if it's a specific, non-default network.
        if ($network && $network !== 'bridge' && $network !== 'ingress') {
            $network_key = preg_replace('/[^\w.-]+/', '_', $network);
            if (!isset($compose_data['networks'][$network_key])) {
            if (!isset($compose_data['networks'])) $compose_data['networks'] = [];
                $compose_data['networks'][$network_key] = [
                'name' => $network,
                'external' => true
            ];
            }
        }
        
        // --- NEW: Define the agent network as external if it was used ---
        if ($is_swarm_manager) {
            if (!isset($compose_data['networks'])) $compose_data['networks'] = [];
            if (!isset($compose_data['networks']['cm-agent-net'])) {
                $compose_data['networks']['cm-agent-net'] = ['external' => true];
            }
        }

    }

    /**
     * Executes the full deployment process for a given set of parameters.
     * This function is designed to be called directly by handlers like app_launcher_handler or webhook_handler.
     * It streams output directly.
     *
     * @param array $post_data The data array, typically from a form or webhook payload.
     * @return void
     * @throws Exception
     */
    public static function executeDeployment(array $post_data): void
    {
        $start_time = microtime(true); // Start the timer

        $conn = Database::getInstance()->getConnection();
        $repo_path = null;
        $temp_dir = null;
        $docker_config_dir = null;
        $git = new GitHelper();

        // --- NEW: Setup Log File ---
        $log_file_handle = null;
        $log_file_path = null;

        try {
            // --- Input Validation ---
            $host_id = $post_data['host_id'] ?? null;
            $stack_name = strtolower(trim($post_data['stack_name'] ?? ''));
            $source_type = $post_data['source_type'] ?? 'git';
            $git_url = trim($post_data['git_url'] ?? '');
            $git_branch = trim($post_data['git_branch'] ?? 'main');
            $compose_path = trim($post_data['compose_path'] ?? '');
            $build_from_dockerfile = isset($post_data['build_from_dockerfile']) && $post_data['build_from_dockerfile'] === '1';

            // Resource settings
            $replicas = !empty($post_data['deploy_replicas']) ? (int)$post_data['deploy_replicas'] : null;
            $cpu = !empty($post_data['deploy_cpu']) ? $post_data['deploy_cpu'] : null;
            $memory = !empty($post_data['deploy_memory']) ? $post_data['deploy_memory'] : null;
            $network = !empty($post_data['network_name']) ? $post_data['network_name'] : null;
            $volume_paths = isset($post_data['volume_paths']) && is_array($post_data['volume_paths']) ? $post_data['volume_paths'] : [];

            // Port mapping settings
            $host_port = !empty($post_data['host_port']) ? (int)$post_data['host_port'] : null;
            $container_port = !empty($post_data['container_port']) ? (int)$post_data['container_port'] : null;
            $container_ip = !empty($post_data['container_ip']) ? trim($post_data['container_ip']) : null;
            $privileged = isset($post_data['privileged']) && $post_data['privileged'] === 'true';
            $deploy_placement_constraint = !empty($post_data['deploy_placement_constraint']) ? trim($post_data['deploy_placement_constraint']) : null;
            $is_update = isset($post_data['update_stack']) && $post_data['update_stack'] === 'true';

            // Autoscaling settings
            $autoscaling_enabled = isset($post_data['autoscaling_enabled']) ? 1 : 0;
            $autoscaling_min_replicas = !empty($post_data['autoscaling_min_replicas']) ? (int)$post_data['autoscaling_min_replicas'] : 1;
            $autoscaling_max_replicas = !empty($post_data['autoscaling_max_replicas']) ? (int)$post_data['autoscaling_max_replicas'] : 1;
            $autoscaling_cpu_threshold_up = !empty($post_data['autoscaling_cpu_threshold_up']) ? (int)$post_data['autoscaling_cpu_threshold_up'] : 80;
            $autoscaling_cpu_threshold_down = !empty($post_data['autoscaling_cpu_threshold_down']) ? (int)$post_data['autoscaling_cpu_threshold_down'] : 20;

            if (empty($host_id) || empty($stack_name)) throw new Exception("Host and Stack Name are required.");
            
            stream_message("Validating stack name '{$stack_name}'...");
            if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $stack_name)) throw new Exception("Invalid Stack Name.");

            $form_params = $post_data; // Pass all data

            $stmt = $conn->prepare("SELECT * FROM docker_hosts WHERE id = ?");
            $stmt->bind_param("i", $host_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if (!($host = $result->fetch_assoc())) throw new Exception("Host not found.");

            // --- NEW: Open log file for writing ---
            $base_log_path = get_setting('default_compose_path');
            if ($base_log_path) {
                $safe_host_name = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $host['name']);
                $log_dir = rtrim($base_log_path, '/') . '/' . $safe_host_name . '/' . $stack_name;
                if (!is_dir($log_dir)) {
                    mkdir($log_dir, 0755, true);
                }
                $log_file_path = $log_dir . '/deployment.log';
                // Open in 'w' mode to overwrite previous log for this deployment
                $log_file_handle = fopen($log_file_path, 'w');
            }

            $stmt->close();

            $dockerClient = new DockerClient($host);
            $dockerInfo = $dockerClient->getInfo();
            $is_swarm_manager = (isset($dockerInfo['Swarm']['ControlAvailable']) && $dockerInfo['Swarm']['ControlAvailable'] === true);

            // Generate Compose Content
            $compose_content = '';
            $compose_file_name = 'docker-compose.yml';

            if ($source_type === 'git') {
                if (empty($git_url)) throw new Exception("Git URL is required.");
                stream_message("Cloning repository '{$git_url}' (branch: {$git_branch})...");
                $repo_path = $git->cloneOrPull($git_url, $git_branch);

                $final_compose_path = '';
                $paths_to_try = array_unique(array_filter([$compose_path, get_setting('default_git_compose_path'), 'docker-compose.yml']));
                foreach ($paths_to_try as $path) {
                    if (file_exists($repo_path . '/' . $path)) {
                        $final_compose_path = $path;
                        break;
                    }
                }
                if (empty($final_compose_path)) throw new Exception("Compose file not found. Tried: " . implode(', ', $paths_to_try));
                
                $compose_file_name = $final_compose_path;
                $base_compose_content = file_get_contents($repo_path . '/' . $compose_file_name);
                $compose_data = DockerComposeParser::YAMLLoad($base_compose_content);
                self::applyFormSettings($compose_data, $form_params, $host, $is_swarm_manager);
                $compose_content = Spyc::YAMLDump($compose_data, 2, 0);

            } elseif ($source_type === 'image' || $source_type === 'hub') {
                $image_name = ($source_type === 'image') ? ($post_data['image_name_local'] ?? '') : ($post_data['image_name_hub'] ?? '');
                if (empty($image_name)) throw new Exception("Image Name is required.");
                
                $compose_data = ['version' => '3.8', 'services' => [$stack_name => ['image' => $image_name]]];
                self::applyFormSettings($compose_data, $form_params, $host, $is_swarm_manager);
                if (empty($compose_data['networks'])) unset($compose_data['networks']);
                $compose_content = Spyc::YAMLDump($compose_data, 2, 0);

            } elseif ($source_type === 'editor') {
                $compose_content = $post_data['compose_content_editor'] ?? '';
                if (empty($compose_content)) throw new Exception("Compose content from editor is required.");
            } else {
                throw new Exception("Invalid source type specified.");
            }

            // Deployment Directory Logic
            $deployment_dir = '';
            $base_compose_path = get_setting('default_compose_path', '');
            $is_persistent_path = !empty($base_compose_path);

            if ($source_type === 'git') {
                if ($is_persistent_path) {
                    $safe_host_name = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $host['name']);
                    $deployment_dir = rtrim($base_compose_path, '/') . '/' . $safe_host_name . '/' . $stack_name;
                    if (is_dir($deployment_dir)) shell_exec("rm -rf " . escapeshellarg($deployment_dir));
                    if (!mkdir($deployment_dir, 0755, true)) throw new \RuntimeException("Deployment directory could not be created.");
                    exec("cp -a " . escapeshellarg($repo_path . '/.') . " " . escapeshellarg($deployment_dir));
                } else {
                    $deployment_dir = $repo_path;
                    $temp_dir = $deployment_dir;
                    $repo_path = null;
                }
                file_put_contents($deployment_dir . '/' . $compose_file_name, $compose_content);
            } else {
                if ($is_persistent_path) {
                    $safe_host_name = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $host['name']);
                    $deployment_dir = rtrim($base_compose_path, '/') . '/' . $safe_host_name . '/' . $stack_name;
                } else {
                    $deployment_dir = rtrim(sys_get_temp_dir(), '/') . '/app_launcher_' . uniqid();
                    $temp_dir = $deployment_dir;
                }
                if (!is_dir($deployment_dir) && !mkdir($deployment_dir, 0755, true)) throw new Exception("Could not create deployment directory: {$deployment_dir}.");
                file_put_contents($deployment_dir . '/' . $compose_file_name, $compose_content);
            }

            // Execute Deployment
            $env_vars = "DOCKER_HOST=" . escapeshellarg($host['docker_api_url']) . " COMPOSE_NONINTERACTIVE=1";
            if ($host['tls_enabled']) {
                $cert_path_dir = $deployment_dir . '/certs';
                if (!is_dir($cert_path_dir) && !mkdir($cert_path_dir, 0700, true)) throw new Exception("Could not create cert directory.");
                copy($host['ca_cert_path'], $cert_path_dir . '/ca.pem');
                copy($host['client_cert_path'], $cert_path_dir . '/cert.pem');
                copy($host['client_key_path'], $cert_path_dir . '/key.pem');
                $env_vars .= " DOCKER_TLS_VERIFY=1 DOCKER_CERT_PATH=" . escapeshellarg($cert_path_dir);
            }

            $login_command = ''; 
            if (!empty($host['registry_username']) && !empty($host['registry_password'])) {
                $docker_config_dir = Config::get('DOCKER_CONFIG_PATH') ?: rtrim(sys_get_temp_dir(), '/') . '/docker_config_' . uniqid();
                if (!is_dir($docker_config_dir) && !mkdir($docker_config_dir, 0755, true)) throw new Exception("Could not create DOCKER_CONFIG_PATH.");
                $env_vars .= " DOCKER_CONFIG=" . escapeshellarg($docker_config_dir);
                $registry_url = !empty($host['registry_url']) ? escapeshellarg($host['registry_url']) : '';
                $login_command = "echo " . escapeshellarg($host['registry_password']) . " | docker login {$registry_url} -u " . escapeshellarg($host['registry_username']) . " --password-stdin 2>&1 && ";
            }

            $cd_command = "cd " . escapeshellarg($deployment_dir);
            $main_compose_command = '';
            if ($is_swarm_manager) {
                $main_compose_command = "docker stack deploy -c " . escapeshellarg($compose_file_name) . " " . escapeshellarg($stack_name) . " --with-registry-auth --prune 2>&1";
            } else {
                $main_compose_command = "docker compose -p " . escapeshellarg($stack_name) . " -f " . escapeshellarg($compose_file_name) . " pull 2>&1 && " .
                                        "docker compose -p " . escapeshellarg($stack_name) . " -f " . escapeshellarg($compose_file_name) . " up -d --force-recreate --remove-orphans --renew-anon-volumes 2>&1";
            }
 
            $script_to_run = $cd_command . ' && ' . $login_command . $main_compose_command;
            $full_command = 'env ' . $env_vars . ' sh -c ' . escapeshellarg($script_to_run);

            stream_exec($full_command, $return_var);

            if ($return_var !== 0) throw new Exception("Docker-compose deployment failed.");

            // Save to DB
            $deployment_details_json = json_encode($post_data, JSON_UNESCAPED_SLASHES);
            $placement_to_save = $deploy_placement_constraint ?? '';
            $stmt_stack = $conn->prepare(
                "INSERT INTO application_stacks (host_id, stack_name, source_type, compose_file_path, deployment_details, autoscaling_enabled, autoscaling_min_replicas, autoscaling_max_replicas, autoscaling_cpu_threshold_up, autoscaling_cpu_threshold_down, deploy_placement_constraint) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE 
                    source_type = VALUES(source_type), compose_file_path = VALUES(compose_file_path), deployment_details = VALUES(deployment_details), 
                    autoscaling_enabled = VALUES(autoscaling_enabled), autoscaling_min_replicas = VALUES(autoscaling_min_replicas), autoscaling_max_replicas = VALUES(autoscaling_max_replicas), 
                    autoscaling_cpu_threshold_up = VALUES(autoscaling_cpu_threshold_up), autoscaling_cpu_threshold_down = VALUES(autoscaling_cpu_threshold_down),
                    deploy_placement_constraint = VALUES(deploy_placement_constraint), updated_at = NOW()"
            );
            $stmt_stack->bind_param("issssiiiiis", $host_id, $stack_name, $source_type, $compose_file_name, $deployment_details_json, $autoscaling_enabled, $autoscaling_min_replicas, $autoscaling_max_replicas, $autoscaling_cpu_threshold_up, $autoscaling_cpu_threshold_down, $placement_to_save);
            $stmt_stack->execute();
            $stmt_stack->close();

            $end_time = microtime(true); // End the timer
            $duration = (int)round($end_time - $start_time);

            // Log change
            $change_type = $is_update ? 'updated' : 'created';
            $log_details = "Source: {$source_type}";
            $stmt_log_change = $conn->prepare("INSERT INTO stack_change_log (host_id, stack_name, change_type, details, duration_seconds, changed_by) VALUES (?, ?, ?, ?, ?, ?)");
            $changed_by = $_SESSION['username'] ?? 'system';
            // Bind the new duration parameter
            $stmt_log_change->bind_param("isssis", $host_id, $stack_name, $change_type, $log_details, $duration, $changed_by);
            $stmt_log_change->execute();
            $stmt_log_change->close();

        } catch (Exception $e) {
            // Ensure the exception is also written to the log file if it's open
            if ($log_file_handle) {
                fwrite($log_file_handle, "\n--- DEPLOYMENT FAILED ---\n" . $e->getMessage());
            }
            throw $e; // Re-throw the exception
        } finally {
            if (isset($git) && isset($repo_path)) $git->cleanup($repo_path);
            if (isset($temp_dir) && is_dir($temp_dir)) shell_exec("rm -rf " . escapeshellarg($temp_dir));
            if (empty(Config::get('DOCKER_CONFIG_PATH')) && isset($docker_config_dir) && is_dir($docker_config_dir)) shell_exec("rm -rf " . escapeshellarg($docker_config_dir));
        }
    }
}

// --- Global stream functions that also write to a file ---
function stream_message($message, $type = 'INFO')
{
    global $log_file_handle;
    $line = date('[Y-m-d H:i:s]') . " [{$type}] " . htmlspecialchars(trim($message)) . "\n";
    echo $line;
    flush();
    if ($log_file_handle) {
        fwrite($log_file_handle, $line);
    }
}

function stream_exec($command, &$return_var)
{
    global $log_file_handle;
    $handle = popen($command . ' 2>&1', 'r');
    while (($line = fgets($handle)) !== false) {
        $trimmed_line = rtrim($line);
        echo $trimmed_line . "\n";
        flush();
        if ($log_file_handle) fwrite($log_file_handle, rtrim($line) . "\n");
    }
    $return_var = pclose($handle);
}