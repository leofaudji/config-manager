<?php
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
    exit;
}

$host_id = $_GET['host_id'] ?? null;
if (!$host_id) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Host ID is required.']);
    exit;
}

$conn = Database::getInstance()->getConnection();

try {
    $stmt = $conn->prepare("SELECT * FROM docker_hosts WHERE id = ?");
    $stmt->bind_param("i", $host_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if (!($host = $result->fetch_assoc()) || empty($host['registry_url'])) {
        throw new Exception("Host or its registry configuration not found.");
    }
    $stmt->close();

    $registry_url = rtrim($host['registry_url'], '/');
    $username = $host['registry_username'];
    $password = $host['registry_password'];

    function call_registry_api($url, $username, $password) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        // Allow insecure connections for local registries with self-signed certs
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        if (!empty($username) && !empty($password)) {
            curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
        }

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) throw new Exception("cURL Error: " . $error);
        if ($http_code !== 200) throw new Exception("Registry API returned HTTP {$http_code}. Response: " . substr($response, 0, 200));

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) throw new Exception("Failed to decode JSON response from registry API.");
        
        return $data;
    }

    $request_uri_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

    if (str_ends_with($request_uri_path, '/repositories')) {
        $data = call_registry_api("{$registry_url}/v2/_catalog", $username, $password);
        $repositories = $data['repositories'] ?? [];
        sort($repositories);
        echo json_encode(['status' => 'success', 'data' => $repositories]);

    } elseif (str_ends_with($request_uri_path, '/tags')) {
        $repo = $_GET['repo'] ?? '';
        if (empty($repo)) throw new Exception("Repository name is required.");

        $data = call_registry_api("{$registry_url}/v2/" . urlencode($repo) . "/tags/list", $username, $password);
        $tags = $data['tags'] ?? [];
        rsort($tags, SORT_NATURAL); // Sort tags naturally in reverse (latest versions first)
        echo json_encode(['status' => 'success', 'data' => $tags]);

    } else {
        throw new Exception("Invalid registry API endpoint.");
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
} finally {
    if (isset($conn)) $conn->close();
}
?>