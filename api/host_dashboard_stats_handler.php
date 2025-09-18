<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/DockerClient.php';

header('Content-Type: application/json');

$request_uri_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$basePath = BASE_PATH;
if ($basePath && strpos($request_uri_path, $basePath) === 0) {
    $request_uri_path = substr($request_uri_path, strlen($basePath));
}

if (!preg_match('/^\/api\/hosts\/(\d+)\/stats$/', $request_uri_path, $matches)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid API endpoint format.']);
    exit;
}
$host_id = $matches[1];

$conn = Database::getInstance()->getConnection();

try {
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

    // Get container stats
    $containers = $dockerClient->listContainers();
    $total_containers = count($containers);
    $running_containers = count(array_filter($containers, fn($c) => $c['State'] === 'running'));
    $stopped_containers = count(array_filter($containers, fn($c) => $c['State'] === 'exited'));

    // Get network stats
    $networks = $dockerClient->listNetworks();
    $total_networks = count($networks);

    // Get stack stats and swarm status. This needs to be robust for both Swarm and non-Swarm nodes.
    $total_stacks = 0;
    $is_swarm_manager = false;
    try {
        $dockerInfo = $dockerClient->getInfo();
        $is_swarm_manager = (isset($dockerInfo['Swarm']['ControlAvailable']) && $dockerInfo['Swarm']['ControlAvailable'] === true);

        if ($is_swarm_manager) {
            // Swarm Manager: Count stacks via services
            $remote_services = $dockerClient->listServices();
            $discovered_stacks = [];
            foreach ($remote_services as $service) {
                $stack_namespace = $service['Spec']['Labels']['com.docker.stack.namespace'] ?? null;
                if ($stack_namespace) {
                    $discovered_stacks[$stack_namespace] = true; // Use keys for uniqueness
                }
            }
            $total_stacks = count($discovered_stacks);
        } else {
            // Not a swarm manager, so we look for docker-compose projects from container labels
            $discovered_stacks = [];
            foreach ($containers as $container) {
                $compose_project = $container['Labels']['com.docker.compose.project'] ?? null;
                if ($compose_project) {
                    $discovered_stacks[$compose_project] = true; // Use keys for uniqueness
                }
            }
            $total_stacks = count($discovered_stacks);
        }
    } catch (Exception $e) {
        // If the /services or /info endpoint fails, it's not a swarm manager.
        error_log("Could not get Swarm stats for host {$host['name']}: " . $e->getMessage());
        $is_swarm_manager = false;
        $total_stacks = 0; // Fallback for the widget
    }

    // Get image stats
    $total_images = 0;
    try {
        // This logic is duplicated from network_handler.php for simplicity, as we can't easily share it.
        $url = $host['docker_api_url'];
        $is_socket = strpos($url, 'unix://') === 0;
        $ch = curl_init();
        if ($is_socket) {
            curl_setopt($ch, CURLOPT_UNIX_SOCKET_PATH, substr($url, 7));
            curl_setopt($ch, CURLOPT_URL, 'http://localhost/images/json');
        } else {
            $curl_url = ($host['tls_enabled'] ? 'https://' : 'http://') . str_replace('tcp://', '', $url);
            curl_setopt($ch, CURLOPT_URL, rtrim($curl_url, '/') . '/images/json');
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        if (!empty($host['tls_enabled'])) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            if (!empty($host['ca_cert_path']) && file_exists($host['ca_cert_path'])) curl_setopt($ch, CURLOPT_CAINFO, $host['ca_cert_path']);
            if (!empty($host['client_cert_path']) && file_exists($host['client_cert_path'])) curl_setopt($ch, CURLOPT_SSLCERT, $host['client_cert_path']);
            if (!empty($host['client_key_path']) && file_exists($host['client_key_path'])) curl_setopt($ch, CURLOPT_SSLKEY, $host['client_key_path']);
        }
        $response = curl_exec($ch);
        curl_close($ch);
        $images_data = json_decode($response, true);
        if (is_array($images_data)) {
            $filtered_images = array_filter($images_data, function($image) {
                return !(empty($image['RepoTags']) || $image['RepoTags'][0] === '<none>:<none>');
            });
            $total_images = count($filtered_images);
        }
    } catch (Exception $e) {
        error_log("Could not get Image stats for host {$host['name']}: " . $e->getMessage());
        $total_images = 'N/A';
    }

    $stats = [
        'total_containers' => $total_containers,
        'running_containers' => $running_containers,
        'stopped_containers' => $stopped_containers,
        'total_networks' => $total_networks,
        'total_stacks' => $total_stacks,
        'total_images' => $total_images,
        'is_swarm_manager' => $is_swarm_manager,
    ];

    echo json_encode(['status' => 'success', 'data' => $stats]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();
?>