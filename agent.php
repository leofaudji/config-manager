<?php
// File: health-agent/agent.php

set_time_limit(0); // Run indefinitely

// --- Konfigurasi Agen (diambil dari environment variables) ---
$configManagerUrl = getenv('CONFIG_MANAGER_URL');
$apiKey = getenv('API_KEY');
$hostId = getenv('HOST_ID');
$autoHealingEnabled = (getenv('AUTO_HEALING_ENABLED') === '1');
$logBuffer = [];

ob_implicit_flush(true); // Ensure logs are sent immediately

if (!$configManagerUrl || !$apiKey || !$hostId) {
    die("Error: Environment variables CONFIG_MANAGER_URL, API_KEY, and HOST_ID must be set.\n");
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
        log_message("  -> ERROR: Gagal mengirim laporan (cURL Error): " . $curl_error);
        return;
    }
    
    if ($http_code >= 200 && $http_code < 300) {
        log_message("  -> SUKSES: Laporan berhasil dikirim ke Config Manager (HTTP {$http_code}).");
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

    log_message("  -> Menemukan " . count($containers) . " kontainer.");

    foreach ($containers as $container) {
        if ($container['State'] !== 'running') {
            continue;
        }

        $container_id = $container['Id'];
        $container_name = ltrim($container['Names'][0] ?? $container_id, '/');

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
            // --- Alur 2: Fallback ke Pengecekan Port TCP ---
            $target_ip = null;
            $target_port = null;

            // Cari IP internal pertama
            if (!empty($details['NetworkSettings']['Networks'])) {
                foreach ($details['NetworkSettings']['Networks'] as $net) {
                    if (!empty($net['IPAddress'])) {
                        $target_ip = $net['IPAddress'];
                        break;
                    }
                }
            }

            // Cari port internal pertama
            if (!empty($details['NetworkSettings']['Ports'])) {
                foreach ($details['NetworkSettings']['Ports'] as $port_info) {
                    if (is_array($port_info)) { // Port yang dipublikasikan
                        $port_key = key($port_info);
                        $parts = explode('/', $port_key);
                        // Ensure the port key is in the expected "port/protocol" format
                        if (count($parts) === 2) {
                            list($private_port, $protocol) = $parts;
                            if (strtolower($protocol) === 'tcp') {
                                $target_port = (int)$private_port;
                                break;
                            }
                        }
                    }
                }
            }

            // Jika port tidak ditemukan, coba tebak dari nama image
            if (!$target_port) {
                $image_name = strtolower($container['Image']);
                $common_ports = [
                    'mysql' => 3306, 'mariadb' => 3306, 'postgres' => 5432,
                    'redis' => 6379, 'mongo' => 27017, 'rabbitmq' => 5672,
                    'nginx' => 80, 'httpd' => 80, 'apache' => 80,
                    'elasticsearch' => 9200, 'kibana' => 5601,
                    'grafana' => 3000, 'prometheus' => 9090
                ];
                foreach ($common_ports as $keyword => $port) {
                    if (strpos($image_name, $keyword) !== false) {
                        $target_port = $port;
                        break;
                    }
                }
            }

            if ($target_ip && $target_port) {
                $timeout = 2; // 2 detik timeout
                $connection = @fsockopen($target_ip, $target_port, $errno, $errstr, $timeout);

                if (is_resource($connection)) {
                    $is_healthy = true;
                    $log_message = "TCP check on internal port {$target_port}: OK";
                    fclose($connection);
                } else {
                    $is_healthy = false;
                    $log_message = "TCP check on internal port {$target_port}: FAILED ({$errstr})";
                }
            } else {
                $log_message = "Container does not have a HEALTHCHECK instruction and no TCP port could be detected.";
            }
        }

        $status_text = $is_healthy === true ? 'Sehat' : ($is_healthy === false ? 'Tidak Sehat' : 'Tidak Diketahui');
        log_message("    - Mengevaluasi '{$container_name}': {$status_text}");

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
    }

    $report_payload = [
        'host_id' => (int)$hostId,
        'reports' => $health_reports
    ];
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