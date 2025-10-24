#!/usr/bin/php
<?php
// File: /var/www/html/config-manager/scheduled_deployment_runner.php
// This script should be run by a cron job every minute.
// * * * * * /usr/bin/php /var/www/html/config-manager/scheduled_deployment_runner.php >> /var/log/scheduled_deployment.log 2>&1

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/DeploymentRunner.php';

function colorize_log($message, $color = "default") {
    $colors = [
        "success" => "32", "error"   => "31", "warning" => "33",
        "info"    => "36", "default" => "0"
    ];
    $color_code = $colors[$color] ?? $colors['default'];
    if (function_exists('posix_isatty') && posix_isatty(STDOUT)) {
        return "\033[{$color_code}m{$message}\033[0m";
    }
    return $message;
}

echo "Starting scheduled deployment check at " . date('Y-m-d H:i:s') . "\n";

$conn = Database::getInstance()->getConnection();

try {
    // Find stacks with pending updates and a scheduled deployment time for today
    // The logic is: find stacks where the scheduled time has passed since the last deployment.
    // We construct a full datetime for today's scheduled time and compare it.
    $sql = "
        SELECT s.id, s.stack_name, s.host_id, s.deployment_details, h.name as host_name
        FROM application_stacks s
        JOIN docker_hosts h ON s.host_id = h.id
        WHERE s.webhook_update_policy = 'scheduled'
          AND s.webhook_pending_update = 1
          AND s.webhook_schedule_time IS NOT NULL
          AND CURTIME() >= s.webhook_schedule_time
          AND (s.last_scheduled_deployment_at IS NULL OR 
               DATE(s.last_scheduled_deployment_at) < CURDATE())
    ";
    
    $result = $conn->query($sql);
    $stacks_to_deploy = $result->fetch_all(MYSQLI_ASSOC);

    if (empty($stacks_to_deploy)) {
        echo "No stacks are due for scheduled deployment right now.\n";
        exit;
    }

    foreach ($stacks_to_deploy as $stack) {
        echo colorize_log("Found pending stack: '{$stack['stack_name']}' on host '{$stack['host_name']}'. Triggering deployment...\n", "info");

        $deployment_details = json_decode($stack['deployment_details'], true);
        if (!$deployment_details) {
            log_activity('SYSTEM', 'Scheduled Deploy Failed', "Could not decode deployment details for stack '{$stack['stack_name']}'.", $stack['host_id']);
            continue;
        }

        $post_data = $deployment_details;
        $post_data['host_id'] = $stack['host_id'];
        $post_data['stack_name'] = $stack['stack_name'];
        $post_data['update_stack'] = 'true';

        // Run deployment in the background
        DeploymentRunner::runInBackground($post_data);

        // Update the stack to mark this deployment as done for today
        $stmt_update = $conn->prepare("UPDATE application_stacks SET webhook_pending_update = 0, last_scheduled_deployment_at = NOW() WHERE id = ?");
        $stmt_update->bind_param("i", $stack['id']);
        $stmt_update->execute();
        $stmt_update->close();

        log_activity('SYSTEM', 'Scheduled Deploy Triggered', "Automatic scheduled deployment triggered for stack '{$stack['stack_name']}' on host '{$stack['host_name']}'.", $stack['host_id']);
        send_notification("Scheduled Deployment Started", "Automatic deployment for '{$stack['stack_name']}' has started.", 'info');
    }
} catch (Exception $e) {
    echo colorize_log("A critical error occurred: " . $e->getMessage() . "\n", "error");
    log_activity('SYSTEM', 'Scheduled Deploy Error', 'Critical error in scheduled_deployment_runner.php: ' . $e->getMessage());
}

$conn->close();
echo "Scheduled deployment check finished at " . date('Y-m-d H:i:s') . "\n";