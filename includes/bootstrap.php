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
    die('Error: Could not load configuration. Make sure a .env file exists in the root directory. Details: ' . $e->getMessage());
}

// Load application-specific configurations (constants)
require_once PROJECT_ROOT . '/config.php';