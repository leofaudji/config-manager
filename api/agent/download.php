<?php
// File: /path/to/your/config-manager/api/agent/download.php

require_once __DIR__ . '/../../includes/bootstrap.php';

// --- 1. Otentikasi Permintaan ---
// Ini adalah langkah paling penting untuk keamanan.
// Pastikan hanya agen yang memiliki API Key yang benar yang bisa mengunduh.
$providedApiKey = $_SERVER['HTTP_X_API_KEY'] ?? null;

// Ambil API Key yang seharusnya dari database settings.
// Ini harus sama dengan API Key yang digunakan oleh agen.
$expectedApiKey = get_setting('health_agent_api_token');

if (!$providedApiKey || !hash_equals($expectedApiKey, $providedApiKey)) {
    // Jika API Key tidak cocok, tolak permintaan.
    header('Content-Type: application/json');
    http_response_code(401); // Unauthorized
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// --- 2. Tentukan Lokasi File Agen ---
// --- FIX: Get the agent script path from settings for flexibility ---
// The default value ensures it works even if the setting hasn't been saved yet.
$agentScriptPath = get_setting('latest_agent_script_path', PROJECT_ROOT . '/storage/latest_agent/agent.php');

// --- 3. Kirim File ke Klien ---
if (file_exists($agentScriptPath) && is_readable($agentScriptPath)) {
    // Set header agar browser/curl tahu ini adalah file unduhan.
    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream'); // Tipe file generik
    header('Content-Disposition: attachment; filename="' . basename($agentScriptPath) . '"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . filesize($agentScriptPath));
    
    // Baca file dan kirimkan isinya ke output.
    readfile($agentScriptPath);
    exit;
} else {
    // Jika file tidak ditemukan, kirim error 404.
    header('Content-Type: application/json');
    http_response_code(404); // Not Found
    echo json_encode(['error' => 'Latest agent script not found on server.']);
    exit;
}
