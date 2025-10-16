#!/usr/bin/php
<?php
// This script is intended to be run from the command line via a cron job.
// Example cron job (runs every 5 minutes):
// */5 * * * * /path/to/your/project/collect_stats.php > /dev/null 2>&1

// Define PROJECT_ROOT if it's not already defined (when running from CLI)
if (!defined('PROJECT_ROOT')) {
    define('PROJECT_ROOT', __DIR__);
}

require_once PROJECT_ROOT . '/includes/bootstrap.php';
require_once PROJECT_ROOT . '/includes/DockerClient.php';
echo "Starting host stats collection at " . date('Y-m-d H:i:s') . "\n";

$conn = Database::getInstance()->getConnection();

try {
    // Get all active hosts
    $hosts_result = $conn->query("SELECT * FROM docker_hosts");
    if ($hosts_result->num_rows === 0) {
        echo "No hosts configured. Exiting.\n";
        exit;
    }

    while ($host = $hosts_result->fetch_assoc()) {
        echo "Processing host: {$host['name']}...\n";
        $agent_container_name = 'cm-health-agent';
        try {
            $dockerClient = new DockerClient($host);
            // Trigger the agent script inside the agent container.
            // The agent itself will collect and send all necessary stats.
            $dockerClient->execInContainer($agent_container_name, ['php', '/usr/src/app/agent.php']);
            echo "  -> Triggered stats collection via health-agent for host {$host['name']}.\n";
        } catch (Exception $e) {
            echo "  -> ERROR processing host {$host['name']}: " . $e->getMessage() . "\n";
        }
    }
} catch (Exception $e) {
    echo "A critical error occurred: " . $e->getMessage() . "\n";
}

$conn->close();
echo "Host stats collection finished at " . date('Y-m-d H:i:s') . "\n";
?>