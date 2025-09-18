<?php
require_once __DIR__ . '/../includes/bootstrap.php';
// Sesi dan otentikasi/otorisasi sudah ditangani oleh Router.

header('Content-Type: application/json');

$api_url = rtrim(TRAEFIK_API_URL, '/') . '/api/http/services';

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $api_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5); // 5 detik timeout koneksi
curl_setopt($ch, CURLOPT_TIMEOUT, 10);      // 10 detik timeout total

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

if ($curl_error) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to connect to Traefik API: ' . $curl_error]);
    exit;
}

if ($http_code !== 200) {
    http_response_code($http_code);
    echo json_encode(['error' => 'Traefik API returned non-200 status code.', 'details' => $response]);
    exit;
}

$services = json_decode($response, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to parse JSON response from Traefik API.']);
    exit;
}

echo json_encode($services);
?>