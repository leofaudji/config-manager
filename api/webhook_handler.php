<?php
// This script does not check for login, as it's called by an external service.
// Security is handled by a secret token.
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/DeploymentRunner.php';

// Start a session to be able to set a username for logging.
// Check if a session is not already active before starting one.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
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

    $conn = Database::getInstance()->getConnection();
    // Find all stacks that use this Git repository as their source
    $stmt = $conn->prepare("SELECT s.id, s.stack_name, s.host_id, s.deployment_details, s.last_webhook_triggered_at, s.webhook_update_policy, h.name as host_name FROM application_stacks s JOIN docker_hosts h ON s.host_id = h.id WHERE s.source_type = 'git' AND JSON_UNQUOTE(JSON_EXTRACT(s.deployment_details, '$.git_url')) = ?");
    $stmt->bind_param("s", $repo_url_from_payload);
    $stmt->execute();
    $stacks_to_update = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($stacks_to_update)) {
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'success', 'message' => "Webhook received for '{$repo_url_from_payload}', but no stacks are configured to use this repository. Nothing to do."]);
        log_activity('webhook_bot', 'Webhook Ignored', "Webhook received for '{$repo_url_from_payload}', but no stacks are configured to use this repository.");
        exit;
    }

    $cooldown_period = (int)get_setting('webhook_cooldown_period', 300); // Default 5 menit
    $updated_stacks = [];
    $ignored_stacks = [];
    $scheduled_stacks = [];

    foreach ($stacks_to_update as $stack) {
        // --- NEW: Cooldown Logic ---
        if ($stack['last_webhook_triggered_at']) {
            $last_triggered_time = strtotime($stack['last_webhook_triggered_at']);
            $time_since_last = time() - $last_triggered_time;
            if ($time_since_last < $cooldown_period) {
                $wait_time = $cooldown_period - $time_since_last;
                $ignored_stacks[] = "{$stack['stack_name']} (cooldown, tunggu {$wait_time} detik lagi)";
                log_activity('webhook_bot', 'Webhook Ignored (Cooldown)', "Redeployment untuk stack '{$stack['stack_name']}' diabaikan karena masih dalam periode cooldown.");
                continue; // Lewati stack ini
            }
        }

        // --- IDE: Check update policy ---
        if ($stack['webhook_update_policy'] === 'scheduled') {
            // Mark for pending update instead of deploying
            $update_stmt = $conn->prepare("UPDATE application_stacks SET webhook_pending_update = 1, webhook_pending_since = NOW() WHERE id = ?");
            $update_stmt->bind_param("i", $stack['id']);
            $update_stmt->execute();
            $update_stmt->close();
            $scheduled_stacks[] = "{$stack['stack_name']} (on {$stack['host_name']})";
            log_activity('webhook_bot', 'Webhook Update Scheduled', "Update for stack '{$stack['stack_name']}' on host '{$stack['host_name']}' has been scheduled due to a push to '{$target_branch}'.");
        } else { // 'realtime' policy
            // --- Trigger the actual deployment ---
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

            // FIX: Directly call the deployment logic instead of making a fragile internal HTTP call.
            DeploymentRunner::runInBackground($post_data);
            log_activity('webhook_bot', 'Webhook Redeploy Triggered', "Redeployment triggered for stack '{$stack['stack_name']}' on host '{$stack['host_name']}' due to a push to '{$target_branch}'.");
            $updated_stacks[] = "{$stack['stack_name']} (on {$stack['host_name']})";
        }
    }

    // --- NEW: Send a notification ---
    if (!empty($updated_stacks)) {
        $notification_title = "Webhook Deployment Triggered";
        $notification_message = "Push to '{$target_branch}' triggered redeployment for: " . implode(', ', $updated_stacks);
        send_notification($notification_title, $notification_message, 'info');
    }
    if (!empty($scheduled_stacks)) {
        $notification_title = "Webhook Update Scheduled";
        $notification_message = "Push to '{$target_branch}' scheduled updates for: " . implode(', ', $scheduled_stacks);
        send_notification($notification_title, $notification_message, 'info');
    }

    $message = 'Webhook processed.';
    if (!empty($updated_stacks)) {
        $message .= ' Redeployment triggered for: ' . implode(', ', $updated_stacks) . '.';
    }
    if (!empty($scheduled_stacks)) {
        $message .= ' Updates scheduled for: ' . implode(', ', $scheduled_stacks) . '.';
    }
    if (!empty($ignored_stacks)) {
        $message .= ' Ignored stacks (cooldown): ' . implode(', ', $ignored_stacks) . '.';
    }
    
    // Respond with a success message
    header('Content-Type: application/json');
    echo json_encode(['status' => 'success', 'message' => $message]);
    // Close the connection at the very end of the successful execution path.
    if (isset($conn)) {
        $conn->close();
    }

} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Webhook processing failed: ' . $e->getMessage()]);
    log_activity('webhook_bot', 'Webhook Deployment Failed', 'Error during webhook-triggered deployment: ' . $e->getMessage());
}
?>