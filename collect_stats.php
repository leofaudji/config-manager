<?php
// This script is intended to be run from the command line via a cron job.
// Example cron job (runs every 5 minutes):
// */5 * * * * /usr/bin/php /path/to/your/project/scripts/collect_stats.php > /dev/null 2>&1

// Set a long execution time
set_time_limit(300); // 5 minutes

// Define PROJECT_ROOT if it's not already defined (when running from CLI)
if (!defined('PROJECT_ROOT')) {
    define('PROJECT_ROOT', dirname(__DIR__));
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

    $stmt_insert = $conn->prepare("INSERT INTO host_stats_history (host_id, cpu_usage_percent, memory_usage_bytes, memory_limit_bytes) VALUES (?, ?, ?, ?)");

    while ($host = $hosts_result->fetch_assoc()) {
        echo "Processing host: {$host['name']}...\n";
        try {
            $dockerClient = new DockerClient($host);

            // Get host-wide info first to get total memory and CPU count
            $dockerInfo = $dockerClient->getInfo();
            $host_total_memory = $dockerInfo['MemTotal'] ?? 0;
            $host_total_cpus = $dockerInfo['NCPU'] ?? 1; // Fallback to 1 to avoid division by zero

            $containers = $dockerClient->listContainers();

            $total_container_cpu_delta = 0;
            $system_cpu_delta = 0;
            $total_mem_usage_bytes = 0;
            $running_container_count = 0;
            $first_stats_collected = false;

            foreach ($containers as $container) {
                if ($container['State'] !== 'running') continue;
                $running_container_count++;
                $stats = $dockerClient->getContainerStats($container['Id']);

                // Sum memory usage
                $total_mem_usage_bytes += $stats['memory_stats']['usage'] ?? 0;

                // Sum CPU delta
                $total_container_cpu_delta += ($stats['cpu_stats']['cpu_usage']['total_usage'] ?? 0) - ($stats['precpu_stats']['cpu_usage']['total_usage'] ?? 0);
                
                // Get system CPU delta only once from the first running container
                if (!$first_stats_collected) {
                    $system_cpu_delta = ($stats['cpu_stats']['system_cpu_usage'] ?? 0) - ($stats['precpu_stats']['system_cpu_usage'] ?? 0);
                    $first_stats_collected = true;
                }
            }

            $final_cpu_usage_percent = 0.0;
            if ($system_cpu_delta > 0 && $total_container_cpu_delta >= 0) {
                $final_cpu_usage_percent = ($total_container_cpu_delta / $system_cpu_delta) * $host_total_cpus * 100.0;
            }

            // Only save stats if there are running containers and we could determine host memory
            if ($running_container_count > 0 && $host_total_memory > 0) {
                $stmt_insert->bind_param("iddd", $host['id'], $final_cpu_usage_percent, $total_mem_usage_bytes, $host_total_memory);
                $stmt_insert->execute();
                echo "  -> Stats saved for host {$host['name']}. CPU: {$final_cpu_usage_percent}%, Mem: {$total_mem_usage_bytes}\n";
            }
        } catch (Exception $e) {
            echo "  -> ERROR processing host {$host['name']}: " . $e->getMessage() . "\n";
        }
    }
    $stmt_insert->close();
} catch (Exception $e) {
    echo "A critical error occurred: " . $e->getMessage() . "\n";
}

$conn->close();
echo "Host stats collection finished at " . date('Y-m-d H:i:s') . "\n";
?>