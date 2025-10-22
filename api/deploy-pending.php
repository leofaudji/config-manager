<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/AppLauncherHelper.php';

// --- Streaming Setup ---
header('Content-Type: text/plain; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-cache');
@ini_set('zlib.output_compression', 0);
if (ob_get_level() > 0) {
    for ($i = 0; $i < ob_get_level(); $i++) {
        ob_end_flush();
    }
}
ob_implicit_flush(1);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden.']);
    exit;
}

$stack_id = $_POST['stack_id'] ?? null;

if (empty($stack_id)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Stack ID is required.']);
    exit;
}

try {
    $conn = Database::getInstance()->getConnection();
    $stmt = $conn->prepare("SELECT s.id, s.stack_name, s.host_id, s.deployment_details, h.name as host_name FROM application_stacks s JOIN docker_hosts h ON s.host_id = h.id WHERE s.id = ? AND s.webhook_pending_update = 1");
    $stmt->bind_param("i", $stack_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if (!($stack = $result->fetch_assoc())) {
        throw new Exception("Stack not found or no pending update for this stack.");
    }
    $stmt->close();

    $deployment_details = json_decode($stack['deployment_details'], true);
    if (!$deployment_details) throw new Exception("Could not decode deployment details for stack '{$stack['stack_name']}'.");

    $post_data = $deployment_details;
    $post_data['host_id'] = $stack['host_id'];
    $post_data['stack_name'] = $stack['stack_name'];
    $post_data['update_stack'] = 'true';

    // --- IDE: Execute directly and stream output ---
    // We don't run in background here because the user is actively watching the log.
    AppLauncherHelper::executeDeployment($post_data);

    // --- IDE: Reset the pending update flag on success ---
    $stmt_reset = $conn->prepare("UPDATE application_stacks SET webhook_pending_update = 0, webhook_pending_since = NULL WHERE id = ?");
    $stmt_reset->bind_param("i", $stack_id);
    $stmt_reset->execute();
    $stmt_reset->close();

    stream_message("---");
    stream_message("Deployment finished successfully!", "SUCCESS");
    echo "_DEPLOYMENT_COMPLETE_";

} catch (Exception $e) {
    stream_message($e->getMessage(), 'ERROR');
    echo "_DEPLOYMENT_FAILED_";
}