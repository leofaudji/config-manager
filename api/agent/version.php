<?php
// File: /var/www/html/config-manager/api/agent/version.php

require_once __DIR__ . '/../../includes/bootstrap.php';

header('Content-Type: application/json');

try {
    // Path ke file agent.php yang selalu terbaru
    $agent_script_path = PROJECT_ROOT . '/storage/latest_agent/agent.php';

    if (!file_exists($agent_script_path) || !is_readable($agent_script_path)) {
        throw new Exception("File skrip agen tidak ditemukan atau tidak dapat dibaca.");
    }

    $script_content = file_get_contents($agent_script_path);

    // Gunakan regex untuk menemukan baris definisi versi secara andal
    if (preg_match("/define\s*\(\s*'AGENT_VERSION'\s*,\s*'([^']+)'\s*\)/", $script_content, $matches)) {
        $version = $matches[1];
        echo json_encode(['status' => 'success', 'version' => $version]);
    } else {
        throw new Exception("Tidak dapat menentukan versi agen dari file skrip.");
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}