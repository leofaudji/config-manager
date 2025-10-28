<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/DockerClient.php';

// Set headers for Server-Sent Events (SSE)
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

// Disable output buffering
@ini_set('zlib.output_compression', 0);
if (ob_get_level() > 0) {
    for ($i = 0; $i < ob_get_level(); $i++) {
        ob_end_flush();
    }
}
ob_implicit_flush(1);

function send_event($data) {
    echo "data: " . json_encode($data) . "\n\n";
    flush();
}

try {
    $request_uri_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $basePath = BASE_PATH;
    if ($basePath && strpos($request_uri_path, $basePath) === 0) {
        $request_uri_path = substr($request_uri_path, strlen($basePath));
    }

    if (!preg_match('/^\/api\/hosts\/(\d+)\/containers\/([a-zA-Z0-9]+)\/stats$/', $request_uri_path, $matches)) {
        throw new InvalidArgumentException("Invalid API endpoint format for container stats.");
    }

    $host_id = $matches[1];
    $container_id = $matches[2];

    $conn = Database::getInstance()->getConnection();
    $stmt = $conn->prepare("SELECT * FROM docker_hosts WHERE id = ?");
    $stmt->bind_param("i", $host_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if (!($host = $result->fetch_assoc())) {
        throw new Exception("Host not found.");
    }
    $stmt->close();
    $conn->close();

    $dockerClient = new DockerClient($host);
    $apiUrl = ($host['tls_enabled'] ? 'https://' : 'http://') . str_replace('tcp://', '', $host['docker_api_url']);
    $url = $apiUrl . "/containers/{$container_id}/stats?stream=true";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false); // Do not buffer output
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($curl, $data) {
        // Each chunk from Docker is a complete JSON object on a new line
        $stats = json_decode($data, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            // Calculate CPU percentage
            $cpu_delta = ($stats['cpu_stats']['cpu_usage']['total_usage'] ?? 0) - ($stats['precpu_stats']['cpu_usage']['total_usage'] ?? 0);
            $system_cpu_delta = ($stats['cpu_stats']['system_cpu_usage'] ?? 0) - ($stats['precpu_stats']['system_cpu_usage'] ?? 0);
            $number_cpus = $stats['cpu_stats']['online_cpus'] ?? count($stats['cpu_stats']['cpu_usage']['percpu_usage'] ?? []);

            $cpu_percent = 0.0;
            if ($system_cpu_delta > 0.0 && $cpu_delta > 0.0) {
                $cpu_percent = ($cpu_delta / $system_cpu_delta) * $number_cpus * 100.0;
            }

            // Memory usage
            $memory_usage = $stats['memory_stats']['usage'] ?? 0;
            $memory_limit = $stats['memory_stats']['limit'] ?? 0;

            send_event([
                'cpu_percent' => round($cpu_percent, 2),
                'memory_usage' => $memory_usage,
                'memory_limit' => $memory_limit,
                'timestamp' => date('H:i:s')
            ]);
        }
        return strlen($data); // Required by cURL
    });

    if ($host['tls_enabled']) {
        curl_setopt($ch, CURLOPT_SSLCERT, $host['client_cert_path']);
        curl_setopt($ch, CURLOPT_SSLKEY, $host['client_key_path']);
        curl_setopt($ch, CURLOPT_CAINFO, $host['ca_cert_path']);
    }

    curl_exec($ch);
    if (curl_errno($ch)) {
        send_event(['error' => 'Stream connection failed: ' . curl_error($ch)]);
    }
    curl_close($ch);

} catch (Exception $e) {
    send_event(['error' => 'An error occurred: ' . $e->getMessage()]);
}