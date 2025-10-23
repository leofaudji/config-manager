<?php
// File: health-agent/agent.php

set_time_limit(0); // Run indefinitely

// --- Konfigurasi Agen (diambil dari environment variables) ---
// Sanitize the URL aggressively to remove any non-printable characters, control characters,
// or anything that isn't a standard URL character. This prevents cURL "Malformed URL" errors.
$rawUrl = getenv('CONFIG_MANAGER_URL');
$configManagerUrl = preg_replace('/[^\x21-\x7E]/', '', $rawUrl); // Allow printable ASCII chars only
$apiKey = getenv('API_KEY');
$hostId = getenv('HOST_ID');
$autoHealingEnabled = (getenv('AUTO_HEALING_ENABLED') === '1');
$logBuffer = [];

ob_implicit_flush(true); // Ensure logs are sent immediately

if (!$configManagerUrl || !$apiKey || !$hostId) {
    die("Error: Environment variables CONFIG_MANAGER_URL, API_KEY, and HOST_ID must be set.\n");
}

// --- NEW: Robust URL Validation ---
if (filter_var($configManagerUrl, FILTER_VALIDATE_URL) === false) {
    die("FATAL ERROR: The provided CONFIG_MANAGER_URL '{$configManagerUrl}' is not a valid URL. Please check the 'Application Base URL' in Settings and redeploy the agent.\n");
}

echo "Memulai Health Agent Daemon pada " . date('Y-m-d H:i:s') . " untuk Host ID: {$hostId}\n";
if ($autoHealingEnabled) {
    echo "Mode Auto-Healing: AKTIF\n";
}


function log_message(string $message) {
    global $logBuffer;
    $timestamped_message = date('[Y-m-d H:i:s] ') . $message;
    echo $timestamped_message . "\n";
    flush();
    $logBuffer[] = $timestamped_message;
}

function send_logs_to_server() {
    global $logBuffer, $configManagerUrl, $apiKey, $hostId;
    if (empty($logBuffer)) return;

    $payload_json = json_encode([
        'source' => 'health-agent',
        'host_id' => (int)$hostId,
        'logs' => $logBuffer
    ]);

    // NEW: Use shell_exec to call curl directly for maximum compatibility
    $url = escapeshellarg($configManagerUrl . '/api/log/ingest');
    $api_key_header = escapeshellarg('X-API-Key: ' . $apiKey);
    $content_type_header = escapeshellarg('Content-Type: application/json');
    $payload_escaped = escapeshellarg($payload_json);

    // Jalankan secara sinkron dan tangkap output serta kode HTTP
    $command = "curl -s -w '%{http_code}' -X POST -H {$content_type_header} -H {$api_key_header} -d {$payload_escaped} {$url}";
    
    shell_exec($command);

}

// --- Salinan minimal dari DockerClient dan fungsi yang diperlukan ---
class DockerClient
{
    private $docker_api_url;

    public function __construct()
    {
        // Agen selalu menggunakan Docker socket lokal
        $this->docker_api_url = 'unix:///var/run/docker.sock';
    }

