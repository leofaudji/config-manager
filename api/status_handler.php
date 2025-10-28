<?php
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');

try {
    $is_dirty = (bool)get_setting('traefik_config_dirty', '0');
    echo json_encode(['status' => 'success', 'dirty' => $is_dirty]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to get config status.']);
}
?>