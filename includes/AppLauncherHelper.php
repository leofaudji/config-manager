<?php

require_once __DIR__ . '/GitHelper.php';
require_once __DIR__ . '/DockerClient.php';
require_once __DIR__ . '/DockerComposeParser.php';
require_once __DIR__ . '/Spyc.php';

class AppLauncherHelper
{
    /**
     * @var resource|null The file handle for the current deployment log.
     */
    private static $log_file_handle = null;

    /**
     * @var string|null The path to the current deployment log file.
     */
    private static ?string $log_file_path = null;

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
        $replicas = !empty($params['deploy_replicas']) ? (int)$params['deploy_replicas'] : null;
        $cpu = !empty($params['deploy_cpu']) ? $params['deploy_cpu'] : null;
        $memory = !empty($params['deploy_memory']) ? $params['deploy_memory'] : null;
        $network = $params['network'] ?? null;
        $volume_paths = $params['volume_paths'] ?? [];
        $host_port = $params['host_port'] ?? null;
        $container_port = $params['container_port'] ?? null;
        $container_ip = $params['container_ip'] ?? null;
        $privileged = $params['privileged'] ?? false;
        $placement_constraint = $params['deploy_placement_constraint'] ?? null;
        $stack_name = $params['stack_name'] ?? '';

        if (!isset($compose_data['services']) || !is_array($compose_data['services'])) {
            throw new Exception("Invalid compose file: 'services' section is missing or not an array.");
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

                // --- FIX: Only create the 'limits' section if CPU or Memory is provided ---
                if ($cpu || $memory) {
                    // Ensure the nested structure exists before assigning values.
                    $compose_data['services'][$service_key]['deploy']['resources'] = $compose_data['services'][$service_key]['deploy']['resources'] ?? [];
                    $compose_data['services'][$service_key]['deploy']['resources']['limits'] = $compose_data['services'][$service_key]['deploy']['resources']['limits'] ?? [];
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

                // --- FIX: Apply resource limits to ALL services ---
                // For standalone, resource limits are at the top level of the service.
                if ($cpu) {
                    $compose_data['services'][$service_key]['cpus'] = (float)$cpu;
                }
                if ($memory) $compose_data['services'][$service_key]['mem_limit'] = $memory;

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
            // This is only done for single-replica services to avoid naming conflicts when scaling.
            if (!$is_swarm_manager && ($replicas === null || $replicas <= 1)) {
                $compose_data['services'][$service_key]['container_name'] = $stack_name . '_' . $service_key;
            } else {
                // If scaling on standalone, we must remove the container_name to allow Docker to generate unique names.
                unset($compose_data['services'][$service_key]['container_name']);
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

                // --- SMART FIX: For standalone scaling, host port must be omitted. ---
                if (!$is_swarm_manager && $replicas > 1) {
                    $host_port = null; // Force host port to be null to allow scaling.
                }

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
     * Opens the log file for the current deployment.
     *
     * @param array $host The host details array.
     * @param string $stack_name The name of the stack being deployed.
     */
    private static function openLogFile(array $post_data, string $stack_name): void
    {
        // If a specific log file path is provided (e.g., from webhook), use it.
        if (!empty($post_data['log_file_path'])) {
            self::$log_file_path = $post_data['log_file_path'];
            $log_dir = dirname(self::$log_file_path);
            if (!is_dir($log_dir)) {
                @mkdir($log_dir, 0755, true);
            }
            self::$log_file_handle = @fopen(self::$log_file_path, 'w');
            return;
        }

        // Fallback to the original logic if no specific path is provided
        $base_log_path = get_setting('default_compose_path');
        $host_name = $post_data['host_name'] ?? 'unknown_host';
        if ($base_log_path) {
            $safe_host_name = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $host_name);
            $log_dir = rtrim($base_log_path, '/') . '/' . $safe_host_name . '/' . $stack_name;
            if (!is_dir($log_dir)) {
                // Attempt to create the directory. The '@' suppresses warnings on failure.
                if (!@mkdir($log_dir, 0755, true) && !is_dir($log_dir)) {
                    // If mkdir fails and the directory still doesn't exist, we can't proceed.
                    // We won't throw an error here, but logging will be disabled.
                    self::$log_file_handle = null;
                    return;
                }
            }
            self::$log_file_path = $log_dir . '/deployment.log';
            // Open in 'w' mode to overwrite previous log for this deployment
            self::$log_file_handle = @fopen(self::$log_file_path, 'w');
        }
    }
    /**
     * Closes the log file handle if it's open.
     * This should be called by the script that initiates the deployment.
     */
    public static function closeLogFile(): void
    {
        if (self::$log_file_handle) {
            fflush(self::$log_file_handle); // Explicitly flush the buffer to disk
        }
        if (self::$log_file_handle) @fclose(self::$log_file_handle);
    }

    /**
     * Clones a Git repo, builds a Docker image from its Dockerfile,
     * updates the compose file to use the new image, and deploys the stack.
     * This is the core of the Git-triggered CI/CD workflow.
     *
     * @param array $post_data The deployment parameters from the webhook.
     * @return void
     * @throws Exception
     */
    public static function buildAndDeployFromGit(array $post_data): void
    {
        $start_time = microtime(true);
        $conn = Database::getInstance()->getConnection();
        $git = new GitHelper();
        $repo_path = null;

        $host_id = $post_data['host_id'];
        $stack_name = $post_data['stack_name'];

        // The log file is opened here, and will be closed in the finally block
        self::openLogFile($post_data, $stack_name); // Pass full post_data

        try {
            self::stream_message("Starting CI/CD build & deploy for stack '{$stack_name}'.");

            // 1. Get Host and Git details from post data
            $stmt_host = $conn->prepare("SELECT * FROM docker_hosts WHERE id = ?");
            $stmt_host->bind_param("i", $host_id);
            $stmt_host->execute();
            $host = $stmt_host->get_result()->fetch_assoc();
            $stmt_host->close();
            if (!$host) throw new Exception("Host not found for build process.");

            $dockerClient = new DockerClient($host);

            $git_url = trim($post_data['git_url'] ?? '');
            $git_branch = trim($post_data['git_branch'] ?? 'main');
            if (empty($git_url)) throw new Exception("Git URL is missing from deployment details.");

            // 2. Clone repo and get commit hash
            self::stream_message("Cloning repository '{$git_url}' (branch: {$git_branch})...");
            $repo_path = $git->cloneOrPull($git_url, $git_branch);
            $commit_hash = trim($git->execute("rev-parse --short HEAD", $repo_path));
            self::stream_message("Cloned to '{$repo_path}'. Latest commit: {$commit_hash}");

            // 3. Find Dockerfile
            $dockerfile_path = $repo_path . '/Dockerfile';
            if (!file_exists($dockerfile_path)) {
                throw new Exception("Dockerfile not found in the root of the repository.");
            }
            $dockerfile_content = file_get_contents($dockerfile_path);

            // 4. Build the image
            // The image name is constructed from the registry user (namespace) and the stack name.
            $image_name_base = strtolower(trim($host['registry_username'] . '/' . $stack_name));
            $new_image_tag = "{$image_name_base}:{$commit_hash}";
            self::stream_message("Building new image: {$new_image_tag}");

            // The buildImage method takes the Dockerfile content and the repo path as build context
            $build_output = $dockerClient->buildImage($dockerfile_content, $repo_path, $new_image_tag);
            self::stream_message("Build output:\n" . $build_output, 'SHELL');
            self::stream_message("Image build complete.");

            // 5. Push the image to the host's configured registry
            self::stream_message("Pushing image '{$new_image_tag}' to registry...");
            $push_output = $dockerClient->pullImage($new_image_tag); // pullImage also handles push auth
            self::stream_message("Push output:\n" . $push_output, 'SHELL');
            self::stream_message("Image push complete.");

            // 6. Modify the compose file to use the new image
            $compose_file_name = $post_data['compose_path'] ?? 'docker-compose.yml';
            $compose_file_full_path = $repo_path . '/' . $compose_file_name;
            if (!file_exists($compose_file_full_path)) throw new Exception("Compose file '{$compose_file_name}' not found.");

            $compose_data = DockerComposeParser::YAMLLoad($compose_file_full_path);
            if (!isset($compose_data['services']) || empty($compose_data['services'])) throw new Exception("No services found in compose file.");

            // Assume the first service is the one to be updated
            $first_service_key = array_key_first($compose_data['services']);
            $compose_data['services'][$first_service_key]['image'] = $new_image_tag;
            self::stream_message("Updated compose file to use image '{$new_image_tag}'.");

            // 7. Re-run the standard deployment logic with the modified compose data
            $post_data['compose_content_editor'] = Spyc::YAMLDump($compose_data, 2, 0);
            $post_data['source_type'] = 'editor'; // Switch source type to use the modified content

            self::executeDeployment($post_data);

            $end_time = microtime(true);
            $duration = (int)round($end_time - $start_time);
            self::stream_message("CI/CD process finished in {$duration} seconds.");

        } catch (Exception $e) {
            self::stream_message("CI/CD process failed: " . $e->getMessage(), 'ERROR');
            throw $e; // Re-throw to be caught by the parent process if any
        } finally {
            if ($repo_path && is_dir($repo_path)) {
                $git->cleanup($repo_path);
            }
            self::closeLogFile();
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

        // --- NEW: Check if this is a build & deploy request from a webhook ---
        if (isset($post_data['build_from_dockerfile']) && $post_data['build_from_dockerfile'] === true) {
            self::buildAndDeployFromGit($post_data);
            return; // The buildAndDeployFromGit function handles the rest, so we exit here.
        }

        $conn = Database::getInstance()->getConnection();
        
        // --- NEW: Setup Log File ---
        $log_file_handle = null;
        $log_file_path = null;

        try {
            // --- Input Validation ---
            $host_id = $post_data['host_id'] ?? null;
            $stack_name = strtolower(trim($post_data['stack_name'] ?? ''));
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

            if (empty($host_id) || empty($stack_name)) throw new Exception("Host and Stack Name are required.");
            
            self::stream_message("Validating stack name '{$stack_name}'...");
            if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $stack_name)) throw new Exception("Invalid Stack Name.");

            $webhook_update_policy = $post_data['webhook_update_policy'] ?? 'realtime';
            $webhook_schedule_time = ($webhook_update_policy === 'scheduled' && !empty($post_data['webhook_schedule_time'])) ? $post_data['webhook_schedule_time'] : null; // Explicitly set to null if not provided

            // Autoscaling settings
            $autoscaling_enabled = isset($post_data['autoscaling_enabled']) ? 1 : 0;
            $autoscaling_min_replicas = !empty($post_data['autoscaling_min_replicas']) ? (int)$post_data['autoscaling_min_replicas'] : 1;
            $autoscaling_max_replicas = !empty($post_data['autoscaling_max_replicas']) ? (int)$post_data['autoscaling_max_replicas'] : 1;
            $autoscaling_cpu_threshold_up = !empty($post_data['autoscaling_cpu_threshold_up']) ? (int)$post_data['autoscaling_cpu_threshold_up'] : 80;
            $autoscaling_cpu_threshold_down = !empty($post_data['autoscaling_cpu_threshold_down']) ? (int)$post_data['autoscaling_cpu_threshold_down'] : 20;

            $form_params = $post_data; // Pass all data

            $stmt = $conn->prepare("SELECT * FROM docker_hosts WHERE id = ?");
            $stmt->bind_param("i", $host_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if (!($host = $result->fetch_assoc())) throw new Exception("Host not found.");
            $stmt->close();

            // --- REFACTORED: Open log file using the new method. ---
            // We need to ensure the full $post_data array is passed so that
            // openLogFile can find the 'log_file_path' key if it exists (from a webhook).
            // We also add the host name to the array for the fallback logic.
            $post_data['host_name'] = $host['name'] ?? 'unknown_host';
            self::openLogFile($post_data, $stack_name);

            $dockerClient = new DockerClient($host);
            $dockerInfo = $dockerClient->getInfo();
            $is_swarm_manager = (isset($dockerInfo['Swarm']['ControlAvailable']) && $dockerInfo['Swarm']['ControlAvailable'] === true);
            // Add swarm status to form_params for applyFormSettings
            $form_params['is_swarm_manager'] = $is_swarm_manager;

            // Generate Compose Content
            $compose_content = '';
            $compose_file_name = 'docker-compose.yml';
            $repo_path = null;
            $temp_dir = null;
            $docker_config_dir = null;
            $git = new GitHelper();

            $source_type = $post_data['source_type'] ?? 'git';
            if ($source_type === 'git') {
                $git_url = trim($post_data['git_url'] ?? '');
                $git_branch = trim($post_data['git_branch'] ?? 'main');
                $compose_path = trim($post_data['compose_path'] ?? '');

                if (empty($git_url)) throw new Exception("Git URL is required.");
                self::stream_message("Cloning repository '{$git_url}' (branch: {$git_branch})...");
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
                // --- FIX: Add --scale flag for standalone replicas ---
                $scale_command = '';
                if ($replicas && $replicas > 1) {
                    // Find the first service name to apply the scale command to.
                    // FIX: Use the existing $compose_data array instead of re-parsing the string.
                    $first_service_name = array_key_first($compose_data['services'] ?? []);
                    if ($first_service_name) {
                        $scale_command = "--scale " . escapeshellarg($first_service_name . '=' . $replicas);
                    }
                }
                $main_compose_command = "docker compose -p " . escapeshellarg($stack_name) . " -f " . escapeshellarg($compose_file_name) . " pull 2>&1 && docker compose -p " . escapeshellarg($stack_name) . " -f " . escapeshellarg($compose_file_name) . " up -d --force-recreate --remove-orphans --renew-anon-volumes {$scale_command} 2>&1";
            }
 
            $script_to_run = $cd_command . ' && ' . $login_command . $main_compose_command;
            $full_command = 'env ' . $env_vars . ' sh -c ' . escapeshellarg($script_to_run);

            self::stream_exec($full_command, $return_var);

            if ($return_var !== 0) throw new Exception("Docker-compose deployment failed.");

            // Save to DB
            $deployment_details_json = json_encode($post_data, JSON_UNESCAPED_SLASHES);
            $placement_to_save = $deploy_placement_constraint ?? '';
            $stmt_stack = $conn->prepare(
                "INSERT INTO application_stacks (host_id, stack_name, source_type, compose_file_path, deployment_details, autoscaling_enabled, autoscaling_min_replicas, autoscaling_max_replicas, autoscaling_cpu_threshold_up, autoscaling_cpu_threshold_down, deploy_placement_constraint, webhook_update_policy, webhook_schedule_time) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE 
                    source_type = VALUES(source_type), compose_file_path = VALUES(compose_file_path), deployment_details = VALUES(deployment_details), 
                    autoscaling_enabled = VALUES(autoscaling_enabled), autoscaling_min_replicas = VALUES(autoscaling_min_replicas), autoscaling_max_replicas = VALUES(autoscaling_max_replicas), 
                    autoscaling_cpu_threshold_up = VALUES(autoscaling_cpu_threshold_up), autoscaling_cpu_threshold_down = VALUES(autoscaling_cpu_threshold_down),
                    deploy_placement_constraint = VALUES(deploy_placement_constraint), webhook_update_policy = VALUES(webhook_update_policy), webhook_schedule_time = VALUES(webhook_schedule_time),
                    webhook_pending_update = 0, updated_at = NOW()"
            );
            $stmt_stack->bind_param("issssiiiiisss", $host_id, $stack_name, $source_type, $compose_file_name, $deployment_details_json, $autoscaling_enabled, $autoscaling_min_replicas, $autoscaling_max_replicas, $autoscaling_cpu_threshold_up, $autoscaling_cpu_threshold_down, $placement_to_save, $webhook_update_policy, $webhook_schedule_time);
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

        } finally {
            if (isset($git) && isset($repo_path)) $git->cleanup($repo_path);
            if (isset($temp_dir) && is_dir($temp_dir)) shell_exec("rm -rf " . escapeshellarg($temp_dir));
            if (empty(Config::get('DOCKER_CONFIG_PATH')) && isset($docker_config_dir) && is_dir($docker_config_dir)) shell_exec("rm -rf " . escapeshellarg($docker_config_dir));
        }
    }

    /**
     * Streams a formatted message to the output and writes to the log file.
     *
     * @param string $message The message to stream.
     * @param string $type The type of message (e.g., INFO, ERROR).
     */
    public static function stream_message(string $message, string $type = 'INFO'): void
    {
        $line = date('[Y-m-d H:i:s]') . " [{$type}] " . htmlspecialchars(trim($message)) . "\n";
        echo $line;
        flush();
        if (self::$log_file_handle) {
            fwrite(self::$log_file_handle, $line);
        }
    }

    /**
     * Executes a shell command and streams its output line by line.
     *
     * @param string $command The command to execute.
     * @param int &$return_var The return status of the command.
     */
    public static function stream_exec(string $command, &$return_var): void
    {
        $handle = popen($command . ' 2>&1', 'r');
        while (($line = fgets($handle)) !== false) {
            self::stream_message(rtrim($line), 'SHELL');
        }
        $return_var = pclose($handle);
    }
}