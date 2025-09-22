<?php
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');

$checks = [];
$conn = null;

// Check #1: PHP Version
$php_version_ok = version_compare(PHP_VERSION, '7.4.0', '>=');
$checks[] = [
    'check' => 'PHP Version',
    'status' => $php_version_ok,
    'message' => $php_version_ok ? 'PHP version is ' . PHP_VERSION . ' (OK).' : 'PHP version is ' . PHP_VERSION . '. Version 7.4.0 or higher is required.'
];

// Check #2: Required PHP Extensions
$required_extensions = ['mysqli', 'curl', 'json', 'mbstring'];
$missing_extensions = [];
foreach ($required_extensions as $ext) {
    if (!extension_loaded($ext)) {
        $missing_extensions[] = $ext;
    }
}
$extensions_ok = empty($missing_extensions);
$checks[] = [
    'check' => 'PHP Extensions',
    'status' => $extensions_ok,
    'message' => $extensions_ok ? 'All required PHP extensions are loaded.' : 'The following required PHP extensions are missing: ' . implode(', ', $missing_extensions) . '.'
];

// Check #3: Database Connection
try {
    $conn = Database::getInstance()->getConnection();
    $db_ok = $conn && $conn->ping();
    $db_message = $db_ok ? 'Successfully connected to the database.' : 'Failed to connect. Check .env. Error: ' . ($conn ? $conn->error : 'N/A');
} catch (Exception $e) {
    $db_ok = false;
    $db_message = 'Failed to connect. Check .env. Error: ' . $e->getMessage();
}
$checks[] = [
    'check' => 'Database Connection',
    'status' => $db_ok,
    'message' => $db_message
];

// Check #4: Traefik Dynamic Config Base Path Writable
$base_config_path = get_setting('yaml_output_path', PROJECT_ROOT . '/traefik-configs');
$is_writable = is_dir($base_config_path) && is_writable($base_config_path);
$checks[] = [
    'check' => 'Traefik Dynamic Config Path',
    'status' => $is_writable,
    'message' => $is_writable ? "The base path '{$base_config_path}' is writable." : "The base path '{$base_config_path}' is NOT writable by the web server or is not a directory. Deployments will fail."
];

if ($conn) {
    $conn->close();
}

echo json_encode($checks);
?>