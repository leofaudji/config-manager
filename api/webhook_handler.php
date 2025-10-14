<?php
// This script does not check for login, as it's called by an external service.
// Security is handled by a secret token.
require_once __DIR__ . '/../includes/bootstrap.php';

// Start a session to be able to set a username for logging.
session_start();

// --- Security Check ---
$provided_token = $_GET['token'] ?? '';
$stored_token = get_setting('webhook_secret_token');

if (empty($provided_token) || empty($stored_token) || !hash_equals($stored_token, $provided_token)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Forbidden: Invalid or missing token.']);
    log_activity('webhook_caller', 'Webhook Failed', 'A webhook call was rejected due to an invalid token. IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'));
    exit;
}

// --- Payload Validation ---
$payload = file_get_contents('php://input');
$data = json_decode($payload, true);

// Check for a valid payload and a push event
if (json_last_error() !== JSON_ERROR_NONE || !isset($data['ref'])) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Bad Request: Invalid or missing payload.']);
    exit;
}

// --- Logic to Trigger Deployment ---
try {
    $target_branch = get_setting('git_branch', 'main');
    $pushed_branch_ref = $data['ref']; // e.g., "refs/heads/main"

    // Check if the push was to the configured target branch
    if ($pushed_branch_ref !== 'refs/heads/' . $target_branch) {
        // It's a push to a different branch, so we can ignore it.
        // Respond with a success message to let the Git provider know we received it.
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'success', 'message' => "Webhook received for branch '{$pushed_branch_ref}', but deployment is only configured for '{$target_branch}'. Ignoring."]);
        exit;
    }

    // If we're here, it's a push to the correct branch. Trigger the deployment.
    // --- NEW: Find and redeploy all stacks linked to this repository ---
    $repo_url_from_payload = $data['repository']['clone_url'] ?? null;
    if (!$repo_url_from_payload) {
        throw new Exception("Could not determine repository URL from webhook payload.");
    }

    // --- NEW: Prepare for internal cURL call ---
    $app_base_url = get_setting('app_base_url');
    // Use loopback address for internal calls for performance and security
    $internal_app_url = str_replace(['localhost', '127.0.0.1'], 'traefik-manager', $app_base_url);
    $deployment_endpoint = rtrim($internal_app_url, '/') . '/api/app-launcher/deploy';

    $conn = Database::getInstance()->getConnection();
    // Find all stacks that use this Git repository as their source
    $stmt = $conn->prepare("SELECT s.id, s.stack_name, s.host_id, s.deployment_details, h.name as host_name FROM application_stacks s JOIN docker_hosts h ON s.host_id = h.id WHERE s.source_type = 'git' AND JSON_UNQUOTE(JSON_EXTRACT(s.deployment_details, '$.git_url')) = ?");
    $stmt->bind_param("s", $repo_url_from_payload);
    $stmt->execute();
    $stacks_to_update = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $conn->close();

    if (empty($stacks_to_update)) {
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'success', 'message' => "Webhook received for '{$repo_url_from_payload}', but no stacks are configured to use this repository. Nothing to do."]);
        log_activity('webhook_bot', 'Webhook Ignored', "Webhook received for '{$repo_url_from_payload}', but no stacks are configured to use this repository.");
        exit;
    }

    $updated_stacks = [];
    foreach ($stacks_to_update as $stack) {
        // --- NEW: Trigger the actual deployment ---
        // We will simulate a form submission to the app_launcher_handler.
        $deployment_details = json_decode($stack['deployment_details'], true);
        if (!$deployment_details) {
            log_activity('webhook_bot', 'Webhook Redeploy Failed', "Could not decode deployment details for stack '{$stack['stack_name']}'. Skipping.");
            continue;
        }

        // Prepare the POST data for the app launcher
        $post_data = $deployment_details;
        $post_data['host_id'] = $stack['host_id'];
        $post_data['stack_name'] = $stack['stack_name'];
        $post_data['update_stack'] = 'true'; // This is crucial to tell the launcher it's an update

        // Use cURL to make an internal, non-blocking POST request to the deployment handler
        $ch = curl_init($deployment_endpoint);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5); // Don't wait for the full deployment, just trigger it.
        curl_exec($ch);
        curl_close($ch);

        log_activity('webhook_bot', 'Webhook Redeploy Triggered', "Redeployment triggered for stack '{$stack['stack_name']}' on host '{$stack['host_name']}' due to a push to '{$target_branch}'.");
        $updated_stacks[] = "{$stack['stack_name']} (on {$stack['host_name']})";
    }

    // --- NEW: Send a notification ---
    if (!empty($updated_stacks)) {
        $notification_title = "Webhook Deployment Triggered";
        $notification_message = "Push to '{$target_branch}' triggered redeployment for: " . implode(', ', $updated_stacks);
        send_notification($notification_title, $notification_message, 'info');
    }
    
    // Respond with a success message
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'success',
        'message' => 'Webhook processed. Redeployment triggered for the following stacks: ' . implode(', ', $updated_stacks)
    ]);

} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Webhook processing failed: ' . $e->getMessage()]);
    log_activity('webhook_bot', 'Webhook Deployment Failed', 'Error during webhook-triggered deployment: ' . $e->getMessage());
}
?>