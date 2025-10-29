<?php

/**
 * Generates a full URL including the base path.
 * @param string $uri The URI segment to append to the base path.
 * @return string The full, correct URL.
 */
function base_url(string $uri = ''): string {
    // Pastikan tidak ada double slash jika $uri dimulai dengan /
    return BASE_PATH . '/' . ltrim($uri, '/');
}

/**
 * Extracts the base domain from a Traefik rule string.
 * e.g., "Host(`sub.domain.co.uk`)" returns "domain.co.uk"
 * e.g., "Host(`domain.com`)" returns "domain.com"
 * @param string $rule The rule string.
 * @return string|null The extracted base domain or null if not found.
 */
function extractBaseDomain(string $rule): ?string
{
    // Find content inside Host(`...`)
    if (preg_match('/Host\(`([^`]+)`\)/i', $rule, $matches)) {
        $hostname = $matches[1];
        $parts = explode('.', $hostname);
        // A simple logic to get the last two parts for TLDs like .com, .net, or three for .co.uk, etc.
        // This is a simplification and might need adjustment for more complex TLDs.
        if (count($parts) > 2 && in_array($parts[count($parts) - 2], ['co', 'com', 'org', 'net', 'gov', 'edu'])) {
            return implode('.', array_slice($parts, -3));
        }
        return implode('.', array_slice($parts, -2));
    }
    return null;
}

/**
 * Expands a CIDR network notation into a list of usable IP addresses.
 * Excludes the network and broadcast addresses.
 * @param string $cidr The network range in CIDR format (e.g., "192.168.1.0/24").
 * @return array An array of IP address strings.
 * @throws Exception If the CIDR format is invalid or the range is too large.
 */
function expandCidrToIpRange(string $cidr): array
{
    if (!preg_match('/^([0-9]{1,3}\.){3}[0-9]{1,3}\/[0-9]{1,2}$/', $cidr)) {
        throw new Exception("Format CIDR tidak valid. Gunakan format seperti '192.168.1.0/24'.");
    }

    list($ip, $mask) = explode('/', $cidr);

    if ($mask < 22 || $mask > 31) { // Limit to a reasonable size (/22 is ~1022 hosts)
        throw new Exception("Ukuran subnet terlalu besar. Harap gunakan subnet antara /22 dan /31.");
    }

    $ip_long = ip2long($ip);
    $network_long = $ip_long & (-1 << (32 - $mask));
    $broadcast_long = $network_long | (1 << (32 - $mask)) - 1;

    $range = [];
    // Start from the first usable IP and end at the last usable IP
    for ($i = $network_long + 1; $i < $broadcast_long; $i++) {
        $range[] = long2ip($i);
    }
    return $range;
}

function log_activity(string $username, string $action, string $details = '', ?int $host_id = null, ?string $log_file_path = null, ?int $pid = null): int {
    try {
        $conn = Database::getInstance()->getConnection();
        $ip_address = 'UNKNOWN';
        if (php_sapi_name() === 'cli') {
            $ip_address = '127.0.0.1';
        } elseif (isset($_SERVER['REMOTE_ADDR'])) { // NOSONAR
            $ip_address = $_SERVER['REMOTE_ADDR'];
        }
        $stmt = $conn->prepare("INSERT INTO activity_log (username, action, details, ip_address, host_id, log_file_path, pid) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssisi", $username, $action, $details, $ip_address, $host_id, $log_file_path, $pid);
        $stmt->execute();
        $log_id = $stmt->insert_id;
        $stmt->close();
        return $log_id;
    } catch (Exception $e) {
        // Log error to a file, don't kill the script
        error_log("Failed to log activity: " . $e->getMessage());
        return 0;
    }
}

/**
 * Sends a notification to the configured external notification server.
 *
 * @param string $title The title of the notification.
 * @param string $message The main content of the notification.
 * @param string $level The severity level (e.g., 'error', 'warning', 'info').
 * @param array $context Additional context data to include in the payload.
 * @return void
 */
