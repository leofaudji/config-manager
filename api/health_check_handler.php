<?php
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');

$results = [];

// 1. Check PHP Version
$php_version_ok = version_compare(PHP_VERSION, '7.4.0', '>=');
$results[] = [
    'check' => 'PHP Version',
    'status' => $php_version_ok,
    'message' => 'Required: >= 7.4.0, Found: ' . PHP_VERSION,
];

// 2. Check Database Connection
try {
    Database::getInstance()->getConnection();
    $db_ok = true;
    $db_message = 'Successfully connected to the database.';
} catch (Exception $e) {
    $db_ok = false;
    $db_message = 'Failed to connect: ' . $e->getMessage();
}
$results[] = [
    'check' => 'Database Connection',
    'status' => $db_ok,
    'message' => $db_message,
];

// 3. Check Required PHP Extensions
$mysqli_ok = extension_loaded('mysqli');
$results[] = [
    'check' => 'PHP Extension: mysqli',
    'status' => $mysqli_ok,
    'message' => $mysqli_ok ? 'Installed.' : 'Not installed.',
];

$curl_ok = extension_loaded('curl');
$results[] = [
    'check' => 'PHP Extension: curl',
    'status' => $curl_ok,
    'message' => $curl_ok ? 'Installed.' : 'Not installed.',
];

// 4. Check YAML Output Path Writable
$yaml_path = YAML_OUTPUT_PATH;
$yaml_writable = is_writable($yaml_path);
$results[] = [
    'check' => 'YAML Output File Writable',
    'status' => $yaml_writable,
    'message' => $yaml_writable ? "Path '{$yaml_path}' is writable." : "Path '{$yaml_path}' is NOT writable. Please check file permissions.",
];

// 5. Check if git command is available
$git_version = shell_exec('git --version');
$git_ok = !empty($git_version);
$results[] = [
    'check' => 'Git Command Availability',
    'status' => $git_ok,
    'message' => $git_ok ? "Git is available. Version: " . trim($git_version) : "Git command not found. Git integration will fail.",
];

// 6. If Git is enabled, check SSH key
if ((bool)get_setting('git_integration_enabled', false)) {
    $ssh_key_path = get_setting('git_ssh_key_path');
    $ssh_key_ok = !empty($ssh_key_path) && file_exists($ssh_key_path) && is_readable($ssh_key_path);
    $results[] = [
        'check' => 'Git SSH Key',
        'status' => $ssh_key_ok,
        'message' => $ssh_key_ok ? "SSH key found and readable at '{$ssh_key_path}'." : "SSH key not found or not readable at '{$ssh_key_path}'.",
    ];
} else {
    $results[] = [
        'check' => 'Git SSH Key',
        'status' => true, // Pass if not enabled
        'message' => 'Git integration is disabled.',
    ];
}

// 7. Check Traefik API Connectivity
$api_url = rtrim(TRAEFIK_API_URL, '/') . '/api/version'; // Use a lightweight endpoint
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $api_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

$traefik_api_ok = ($http_code === 200 && !$curl_error);
$traefik_message = $traefik_api_ok
    ? "Successfully connected to Traefik API at '{$api_url}'."
    : "Failed to connect to Traefik API at '{$api_url}'. HTTP Code: {$http_code}. Error: {$curl_error}";

$results[] = [
    'check' => 'Traefik API Connectivity',
    'status' => $traefik_api_ok,
    'message' => $traefik_message,
];

echo json_encode($results);
?>