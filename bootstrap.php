<?php
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

// Load environment variables from the root directory
try {
    Config::load(__DIR__ . '/../.env');
} catch (\Exception $e) {
    // Check if the request is AJAX. If so, return a JSON error. Otherwise, die with HTML.
    $is_ajax_request = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
    $error_message = 'Error: Could not load configuration. Make sure a .env file exists in the root directory.';

    if ($is_ajax_request) {
        http_response_code(500);
        // Ensure the header is set, as this might be the first output.
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => $error_message]);
        exit;
    }
    die($error_message . ' Details: ' . $e->getMessage());
}

// Load application-specific configurations (constants)
require_once PROJECT_ROOT . '/config.php';