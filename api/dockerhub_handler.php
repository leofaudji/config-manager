<?php
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

try {
    $query = trim($_GET['q'] ?? '');
    if (empty($query)) {
        throw new InvalidArgumentException("Search query is required.");
    }
    $page = (int)($_GET['page'] ?? 1);
    $page_size = 20;

    $url = "https://hub.docker.com/v2/search/repositories/?query=" . urlencode($query) . "&page_size={$page_size}&page={$page}";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Config-Manager-App/1.0');

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) throw new Exception("cURL Error: " . $error);
    if ($http_code !== 200) throw new Exception("Docker Hub API returned HTTP " . $http_code);

    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) throw new Exception("Failed to decode JSON response from Docker Hub API.");

    $results = [];
    if (isset($data['results']) && is_array($data['results'])) {
        foreach ($data['results'] as $repo) {
            $results[] = [
                'name' => $repo['repo_name'],
                'description' => $repo['short_description'],
                'stars' => $repo['star_count'],
                'is_official' => $repo['is_official'],
            ];
        }
    }

    $total_count = $data['count'] ?? 0;
    $total_pages = ceil($total_count / $page_size);

    echo json_encode([
        'status' => 'success', 
        'data' => $results,
        'pagination' => ['total_pages' => $total_pages, 'current_page' => $page, 'total_results' => $total_count]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>