    private function sendRequest(string $endpoint, string $method = 'GET', $data = null)
    {
        // --- CRITICAL FIX: URL-encode the dynamic parts of the endpoint ---
        // This prevents "Malformed URL" errors if container/network IDs contain special characters.
        // We split the path and encode each part individually, then rejoin them.
        // Example: /containers/abc/json -> ['containers', 'abc', 'json'] -> ['containers', 'abc', 'json'] -> /containers/abc/json
        // Example with special chars: /networks/net@work/connect -> ['networks', 'net@work', 'connect'] -> ['networks', 'net%40work', 'connect'] -> /networks/net%40work/connect
        $path_parts = explode('/', ltrim($endpoint, '/'));
        $query_string = '';
        // Separate path from query string if it exists
        $last_part_index = count($path_parts) - 1;
        if (strpos($path_parts[$last_part_index], '?') !== false) {
            list($last_path_part, $query_string) = explode('?', $path_parts[$last_part_index], 2);
            $path_parts[$last_part_index] = $last_path_part;
            $query_string = '?' . $query_string; // Keep the '?'
        }

        $encoded_parts = array_map('urlencode', $path_parts);
        $encoded_endpoint = implode('/', $encoded_parts);

        $url = "http://localhost/v1.41/" . $encoded_endpoint . $query_string;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_UNIX_SOCKET_PATH, '/var/run/docker.sock');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            throw new Exception('cURL error: ' . curl_error($ch));
        }
        curl_close($ch);
        return json_decode($response, true);
    }

    public function listContainers(): array
    {
        // The query string is now handled correctly by sendRequest
        $result = $this->sendRequest('/containers/json?all=1');
        return is_array($result) ? $result : [];
    }

    public function inspectContainer(string $id): array
    {
        $result = $this->sendRequest('/containers/' . $id . '/json');
        return is_array($result) ? $result : [];
    }

    public function getContainerStats(string $id): array
    {
        $result = $this->sendRequest("/containers/{$id}/stats?stream=false");
        return is_array($result) ? $result : [];
    }

    public function getInfo(): array
    {
        $result = $this->sendRequest('/info');
        return is_array($result) ? $result : [];
    }

    public function listNetworks(): array
    {
        $result = $this->sendRequest('/networks');
        return is_array($result) ? $result : [];
    }

    public function restartContainer(string $id): bool
    {
        // A successful restart returns a 204 No Content, which json_decode turns into null.
        // We check if an exception was thrown. If not, it's a success.
        $response = $this->sendRequest('/containers/' . $id . '/restart', 'POST');
        // A null response on a POST is often a success (e.g., 204 No Content).
        return $response === null || $response === true;
    }

    public function connectToNetwork(string $networkId, string $containerId, ?string $alias = null): bool
    {
        $config = [
            'Container' => $containerId,
            'EndpointConfig' => []
        ];
        // Add a unique alias to prevent network-scoped alias conflicts in Swarm
        if ($alias) {
            $config['EndpointConfig']['Aliases'] = [$alias];
        }

        $this->sendRequest("/networks/{$networkId}/connect", 'POST', $config);
        return true;
    }

    public function disconnectFromNetwork(string $networkId, string $containerId): bool
    {
        $config = ['Container' => $containerId, 'Force' => true];
        $this->sendRequest("/networks/{$networkId}/disconnect", 'POST', $config);
        return true;
    }

    public function forceServiceUpdate(string $serviceId): bool
    {
        // First, we need to get the current version of the service to send the update request.
        try {
            $service_details = $this->sendRequest("/services/{$serviceId}");
            $version = $service_details['Version']['Index'] ?? null;

            if ($version === null) {
                throw new Exception("Could not determine service version.");
            }

            // The `--force` flag in the CLI is equivalent to sending an empty POST request
            // with the current version. The API will re-evaluate and re-create tasks.
            $this->sendRequest("/services/{$serviceId}/update?version={$version}", 'POST', []);
            return true;
        } catch (Exception $e) {
            throw new Exception("Failed to force service update: " . $e->getMessage());
        }
    }
}

/**
 * Sends a notification to the configured external notification server.
 * This is a minimal, self-contained version for the agent.
 *
 * @param string $title The title of the notification.
 * @param string $message The main content of the notification.
 * @param string $level The severity level (e.g., 'error', 'warning', 'info').
 * @param array $context Additional context data to include in the payload.
 * @return void
 */
function send_notification(string $title, string $message, string $level = 'error', array $context = []): void {
    global $configManagerUrl;

    // The agent doesn't have direct access to settings, so it sends to a dedicated endpoint.
    $notification_endpoint = rtrim($configManagerUrl, '/') . '/api/notifications/agent-relay';

    $payload = array_merge([
        'title' => $title,
        'message' => $message,
        'level' => $level,
        'timestamp' => date('c'), // ISO 8601 timestamp
        'source_app' => 'Config Manager Health Agent'
    ], $context);

    // Use a simple, non-blocking way to send the notification.
    $payload_json = escapeshellarg(json_encode($payload));
    $url_escaped = escapeshellarg($notification_endpoint);
    $content_type_header = escapeshellarg('Content-Type: application/json');

    shell_exec("curl -s -X POST -H {$content_type_header} -d {$payload_json} {$url_escaped} > /dev/null 2>&1 &");
}

