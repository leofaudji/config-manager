<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/DeploymentRunner.php';

header('Content-Type: application/json');

/**
 * Streams a message to a dedicated webhook log file.
 * @param string $message The message to log.
 * @param string $level The log level (e.g., INFO, ERROR).
 */
function log_webhook_activity($message, $level = 'INFO') {
    $log_path = get_setting('cron_log_path', '/var/log');
    $log_file = rtrim($log_path, '/') . '/webhook_activity.log';
    $line = date('[Y-m-d H:i:s]') . " [{$level}] " . trim($message) . "\n";
    file_put_contents($log_file, $line, FILE_APPEND);
}

try {
    // --- 1. Security Validation ---
    $provided_token = $_GET['token'] ?? '';
    $expected_token = get_setting('webhook_secret_token');

    if (empty($provided_token) || empty($expected_token) || !hash_equals($expected_token, $provided_token)) {
        http_response_code(401);
        log_webhook_activity('Unauthorized webhook trigger attempt. Invalid token.', 'ERROR');
        log_activity('webhook_bot', 'Webhook Failed', 'Invalid token provided from IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'));
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
        exit;
    }

    // --- 2. Parse Git Provider Payload ---
    $payload = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        log_webhook_activity('Invalid JSON payload received.', 'ERROR');
        log_activity('webhook_bot', 'Webhook Failed', 'Invalid JSON payload from IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'));
        echo json_encode(['status' => 'error', 'message' => 'Invalid JSON payload']);
        exit;
    }

    // --- NEW: Handle GitHub/Gitea 'ping' event for successful webhook setup ---
    $event_type = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? $_SERVER['HTTP_X_GITEA_EVENT'] ?? null;
    if ($event_type === 'ping') {
        http_response_code(200);
        log_webhook_activity("Received successful 'ping' event from Git provider. Webhook is configured correctly.");
        log_activity('webhook_bot', 'Webhook Ping', 'Received successful ping from IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'));
        echo json_encode(['status' => 'success', 'message' => 'Pong! Webhook is configured correctly.']);
        exit;
    }

    //print_r($payload) ;
    // Extract repository URL and branch from payload (supports GitHub and GitLab)
    $repo_url = $payload['repository']['ssh_url'] ?? $payload['repository']['git_ssh_url'] ?? $payload['repository']['clone_url'] ?? $payload['project']['git_ssh_url'] ?? null;
    $ref = $payload['ref'] ?? null; // e.g., "refs/heads/main"

    if (!$repo_url || !$ref) {
        http_response_code(400);
        log_webhook_activity('Payload missing repository URL or ref.', 'ERROR');
        log_activity('webhook_bot', 'Webhook Failed', 'Payload missing repository URL or ref from IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'));
        echo json_encode(['status' => 'error', 'message' => 'Payload missing repository URL or ref.']);
        exit;
    }

    $branch_pushed = str_replace('refs/heads/', '', $ref);
    log_webhook_activity("Webhook received for repo '{$repo_url}' on branch '{$branch_pushed}'.");

    // --- 3. Find Matching Stacks in DB ---
    $conn = Database::getInstance()->getConnection();
    $stmt = $conn->prepare(
        "SELECT id, deployment_details, stack_name, host_id 
         FROM application_stacks 
         WHERE source_type = 'git' 
           AND JSON_UNQUOTE(JSON_EXTRACT(deployment_details, '$.git_url')) = ? 
           AND JSON_UNQUOTE(JSON_EXTRACT(deployment_details, '$.git_branch')) = ?"
    );
    $stmt->bind_param("ss", $repo_url, $branch_pushed);
    $stmt->execute();
    $stacks_to_update = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($stacks_to_update)) {
        log_webhook_activity("No stacks found matching the repository and branch. Nothing to do.");
        echo json_encode(['status' => 'success', 'message' => 'Webhook received, but no matching stacks found for auto-deployment.']);
        exit;
    }

    // --- 4. Trigger Deployments in Background ---
    $triggered_count = 0;
    foreach ($stacks_to_update as $stack) {
        $deployment_details = json_decode($stack['deployment_details'], true);
        if (!$deployment_details) continue;

        // Add necessary info for the deployment helper
        $deployment_details['host_id'] = $stack['host_id'];
        $deployment_details['stack_name'] = $stack['stack_name'];
        $deployment_details['update_stack'] = 'true';

        // This is the new flag that tells the helper to build the image
        $deployment_details['build_from_dockerfile'] = (bool)get_setting('webhook_build_image_enabled', false);

        try {
            // --- NEW: Generate a unique log file path for this specific deployment ---
            $base_log_path = get_setting('default_compose_path');
            $safe_host_name = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $stack['host_name'] ?? 'unknown_host');
            $log_dir = rtrim($base_log_path, '/') . '/' . $safe_host_name . '/' . $stack['stack_name'];
            $log_file_path = $log_dir . '/deployment.log'; // This will be updated with a unique name later
            $deployment_details['log_file_path'] = $log_file_path; // Pass this to the runner

            // Use the runner to execute the deployment in a separate process
            DeploymentRunner::runInBackground($deployment_details);
            $log_message = "Triggered background build & deploy for stack '{$stack['stack_name']}' on host ID {$stack['host_id']}.";
            log_webhook_activity($log_message);
            log_activity('webhook_bot', 'Webhook Triggered', $log_message, $stack['host_id'], $log_file_path);
            $triggered_count++;
        } catch (Exception $e) {
            $error_message = "Failed to trigger deployment for stack '{$stack['stack_name']}': " . $e->getMessage();
            log_webhook_activity($error_message, 'ERROR');
            log_activity('webhook_bot', 'Webhook Failed', $error_message, $stack['host_id']);
        }
    }

    echo json_encode(['status' => 'success', 'message' => "Webhook processed. Triggered deployments for {$triggered_count} stack(s)."]);

} catch (Exception $e) {
    http_response_code(500);
    log_webhook_activity('Critical error in webhook handler: ' . $e->getMessage(), 'ERROR');
    echo json_encode(['status' => 'error', 'message' => 'An internal server error occurred.']);
}

?>