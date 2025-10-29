#!/usr/bin/php
<?php
// File: /var/www/html/config-manager/health_monitor.php
// Jalankan via cron job, misalnya setiap 1 menit:
// * * * * * /path/to/your/project/health_monitor.php >> /var/log/health_monitor.log 2>&1

set_time_limit(55); // Run for slightly less than a minute

if (!defined('PROJECT_ROOT')) {
    define('PROJECT_ROOT', dirname(__DIR__));
}

require_once PROJECT_ROOT . '/includes/bootstrap.php';
require_once PROJECT_ROOT . '/includes/DockerClient.php';

echo "Memulai Health Monitor pada " . date('Y-m-d H:i:s') . "\n";

$conn = Database::getInstance()->getConnection();

try {
    // 1. Get all services with HTTP health checks enabled.
    // Container-level checks are now handled by the on-host agent.
    $sql_services = "
        SELECT 
            s.id, s.name, s.health_check_enabled, s.health_check_type, s.target_stack_id,
            s.health_check_endpoint, s.health_check_interval, s.health_check_timeout,
            s.unhealthy_threshold, s.healthy_threshold,
            shs.status, shs.consecutive_failures, shs.consecutive_successes, shs.last_checked_at
        FROM services s
        LEFT JOIN service_health_status shs ON s.id = shs.service_id
        WHERE s.health_check_type = 'http'
    ";

    $stmt_services = $conn->prepare($sql_services);
    $stmt_services->execute();
    $services_result = $stmt_services->get_result();
    $services_to_check = $services_result->fetch_all(MYSQLI_ASSOC);

    if (empty($services_to_check)) {
        echo "Tidak ada service dengan health check aktif. Selesai.\n";
        exit;
    }

    // 2. Loop through each service and check its health
    foreach ($services_to_check as $service) {
        echo "Mengevaluasi service: '{$service['name']}'\n";

        // 3. Check if it's time to perform a check
        $last_checked_timestamp = $service['last_checked_at'] ? strtotime($service['last_checked_at']) : 0;
        if (time() - $last_checked_timestamp < $service['health_check_interval']) {
            echo "  -> Belum waktunya untuk check. Melewati.\n";
            continue;
        }

        $is_healthy = false;
        $log_message = '';

        // --- HTTP Check Logic ---
        echo "  -> Tipe Check: HTTP\n";
        // 4. Get the service's URL from its router
        $stmt_router = $conn->prepare("SELECT rule FROM routers WHERE service_name = ? LIMIT 1");
        $stmt_router->bind_param("s", $service['name']);
        $stmt_router->execute();
        $router_result = $stmt_router->get_result()->fetch_assoc();
        $stmt_router->close();

        if (!$router_result || !preg_match('/Host\(`([^`]+)`\)/', $router_result['rule'], $matches)) {
            echo "  -> Tidak dapat menemukan Host rule untuk service '{$service['name']}'. Menghapus status kesehatan usang.\n";
            
            // If the router rule is gone, the service is effectively gone from a routing perspective.
            // Clean up its health status entry.
            $stmt_delete_status = $conn->prepare("DELETE FROM service_health_status WHERE service_id = ?");
            $stmt_delete_status->bind_param("i", $service['id']);
            $stmt_delete_status->execute();
            $stmt_delete_status->close();
            log_activity('SYSTEM', 'Health Status Cleanup', "Menghapus status kesehatan untuk service '{$service['name']}' karena router rule tidak ditemukan.");

            continue;
        }
        $hostname = $matches[1];
        $endpoint = ltrim($service['health_check_endpoint'], '/');
        $url_to_check = "http://{$hostname}/{$endpoint}"; // Assuming http for internal checks

        echo "  -> Mengecek URL: {$url_to_check}\n";

        // 5. Perform the health check using cURL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url_to_check);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $service['health_check_timeout']);
        curl_setopt($ch, CURLOPT_NOBODY, true); // We only need the status code
        curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        $is_healthy = ($http_code >= 200 && $http_code < 300);
        $log_message = $is_healthy ? "OK (HTTP {$http_code})" : "FAIL (HTTP {$http_code}, Error: {$curl_error})";
        echo "  -> Hasil: {$log_message}\n";

        // 6. Update status in the database
        $new_status = $service['status'] ?? 'unknown';
        $failures = $service['consecutive_failures'] ?? 0;
        $successes = $service['consecutive_successes'] ?? 0;

        if ($is_healthy) {
            $successes++;
            $failures = 0;
            if ($successes >= $service['healthy_threshold']) {
                $new_status = 'healthy';
            }
        } else {
            $failures++;
            $successes = 0;
            if ($failures >= $service['unhealthy_threshold']) {
                $new_status = 'unhealthy';
            }
        }

        $stmt_update = $conn->prepare(
            "INSERT INTO service_health_status (service_id, status, consecutive_failures, consecutive_successes, last_checked_at, last_log)
             VALUES (?, ?, ?, ?, NOW(), ?)
             ON DUPLICATE KEY UPDATE 
                status = VALUES(status), 
                consecutive_failures = VALUES(consecutive_failures), 
                consecutive_successes = VALUES(consecutive_successes), 
                last_checked_at = VALUES(last_checked_at),
                last_log = VALUES(last_log)"
        );
        $stmt_update->bind_param("isiss", $service['id'], $new_status, $failures, $successes, $log_message);
        $stmt_update->execute();
        $stmt_update->close();

        // 7. Send notification if status changed to 'unhealthy'
        if ($new_status === 'unhealthy' && $service['status'] !== 'unhealthy') {
            echo "  -> STATUS TIDAK SEHAT TERDETEKSI! Mengirim notifikasi untuk service '{$service['name']}'.\n";
            log_activity('SYSTEM', 'Service Unhealthy', "Service '{$service['name']}' marked as unhealthy from HTTP check.", null);
        }
    }

} catch (Exception $e) {
    echo "Terjadi error kritis pada Health Monitor: " . $e->getMessage() . "\n";
    log_activity('SYSTEM', 'Health Monitor Error', "Error kritis: " . $e->getMessage());
}

$conn->close();
echo "Health Monitor selesai pada " . date('Y-m-d H:i:s') . "\n";
?>