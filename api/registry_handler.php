<?php
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
    exit;
}

    function call_registry_api($url, $username, $password) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        // Allow insecure connections for local registries with self-signed certs
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        // FIX: Only set credentials if both username and password are provided.
        if (!empty(trim((string)$username)) && !empty(trim((string)$password))) {
            curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
        }

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) throw new Exception("cURL Error: " . $error);
        // FIX: Handle 404 Not Found gracefully. It's a valid response if a repo/tag doesn't exist.
        if ($http_code === 404) {
            return []; // Return an empty array, which the frontend can handle.
        }
        // For other errors, throw an exception.
        if ($http_code !== 200) throw new Exception("Registry API returned HTTP {$http_code}. Response: " . substr($response, 0, 200));

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) throw new Exception("Failed to decode JSON response from registry API.");
        
        return $data;
    }

$request_uri_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && str_ends_with($request_uri_path, '/test-connection')) {
    try {
        $url = trim($_POST['registry_url'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');

        if (empty($url)) {
            throw new Exception("Registry URL is required.");
        }

        // The /v2/ endpoint should return a 200 OK on a valid registry, even if it requires auth (it will send a challenge).
        call_registry_api(rtrim($url, '/') . '/v2/', $username, $password);

        echo json_encode(['status' => 'success', 'message' => 'Connection successful! Registry API is reachable.']);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Connection failed: ' . $e->getMessage()]);
    }
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

    $request_uri_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

    if (str_ends_with($request_uri_path, '/repositories')) {
        $data = call_registry_api("{$registry_url}/v2/_catalog", $username, $password);
        $repositories = $data['repositories'] ?? [];
        sort($repositories);
        echo json_encode(['status' => 'success', 'data' => $repositories]);

    } elseif (str_ends_with($request_uri_path, '/tags')) {
        $repo = $_GET['repo'] ?? '';
        if (empty($repo)) throw new Exception("Repository name is required.");

        // FIX: The repository name from $_GET is already URL-decoded by PHP.
        // We must re-encode it to handle repository names containing slashes (e.g., 'group/image').
        // This ensures the slash is correctly passed to the registry API as part of the name.
        $encoded_repo = str_replace('%2F', '/', urlencode($repo));
        $data = call_registry_api("{$registry_url}/v2/{$encoded_repo}/tags/list", $username, $password);
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