<?php
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
    exit;
}

class CronManager {
    private array $scripts; // Removed $user property

    public function __construct() {
        $this->checkPrerequisites();
        $this->scripts = [
            'collect_stats' => PROJECT_ROOT . '/collect_stats.php',
            'autoscaler' => PROJECT_ROOT . '/autoscaler.php',
        ];
    }

    private function checkPrerequisites(): void {
        if (!function_exists('exec') || !function_exists('shell_exec')) {
            throw new Exception("PHP functions 'exec' and 'shell_exec' are disabled on this server. This feature cannot work without them.");
        }
        $disabled = array_map('trim', explode(',', ini_get('disable_functions')));
        if (in_array('exec', $disabled, true) || in_array('shell_exec', $disabled, true)) {
            throw new Exception("PHP functions 'exec' and/or 'shell_exec' are disabled in php.ini. Please remove them from the 'disable_functions' directive to use this feature.");
        }
        // Check for open_basedir restrictions
        $open_basedir = ini_get('open_basedir');
        if ($open_basedir) {
            // This is a simple check; it might not cover all cases but is a good indicator.
            if (!is_executable('/usr/bin/crontab')) {
                 throw new Exception("PHP's 'open_basedir' restriction is active and is preventing access to '/usr/bin/crontab'. Please check your php.ini configuration.");
            }
        }
    }

    public function getJobs(): array {
        // IMPORTANT: No 'sudo -u www-data' here. The web server user (e.g., www-data)
        // should be able to read its own crontab directly.
        $output = shell_exec("/usr/bin/crontab -l 2>/dev/null");
        $lines = $output ? explode("\n", $output) : [];
        $jobs = [];

        foreach ($this->scripts as $key => $path) {
            $jobs[$key] = ['enabled' => false, 'schedule' => ''];
            foreach ($lines as $line) {
                if (strpos($line, $path) !== false) {
                    $parts = preg_split('/\s+/', $line, 6);
                    $jobs[$key]['enabled'] = (strpos(ltrim($line), '#') !== 0);
                    $jobs[$key]['schedule'] = implode(' ', array_slice($parts, 0, 5));
                    break;
                }
            }
        }
        return $jobs;
    }

    public function saveJobs(array $postData): void {
        // IMPORTANT: No 'sudo -u www-data' here. The web server user (e.g., www-data)
        // should be able to read and write its own crontab directly.
        $output = shell_exec("/usr/bin/crontab -l 2>/dev/null");
        $lines = $output ? explode("\n", $output) : [];

        // Filter out existing lines for our managed scripts
        $new_lines = array_filter($lines, function($line) {
            foreach ($this->scripts as $path) {
                if (strpos($line, $path) !== false) {
                    return false;
                }
            }
            return true;
        });

        $php_path = PHP_BINARY;
        $log_path = get_setting('cron_log_path', '/var/log');

        // Add new lines based on form data
        foreach ($this->scripts as $key => $path) {
            if (isset($postData[$key])) {
                $is_enabled = isset($postData[$key]['enabled']) && $postData[$key]['enabled'] == '1';
                $schedule = trim($postData[$key]['schedule'] ?? '');

                if (!empty($schedule)) {
                    $log_file = rtrim($log_path, '/') . "/{$key}.log";
                    $line = "{$schedule} " . escapeshellarg($path) . " >> " . escapeshellarg($log_file) . " 2>&1";
                    if (!$is_enabled) {
                        $line = '#' . $line;
                    }
                    $new_lines[] = $line;
                }
            }
        }

        // Write the new crontab to a temporary file
        $temp_file = tempnam(sys_get_temp_dir(), 'cron');
        if ($temp_file === false) {
            throw new Exception("Failed to create temporary file for crontab.");
        }
        if (file_put_contents($temp_file, implode("\n", $new_lines) . "\n") === false) {
            throw new Exception("Failed to write content to temporary crontab file.");
        }

        // Load the new crontab
        // The '2>&1' redirects stderr to stdout, so exec() can capture all output.
        $command = "/usr/bin/crontab " . escapeshellarg($temp_file) . " 2>&1";
        $last_line = exec($command, $cmd_output, $return_var);

        if ($last_line === false && $return_var !== 0) {
            throw new Exception("Failed to execute the 'crontab' command. This can be caused by server security policies (like SELinux/AppArmor) or file permissions preventing the web server user from running the command.");
        }

        // Clean up the temporary file
        unlink($temp_file);

        if ($return_var !== 0) {
            // If crontab command returns non-zero, it means an error occurred.
            // The $cmd_output array should contain the error message.
            $error_details = implode("\n", $cmd_output);
            if (empty($error_details)) {
                $error_details = "Crontab command failed without providing a specific error message (Exit Code: {$return_var}). This is often due to server configuration issues. Please check the 'System Health Check' page for potential problems like invalid user shells or file permissions.";
            }
            throw new Exception("Failed to save crontab. Error: " . $error_details);
        }
    }
}

try {
    $manager = new CronManager();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $jobs = $manager->getJobs();
        echo json_encode(['status' => 'success', 'jobs' => $jobs]);
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $manager->saveJobs($_POST);
        echo json_encode(['status' => 'success', 'message' => 'Crontab updated successfully.']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>