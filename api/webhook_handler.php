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
    $_SESSION['username'] = 'webhook_bot'; // Set a username for logging purposes
    
    // Capture the output of the generate_config.php script
    ob_start();
    require_once PROJECT_ROOT . '/generate_config.php';
    $output = ob_get_clean();
    
    header('Content-Type: application/json');
    echo $output;

} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Webhook processing failed: ' . $e->getMessage()]);
    log_activity('webhook_bot', 'Webhook Deployment Failed', 'Error during webhook-triggered deployment: ' . $e->getMessage());
}
?>