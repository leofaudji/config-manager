<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/DockerClient.php';
require_once __DIR__ . '/../includes/Spyc.php';
require_once __DIR__ . '/../includes/GitHelper.php';

header('Content-Type: application/json');

$request_uri_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$basePath = BASE_PATH;
if ($basePath && strpos($request_uri_path, $basePath) === 0) {
    $request_uri_path = substr($request_uri_path, strlen($basePath));
}

$conn = Database::getInstance()->getConnection();

try {
    if (!preg_match('/^\/api\/hosts\/(\d+)\//', $request_uri_path, $matches)) {
        throw new InvalidArgumentException("Invalid API endpoint format.");
    }
    $host_id = $matches[1];

    $stmt = $conn->prepare("SELECT * FROM docker_hosts WHERE id = ?");
    $stmt->bind_param("i", $host_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if (!($host = $result->fetch_assoc())) {
        throw new Exception("Host not found.");
    }
    $stmt->close();

    $dockerClient = new DockerClient($host);

    // --- GET Stack Spec Logic ---
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && preg_match('/^\/api\/hosts\/\d+\/stacks\/([a-zA-Z0-9_.-]+)\/spec$/', $request_uri_path, $spec_matches)) {
        $stack_name = $spec_matches[1];

        $dockerInfo = $dockerClient->getInfo();
        $is_swarm_manager = (isset($dockerInfo['Swarm']['ControlAvailable']) && $dockerInfo['Swarm']['ControlAvailable'] === true);

        if ($is_swarm_manager) {
            // --- SWARM LOGIC (Existing) ---
            $all_services = $dockerClient->listServices();
            
            $stack_services_spec = [];
            foreach ($all_services as $service) {
                $stack_namespace = $service['Spec']['Labels']['com.docker.stack.namespace'] ?? null;
                if ($stack_namespace === $stack_name) {
                    // We only care about the spec for generating the YAML
                    $service_name = str_replace($stack_name . '_', '', $service['Spec']['Name']);
                    $stack_services_spec[$service_name] = $service['Spec'];
                }
            }

            if (empty($stack_services_spec)) {
                throw new Exception('No services found for this stack. It might be a standalone stack.');
            }

            $yaml_output = Spyc::YAMLDump(['services' => $stack_services_spec], 2, 0);
            echo json_encode(['status' => 'success', 'content' => $yaml_output]);

        } else {
            // --- STANDALONE LOGIC (New) ---
            $base_compose_path = get_setting('default_compose_path', '');
            if (empty($base_compose_path)) {
                throw new Exception("Cannot view spec for a standalone host stack. A 'Default Compose File Path' must be configured for the host for this feature to work.");
            }

            // Query the database for the exact compose file path used during deployment
            $stmt_stack = $conn->prepare("SELECT compose_file_path FROM application_stacks WHERE host_id = ? AND stack_name = ?");
            $stmt_stack->bind_param("is", $host_id, $stack_name);
            $stmt_stack->execute();
            $stack_record = $stmt_stack->get_result()->fetch_assoc();
            $stmt_stack->close();

            if (!$stack_record) {
                throw new Exception("Stack '{$stack_name}' not found in the application's database. It might have been deployed manually.");
            }

            $compose_filename = $stack_record['compose_file_path'];
            $safe_host_name = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $host['name']);
            $full_compose_path = rtrim($base_compose_path, '/') . '/' . $safe_host_name . '/' . $stack_name . '/' . $compose_filename;

            if (!file_exists($full_compose_path) || !is_readable($full_compose_path)) {
                throw new Exception("Compose file '{$compose_filename}' not found at the persistent path '{$full_compose_path}'. The file may have been moved or deleted, or the persistent path was not set correctly during deployment.");
            }

            $compose_content = file_get_contents($full_compose_path);
            if ($compose_content === false) {
                throw new Exception("Could not read the compose file at '{$full_compose_path}'. Check file permissions.");
            }

            echo json_encode(['status' => 'success', 'content' => $compose_content]);
        }

        $conn->close();
        exit;
    }

    // --- GET Stack Details Logic (Tasks per service) ---
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && preg_match('/^\/api\/hosts\/\d+\/stacks\/([a-zA-Z0-9_.-]+)\/details$/', $request_uri_path, $details_matches)) {
        $stack_name = $details_matches[1];

        $all_services = $dockerClient->listServices(['label' => ["com.docker.stack.namespace={$stack_name}"]]);
        // Correctly filter tasks by the stack name.
        $all_tasks = $dockerClient->listTasks([
            'label' => ["com.docker.stack.namespace={$stack_name}"]
        ]);
        $nodes = $dockerClient->listNodes();

        // Create a map of Node IDs to Hostnames for quick lookup
        $nodes_map = [];
        foreach ($nodes as $node) {
            $nodes_map[$node['ID']] = $node['Description']['Hostname'] ?? $node['ID'];
        }

        $services_with_tasks = [];
        foreach ($all_services as $service) {
            $service_name = str_replace($stack_name . '_', '', $service['Spec']['Name']);
            $tasks_for_service = array_filter($all_tasks, fn($t) => $t['ServiceID'] === $service['ID']);

            // Sort tasks by timestamp in ascending order (oldest first)
            usort($tasks_for_service, function ($a, $b) {
                $timestampA = $a['Status']['Timestamp'] ?? '0';
                $timestampB = $b['Status']['Timestamp'] ?? '0';
                return strcmp($timestampA, $timestampB);
            });
            
            $service_tasks = [];
            foreach ($tasks_for_service as $task) {
                $node_id = $task['NodeID'];
                $node_name = $nodes_map[$node_id] ?? $node_id; // Fallback to ID if not found

                // Determine the origin of the task by checking for our custom label.
                $origin = 'Deployment'; // Default to initial deployment
                if (isset($task['Spec']['ContainerSpec']['Labels']['com.config-manager.origin']) && $task['Spec']['ContainerSpec']['Labels']['com.config-manager.origin'] === 'autoscaled') {
                    $origin = 'Autoscaled';
                }

                $service_tasks[] = [
                    'ID' => $task['ID'],
                    'Node' => $node_name,
                    'CurrentState' => $task['Status']['State'],
                    'DesiredState' => $task['DesiredState'],
                    'Timestamp' => $task['Status']['Timestamp'],
                    'Origin' => $origin,
                    'Message' => $task['Status']['Message'] ?? '',
                    'Error' => $task['Status']['Err'] ?? ''
                ];
            }

            $services_with_tasks[] = ['Name' => $service_name, 'Image' => $service['Spec']['TaskTemplate']['ContainerSpec']['Image'], 'Tasks' => $service_tasks];
        }

        echo json_encode(['status' => 'success', 'data' => $services_with_tasks]);
        $conn->close();
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $search = trim($_GET['search'] ?? '');
        $limit_get = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
        $limit = ($limit_get == -1) ? 1000000 : $limit_get;
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $sort = $_GET['sort'] ?? 'Name';
        $order = $_GET['order'] ?? 'asc';
        
        $dockerInfo = $dockerClient->getInfo();
        $is_swarm_manager = (isset($dockerInfo['Swarm']['ControlAvailable']) && $dockerInfo['Swarm']['ControlAvailable'] === true);
        $discovered_stacks = [];

        // Fetch managed stacks from DB first for both host types
        $stmt_managed = $conn->prepare("SELECT id, stack_name, source_type, autoscaling_enabled, autoscaling_cpu_threshold_up, autoscaling_cpu_threshold_down, deploy_placement_constraint FROM application_stacks WHERE host_id = ?");
        $stmt_managed->bind_param("i", $host_id);
        $stmt_managed->execute();
        $managed_stacks_result = $stmt_managed->get_result();
        $managed_stacks_map = [];
        while ($row = $managed_stacks_result->fetch_assoc()) {
            $managed_stacks_map[$row['stack_name']] = [
                'id' => $row['id'], 
                'source_type' => $row['source_type'],
                'autoscaling_enabled' => $row['autoscaling_enabled'],
                'autoscaling_cpu_threshold_up' => $row['autoscaling_cpu_threshold_up'],
                'autoscaling_cpu_threshold_down' => $row['autoscaling_cpu_threshold_down'],
                'deploy_placement_constraint' => $row['deploy_placement_constraint']
            ];
        }
        $stmt_managed->close();

        // Check if the node is a Swarm manager
        if ($is_swarm_manager) { // Swarm Logic
            // --- SWARM LOGIC ---
            $remote_services = $dockerClient->listServices();
            $remote_tasks = $dockerClient->listTasks();

            // Create a map of running tasks per service for efficiency
            $running_tasks_per_service = [];
            foreach ($remote_tasks as $task) {
                $service_id = $task['ServiceID'];
                if (!isset($running_tasks_per_service[$service_id])) {
                    $running_tasks_per_service[$service_id] = 0;
                }
                if (isset($task['Status']['State']) && $task['Status']['State'] === 'running') {
                    $running_tasks_per_service[$service_id]++;
                }
            }

            foreach ($remote_services as $service) {
                $stack_namespace = $service['Spec']['Labels']['com.docker.stack.namespace'] ?? null;
                if ($stack_namespace) {
                    if (!isset($discovered_stacks[$stack_namespace])) {
                        $db_info = $managed_stacks_map[$stack_namespace] ?? null;
                        $discovered_stacks[$stack_namespace] = [
                            'Name' => $stack_namespace, 
                            'Services' => 0, 
                            'RunningServices' => 0,
                            'DesiredServices' => 0,
                            'CreatedAt' => $service['CreatedAt'], 
                            'DbId' => $db_info['id'] ?? null, 
                            'SourceType' => $db_info['source_type'] ?? null,
                            'AutoscalingEnabled' => $db_info['autoscaling_enabled'] ?? 0,
                            'ThresholdUp' => $db_info['autoscaling_cpu_threshold_up'] ?? 0,
                            'ThresholdDown' => $db_info['autoscaling_cpu_threshold_down'] ?? 0,
                            'PlacementConstraint' => $db_info['deploy_placement_constraint'] ?? null
                        ];
                    }
                    $discovered_stacks[$stack_namespace]['Services']++;
                    $desired_replicas = $service['Spec']['Mode']['Replicated']['Replicas'] ?? 1;
                    $running_replicas = $running_tasks_per_service[$service['ID']] ?? 0;
                    
                    $discovered_stacks[$stack_namespace]['DesiredServices'] += $desired_replicas;
                    $discovered_stacks[$stack_namespace]['RunningServices'] += $running_replicas;

                    if (strtotime($service['CreatedAt']) < strtotime($discovered_stacks[$stack_namespace]['CreatedAt'])) {
                        $discovered_stacks[$stack_namespace]['CreatedAt'] = $service['CreatedAt'];
                    }
                }
            }
        } else { // Standalone Logic
            // --- STANDALONE LOGIC ---
            $containers = $dockerClient->listContainers();
            foreach ($containers as $container) {
                $compose_project = $container['Labels']['com.docker.compose.project'] ?? null;
                if ($compose_project) {
                    if (!isset($discovered_stacks[$compose_project])) {
                        $db_info = $managed_stacks_map[$compose_project] ?? null;
                        $discovered_stacks[$compose_project] = [
                            'Name' => $compose_project,
                            'Services' => 0,
                            'RunningServices' => 0,
                            'StoppedServices' => 0,
                            'CreatedAt' => date('c', $container['Created']),
                            'DbId' => $db_info['id'] ?? null,
                            'SourceType' => $db_info['source_type'] ?? null,
                            'AutoscalingEnabled' => $db_info['autoscaling_enabled'] ?? 0,
                            'ThresholdUp' => $db_info['autoscaling_cpu_threshold_up'] ?? 0,
                            'ThresholdDown' => $db_info['autoscaling_cpu_threshold_down'] ?? 0,
                            'PlacementConstraint' => $db_info['deploy_placement_constraint'] ?? null
                        ];
                    }
                    $discovered_stacks[$compose_project]['Services']++;
                    if ($container['State'] === 'running') {
                        $discovered_stacks[$compose_project]['RunningServices']++;
                    } else {
                        $discovered_stacks[$compose_project]['StoppedServices']++;
                    }

                    if ($container['Created'] < strtotime($discovered_stacks[$compose_project]['CreatedAt'])) {
                        $discovered_stacks[$compose_project]['CreatedAt'] = date('c', $container['Created']);
                    }
                }
            }
        }
        
        // Filter by search term if provided
        if (!empty($search)) {
            $discovered_stacks = array_filter($discovered_stacks, function($stack_data) use ($search) {
                return stripos($stack_name, $search) !== false;
            }, ARRAY_FILTER_USE_BOTH);
        }

        $discovered_stacks = array_values($discovered_stacks); // Convert to indexed array for sorting

        // Sort the data
        usort($discovered_stacks, function($a, $b) use ($sort, $order) {
            $valA = $a[$sort] ?? null;
            $valB = $b[$sort] ?? null;

            if ($sort === 'CreatedAt') {
                $valA = strtotime($valA);
                $valB = strtotime($valB);
            }

            // For numeric fields, use numeric comparison
            if (in_array($sort, ['Services', 'RunningServices', 'ThresholdUp'])) {
                 $comparison = ($valA ?? 0) <=> ($valB ?? 0);
            } else {
                $comparison = strnatcasecmp((string)$valA, (string)$valB);
            }

            return ($order === 'asc') ? $comparison : -$comparison;
        });

        // Paginate the results
        $total_items = count($discovered_stacks);
        $total_pages = ($limit_get == -1) ? 1 : ceil($total_items / $limit);
        $offset = ($page - 1) * $limit;
        $paginated_stacks = array_slice($discovered_stacks, $offset, $limit);

        $stacks = [];
        foreach ($paginated_stacks as $stack_data) {
            $stacks[] = [
                'ID' => $stack_data['Name'],
                'Name' => $stack_data['Name'],
                'Services' => $stack_data['Services'],
                'RunningServices' => $stack_data['RunningServices'] ?? 0,
                'DesiredServices' => $stack_data['DesiredServices'] ?? 0,
                'StoppedServices' => $stack_data['StoppedServices'] ?? 0,
                'CreatedAt' => $stack_data['CreatedAt'],
                'DbId' => $stack_data['DbId'] ?? null,
                'SourceType' => $stack_data['SourceType'] ?? null,
                'AutoscalingEnabled' => $stack_data['AutoscalingEnabled'] ?? 0,
                'ThresholdUp' => $stack_data['ThresholdUp'] ?? 0,
                'ThresholdDown' => $stack_data['ThresholdDown'] ?? 0,
                'PlacementConstraint' => $stack_data['PlacementConstraint'] ?? null
            ];
        }

        echo json_encode([
            'status' => 'success', 
            'data' => $stacks, 
            'is_swarm_manager' => $is_swarm_manager,
            'total_pages' => $total_pages,
            'current_page' => $page,
            'limit' => $limit_get,
            'info' => "Showing <strong>" . count($paginated_stacks) . "</strong> of <strong>{$total_items}</strong> stacks."
        ]);

    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? 'create';

        $dockerInfo = $dockerClient->getInfo();
        $is_swarm_manager = (isset($dockerInfo['Swarm']['ControlAvailable']) && $dockerInfo['Swarm']['ControlAvailable'] === true);

        if ($action === 'create') {
            if (!$is_swarm_manager) throw new Exception('Stack creation via this form is only supported on Docker Swarm managers.');

            $name = trim($_POST['name'] ?? '');
            $compose_array = buildComposeArrayFromPost($_POST);
            $compose_content = Spyc::YAMLDump($compose_array, 2, 0);

            if (empty($name) || empty($compose_content)) {
                throw new InvalidArgumentException("Stack name and compose content are required.");
            }

            // Create on remote host
            $dockerClient->createStack($name, $compose_content);

            // --- Save to application_stacks to make it manageable ---
            $source_type = 'builder'; // A new type to identify stacks from the form builder
            $compose_file_to_save = 'docker-compose.yml'; // A conventional name
            $deployment_details_to_save = $_POST;
            unset($deployment_details_to_save['host_id'], $deployment_details_to_save['action']);
            $deployment_details_json = json_encode($deployment_details_to_save);

            $stmt_stack = $conn->prepare(
                "INSERT INTO application_stacks (host_id, stack_name, source_type, compose_file_path, deployment_details) 
                 VALUES (?, ?, ?, ?, ?)"
            );
            $stmt_stack->bind_param("issss", $host_id, $name, $source_type, $compose_file_to_save, $deployment_details_json);
            $stmt_stack->execute();
            $stmt_stack->close();

            log_activity($_SESSION['username'], 'Stack Created', "Created stack '{$name}' on host '{$host['name']}'.");
            echo json_encode(['status' => 'success', 'message' => "Stack '{$name}' created successfully on host '{$host['name']}'."]);

        } elseif ($action === 'update') {
            if (!$is_swarm_manager) throw new Exception('Stack editing via this form is only supported on Docker Swarm managers.');

            $stack_db_id = $_POST['stack_db_id'] ?? null;
            $name = trim($_POST['name'] ?? '');
            if (empty($stack_db_id) || empty($name)) {
                throw new InvalidArgumentException("Stack DB ID and name are required for update.");
            }

            $compose_array = buildComposeArrayFromPost($_POST);
            $compose_content = Spyc::YAMLDump($compose_array, 2, 0);

            $dockerClient->createStack($name, $compose_content);

            $deployment_details_to_save = $_POST;
            unset($deployment_details_to_save['host_id'], $deployment_details_to_save['action'], $deployment_details_to_save['stack_db_id']);
            $deployment_details_json = json_encode($deployment_details_to_save);

            $stmt_stack = $conn->prepare("UPDATE application_stacks SET deployment_details = ?, updated_at = NOW() WHERE id = ?");
            $stmt_stack->bind_param("si", $deployment_details_json, $stack_db_id);
            $stmt_stack->execute();
            $stmt_stack->close();

            log_activity($_SESSION['username'], 'Stack Edited', "Edited stack '{$name}' on host '{$host['name']}'.");
            echo json_encode(['status' => 'success', 'message' => "Stack '{$name}' updated successfully on host '{$host['name']}'."]);

        } elseif ($action === 'delete') {
            $stack_name = $_POST['stack_name'] ?? '';
            $git_sync_warning = '';

            if (empty($stack_name)) {
                throw new InvalidArgumentException("Stack name is required for deletion.");
            }

            if ($is_swarm_manager) {
                // --- SWARM DELETE ---
                $env_vars = "DOCKER_HOST=" . escapeshellarg($host['docker_api_url']);
                $cert_dir = null;
                try {
                    if ($host['tls_enabled']) {
                        $cert_dir = rtrim(sys_get_temp_dir(), '/') . '/docker_certs_' . uniqid();
                        if (!mkdir($cert_dir, 0700, true)) throw new Exception("Could not create temporary cert directory.");
                        
                        if (!file_exists($host['ca_cert_path']) || !file_exists($host['client_cert_path']) || !file_exists($host['client_key_path'])) {
                            throw new Exception("One or more TLS certificate files for host '{$host['name']}' not found on the application server.");
                        }
                        
                        copy($host['ca_cert_path'], $cert_dir . '/ca.pem');
                        copy($host['client_cert_path'], $cert_dir . '/cert.pem');
                        copy($host['client_key_path'], $cert_dir . '/key.pem');

                        $env_vars .= " DOCKER_TLS_VERIFY=1 DOCKER_CERT_PATH=" . escapeshellarg($cert_dir);
                    }

                    $command = "docker stack rm " . escapeshellarg($stack_name) . " 2>&1";
                    $full_command = 'env ' . $env_vars . ' ' . $command;

                    exec($full_command, $output, $return_var);

                    if ($return_var !== 0) {
                        throw new Exception("Failed to remove Swarm stack. Output: " . implode("\n", $output));
                    }
                } finally {
                    // Always clean up the temporary cert directory
                    if ($cert_dir && is_dir($cert_dir)) {
                        shell_exec("rm -rf " . escapeshellarg($cert_dir));
                    }
                }
            } else {
                // --- STANDALONE DELETE ---
                $base_compose_path = get_setting('default_compose_path', '');
                if (empty($base_compose_path)) {
                    throw new Exception("Cannot delete stack. A 'Default Compose File Path' must be configured for the host to manage its stacks.");
                }
                $safe_host_name = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $host['name']);
                $deployment_dir = rtrim($base_compose_path, '/') . '/' . $safe_host_name . '/' . $stack_name;
                if (!is_dir($deployment_dir)) {
                    throw new Exception("Deployment directory '{$deployment_dir}' not found. The stack might have been deployed without a persistent path or removed manually.");
                }

                $stmt_stack = $conn->prepare("SELECT compose_file_path FROM application_stacks WHERE host_id = ? AND stack_name = ?");
                $stmt_stack->bind_param("is", $host_id, $stack_name);
                $stmt_stack->execute();
                $stack_record = $stmt_stack->get_result()->fetch_assoc();
                $stmt_stack->close();
                if (!$stack_record) {
                    throw new Exception("Stack '{$stack_name}' not found in the application's database. It cannot be managed automatically.");
                }
                $compose_filename = $stack_record['compose_file_path'];

                $env_vars = "DOCKER_HOST=" . escapeshellarg($host['docker_api_url']) . " COMPOSE_NONINTERACTIVE=1";
                if ($host['tls_enabled']) {
                    $env_vars .= " DOCKER_TLS_VERIFY=1 DOCKER_CERT_PATH=" . escapeshellarg($deployment_dir . '/certs');
                }

                $cd_command = "cd " . escapeshellarg($deployment_dir);
                $compose_down_command = "docker compose -p " . escapeshellarg($stack_name) . " -f " . escapeshellarg($compose_filename) . " down --remove-orphans --volumes 2>&1";
                $full_command = $env_vars . ' ' . $cd_command . ' && ' . $compose_down_command;

                exec($full_command, $output, $return_var);
                if ($return_var !== 0) {
                    // Log the error but continue to attempt cleanup
                    error_log("Docker-compose down command failed for stack '{$stack_name}' on host '{$host['name']}'. Output: " . implode("\n", $output));
                }

                shell_exec("rm -rf " . escapeshellarg($deployment_dir));
            }

            // Also delete from our application_stacks table
            $stmt_delete_db = $conn->prepare("DELETE FROM application_stacks WHERE host_id = ? AND stack_name = ?");
            $stmt_delete_db->bind_param("is", $host_id, $stack_name);
            $stmt_delete_db->execute();
            $stmt_delete_db->close();

            // Log the deletion to the new stack_change_log table
            $stmt_log_change = $conn->prepare(
                "INSERT INTO stack_change_log (host_id, stack_name, change_type, details, changed_by) VALUES (?, ?, 'deleted', ?, ?)"
            );
            $changed_by = $_SESSION['username'] ?? 'system';
            $stack_type = $is_swarm_manager ? 'Swarm' : 'Standalone';
            $details = "Stack removed from host ({$stack_type}).";
            $stmt_log_change->bind_param("isss", $host_id, $stack_name, $details, $changed_by);
            $stmt_log_change->execute();
            $stmt_log_change->close();

            // --- Sync deletion to Git repository ---
            $git_enabled = (bool)get_setting('git_integration_enabled', false);
            if ($git_enabled) {
                $repo_path_for_delete = null; // Define to be accessible in finally
                try {
                    $git = new GitHelper();
                    $repo_path_for_delete = $git->setupRepository();

                    $safe_host_name = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $host['name']);
                    $directory_to_remove = "{$safe_host_name}/{$stack_name}";
                    $commit_message = "Remove stack '{$stack_name}' from host '{$host['name']}'";

                    $full_path_to_remove = $repo_path_for_delete . '/' . $directory_to_remove;

                    if (file_exists($full_path_to_remove)) {
                        // The directory exists, so we can remove it.
                        $git_rm_command = "git -C " . escapeshellarg($repo_path_for_delete) . " rm -r -f " . escapeshellarg($directory_to_remove);
                        exec($git_rm_command, $output, $return_var);
                        if ($return_var !== 0) {
                            throw new Exception("Git remove command failed. Output: " . implode("\n", $output));
                        }

                        // Check git status to see if there are changes to commit
                        $git_status_command = "git -C " . escapeshellarg($repo_path_for_delete) . " status --porcelain";
                        exec($git_status_command, $status_output, $status_return_var);

                        if (!empty($status_output)) {
                            // There are changes, so commit and push
                            $git->configure($repo_path_for_delete);
                            $git_commit_command = "git -C " . escapeshellarg($repo_path_for_delete) . " commit -m " . escapeshellarg($commit_message);
                            exec($git_commit_command, $output, $return_var);
                            if ($return_var !== 0) throw new Exception("Git commit command failed. Output: " . implode("\n", $output));
                            $git->push($repo_path_for_delete);
                        }
                    }

                } catch (Exception $git_e) {
                    // If the Git operation fails, log it and append a warning to the success message.
                    error_log("Config Manager: Failed to sync stack deletion to Git for '{$stack_name}'. Error: " . $git_e->getMessage());
                    $git_sync_warning = " Warning: Failed to sync deletion to Git repository. Please check logs.";
                } finally {
                    if (isset($git) && isset($repo_path_for_delete) && !$git->isPersistentPath($repo_path_for_delete)) {
                        $git->cleanup($repo_path_for_delete);
                    }
                }
            }

            log_activity($_SESSION['username'], 'Stack Deleted', "Deleted stack '{$stack_name}' on host '{$host['name']}'.");
            echo json_encode(['status' => 'success', 'message' => "Stack successfully deleted." . $git_sync_warning]);
        } else {
            throw new InvalidArgumentException("Invalid action specified.");
        }
    } else {
        throw new InvalidArgumentException("Unsupported request method.");
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();

// This function is duplicated from api/stack_handler.php.
// In a larger refactor, this should be moved to a shared helper/class.
function buildComposeArrayFromPost(array $postData): array {
    $compose = ['version' => '3.8', 'services' => [], 'networks' => []];
    $is_swarm_manager = $postData['is_swarm_manager'] ?? false; // Pass this info to the function

    if (isset($postData['services']) && is_array($postData['services'])) {
        foreach ($postData['services'] as $serviceData) {
            $serviceName = $serviceData['name'];
            if (empty($serviceName)) continue;
            $compose['services'][$serviceName] = ['image' => $serviceData['image'] ?? 'alpine:latest'];

            $replicas = $serviceData['deploy']['replicas'] ?? null;
            $cpus = $serviceData['deploy']['resources']['limits']['cpus'] ?? null;
            $memory = $serviceData['deploy']['resources']['limits']['memory'] ?? null;

            if ($is_swarm_manager) {
                if ($replicas || $cpus || $memory) {
                    $compose['services'][$serviceName]['deploy'] = [];
                    if ($replicas) $compose['services'][$serviceName]['deploy']['replicas'] = (int)$replicas;
                    if ($cpus || $memory) {
                        $compose['services'][$serviceName]['deploy']['resources']['limits'] = [];
                        if ($cpus) $compose['services'][$serviceName]['deploy']['resources']['limits']['cpus'] = $cpus;
                        if ($memory) $compose['services'][$serviceName]['deploy']['resources']['limits']['memory'] = $memory;
                    }
                }
            } else { // Standalone Host
                $compose['services'][$serviceName]['restart'] = 'unless-stopped';
                if ($cpus) $compose['services'][$serviceName]['cpus'] = $cpus;
                if ($memory) $compose['services'][$serviceName]['mem_limit'] = $memory;
            }

            foreach (['ports', 'environment', 'volumes', 'networks', 'depends_on'] as $key) {
                if (!empty($serviceData[$key]) && is_array($serviceData[$key])) $compose['services'][$serviceName][$key] = array_values(array_filter($serviceData[$key]));
            }
        }
    }
    if (isset($postData['networks']) && is_array($postData['networks'])) {
        foreach ($postData['networks'] as $networkData) {
            $networkName = $networkData['name'];
            if (!empty($networkName)) {
                // Correctly create a map, not a list of maps
                $compose['networks'][$networkName] = new stdClass(); // Use an empty object for correct YAML output
            }
        }
    }
    if (empty($compose['networks'])) {
        unset($compose['networks']);
    }
    return $compose;
}
?>