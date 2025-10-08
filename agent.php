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
        $url = "http://localhost/v1.41" . $endpoint;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_UNIX_SOCKET_PATH, '/var/run/docker.sock');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        }
        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            throw new Exception('cURL error: ' . curl_error($ch));
        }
        curl_close($ch);
        return json_decode($response, true);
    }

    public function listContainers(): array
    {
        return $this->sendRequest('/containers/json?all=1');
    }

    public function inspectContainer(string $id): array
    {
        return $this->sendRequest('/containers/' . $id . '/json');
    }

    public function restartContainer(string $id): bool
    {
        $this->sendRequest('/containers/' . $id . '/restart', 'POST');
        return true;
    }

    public function connectToNetwork(string $networkId, string $containerId): bool
    {
        $config = ['Container' => $containerId];
        $this->sendRequest("/networks/{$networkId}/connect", 'POST', $config);
        return true;
    }

    public function disconnectFromNetwork(string $networkId, string $containerId): bool
    {
        $config = ['Container' => $containerId, 'Force' => true];
        $this->sendRequest("/networks/{$networkId}/disconnect", 'POST', $config);
        return true;
    }
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
    $containers = $dockerClient->listContainers();
    $health_reports = [];

    // Inisialisasi penghitung untuk ringkasan
    $healthy_count = 0;
    $unhealthy_count = 0;
    $unknown_count = 0;
    $running_container_count = 0;
    $unhealthy_container_names = [];
    $unknown_container_names = [];

    log_message("  -> Menemukan " . count($containers) . " kontainer.");

    foreach ($containers as $container) {
        try {
            if ($container['State'] !== 'running') {
                continue;
            }
            $running_container_count++;

            $container_id = $container['Id'];
            $container_name = ltrim($container['Names'][0] ?? $container_id, '/');

            // --- Pengecualian Khusus ---
            // Abaikan kontainer utilitas yang tidak perlu dicek kesehatannya.
            if ($container_name === 'host-cpu-reader') {
                continue;
            }

            // Jangan laporkan diri sendiri
            if ($container_name === 'cm-health-agent') {
                continue;
            }

            $details = $dockerClient->inspectContainer($container_id);
            $docker_health_status = $details['State']['Health']['Status'] ?? null;

            $is_healthy = null; // Default to unknown
            $log_message = '';

            if ($docker_health_status) {
                // --- Alur 1: Gunakan HEALTHCHECK bawaan dari Docker ---
                $is_healthy = ($docker_health_status === 'healthy');
                $log_message = "Container health status from Docker: {$docker_health_status}";
            } else {
                // --- Alur 2: Fallback ke Pengecekan Port TCP (Struktur Baru yang Disederhanakan dan Diperbaiki) ---

                // Prioritas 2a: Coba cek Published Port terlebih dahulu
                $published_port_to_check = null;
                if (!empty($details['NetworkSettings']['Ports'])) {
                    foreach ($details['NetworkSettings']['Ports'] as $port_bindings) {
                        if (is_array($port_bindings) && !empty($port_bindings[0]['HostPort']) && $port_bindings[0]['HostPort'] != '0') {
                            $published_port_to_check = (int)$port_bindings[0]['HostPort'];
                            break;
                        }
                    }
                }

                if ($published_port_to_check && $is_healthy === null) {
                    $connection = @fsockopen('127.0.0.1', $published_port_to_check, $errno, $errstr, 2);
                    if (is_resource($connection)) {
                        $is_healthy = true;
                        $log_message = "TCP check on published port 127.0.0.1:{$published_port_to_check}: OK";
                        fclose($connection);
                    } else {
                        // Jika port publik ada tapi tidak bisa dijangkau, jangan langsung gagal.
                        // Biarkan $is_healthy tetap null agar fallback ke pengecekan internal.
                        log_message("    -> Pengecekan port publik 127.0.0.1:{$published_port_to_check} gagal. Melanjutkan ke pengecekan internal...");
                    }
                }

                // Prioritas 2b: Jika status masih belum ditentukan, coba port internal.
                // Ini akan berjalan jika tidak ada HEALTHCHECK, dan (tidak ada port publik ATAU port publik gagal dicek).
                if ($is_healthy === null) {
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
                    $exposed_ports = array_keys($details['NetworkSettings']['Ports']);
                    $tcp_ports = array_map(fn($p) => (int)$p, array_filter($exposed_ports, fn($p) => str_ends_with($p, '/tcp')));

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

                    // 3. Lakukan pengecekan jika IP dan Port internal ditemukan
                    if ($internal_ip && $internal_port) {
                        $network_id_to_join = key($details['NetworkSettings']['Networks']);
                        $agent_container_id = getenv('HOSTNAME');
                        try {
                            $dockerClient->connectToNetwork($network_id_to_join, $agent_container_id);
                            log_message("    -> Joined network '{$network_id_to_join}' to check internal IP {$internal_ip}:{$internal_port}.");
                            $connection = @fsockopen($internal_ip, $internal_port, $errno, $errstr, 2);
                            if (is_resource($connection)) {
                                $is_healthy = true;
                                $log_message = "TCP check on internal port {$internal_port}: OK";
                                fclose($connection);
                            } else {
                                $is_healthy = false;
                                $log_message = "TCP check on internal port {$internal_port}: FAILED ({$errstr})";
                            }
                        } catch (Exception $e) {
                            $is_healthy = false;
                            $log_message = "Failed to join network or perform internal check: " . $e->getMessage();
                        } finally {
                            $dockerClient->disconnectFromNetwork($network_id_to_join, $agent_container_id);
                            log_message("    -> Left network '{$network_id_to_join}'.");
                        }
                    }

                    // 4. Jika tidak ada port yang terdeteksi tapi IP ada, lakukan ping check sebagai fallback
                    if ($is_healthy === null && $internal_ip) {
                        log_message("    -> No TCP port found. Attempting ICMP (ping) check on internal IP {$internal_ip}.");
                        $network_id_to_join = key($details['NetworkSettings']['Networks']);
                        $agent_container_id = getenv('HOSTNAME');
                        try {
                            $dockerClient->connectToNetwork($network_id_to_join, $agent_container_id);
                            log_message("    -> Joined network '{$network_id_to_join}' for ping check.");
                            
                            // Gunakan exec untuk menjalankan ping dan cek return code.
                            // -c 1: kirim 1 paket. -W 1: timeout 1 detik.
                            exec("ping -c 1 -W 1 " . escapeshellarg($internal_ip), $output, $return_var);

                            if ($return_var === 0) {
                                $is_healthy = true;
                                $log_message = "ICMP (ping) check on internal IP {$internal_ip}: OK";
                            } else {
                                $is_healthy = false;
                                $log_message = "ICMP (ping) check on internal IP {$internal_ip}: FAILED";
                            }
                        } finally {
                            $dockerClient->disconnectFromNetwork($network_id_to_join, $agent_container_id);
                            log_message("    -> Left network '{$network_id_to_join}'.");
                        }
                    }

                }

                // Jika setelah semua usaha, status masih belum ditentukan, biarkan null (unknown).
                if ($is_healthy === null) {
                    $is_healthy = null; // Tetap null untuk status 'unknown'
                    $log_message = "Container does not have a HEALTHCHECK instruction and no TCP port could be reliably checked.";
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
                try {
                    $dockerClient->restartContainer($container_id);
                    log_message("  -> SUKSES: Perintah restart berhasil dikirim ke kontainer '{$container_name}'.");
                    // Add the healing action to the main log message
                    $log_message .= " | Auto-healing: Restart command sent.";
                } catch (Exception $e) {
                    log_message("  -> ERROR saat auto-healing: " . $e->getMessage());
                }
            }

            $health_reports[] = [
                'container_id' => $container_id,
                'container_name' => $container_name,
                'is_healthy' => $is_healthy,
                'log_message' => $log_message
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

    $report_payload = [
        'host_id' => (int)$hostId,
        'reports' => $health_reports
    ];

    log_message("  -> Preparing to send report with the following configuration:");
    log_message("    -> Target URL: {$configManagerUrl}");
    // Mask the API key for security, showing only the first and last 4 characters.
    log_message("    -> API Key: " . substr($apiKey, 0, 4) . "..." . substr($apiKey, -4));
    log_message("    -> Auto-Healing: " . ($autoHealingEnabled ? 'AKTIF' : 'NONAKTIF'));

    log_message("  -> Sending report payload: " . json_encode($report_payload));
    postHealthData($configManagerUrl . '/api/health/report', $apiKey, $report_payload);
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