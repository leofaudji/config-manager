<?php
// Set the default timezone for the entire application to GMT+7
date_default_timezone_set('Asia/Jakarta');


require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/functions.php';

// Define base path dynamically so it's available globally.
if (!defined('BASE_PATH')) {
    $basePath = dirname($_SERVER['SCRIPT_NAME']);
    if ($basePath === '/' || $basePath === '\\') {
        $basePath = ''; // Empty if in root directory
    }
    define('BASE_PATH', rtrim($basePath, '/'));
}

// Define project root path for reliable file includes.
if (!defined('PROJECT_ROOT')) {
    define('PROJECT_ROOT', dirname(__DIR__));
}

if (!defined('APP_VERSION')) {
    // --- Versi Aplikasi (Dinamis dari Changelog) ---
    require_once PROJECT_ROOT . '/includes/ChangelogParser.php';
    define('APP_VERSION', ChangelogParser::getLatestVersion());
}


// --- NEW: Define storage path for logs, cache, etc. ---
if (!defined('STORAGE_PATH')) {
    define('STORAGE_PATH', PROJECT_ROOT . '/storage');
}
// --- NEW: Define logs path and ensure it exists ---
if (!defined('LOGS_PATH')) {
    define('LOGS_PATH', STORAGE_PATH . '/logs');
    $deployment_logs_dir = LOGS_PATH . '/deployments';
    if (!is_dir($deployment_logs_dir)) {
        // Attempt to create with 0775 permissions, allowing web server and group to write.
        @mkdir($deployment_logs_dir, 0775, true);
    }
}

// Load environment variables from the root directory
try {
    Config::load(PROJECT_ROOT . '/.env');
} catch (\Exception $e) {
    die('Error: Could not load configuration. Make sure a .env file exists in the root directory. Details: ' . $e->getMessage());
}

// Load application-specific configurations (constants)
require_once PROJECT_ROOT . '/config.php';