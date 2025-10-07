#!/usr/bin/php
<?php
// This script is intended to be run from the command line via a cron job.
// Example cron job (runs every 5 minutes):
// */5 * * * * /path/to/your/project/collect_stats.php > /dev/null 2>&1

// Set a long execution time
set_time_limit(300); // 5 minutes

// Define PROJECT_ROOT if it's not already defined (when running from CLI)
if (!defined('PROJECT_ROOT')) {
    define('PROJECT_ROOT', __DIR__);
}

require_once PROJECT_ROOT . '/includes/bootstrap.php';
require_once PROJECT_ROOT . '/includes/DockerClient.php';

/**
 * Cleans up old log files based on settings.
 * Runs at most once every 24 hours.
 */
function performLogCleanup() {
    $cleanup_days = (int)get_setting('log_cleanup_days', 7);
    if ($cleanup_days <= 0) {
        return; // Feature disabled
    }

    $last_cleanup_file = sys_get_temp_dir() . '/config_manager_log_cleanup.timestamp';
    $last_run = file_exists($last_cleanup_file) ? (int)file_get_contents($last_cleanup_file) : 0;

    // Check if it has been more than 24 hours since the last run
    if (time() - $last_run > 86400) {
        echo "Running daily log cleanup...\n";
        $log_path = get_setting('cron_log_path', '/var/log');
        $log_files = glob(rtrim($log_path, '/') . '/*.log');
        $cutoff_time = time() - ($cleanup_days * 86400);

        foreach ($log_files as $file) {
            if (is_file($file) && filemtime($file) < $cutoff_time) {
                if (@unlink($file)) {
                    echo "  -> Deleted old log file: " . basename($file) . "\n";
                } else {
                    echo "  -> FAILED to delete old log file: " . basename($file) . ". Check permissions.\n";
                }
            }
        }
        file_put_contents($last_cleanup_file, time());
    }
}

/**
 * Cleans up old host stats history from the database.
 * Runs at most once every 24 hours.
 */
function performDatabaseCleanup() {
    // Reuse the same threshold as log file cleanup for simplicity.
    $cleanup_days = (int)get_setting('log_cleanup_days', 7);
    if ($cleanup_days <= 0) {
        return; // Feature disabled
    }

    $last_cleanup_file = sys_get_temp_dir() . '/config_manager_db_cleanup.timestamp';
    $last_run = file_exists($last_cleanup_file) ? (int)file_get_contents($last_cleanup_file) : 0;

    // Check if it has been more than 24 hours since the last run
    if (time() - $last_run > 86400) {
        echo "Running daily database cleanup for host_stats_history...\n";
        $conn = Database::getInstance()->getConnection();
        $stmt = $conn->prepare("DELETE FROM host_stats_history WHERE created_at < NOW() - INTERVAL ? DAY");
        $stmt->bind_param("i", $cleanup_days);
        $stmt->execute();
        echo "  -> Deleted {$stmt->affected_rows} old records from host_stats_history.\n";
        $stmt->close();
        file_put_contents($last_cleanup_file, time());
    }
}

performLogCleanup();
performDatabaseCleanup();
echo "Starting host stats collection at " . date('Y-m-d H:i:s') . "\n";

$conn = Database::getInstance()->getConnection();

