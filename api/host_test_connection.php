<?php
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

// The router should have already matched /{id}/ so we can get it from the URI
$request_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (!preg_match('/\/hosts\/(\d+)\/test/', $request_path, $matches)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid host ID.']);
    exit;
}
$id = $matches[1];

$conn = Database::getInstance()->getConnection();

try {
    $stmt = $conn->prepare("SELECT * FROM docker_hosts WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    if (!($host = $result->fetch_assoc())) {
        throw new Exception("Host not found.");
    }
    $stmt->close();

    $api_url = $host['docker_api_url'];
    // Docker API uses http scheme over tcp sockets. We need to convert tcp:// to http:// or https:// for cURL.
    $curl_url = ($host['tls_enabled'] ? 'https://' : 'http://') . str_replace('tcp://', '', $api_url) . '/_ping';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $curl_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5); // 5 second timeout

    if ($host['tls_enabled']) {
        // Check if cert files exist
        if (!file_exists($host['ca_cert_path']) || !file_exists($host['client_cert_path']) || !file_exists($host['client_key_path'])) {
            throw new Exception("One or more TLS certificate files not found on the application server.");
        }
        
        curl_setopt($ch, CURLOPT_SSLCERT, $host['client_cert_path']);
        curl_setopt($ch, CURLOPT_SSLKEY, $host['client_key_path']);
        curl_setopt($ch, CURLOPT_CAINFO, $host['ca_cert_path']);
    }

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($http_code === 200 && $response === 'OK') {
        log_activity($_SESSION['username'], 'Host Connection Test', "Successfully connected to host '{$host['name']}'.");
        echo json_encode(['status' => 'success', 'message' => "Successfully connected to {$host['name']}."]);
    } else {
        $error_message = "Failed to connect to {$host['name']}.";
        if ($curl_error) {
            $error_message .= " cURL Error: " . $curl_error;
        } else {
            $error_message .= " Received HTTP status {$http_code}. Response: " . substr(htmlspecialchars($response), 0, 100);
        }
        throw new Exception($error_message);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();
?>