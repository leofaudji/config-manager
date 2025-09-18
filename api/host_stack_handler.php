<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/DockerClient.php';
require_once __DIR__ . '/../includes/Spyc.php';

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
            $full_compose_path = rtrim($base_compose_path, '/') . '/' . $stack_name . '/' . $compose_filename;

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

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $dockerInfo = $dockerClient->getInfo();
        $search = trim($_GET['search'] ?? '');
        $limit_get = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
        $limit = ($limit_get == -1) ? 1000000 : $limit_get;
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $sort = $_GET['sort'] ?? 'Name';
        $order = $_GET['order'] ?? 'asc';
        $is_swarm_manager = (isset($dockerInfo['Swarm']['ControlAvailable']) && $dockerInfo['Swarm']['ControlAvailable'] === true);
        $discovered_stacks = [];

        // --- UNIFIED LOGIC: Fetch managed stacks from DB first for both host types ---
        $stmt_managed = $conn->prepare("SELECT id, stack_name, source_type FROM application_stacks WHERE host_id = ?");
        $stmt_managed->bind_param("i", $host_id);
        $stmt_managed->execute();
        $managed_stacks_result = $stmt_managed->get_result();
        $managed_stacks_map = [];
        while ($row = $managed_stacks_result->fetch_assoc()) {
            $managed_stacks_map[$row['stack_name']] = ['id' => $row['id'], 'source_type' => $row['source_type']];
        }
        $stmt_managed->close();

        // Check if the node is a Swarm manager
        if ($is_swarm_manager) {
            $remote_services = $dockerClient->listServices();
            foreach ($remote_services as $service) {
                $stack_namespace = $service['Spec']['Labels']['com.docker.stack.namespace'] ?? null;
                if ($stack_namespace) {
                    if (!isset($discovered_stacks[$stack_namespace])) {
                        $db_info = $managed_stacks_map[$stack_namespace] ?? null;
                        $discovered_stacks[$stack_namespace] = ['Name' => $stack_namespace, 'Services' => 0, 'CreatedAt' => $service['CreatedAt'], 'DbId' => $db_info['id'] ?? null, 'SourceType' => $db_info['source_type'] ?? null];
                    }
                    $discovered_stacks[$stack_namespace]['Services']++;
                    if (strtotime($service['CreatedAt']) < strtotime($discovered_stacks[$stack_namespace]['CreatedAt'])) {
                        $discovered_stacks[$stack_namespace]['CreatedAt'] = $service['CreatedAt'];
                    }
                }
            }
        } else {
            // Not a swarm manager, so we look for docker-compose projects from container labels.
            // The $managed_stacks_map is already prepared.
            $containers = $dockerClient->listContainers();
            foreach ($containers as $container) {
                $compose_project = $container['Labels']['com.docker.compose.project'] ?? null;
                if ($compose_project) {
                    if (!isset($discovered_stacks[$compose_project])) {
                        $db_info = $managed_stacks_map[$compose_project] ?? null;
                        $discovered_stacks[$compose_project] = [
                            'Name' => $compose_project, 
                            'Services' => 0, 
                            'CreatedAt' => date('c', $container['Created']),
                            'DbId' => $db_info['id'] ?? null,
                            'SourceType' => $db_info['source_type'] ?? null
                        ];
                    }
                    $discovered_stacks[$compose_project]['Services']++;
                    if ($container['Created'] < strtotime($discovered_stacks[$compose_project]['CreatedAt'])) {
                        $discovered_stacks[$compose_project]['CreatedAt'] = date('c', $container['Created']);
                    }
                }
            }
        }

        // Filter by search term if provided
        if (!empty($search)) {
            $discovered_stacks = array_filter($discovered_stacks, function($stack_data, $stack_name) use ($search) {
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

            $comparison = strnatcasecmp((string)$valA, (string)$valB);
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
                'CreatedAt' => $stack_data['CreatedAt'],
                'DbId' => $stack_data['DbId'] ?? null,
                'SourceType' => $stack_data['SourceType'] ?? null
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

            if (empty($stack_name)) {
                throw new InvalidArgumentException("Stack name is required for deletion.");
            }

            if ($is_swarm_manager) {
                // --- SWARM DELETE ---
                $dockerClient->removeStack($stack_name);
            } else {
                // --- STANDALONE DELETE ---
                $base_compose_path = get_setting('default_compose_path', '');
                if (empty($base_compose_path)) {
                    throw new Exception("Cannot delete stack. A 'Default Compose File Path' must be configured for the host to manage its stacks.");
                }
                $deployment_dir = rtrim($base_compose_path, '/') . '/' . $stack_name;
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
                $compose_down_command = "docker-compose -p " . escapeshellarg($stack_name) . " -f " . escapeshellarg($compose_filename) . " down --remove-orphans --volumes 2>&1";
                $full_command = $env_vars . ' ' . $cd_command . ' && ' . $compose_down_command;

                exec($full_command, $output, $return_var);
                if ($return_var !== 0) throw new Exception("Docker-compose down command failed. Output: " . implode("\n", $output));

                shell_exec("rm -rf " . escapeshellarg($deployment_dir));
            }

            // Also delete from our application_stacks table
            $stmt_delete_db = $conn->prepare("DELETE FROM application_stacks WHERE host_id = ? AND stack_name = ?");
            $stmt_delete_db->bind_param("is", $host_id, $stack_name);
            $stmt_delete_db->execute();
            $stmt_delete_db->close();

            log_activity($_SESSION['username'], 'Stack Deleted', "Deleted stack '{$stack_name}' on host '{$host['name']}'.");
            echo json_encode(['status' => 'success', 'message' => "Stack successfully deleted."]);
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
    if (isset($postData['services']) && is_array($postData['services'])) {
        foreach ($postData['services'] as $serviceData) {
            $serviceName = $serviceData['name'];
            if (empty($serviceName)) continue;
            $compose['services'][$serviceName] = ['image' => $serviceData['image'] ?? 'alpine:latest'];
            if (!empty($serviceData['deploy']['replicas']) || !empty($serviceData['deploy']['resources']['limits']['cpus']) || !empty($serviceData['deploy']['resources']['limits']['memory'])) {
                $compose['services'][$serviceName]['deploy'] = [];
                if (!empty($serviceData['deploy']['replicas'])) $compose['services'][$serviceName]['deploy']['replicas'] = (int)$serviceData['deploy']['replicas'];
                if (!empty($serviceData['deploy']['resources']['limits']['cpus']) || !empty($serviceData['deploy']['resources']['limits']['memory'])) {
                    $compose['services'][$serviceName]['deploy']['resources']['limits'] = [];
                    if (!empty($serviceData['deploy']['resources']['limits']['cpus'])) $compose['services'][$serviceName]['deploy']['resources']['limits']['cpus'] = $serviceData['deploy']['resources']['limits']['cpus'];
                    if (!empty($serviceData['deploy']['resources']['limits']['memory'])) $compose['services'][$serviceName]['deploy']['resources']['limits']['memory'] = $serviceData['deploy']['resources']['limits']['memory'];
                }
            }
            foreach (['ports', 'environment', 'volumes', 'networks', 'depends_on'] as $key) {
                if (!empty($serviceData[$key]) && is_array($serviceData[$key])) $compose['services'][$serviceName][$key] = array_values(array_filter($serviceData[$key]));
            }
        }
    }
    if (isset($postData['networks']) && is_array($postData['networks'])) {
        foreach ($postData['networks'] as $networkData) {
            $networkName = $networkData['name'];
            if (empty($networkName)) continue;
            $compose['networks'][$networkName] = null;
        }
    }
    return $compose;
}
?>