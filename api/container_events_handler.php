<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/DockerClient.php';

// Set headers for Server-Sent Events (SSE)
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

// Disable output buffering for streaming
@ini_set('zlib.output_compression', 0);
if (ob_get_level() > 0) {
    for ($i = 0; $i < ob_get_level(); $i++) {
        ob_end_flush();
    }
}
ob_implicit_flush(1);

function send_event($data) {
    // The data must be a single line. We JSON encode it.
    echo "data: " . json_encode($data) . "\n\n";
    // Flush the output buffer to send the event immediately
    flush();
}

try {
    // Extract host ID from the URL path
    $request_uri_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $basePath = BASE_PATH;
    if ($basePath && strpos($request_uri_path, $basePath) === 0) {
        $request_uri_path = substr($request_uri_path, strlen($basePath));
    }

    if (!preg_match('/^\/api\/hosts\/(\d+)\/events$/', $request_uri_path, $matches)) {
        throw new InvalidArgumentException("Invalid API endpoint format for container events.");
    }

    $host_id = $matches[1];

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

    // Use the DockerClient to make a streaming request to the /events endpoint
    $dockerClient = new DockerClient($host);
    $apiUrl = ($host['tls_enabled'] ? 'https://' : 'http://') . str_replace('tcp://', '', $host['docker_api_url']);
    $url = $apiUrl . "/events";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false); // Do not buffer output
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($curl, $data) {
        // Each chunk from Docker is a complete JSON object on a new line
        $event = json_decode($data, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            send_event($event);
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