function postHealthData(string $url, string $apiKey, array $data)
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-API-Key: ' . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10); // 10 detik timeout

    $response_body = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        log_message("  -> ERROR: Gagal mengirim laporan (cURL Error): " . $url . " Error: " . $curl_error);
        return;
    }
    
    if ($http_code >= 200 && $http_code < 300) {
        log_message("  -> SUKSES: Laporan berhasil dikirim ke Config Manager (HTTP {$http_code}). Response: {$response_body}");
    } else {
        log_message("  -> ERROR: Gagal mengirim laporan. HTTP Code: {$http_code}. Response: {$response_body}");
    }
}

/**
 * Fungsi utama untuk menjalankan satu siklus pengecekan kesehatan.
 */
function run_check_cycle() {
    global $hostId, $configManagerUrl, $apiKey, $autoHealingEnabled;

    log_message("Memulai siklus pengecekan...");
    $dockerClient = new DockerClient();

    // --- NEW: Determine if the host is a Swarm Node ---
    $dockerInfo = [];
    try {
        $dockerInfo = $dockerClient->getInfo();
    } catch (Exception $e) {
        log_message("  -> WARN: Could not get Docker info: " . $e->getMessage());
    }
    // Check if the node is part of a swarm and is 'active'.
    $is_swarm_node = (isset($dockerInfo['Swarm']['LocalNodeState']) && $dockerInfo['Swarm']['LocalNodeState'] === 'active');
    log_message("Host Type Detection: " . ($is_swarm_node ? "Swarm Node" : "Standalone"));

    // --- NEW: Get all networks on the host once for efficiency ---
    $all_networks_on_host = [];
    $network_name_to_id_map = [];
    try {
        $all_networks_on_host = $dockerClient->listNetworks();
        foreach ($all_networks_on_host as $network) {
            $network_name_to_id_map[$network['Name']] = $network['Id'];
        }
    } catch (Exception $e) {
        log_message("  -> WARN: Could not list Docker networks: " . $e->getMessage());
    }


    $containers = $dockerClient->listContainers();
    $health_reports = [];

    // Inisialisasi penghitung untuk ringkasan
    $healthy_count = 0;
    $unhealthy_count = 0;
    $unknown_count = 0;
    $running_container_count = 0;
    $container_stats_reports = [];
    $unhealthy_container_names = [];
    $unknown_container_names = [];

    $all_running_container_ids = []; // NEW: To track all running container IDs
    log_message("  -> Menemukan " . count($containers) . " kontainer.");

    foreach ($containers as $container) {
        try {
            $container_id = $container['Id'];
            $container_name = ltrim($container['Names'][0] ?? $container_id, '/');

            if ($container['State'] !== 'running') {
                // --- NEW: Logic to differentiate between stopped and unhealthy ---
                $details = $dockerClient->inspectContainer($container_id);
                $exitCode = $details['State']['ExitCode'] ?? -1;
                $errorMsg = $details['State']['Error'] ?? '';

                // If container was stopped manually (usually ExitCode 0) and not by an error, skip it.
                if ($exitCode === 0 && empty($errorMsg)) { 
                    $health_reports[] = ['container_id' => $container_id, 'container_name' => $container_name, 'is_healthy' => 'stopped', 'log_message' => "Container was stopped manually."];
                    log_message("    - Container '{$container_name}' is stopped (manual). Reporting as 'stopped'.");
                    continue; 
                }

                // If it's not running and has an error, it's unhealthy.
                $health_reports[] = ['container_id' => $container_id, 'container_name' => $container_name, 'is_healthy' => false, 'log_message' => "Container is not running. Exit Code: {$exitCode}. Error: {$errorMsg}"];
                $unhealthy_count++;
                $unhealthy_container_names[] = $container_name;
                log_message("    - Mengevaluasi '{$container_name}': Tidak Sehat (Tidak Berjalan)");
                continue;
            }
            $running_container_count++;
            $all_running_container_ids[] = $container['Id']; // NEW: Add ID to the list

            // --- NEW: Collect stats for each running container ---
            try {
                $stats = $dockerClient->getContainerStats($container_id);
                $cpu_delta = ($stats['cpu_stats']['cpu_usage']['total_usage'] ?? 0) - ($stats['precpu_stats']['cpu_usage']['total_usage'] ?? 0);
                $system_cpu_delta = ($stats['cpu_stats']['system_cpu_usage'] ?? 0) - ($stats['precpu_stats']['system_cpu_usage'] ?? 0);
                $number_cpus = $stats['cpu_stats']['online_cpus'] ?? count($stats['cpu_stats']['cpu_usage']['percpu_usage'] ?? []);

                $cpu_percent = 0.0;
                if ($system_cpu_delta > 0.0 && $cpu_delta > 0.0 && $number_cpus > 0) {
                    $cpu_percent = ($cpu_delta / $system_cpu_delta) * $number_cpus * 100.0;
                }

                $container_stats_reports[] = [
                    'container_id' => $container_id,
                    'container_name' => $container_name,
                    'cpu_usage' => round($cpu_percent, 2),
                    'memory_usage' => $stats['memory_stats']['usage'] ?? 0,
                ];
            } catch (Exception $e) {
                log_message("  -> WARN: Could not get stats for container '{$container_name}': " . $e->getMessage());
            }

            // --- Pengecualian Khusus ---
            // Abaikan kontainer utilitas yang tidak perlu dicek kesehatannya.
            if ($container_name === 'host-cpu-reader') {
                continue;
            }

            // Jangan laporkan diri sendiri
            if ($container_name === 'cm-health-agent') {
                continue;
            }

            // Jangan laporkan kontainer Falco atau Falcosidekick
            if ($container_name === 'falco-sensor' || $container_name === 'falcosidekick') {
                log_message("    - Melewati '{$container_name}' (agen keamanan).");
                continue;
            }

            $details = $dockerClient->inspectContainer($container_id);
            $docker_health_status = $details['State']['Health']['Status'] ?? null;

            $is_healthy = null; // Default to unknown
            $check_flow_log = [];

            if ($docker_health_status) {
                // --- Alur 1: Gunakan HEALTHCHECK bawaan dari Docker ---
                if ($docker_health_status === 'healthy') {
                    $is_healthy = true;
                } elseif ($docker_health_status === 'starting') {
                    $is_healthy = null; // Dianggap 'unknown' untuk memberi waktu
                } else { // 'unhealthy' atau status lainnya
                    $is_healthy = false;
                }
                $check_flow_log[] = [
                    'step' => 'Docker Healthcheck',
                    'status' => $is_healthy === true ? 'success' : ($is_healthy === false ? 'fail' : 'skipped'),
                    'message' => "Container reported status: {$docker_health_status}"
                ];
            } else {
                // --- Alur 2: Fallback ke Pengecekan Port TCP ---
                $check_flow_log[] = ['step' => 'Docker Healthcheck', 'status' => 'skipped', 'message' => 'No built-in HEALTHCHECK instruction found.'];

                // Prioritas 2a: Coba cek SEMUA Published Port terlebih dahulu via host.docker.internal
                // Ini adalah cara paling andal jika port dipublikasikan ke host.
                $all_published_ports = [];
                if (!empty($details['NetworkSettings']['Ports'])) {
                    foreach ($details['NetworkSettings']['Ports'] as $port_bindings) {
                        // FIX: Iterate through the bindings for a given private port.
                        // $port_bindings is an array of mappings for a single private port.
                        if (is_array($port_bindings)) {
                            foreach ($port_bindings as $binding) {
                                // Ensure the binding has a HostPort and it's not an ephemeral port (0).
                                if (isset($binding['HostPort']) && !empty($binding['HostPort']) && $binding['HostPort'] != '0') {
                                    $all_published_ports[] = (int)$binding['HostPort'];
                                }
                            }
                        }
                    }
                }
                $all_published_ports = array_unique($all_published_ports);

                if (!empty($all_published_ports)) {
                    log_message("    -> No HEALTHCHECK found. Trying published ports on host.docker.internal: " . implode(', ', $all_published_ports));
                    foreach ($all_published_ports as $port) {
                        // host.docker.internal adalah DNS name khusus yang menunjuk ke host dari dalam container
                        $connection = @fsockopen('host.docker.internal', $port, $errno, $errstr, 2);
                        if (is_resource($connection)) {
                            $is_healthy = true;
                            $check_flow_log[] = ['step' => 'Published Port Check', 'status' => 'success', 'message' => "TCP connection to host.docker.internal:{$port} was successful."];
                            fclose($connection);
                            break; // Ditemukan port yang sehat, hentikan pengecekan.
                        }
                    }
                    if ($is_healthy === null) {
                        // Jika semua port publik sudah dicoba dan gagal, catat pesannya.
                        $check_flow_log[] = ['step' => 'Published Port Check', 'status' => 'fail', 'message' => 'Could not connect to any published ports: ' . implode(', ', $all_published_ports)];
                    }
                } else {
                    $check_flow_log[] = [
                        'step' => 'Published Port Check',
                        'status' => 'skipped',
                        'message' => 'No published ports found to check.'
                    ];
                }

                // Prioritas 2b: Jika status masih belum ditentukan, coba port internal.
                // Ini akan berjalan jika tidak ada HEALTHCHECK, dan (tidak ada port publik ATAU port publik gagal dicek).
                // --- NEW: Only run this complex check for Standalone hosts ---
                if ($is_healthy === null && !$is_swarm_node) {
                    $internal_ip = null;
                    $internal_port = null;

                    // 1. Dapatkan IP internal dari network pertama yang valid
                    if (!empty($details['NetworkSettings']['Networks'])) {
                        foreach ($details['NetworkSettings']['Networks'] as $net) {
                            if (!empty($net['IPAddress'])) {
                                $internal_ip = $net['IPAddress'];
                                break;
                            }
                        }
                    }

                    // 2. Dapatkan port internal (prioritaskan 80/443, lalu port pertama, lalu tebakan)
                    $tcp_ports = [];
                    if (isset($details['NetworkSettings']['Ports']) && is_array($details['NetworkSettings']['Ports'])) {
                        // This logic now correctly iterates through the port mappings to find the internal (private) port.
                        // It only considers ports that are actually exposed by this specific container.
                        foreach ($details['NetworkSettings']['Ports'] as $port_mapping => $bindings) {
                            if (str_ends_with($port_mapping, '/tcp')) {
                                $tcp_ports[] = (int)str_replace('/tcp', '', $port_mapping);
                            }
                        }
                    }

                    // --- Prioritize 80/443, then first exposed port, then educated guess ---
                    // Remove duplicates after the above steps to ensure the prioritized ports are listed first.
                    $tcp_ports = array_unique($tcp_ports);

                    if (in_array(80, $tcp_ports)) $internal_port = 80;
                    elseif (in_array(443, $tcp_ports)) $internal_port = 443;
                    elseif (!empty($tcp_ports)) $internal_port = $tcp_ports[0];
                    else {
                        // Tebak port jika tidak ada yang diekspos
                        $image_name = strtolower($container['Image']);
                        $common_ports = ['nginx' => 80, 'httpd' => 80, 'apache' => 80, 'mysql' => 3306, 'postgres' => 5432, 'redis' => 6379, 'mariadb' => 3306, 'mongo' => 27017];
                        foreach ($common_ports as $keyword => $port) {
                            if (strpos($image_name, $keyword) !== false) {
                                $internal_port = $port;
                                break;
                            }
                        }
                    }

                    // Lakukan pengecekan jika IP dan Port internal ditemukan
                    if ($internal_ip && $internal_port) { // This block is now only for Standalone
                        log_message("    -> [Standalone] Checking internal TCP port {$internal_ip}:{$internal_port}.");
                        $network_id_to_join = isset($details['NetworkSettings']['Networks']) ? key($details['NetworkSettings']['Networks']) : null;
                        $agent_container_id = getenv('HOSTNAME');
                        try {
                            $dockerClient->connectToNetwork($network_id_to_join, $agent_container_id);
                            
                            exec("nc -z -w 2 " . escapeshellarg($internal_ip) . " " . escapeshellarg($internal_port), $output, $return_var);

                            if ($return_var === 0) {
                                $is_healthy = true;
                                $check_flow_log[] = ['step' => 'Internal TCP Check', 'status' => 'success', 'message' => "TCP connection to internal IP {$internal_ip}:{$internal_port} was successful."];
                            } else {
                                $is_healthy = false;
                                $check_flow_log[] = ['step' => 'Internal TCP Check', 'status' => 'fail', 'message' => "TCP connection to internal IP {$internal_ip}:{$internal_port} failed."];
                            }
                        } catch (Exception $e) {
                            $is_healthy = false;
                            $check_flow_log[] = ['step' => 'Internal TCP Check', 'status' => 'fail', 'message' => "Failed to join network or perform internal check: " . $e->getMessage()];
                        } finally {
                            $dockerClient->disconnectFromNetwork($network_id_to_join, $agent_container_id);
                        }
                    }
                }

                // --- Final Fallback: Ping Check ---
                // This will run if:
                // - It's a Swarm node and previous checks failed.
                // - It's a Standalone node and all previous checks (including internal TCP) failed.
                if ($is_healthy === null) {
                    // --- FIX: Ensure NetworkSettings and Networks exist and are not empty before proceeding ---
                    $networks = $details['NetworkSettings']['Networks'] ?? null;
                    if (is_array($networks) && !empty($networks)) {
                        $first_network_key = array_key_first($networks);
                        $internal_ip = $networks[$first_network_key]['IPAddress'] ?? null;

                        // --- NEW: Check for a shared network first to avoid unnecessary join/disconnect ---
                        $agent_container_id = getenv('HOSTNAME');
                        $agent_details = $dockerClient->inspectContainer($agent_container_id);
                        $agent_networks = array_keys($agent_details['NetworkSettings']['Networks'] ?? []);
                        $target_networks = array_keys($networks);

                        $shared_networks = array_intersect($agent_networks, $target_networks);
                        // Filter out the default ingress network as it's not useful for direct communication
                        $shared_networks = array_filter($shared_networks, fn($net) => strpos($net, 'ingress') === false);

                        if (!empty($shared_networks)) {
                            log_message("    -> Found shared network: " . implode(', ', $shared_networks) . ". Pinging directly.");
                            exec("ping -c 1 -W 1 " . escapeshellarg($internal_ip), $output, $return_var);
                            if ($return_var === 0) {
                                $is_healthy = true;
                                $check_flow_log[] = ['step' => 'ICMP (Ping) Check', 'status' => 'success', 'message' => "Ping to internal IP {$internal_ip} via shared network was successful."];
                            } else {
                                $is_healthy = false;
                                $check_flow_log[] = ['step' => 'ICMP (Ping) Check', 'status' => 'fail', 'message' => "Ping to internal IP {$internal_ip} via shared network failed."];
                            }
                        }

                        // --- MODIFIED: Only run dynamic join if no shared network was found ---
                        if ($is_healthy === null) {
                            if (!$internal_ip) continue; // Skip if no IP found
                            $network_name_to_join = null;
                            // Find the first non-ingress, overlay network to join. This is the most reliable target.
                            foreach ($details['NetworkSettings']['Networks'] as $net_name => $net_details) {
                                if (strpos($net_name, 'ingress') === false) {
                                    $network_name_to_join = $net_name;
                                    break;
                                }
                            }
    
                            if (!$network_name_to_join) {
                                $check_flow_log[] = ['step' => 'ICMP (Ping) Check', 'status' => 'fail', 'message' => "Could not find a suitable non-ingress network to join for ping check."];
                            } else {
                                log_message("    -> No shared network found. Attempting ICMP (ping) check on internal IP {$internal_ip} via dynamic join to network '{$network_name_to_join}'.");
                                $network_id_to_join = $network_name_to_id_map[$network_name_to_join] ?? null;
    
                                // Use a unique alias for the agent container when connecting to the target network.
                                $agent_container_id = getenv('HOSTNAME');
                                $unique_alias = 'agent-check-' . substr(md5(uniqid((string)rand(), true)), 0, 8);
    
                                if ($network_id_to_join) {
                                    $network_joined = false; // Flag to track connection status
                                    try {
                                        $dockerClient->connectToNetwork($network_id_to_join, $agent_container_id, $unique_alias);
                                        $network_joined = true; // Set flag on successful connection
                                        
                                        exec("ping -c 1 -W 1 " . escapeshellarg($internal_ip), $output, $return_var);
    
                                        if ($return_var === 0) {
                                            $is_healthy = true;
                                            $check_flow_log[] = ['step' => 'ICMP (Ping) Check', 'status' => 'success', 'message' => "Ping to internal IP {$internal_ip} was successful."];
                                        } else {
                                            $is_healthy = false;
                                            $check_flow_log[] = ['step' => 'ICMP (Ping) Check', 'status' => 'fail', 'message' => "Ping to internal IP {$internal_ip} failed."];
                                        }
                                    } catch (Exception $e) {
                                        $check_flow_log[] = ['step' => 'ICMP (Ping) Check', 'status' => 'fail', 'message' => "Failed to join network '{$network_name_to_join}' for ping: " . $e->getMessage()];
                                    } finally {
                                        if ($network_joined) $dockerClient->disconnectFromNetwork($network_id_to_join, $agent_container_id);
                                    }
                                } else {
                                     $check_flow_log[] = ['step' => 'ICMP (Ping) Check', 'status' => 'fail', 'message' => "Network ID for '{$network_name_to_join}' not found. Cannot perform ping check."];
                                }
                            }
                        }
                    } else {
                        $check_flow_log[] = ['step' => 'ICMP (Ping) Check', 'status' => 'skipped', 'message' => 'Container is not attached to any scannable networks.'];
                    }
                }

                // Jika setelah semua usaha, status masih belum ditentukan, biarkan null (unknown).
                if ($is_healthy === null) {
                    $is_healthy = null; // Tetap null untuk status 'unknown'
                    $check_flow_log[] = ['step' => 'Final Result', 'status' => 'unknown', 'message' => 'No reliable check method could determine the container status.'];
                }
            }

            $status_text = $is_healthy === true ? 'Sehat' : ($is_healthy === false ? 'Tidak Sehat' : 'Tidak Diketahui');
            log_message("    - Mengevaluasi '{$container_name}': {$status_text}");

            // Tambahkan ke penghitung ringkasan
            if ($is_healthy === true) {
                $healthy_count++;
            } elseif ($is_healthy === false) {
                $unhealthy_count++;
                $unhealthy_container_names[] = $container_name;
            } else {
                $unknown_count++;
                $unknown_container_names[] = $container_name;
            }

            // --- Auto-Healing Logic ---
            if ($is_healthy === false && $autoHealingEnabled) {
                log_message("  -> STATUS TIDAK SEHAT TERDETEKSI! Memicu auto-healing untuk kontainer '{$container_name}'.");
                // --- NEW: Send notification when an unhealthy container is found ---
                send_notification(
                    "Container Unhealthy: " . $container_name,
                    "The container '{$container_name}' on host '{$hostId}' has been marked as unhealthy. Auto-healing has been triggered.",
                    'error',
                    ['container_name' => $container_name, 'host_id' => $hostId]
                );
                
                // --- NEW: Swarm-aware auto-healing ---
                $service_id = $details['Config']['Labels']['com.docker.swarm.service.id'] ?? null;

                try {
                    if ($is_swarm_node && $service_id) {
                        // For Swarm, force the service to update, which recreates the task.
                        log_message("    -> Mode Swarm terdeteksi. Memaksa pembaruan untuk service ID '{$service_id}'.");
                        $dockerClient->forceServiceUpdate($service_id);
                        $healing_message = "Force update command sent to service '{$service_id}'.";
                        log_message("  -> SUKSES: {$healing_message}");
                    } else {
                        // For Standalone, just restart the container.
                        log_message("    -> Mode Standalone terdeteksi. Merestart kontainer '{$container_name}'.");
                        $dockerClient->restartContainer($container_id);
                        $healing_message = "Restart command sent successfully to container '{$container_name}'.";
                        log_message("  -> SUKSES: {$healing_message}");
                    }
                    $check_flow_log[] = ['step' => 'Auto-Healing', 'status' => 'success', 'message' => $healing_message];
                } catch (Exception $e) {
                    log_message("  -> ERROR saat auto-healing: " . $e->getMessage());
                }
            }

            $health_reports[] = [
                'container_id' => $container_id,
                'container_name' => $container_name,
                'is_healthy' => $is_healthy,
                'log_message' => json_encode($check_flow_log)
            ];
        } catch (Exception $e) {
            $container_name_for_log = isset($container['Names'][0]) ? ltrim($container['Names'][0], '/') : ($container['Id'] ?? 'unknown');
            log_message("  -> ERROR: Terjadi kesalahan saat memproses kontainer '{$container_name_for_log}': " . $e->getMessage());
            // Tandai sebagai unknown agar tidak salah memicu auto-healing
            $unknown_count++;
            $unknown_container_names[] = $container_name_for_log;
        }
    }

    // Tambahkan ringkasan ke log
    log_message("---");
    $summary_message = "Check Summary: {$healthy_count} Healthy, {$unhealthy_count} Unhealthy, {$unknown_count} Unknown (from {$running_container_count} running containers).";
    if ($unhealthy_count > 0) {
        $summary_message .= "\n    -> Unhealthy containers: " . implode(', ', $unhealthy_container_names);
    }
    if ($unknown_count > 0) {
        $summary_message .= "\n    -> Unknown containers: " . implode(', ', $unknown_container_names);
    }
    log_message($summary_message);
    log_message("---");

    // --- Get Host Uptime ---
    $host_uptime_seconds = null;
    // This reads the system uptime from the host, which is more reliable.
    // The agent container needs read-only access to /proc on the host for this to work.
    $proc_uptime_path = '/proc/uptime';
    if (is_readable($proc_uptime_path)) {
        $uptime_str = file_get_contents($proc_uptime_path);
        $host_uptime_seconds = (int)explode(' ', $uptime_str)[0];
    } else {
        log_message("  -> WARN: Could not read {$proc_uptime_path}. Host uptime will not be reported. Ensure /proc is mounted read-only into the agent.");
    }

    // --- NEW: Get Host CPU Usage ---
    $host_cpu_usage = get_host_cpu_usage();
    if ($host_cpu_usage !== null) {
        log_message("  -> Host CPU Usage: " . number_format($host_cpu_usage, 2) . "%");
    } else {
        log_message("  -> WARN: Failed to determine host CPU usage. The 'procps' package might be missing in the agent container.");
    }

    $report_payload = [
        'host_id' => (int)$hostId,
        'host_uptime_seconds' => $host_uptime_seconds,
        'host_cpu_usage_percent' => $host_cpu_usage, // NEW: Add host CPU to payload
        'reports' => $health_reports,
        'running_container_ids' => $all_running_container_ids, // NEW: Send the complete list
        'container_stats' => $container_stats_reports // NEW: Send container stats
    ]; 

    
    postHealthData($configManagerUrl . '/api/health-report', $apiKey, $report_payload);
}

/**
 * Gets the overall host CPU usage percentage by running `top`.
 * @return float|null The CPU usage percentage, or null on failure.
 */
function get_host_cpu_usage(): ?float {
    // This awk command is robust. It looks for a line starting with '%Cpu' or 'CPU:',
    // then finds the column containing 'id' and prints 100 minus the preceding value (the idle percentage).
    // The `|| echo "-1"` provides a fallback if awk produces no output.
    $cpu_command = "top -bn1 | grep -E '^(%Cpu|CPU:)' | awk '{for(i=1;i<=NF;i++) if (\$i ~ /id/) {print 100-\$(i-1); exit}}' || echo \"-1\"";
    $cpu_output = shell_exec($cpu_command);
    $host_cpu_usage = (float)$cpu_output;

    return ($host_cpu_usage >= 0) ? $host_cpu_usage : null;
}

// --- Logika Utama Eksekusi ---
try {
    run_check_cycle();
} catch (Exception $e) {
    log_message("ERROR Kritis: " . $e->getMessage());
} finally {
    log_message("Siklus pengecekan selesai. Mengirim log ke server...");
    send_logs_to_server();
    log_message("Skrip selesai.\n");
}