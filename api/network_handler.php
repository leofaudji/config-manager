<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/DockerClient.php';

header('Content-Type: application/json');

$request_uri_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$basePath = BASE_PATH;
if ($basePath && strpos($request_uri_path, $basePath) === 0) {
    $request_uri_path = substr($request_uri_path, strlen($basePath));
}

$conn = Database::getInstance()->getConnection();

// --- Main Logic ---
try {
    // Extract Host ID
    if (!preg_match('/^\/api\/hosts\/(\d+)\//', $request_uri_path, $matches)) {
        throw new InvalidArgumentException("Invalid API endpoint format.");
    }
    $host_id = $matches[1];

    // Get Host details
    $stmt = $conn->prepare("SELECT * FROM docker_hosts WHERE id = ?");
    $stmt->bind_param("i", $host_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if (!($host = $result->fetch_assoc())) {
        throw new Exception("Host not found.");
    }
    $stmt->close();

    $dockerClient = new DockerClient($host);

    // --- Handle different request methods ---
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Handle inspect single volume
        if (preg_match('/^\/api\/hosts\/\d+\/volumes\/([a-zA-Z0-9_.-]+)$/', $request_uri_path, $volume_matches)) {
            $volume_name = $volume_matches[1];
            $volume_details = $dockerClient->inspectVolume($volume_name);
            echo json_encode(['status' => 'success', 'data' => $volume_details]);
            $conn->close();
            exit;
        }

        if (str_ends_with($request_uri_path, '/networks')) {
            // LIST networks
            $networks = $dockerClient->listNetworks();
            $sort = $_GET['sort'] ?? 'Name';
            $order = $_GET['order'] ?? 'asc';
            $limit_get = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
            $limit = ($limit_get == -1) ? 1000000 : $limit_get;
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;

            $search = trim($_GET['search'] ?? '');
            if (!empty($search)) {
                $networks = array_filter($networks, function($net) use ($search) {
                    if (stripos($net['Name'], $search) !== false) return true;
                    if (isset($net['IPAM']['Config'][0]['Subnet']) && stripos($net['IPAM']['Config'][0]['Subnet'], $search) !== false) return true;
                    if (isset($net['IPAM']['Config'][0]['Gateway']) && stripos($net['IPAM']['Config'][0]['Gateway'], $search) !== false) return true;
                    return false;
                });
            }

            $total_items = count($networks);
            $total_pages = ($limit_get == -1) ? 1 : ceil($total_items / $limit);
            $offset = ($page - 1) * $limit;
            $paginated_networks = array_slice(array_values($networks), $offset, $limit);

            echo json_encode([
                'status' => 'success', 
                'data' => $paginated_networks,
                'total_pages' => $total_pages,
                'current_page' => $page,
                'limit' => $limit_get,
                'info' => "Showing <strong>" . count($paginated_networks) . "</strong> of <strong>{$total_items}</strong> networks."
            ]);
        } elseif (str_ends_with($request_uri_path, '/volumes')) {
            // LIST volumes
            $volumes = $dockerClient->listVolumes();
            $volumes_list = $volumes['Volumes'] ?? [];
            $sort = $_GET['sort'] ?? 'Name';
            $order = $_GET['order'] ?? 'asc';
            $limit_get = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
            $limit = ($limit_get == -1) ? 1000000 : $limit_get;
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;

            $search = trim($_GET['search'] ?? '');
            if (!empty($search)) {
                $volumes_list = array_filter($volumes_list, function($vol) use ($search) {
                    return stripos($vol['Name'], $search) !== false;
                });
            }

            // Convert to indexed array for sorting
            $volumes_list = array_values($volumes_list);

            // Sort the data
            usort($volumes_list, function($a, $b) use ($sort, $order) {
                $valA = $a[$sort] ?? null;
                $valB = $b[$sort] ?? null;

                if ($sort === 'CreatedAt') {
                    $valA = strtotime($valA ?? '');
                    $valB = strtotime($valB ?? '');
                }

                $comparison = strnatcasecmp((string)$valA, (string)$valB);
                return ($order === 'asc') ? $comparison : -$comparison;
            });

            $total_items = count($volumes_list);
            $total_pages = ($limit_get == -1) ? 1 : ceil($total_items / $limit);
            $offset = ($page - 1) * $limit;
            $paginated_volumes = array_slice($volumes_list, $offset, $limit);

            echo json_encode([
                'status' => 'success', 
                'data' => $paginated_volumes,
                'total_pages' => $total_pages,
                'current_page' => $page,
                'limit' => $limit_get,
                'info' => "Showing <strong>" . count($paginated_volumes) . "</strong> of <strong>{$total_items}</strong> volumes."
            ]);
        } elseif (str_ends_with($request_uri_path, '/images')) {
            // LIST images
            $url = $host['docker_api_url'];
            $is_socket = strpos($url, 'unix://') === 0;
            $sort = $_GET['sort'] ?? 'RepoTags';
            $order = $_GET['order'] ?? 'asc';
            $limit_get = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
            $limit = ($limit_get == -1) ? 1000000 : $limit_get;
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;

            $ch = curl_init();
            
            if ($is_socket) {
                $socket_path = substr($url, 7);
                curl_setopt($ch, CURLOPT_UNIX_SOCKET_PATH, $socket_path);
                curl_setopt($ch, CURLOPT_URL, 'http://localhost/images/json'); // URL is arbitrary for socket
            } else {
                // Convert tcp:// to http(s):// for cURL
                $curl_url = ($host['tls_enabled'] ? 'https://' : 'http://') . str_replace('tcp://', '', $url);
                curl_setopt($ch, CURLOPT_URL, rtrim($curl_url, '/') . '/images/json');
            }

            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);

            if (!empty($host['tls_enabled'])) {
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
                if (!empty($host['ca_cert_path']) && file_exists($host['ca_cert_path'])) curl_setopt($ch, CURLOPT_CAINFO, $host['ca_cert_path']);
                if (!empty($host['client_cert_path']) && file_exists($host['client_cert_path'])) curl_setopt($ch, CURLOPT_SSLCERT, $host['client_cert_path']);
                if (!empty($host['client_key_path']) && file_exists($host['client_key_path'])) curl_setopt($ch, CURLOPT_SSLKEY, $host['client_key_path']);
            } else {
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            }

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) throw new Exception("cURL Error: " . $error);
            if ($http_code !== 200) throw new Exception("Docker API returned HTTP " . $http_code . ". Response: " . $response);

            $images_data = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) throw new Exception("Failed to decode JSON response from Docker API.");

            $search = trim($_GET['search'] ?? '');
            if (!empty($search)) {
                $images_data = array_filter($images_data, function($img) use ($search) {
                    if (isset($img['RepoTags']) && is_array($img['RepoTags'])) {
                        foreach ($img['RepoTags'] as $tag) {
                            if (stripos($tag, $search) !== false) {
                                return true;
                            }
                        }
                    }
                    // Also search by image ID
                    if (isset($img['Id']) && stripos($img['Id'], $search) !== false) {
                        return true;
                    }
                    return false;
                });
            }

            usort($images_data, function($a, $b) use ($sort, $order) {
                $valA = $a[$sort] ?? null;
                $valB = $b[$sort] ?? null;

                if ($sort === 'RepoTags') {
                    $valA = $a['RepoTags'][0] ?? 'z'; // Put <none> at the end
                    $valB = $b['RepoTags'][0] ?? 'z';
                }

                $comparison = strnatcasecmp((string)$valA, (string)$valB);
                return ($order === 'asc') ? $comparison : -$comparison;
            });

            if (isset($_GET['details']) && $_GET['details'] === 'true') {
                // Filter out dangling images (<none>:<none>) for the detailed view
                $detailed_images = array_filter($images_data, function($image) {
                    return !(empty($image['RepoTags']) || $image['RepoTags'][0] === '<none>:<none>');
                });

                // --- Get all containers to map images to stacks ---
                $all_containers = $dockerClient->listContainers();
                $image_to_stack_map = [];
                foreach ($all_containers as $container) {
                    $image_id = $container['ImageID'] ?? null;
                    $stack_name = $container['Labels']['com.docker.compose.project'] ?? null;
                    if ($image_id && $stack_name) {
                        if (!isset($image_to_stack_map[$image_id])) {
                            $image_to_stack_map[$image_id] = [];
                        }
                        // Add stack name if it's not already in the list for this image
                        if (!in_array($stack_name, $image_to_stack_map[$image_id], true)) {
                            $image_to_stack_map[$image_id][] = $stack_name;
                        }
                    }
                }

                // Add the stack usage data to each image object
                foreach ($detailed_images as &$image) { // Use reference to modify
                    $image['UsedByStacks'] = $image_to_stack_map[$image['Id']] ?? [];
                }
                unset($image); // Unset reference

                $total_items = count($detailed_images);
                $total_pages = ($limit_get == -1) ? 1 : ceil($total_items / $limit);
                $offset = ($page - 1) * $limit;
                $paginated_images = array_slice(array_values($detailed_images), $offset, $limit);
                echo json_encode([
                    'status' => 'success', 
                    'data' => $paginated_images,
                    'total_pages' => $total_pages,
                    'current_page' => $page,
                    'limit' => $limit_get,
                    'info' => "Showing <strong>" . count($paginated_images) . "</strong> of <strong>{$total_items}</strong> images."
                ]);
            } else {
                // Original behavior for App Launcher
                $all_tags = [];
                if (is_array($images_data)) {
                    foreach ($images_data as $image) {
                        if (!empty($image['RepoTags']) && is_array($image['RepoTags'])) {
                            $all_tags = array_merge($all_tags, array_filter($image['RepoTags'], fn($tag) => $tag !== '<none>:<none>'));
                        }
                    }
                }
                echo json_encode(['status' => 'success', 'data' => array_values(array_unique($all_tags))]);
            }
        }

    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Handle prune action on a separate endpoint
        if (str_ends_with($request_uri_path, '/volumes/prune')) {
            $prune_result = $dockerClient->pruneVolumes();
            $space_reclaimed = $prune_result['SpaceReclaimed'] ?? 0;
            // The formatBytes function is available from bootstrap.php
            $formatted_space = formatBytes($space_reclaimed); 
            log_activity($_SESSION['username'], 'Volumes Pruned', "Pruned unused volumes on host '{$host['name']}'. Space reclaimed: {$formatted_space}.");
            echo json_encode(['status' => 'success', 'message' => "Unused volumes successfully pruned. Space reclaimed: {$formatted_space}."]);
            $conn->close();
            exit;
        }

        // Handle network prune action
        if (str_ends_with($request_uri_path, '/networks/prune')) {
            $prune_result = $dockerClient->pruneNetworks();
            $networks_deleted = $prune_result['NetworksDeleted'] ?? [];
            $deleted_count = count($networks_deleted);

            log_activity($_SESSION['username'], 'Networks Pruned', "Pruned {$deleted_count} unused networks on host '{$host['name']}'.");
            echo json_encode(['status' => 'success', 'message' => "Unused networks successfully pruned. {$deleted_count} network(s) removed."]);
            $conn->close();
            exit;
        }

        // Handle image prune action
        if (str_ends_with($request_uri_path, '/images/prune')) {
            $prune_result = $dockerClient->pruneImages();
            $space_reclaimed = $prune_result['SpaceReclaimed'] ?? 0;
            // The formatBytes function is available from bootstrap.php
            $formatted_space = formatBytes($space_reclaimed); 
            log_activity($_SESSION['username'], 'Images Pruned', "Pruned unused images on host '{$host['name']}'. Space reclaimed: {$formatted_space}.");
            echo json_encode(['status' => 'success', 'message' => "Unused images successfully pruned. Space reclaimed: {$formatted_space}."]);
            $conn->close();
            exit;
        }

        // Handle container prune action
        if (str_ends_with($request_uri_path, '/containers/prune')) {
            $prune_result = $dockerClient->pruneContainers();
            $deleted_count = count($prune_result['ContainersDeleted'] ?? []);
            $space_reclaimed = $prune_result['SpaceReclaimed'] ?? 0;
            $formatted_space = formatBytes($space_reclaimed);

            log_activity($_SESSION['username'], 'Containers Pruned', "Pruned {$deleted_count} stopped containers on host '{$host['name']}'. Space reclaimed: {$formatted_space}.");
            echo json_encode(['status' => 'success', 'message' => "Successfully pruned {$deleted_count} container(s). Space reclaimed: {$formatted_space}."]);
            $conn->close();
            exit;
        }

        $action = $_POST['action'] ?? 'create';

        if ($action === 'pull_image') {
            // PULL image
            $image_name = trim($_POST['image_name'] ?? '');
            if (empty($image_name)) {
                throw new InvalidArgumentException("Image name is required to pull.");
            }

            $env_vars = "DOCKER_HOST=" . escapeshellarg($host['docker_api_url']);
            $docker_config_dir = null; // For cleanup
            $cert_dir = null; // For cleanup
            if ($host['tls_enabled']) {
                if (!file_exists($host['ca_cert_path'])) throw new Exception("CA certificate not found at: {$host['ca_cert_path']}");
                if (!file_exists($host['client_cert_path'])) throw new Exception("Client certificate not found at: {$host['client_cert_path']}");
                if (!file_exists($host['client_key_path'])) throw new Exception("Client key not found at: {$host['client_key_path']}");

                $cert_dir = rtrim(sys_get_temp_dir(), '/') . '/docker_certs_' . uniqid();
                if (!mkdir($cert_dir, 0700, true)) throw new Exception("Could not create temporary cert directory.");
                
                copy($host['ca_cert_path'], $cert_dir . '/ca.pem');
                copy($host['client_cert_path'], $cert_dir . '/cert.pem');
                copy($host['client_key_path'], $cert_dir . '/key.pem');

                $env_vars .= " DOCKER_TLS_VERIFY=1 DOCKER_CERT_PATH=" . escapeshellarg($cert_dir);
            }

            $login_command = '';
            if (!empty($host['registry_username']) && !empty($host['registry_password'])) {
                // Use a persistent path from .env if available, otherwise use a temporary one.
                $docker_config_path_from_env = Config::get('DOCKER_CONFIG_PATH');
                if (!empty($docker_config_path_from_env)) {
                    if (!is_dir($docker_config_path_from_env) && !mkdir($docker_config_path_from_env, 0755, true)) {
                        throw new Exception("Could not create specified DOCKER_CONFIG_PATH: {$docker_config_path_from_env}. Check permissions.");
                    }
                    $docker_config_dir = $docker_config_path_from_env;
                } else {
                    $docker_config_dir = rtrim(sys_get_temp_dir(), '/') . '/docker_config_' . uniqid();
                    if (!mkdir($docker_config_dir, 0700, true)) throw new Exception("Could not create temporary docker config directory.");
                }
                $env_vars .= " DOCKER_CONFIG=" . escapeshellarg($docker_config_dir);
                $registry_url = !empty($host['registry_url']) ? escapeshellarg($host['registry_url']) : '';
                $login_command = "echo " . escapeshellarg($host['registry_password']) . " | docker login {$registry_url} -u " . escapeshellarg($host['registry_username']) . " --password-stdin 2>&1 && ";
            }

            $pull_command = "docker pull " . escapeshellarg($image_name) . " 2>&1";
            $script_to_run = $login_command . $pull_command;
            $full_command = 'env ' . $env_vars . ' sh -c ' . escapeshellarg($script_to_run);

            
            set_time_limit(300); // 5 minutes
            exec($full_command, $output, $return_var);

            if (isset($cert_dir) && is_dir($cert_dir)) shell_exec("rm -rf " . escapeshellarg($cert_dir));
            // Only remove the config directory if it was a temporary one
            if (empty(Config::get('DOCKER_CONFIG_PATH')) && isset($docker_config_dir) && is_dir($docker_config_dir)) {
                 shell_exec("rm -rf " . escapeshellarg($docker_config_dir));
            }
            if ($return_var !== 0) throw new Exception("Failed to pull image. Output: " . implode("\n", $output));

            log_activity($_SESSION['username'], 'Image Pulled', "Pulled image '{$image_name}' on host '{$host['name']}'.");
            echo json_encode(['status' => 'success', 'message' => "Image '{$image_name}' pulled successfully."]);
        } elseif ($action === 'delete_image') {
            // DELETE image
            $image_id = $_POST['image_id'] ?? '';
            if (empty($image_id)) {
                throw new InvalidArgumentException("Image ID is required for deletion.");
            }
            $dockerClient->removeImage($image_id);
            log_activity($_SESSION['username'], 'Image Deleted', "Deleted image ID '{$image_id}' on host '{$host['name']}'.");
            echo json_encode(['status' => 'success', 'message' => "Image successfully deleted."]);
        } elseif ($action === 'delete_volume') {
            // DELETE volume
            $volume_name = $_POST['volume_name'] ?? '';
            if (empty($volume_name)) {
                throw new InvalidArgumentException("Volume name is required for deletion.");
            }
            $dockerClient->removeVolume($volume_name);
            log_activity($_SESSION['username'], 'Volume Deleted', "Deleted volume '{$volume_name}' on host '{$host['name']}'.");
            echo json_encode(['status' => 'success', 'message' => "Volume successfully deleted."]);
        } elseif ($action === 'create_volume') {
            // CREATE volume
            $name = trim($_POST['name'] ?? '');
            if (empty($name)) {
                throw new InvalidArgumentException("Volume name is required.");
            }

            $config = ['Name' => $name];
            if (!empty($_POST['driver'])) {
                $config['Driver'] = $_POST['driver'];
            }

            if (!empty($_POST['driver_opts']) && is_array($_POST['driver_opts'])) {
                $opts = [];
                foreach ($_POST['driver_opts'] as $opt) {
                    if (!empty($opt['key']) && isset($opt['value'])) $opts[$opt['key']] = $opt['value'];
                }
                if (!empty($opts)) $config['DriverOpts'] = $opts;
            }

            if (!empty($_POST['labels']) && is_array($_POST['labels'])) {
                $labels = [];
                foreach ($_POST['labels'] as $label) {
                    if (!empty($label['key']) && isset($label['value'])) $labels[$label['key']] = $label['value'];
                }
                if (!empty($labels)) $config['Labels'] = $labels;
            }

            $dockerClient->createVolume($config);
            log_activity($_SESSION['username'], 'Volume Created', "Created volume '{$name}' on host '{$host['name']}'.");
            echo json_encode(['status' => 'success', 'message' => "Volume '{$name}' created successfully."]);
        } elseif ($action === 'create') {
            // CREATE network
            $name = trim($_POST['name'] ?? '');
            if (empty($name)) {
                throw new InvalidArgumentException("Network name is required.");
            }
            
            $config = ['Name' => $name];
            if (!empty($_POST['driver'])) {
                $config['Driver'] = $_POST['driver'];
            }
            // Add macvlan parent option if specified
            if ($_POST['driver'] === 'macvlan' && !empty($_POST['macvlan_parent'])) {
                $config['Options'] = ['parent' => $_POST['macvlan_parent']];
            }
            if (isset($_POST['attachable'])) {
                $config['Attachable'] = (bool)$_POST['attachable'];
            }
            if (!empty($_POST['labels']) && is_array($_POST['labels'])) {
                $labels = [];
                foreach ($_POST['labels'] as $label) {
                    if (strpos($label, '=') !== false) {
                        list($key, $value) = explode('=', $label, 2);
                        $labels[trim($key)] = trim($value);
                    }
                }
                if (!empty($labels)) {
                    $config['Labels'] = $labels;
                }
            }

            // IPAM Configuration
            if (!empty($_POST['ipam_subnet'])) {
                $ipamConfig = ['Subnet' => $_POST['ipam_subnet']];
                if (!empty($_POST['ipam_gateway'])) {
                    $ipamConfig['Gateway'] = $_POST['ipam_gateway'];
                }
                if (!empty($_POST['ipam_ip_range'])) {
                    $ipamConfig['IPRange'] = $_POST['ipam_ip_range'];
                }
                $config['IPAM'] = [
                    'Driver' => 'default',
                    'Config' => [$ipamConfig]
                ];
            }

            $response = $dockerClient->createNetwork($config);
            log_activity($_SESSION['username'], 'Network Created', "Created network '{$name}' on host '{$host['name']}'.");
            echo json_encode(['status' => 'success', 'message' => "Network '{$name}' created successfully.", 'data' => $response]);

        } elseif ($action === 'delete') {
            // DELETE network
            $network_id = $_POST['network_id'] ?? '';
            if (empty($network_id)) {
                throw new InvalidArgumentException("Network ID is required for deletion.");
            }
            $dockerClient->removeNetwork($network_id);
            log_activity($_SESSION['username'], 'Network Deleted', "Deleted network ID '{$network_id}' on host '{$host['name']}'.");
            echo json_encode(['status' => 'success', 'message' => "Network successfully deleted."]);
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
?>