function send_notification(string $title, string $message, string $level = 'error', array $context = []): void {
    $is_enabled = (int)get_setting('notification_enabled', 0);
    $url = get_setting('notification_server_url');
    $token = get_setting('notification_secret_token');

    if (!$is_enabled || empty($url)) {
        return; // Do nothing if not enabled or URL is not set
    }

    $payload = array_merge([
        'title' => $title,
        'message' => $message,
        'level' => $level,
        'timestamp' => date('c'), // ISO 8601 timestamp
        'source_app' => 'Config Manager'
    ], $context);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    
    $headers = ['Content-Type: application/json'];
    if (!empty($token)) {
        $headers[] = 'X-Secret-Token: ' . $token;
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    // Use a short timeout to avoid blocking the main process for too long
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);

    curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    // --- NEW: Log the outcome of the notification attempt ---
    if ($curl_error) {
        log_activity('SYSTEM', 'Notification Failed', "Failed to send notification '{$title}'. cURL Error: {$curl_error}");
    } elseif ($http_code < 200 || $http_code >= 300) {
        log_activity('SYSTEM', 'Notification Failed', "Failed to send notification '{$title}'. Server responded with HTTP {$http_code}.");
    } else {
        log_activity('SYSTEM', 'Notification Sent', "Successfully sent notification '{$title}'.");
    }
}

/**
 * Gets a specific setting value from the database.
 * Caches all settings on first call to avoid multiple DB queries.
 * @param string $key The setting key to retrieve.
 * @param mixed $default The default value to return if the key is not found.
 * @return mixed The setting value.
 */
function get_setting(string $key, $default = null)
{
    static $settings = null;

    if ($settings === null) {
        $conn = Database::getInstance()->getConnection();
        $result = $conn->query("SELECT setting_key, setting_value FROM settings");
        $settings = [];
        while ($row = $result->fetch_assoc()) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }

    return $settings[$key] ?? $default;
}

/**
 * Gets the default group ID from the settings table.
 * @return int The default group ID.
 */
function getDefaultGroupId(): int
{
    return (int)get_setting('default_group_id', 1);
}

/**
 * Formats bytes into a human-readable string.
 * @param int $bytes The number of bytes.
 * @param int $precision The number of decimal places.
 * @return string The formatted string.
 */
function formatBytes(int $bytes, int $precision = 2): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));
    return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * Sets a flag in the database indicating that the Traefik configuration has been modified
 * and needs to be deployed.
 */
function set_config_dirty() {
    // This function is now deprecated in favor of set_group_dirty($group_id).
    // Kept for backward compatibility, marks the default group as dirty.
    set_group_dirty(getDefaultGroupId());
}

function set_group_dirty(int $group_id) {
    $conn = Database::getInstance()->getConnection();
    $stmt = $conn->prepare("UPDATE `groups` SET has_pending_changes = 1 WHERE id = ?");
    $stmt->bind_param("i", $group_id);
    $stmt->execute();
    $stmt->close();
}

/**
 * Triggers a background deployment process for a specific group.
 * It executes the generate_config.php script as a background process.
 *
 * @param int $group_id The ID of the group to deploy.
 * @return void
 */
function trigger_background_deployment(int $group_id): void {
    // We don't need the dirty flag anymore with auto-deployment, but we keep the function
    // in case we want to revert. This function will now directly trigger deployment.

    // Path to the PHP executable and the script to run
    $php_executable = PHP_BINARY; // More reliable than hardcoding '/usr/bin/php'
    $script_path = PROJECT_ROOT . '/generate_config.php';

    // --- NEW: Redirect output to a dedicated log file instead of /dev/null ---
    $log_path = get_setting('cron_log_path', '/var/log'); // Reuse cron log path setting
    if (!is_dir($log_path)) {
        // Attempt to create the directory if it doesn't exist
        @mkdir($log_path, 0755, true);
    }
    $log_file = rtrim($log_path, '/') . '/auto_deploy.log';

    // Build the command to run in the background
    // We pass the group_id as a command-line argument
    // Redirect both stdout and stderr to the log file. Use '>>' to append.
    // We pipe the output through awk to prepend a timestamp to each line.
    // The `&` at the end runs the process in the background
    $command = '{ ' . $php_executable . ' ' . escapeshellarg($script_path) . ' ' . escapeshellarg('group_id=' . $group_id) . '; } 2>&1';
    $command .= " | awk '{ print \"[\" strftime(\"%Y-%m-%d %H:%M:%S\") \"] \" \$0; fflush(); }'";
    $command .= ' >> ' . escapeshellarg($log_file) . ' &';

    // Execute the command
    shell_exec($command);
    log_activity($_SESSION['username'] ?? 'system', 'Auto-Deploy Triggered', "Deployment automatically triggered for group ID #{$group_id}.");
}