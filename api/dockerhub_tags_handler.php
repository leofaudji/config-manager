<?php
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

try {
    $image = trim($_GET['image'] ?? '');
    if (empty($image)) {
        throw new InvalidArgumentException("Image name is required.");
    }

    // Docker Hub official images are under 'library/'
    if (strpos($image, '/') === false) {
        $image = 'library/' . $image;
    }

    $all_tags = [];
    $url = "https://hub.docker.com/v2/repositories/" . urlencode($image) . "/tags/?page_size=100";

    while ($url) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Config-Manager-App/1.0');

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) throw new Exception("cURL Error: " . $error);
        if ($http_code !== 200) throw new Exception("Docker Hub API returned HTTP " . $http_code . " for image '{$image}'. The image may not exist or is private.");

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) throw new Exception("Failed to decode JSON response from Docker Hub API.");

        if (isset($data['results']) && is_array($data['results'])) $all_tags = array_merge($all_tags, array_column($data['results'], 'name'));

        $url = $data['next'] ?? null; // Get URL for the next page
    }

    echo json_encode(['status' => 'success', 'data' => $all_tags]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>