try {
    // Get all active hosts
    $hosts_result = $conn->query("SELECT * FROM docker_hosts");
    if ($hosts_result->num_rows === 0) {
        echo "No hosts configured. Exiting.\n";
        exit;
    }

    $stmt_insert = $conn->prepare("INSERT INTO host_stats_history (host_id, container_cpu_usage_percent, host_cpu_usage_percent, memory_usage_bytes, memory_limit_bytes) VALUES (?, ?, ?, ?, ?)");

    while ($host = $hosts_result->fetch_assoc()) {
        echo "Processing host: {$host['name']}...\n";
        try {
            $dockerClient = new DockerClient($host);

            // Get host-wide info first to get total memory and CPU count
            $dockerInfo = $dockerClient->getInfo();
            $host_total_memory = $dockerInfo['MemTotal'] ?? 0;
            $host_total_cpus = $dockerInfo['NCPU'] ?? 1; // Fallback to 1 to avoid division by zero

            // --- New: Get overall host CPU usage ---
            $helper_container_name = 'host-cpu-reader';
            $host_cpu_usage = null;
            try {
                // Check if the helper container exists and is running.
                try {
                    $helper_container_details = $dockerClient->inspectContainer($helper_container_name);
                    if ($helper_container_details['State']['Running'] !== true) {
                        $dockerClient->startContainer($helper_container_name);
                        echo "  -> INFO: Started existing helper container '{$helper_container_name}'.\n";
                    }
                } catch (Exception $e) {
                    // If container not found (404), create it.
                    if (strpos($e->getMessage(), '404') !== false) {
                        echo "  -> INFO: Helper container '{$helper_container_name}' not found. Creating it now...\n";
                        $dockerClient->createAndStartHelperContainer($helper_container_name);
                    } else {
                        throw $e; // Re-throw other errors
                    }
                } 

                // Now, execute the command inside the helper container.
                // This awk command is more robust. It looks for a line starting with '%Cpu' or 'CPU:',
                // then finds the column containing 'id' and prints 100 minus the preceding value (the idle percentage).
                // The `|| echo "-1"` provides a fallback if awk produces no output.
                $cpu_command = "top -bn1 | grep -E '^(%Cpu|CPU:)' | awk '{for(i=1;i<=NF;i++) if (\$i ~ /id/) {print 100-\$(i-1); exit}}' || echo \"-1\"";
                $cpu_output = $dockerClient->exec($helper_container_name, $cpu_command);
                $host_cpu_usage = (float)$cpu_output;
                // If the command failed or returned an invalid value, default to 0.0
                if ($host_cpu_usage < 0) {
                    $host_cpu_usage = 0.0;
                }
            } catch (Exception $e) {
                echo "  -> WARN: Could not determine overall host CPU usage. A 'host-cpu-reader' container might be needed. Error: " . $e->getMessage() . "\n";
            }

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
                
                // Get system CPU delta only once from the first running container that provides valid stats
                if (!$first_stats_collected && isset($stats['cpu_stats']['system_cpu_usage'], $stats['precpu_stats']['system_cpu_usage'])) {
                    $system_cpu_delta = ($stats['cpu_stats']['system_cpu_usage'] ?? 0) - ($stats['precpu_stats']['system_cpu_usage'] ?? 0);
                    $first_stats_collected = true;
                }
            }

            $container_cpu_usage_percent = 0.0;
            if ($system_cpu_delta > 0 && $total_container_cpu_delta >= 0) {
                $container_cpu_usage_percent = ($total_container_cpu_delta / $system_cpu_delta) * $host_total_cpus * 100.0;
            }

            // Only save stats if there are running containers and we could determine host memory
            if ($running_container_count > 0 && $host_total_memory > 0) {
                $stmt_insert->bind_param("idddd", $host['id'], $container_cpu_usage_percent, $host_cpu_usage, $total_mem_usage_bytes, $host_total_memory);
                $stmt_insert->execute();
                // NEW: Update the last_cpu_report_at timestamp for this host
                $stmt_update_timestamp = $conn->prepare("UPDATE docker_hosts SET last_cpu_report_at = NOW() WHERE id = ?");
                $stmt_update_timestamp->bind_param("i", $host['id']);
                $stmt_update_timestamp->execute();
                $stmt_update_timestamp->close();
                echo "  -> Stats saved for host {$host['name']}. Container CPU: {$container_cpu_usage_percent}%, Host CPU: {$host_cpu_usage}%, Mem: {$total_mem_usage_bytes}\n";
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