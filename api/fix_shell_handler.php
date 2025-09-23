<?php
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
    exit;
}

try {
    $web_user = exec('whoami');
    if (empty($web_user) || !preg_match('/^[a-zA-Z0-9_-]+$/', $web_user)) {
        throw new Exception("Could not determine a valid web server user.");
    }

    // The command must exactly match the one in the sudoers file.
    // We use -n (non-interactive) to make sudo fail immediately if a password is required.
    // We don't use escapeshellarg on $web_user because it adds quotes that break the sudoers match.
    // The user is validated with a regex, so it's safe.
    $command = "/usr/bin/sudo -n /usr/sbin/usermod -s /bin/sh " . $web_user . " 2>&1";
    exec($command, $output, $return_var);

    if ($return_var !== 0) {
        $error_string = implode("\n", $output);
        if (stripos($error_string, 'a password is required') !== false || stripos($error_string, 'terminal is required') !== false) {
            $error_string .= "\n\nHint: This error often occurs when 'Defaults requiretty' is enabled in your server's /etc/sudoers file. Please ensure your sudoers configuration for the '{$web_user}' user includes '!requiretty'. Example:\nDefaults:{$web_user} !requiretty\n{$web_user} ALL=(root) NOPASSWD: /usr/sbin/usermod -s /bin/sh {$web_user}";
        }
        throw new Exception("Failed to execute command. Output: " . $error_string);
    }

    echo json_encode(['status' => 'success', 'message' => "Successfully changed shell for user '{$web_user}' to /bin/sh. Please re-run checks."]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>