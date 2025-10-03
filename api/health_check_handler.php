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

// Check #5: Cron Job Log Path Writable
$cron_log_path = get_setting('cron_log_path', '/var/log');
$cron_log_path_ok = is_dir($cron_log_path) && is_writable($cron_log_path);
$checks[] = [
    'check' => 'Cron Job Log Path',
    'status' => $cron_log_path_ok,
    'message' => $cron_log_path_ok ? "The log path '{$cron_log_path}' is writable." : "The log path '{$cron_log_path}' is NOT writable by the web server. Cron jobs will fail to write logs."
];

// Check #5a: Health Monitor Log File Writable
$health_monitor_log_file = rtrim($cron_log_path, '/') . '/health_monitor.log';
$health_monitor_log_ok = false;
$health_monitor_log_message = '';

if (file_exists($health_monitor_log_file)) {
    if (is_writable($health_monitor_log_file)) {
        $health_monitor_log_ok = true;
        $health_monitor_log_message = "Log file '{$health_monitor_log_file}' exists and is writable.";
    } else {
        $health_monitor_log_ok = false;
        $health_monitor_log_message = "Log file '{$health_monitor_log_file}' exists but is NOT writable by the web server. Please check file permissions.";
    }
} else {
    if ($cron_log_path_ok) { // Check if the parent directory is writable
        $health_monitor_log_ok = true;
        $health_monitor_log_message = "Log file does not exist yet, but the directory '{$cron_log_path}' is writable. The file will be created when the health monitor cron job runs.";
    } else {
        $health_monitor_log_ok = false;
        $health_monitor_log_message = "Log file does not exist and the directory '{$cron_log_path}' is NOT writable. The cron job will fail to create the log file.";
    }
}
$checks[] = [
    'check' => 'Health Monitor Log File',
    'status' => $health_monitor_log_ok,
    'message' => $health_monitor_log_message
];

// Check #6: Cron User Shell
$web_user = exec('whoami');
$user_shell_ok = false;
$user_shell_message = "Could not determine web server user or read /etc/passwd.";
if ($web_user && file_exists('/etc/passwd') && is_readable('/etc/passwd')) {
    $passwd_content = file_get_contents('/etc/passwd');
    if (preg_match('/^' . preg_quote($web_user, '/') . ':x:\d+:\d+:.*:.*:(.*)$/m', $passwd_content, $matches)) {
        $shell = $matches[1];
        if ($shell !== '/usr/sbin/nologin' && $shell !== '/bin/false' && $shell !== '/sbin/nologin') {
            $user_shell_ok = true;
            $user_shell_message = "Web server user '{$web_user}' has a valid shell: {$shell}.";
        } else {
            $user_shell_message = "Web server user '{$web_user}' has a shell set to '{$shell}', which will prevent cron jobs from running. To fix this, an administrator must run the following command on the server: <code>sudo usermod -s /bin/sh {$web_user}</code>";
        }
    } else {
        $user_shell_message = "Could not find user '{$web_user}' in /etc/passwd.";
    }
}
$checks[] = [
    'check' => 'Cron User Shell',
    'status' => $user_shell_ok,
    'message' => $user_shell_message
];

// Check #7: Cron Scripts Executable
$collect_stats_path = PROJECT_ROOT . '/collect_stats.php';
$autoscaler_path = PROJECT_ROOT . '/autoscaler.php';
$collect_stats_ok = is_executable($collect_stats_path);
$autoscaler_ok = is_executable($autoscaler_path);
$cron_scripts_ok = $collect_stats_ok && $autoscaler_ok;
$cron_scripts_message = 'All cron scripts are executable.';
if (!$cron_scripts_ok) {
    $failed_scripts = [];
    if (!$collect_stats_ok) $failed_scripts[] = '`collect_stats.php`';
    if (!$autoscaler_ok) $failed_scripts[] = '`autoscaler.php`';
    $cron_scripts_message = 'The following script(s) are not executable by the web server: ' . implode(', ', $failed_scripts) . '. Please run `chmod +x` on these files via SSH.';
}
$checks[] = [
    'check' => 'Cron Scripts Executable',
    'status' => $cron_scripts_ok,
    'message' => $cron_scripts_message
];

if ($conn) {
    $conn->close();
}

echo json_encode($checks);
?>