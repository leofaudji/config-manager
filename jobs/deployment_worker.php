#!/usr/bin/php
<?php
// File: /var/www/html/config-manager/jobs/deployment_worker.php

// --- NEW: Robust error handling and output redirection ---
// Redirect all output (echo, die, errors) to stderr so it can be captured by the shell's `2>&1`.
ini_set('display_errors', 'stderr');
error_reporting(E_ALL);

try {
    // Pastikan skrip ini hanya bisa dijalankan dari command line.
    if (php_sapi_name() !== 'cli') {
        throw new Exception("This script can only be run from the command line.");
    }

    // Beri waktu yang cukup untuk proses deployment yang mungkin lama.
    set_time_limit(900); // 15 menit

    // --- FIX: Define PROJECT_ROOT explicitly for CLI context ---
    // This ensures that bootstrap.php can find the .env file correctly.
    if (!defined('PROJECT_ROOT')) {
        define('PROJECT_ROOT', dirname(__DIR__));
    }
    // Bootstrap aplikasi untuk mendapatkan akses ke semua fungsi dan class.
    require_once __DIR__ . '/../includes/bootstrap.php';
    require_once __DIR__ . '/../includes/AppLauncherHelper.php';

    // Periksa apakah argumen data diberikan.
    if ($argc < 2) {
        throw new Exception("Usage: php deployment_worker.php <base64_encoded_data>");
    }

    // Ambil dan dekode data dari argumen command line.
    $encoded_data = $argv[1];
    $json_data = base64_decode($encoded_data);
    $post_data = json_decode($json_data, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid data passed to background worker. JSON Error: ' . json_last_error_msg());
    }

    // --- DEFINITIVE FIX: Use the injected config from the parent process ---
    // This sets the environment variables for THIS process before anything else runs.
    if (isset($post_data['db_config']) && is_array($post_data['db_config'])) {
        foreach ($post_data['db_config'] as $key => $value) {
            // Use putenv to make it available to getenv() and subsequently Config::get()
            putenv("{$key}={$value}");
            $_ENV[$key] = $value; // Also set it in the superglobal for good measure
        }
    }

    // --- NEW: Check if this is a grouped deployment ---
    if (isset($post_data['is_grouped_deployment']) && $post_data['is_grouped_deployment'] === true) {
        // Call the new grouped deployment handler
        AppLauncherHelper::executeGroupedDeploymentFromGit($post_data);
    } else {
        // Fallback to the original single deployment logic
        AppLauncherHelper::executeDeployment($post_data);
    }

} catch (Exception $e) {
    // Catch any exception, including those from require_once or invalid data, and write to stderr.
    fwrite(STDERR, "FATAL ERROR in deployment worker: " . $e->getMessage() . "\n");
    exit(1); // Exit with a non-zero status code to indicate failure.
}
?>