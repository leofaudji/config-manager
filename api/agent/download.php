<?php
// File: /path/to/your/config-manager/api/agent/download.php

// --- 1. Otentikasi Permintaan ---
// Ini adalah langkah paling penting untuk keamanan.
// Pastikan hanya agen yang memiliki API Key yang benar yang bisa mengunduh.
$providedApiKey = $_SERVER['HTTP_X_API_KEY'] ?? null;

// Ambil API Key yang seharusnya dari environment variable atau file konfigurasi Anda.
// Ini harus sama dengan API Key yang digunakan oleh agen.
$expectedApiKey = getenv('YOUR_APP_API_KEY'); 

if (!$providedApiKey || !hash_equals($expectedApiKey, $providedApiKey)) {
    // Jika API Key tidak cocok, tolak permintaan.
    header('Content-Type: application/json');
    http_response_code(401); // Unauthorized
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// --- 2. Tentukan Lokasi File Agen ---
// Simpan file agent.php versi terbaru di lokasi yang aman di server Anda,
// idealnya di luar direktori web publik.
$agentScriptPath = '/var/www/html/config-manager/storage/latest_agent/agent.php';

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
