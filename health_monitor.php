#!/usr/bin/php
<?php
// File: /var/www/html/config-manager/health_monitor.php
// Jalankan via cron job, misalnya setiap 1 menit:
// * * * * * /path/to/your/project/health_monitor.php >> /var/log/health_monitor.log 2>&1

set_time_limit(55); // Run for slightly less than a minute

if (!defined('PROJECT_ROOT')) {
    define('PROJECT_ROOT', __DIR__);
}

require_once PROJECT_ROOT . '/includes/bootstrap.php';
require_once PROJECT_ROOT . '/includes/DockerClient.php';

echo "Memulai Health Monitor pada " . date('Y-m-d H:i:s') . "\n";

$conn = Database::getInstance()->getConnection();

try {
    // 1. Get all services with health checks enabled
    // If global setting is on, ignore individual 'health_check_enabled' flag.
    $sql_services = "
        SELECT 
            s.id, s.name, s.health_check_enabled, s.health_check_type, s.target_stack_id,
            s.health_check_endpoint, s.health_check_interval, s.health_check_timeout,
            s.unhealthy_threshold, s.healthy_threshold,
            shs.status, shs.consecutive_failures, shs.consecutive_successes, shs.last_checked_at,
            -- Get host details from different sources based on check type
            COALESCE(h_stack.id, h_traefik.id) as host_id,
            COALESCE(h_stack.name, h_traefik.name) as host_name,
            COALESCE(h_stack.docker_api_url, h_traefik.docker_api_url) as docker_api_url,
            COALESCE(h_stack.tls_enabled, h_traefik.tls_enabled) as tls_enabled,
            COALESCE(h_stack.ca_cert_path, h_traefik.ca_cert_path) as ca_cert_path,
            COALESCE(h_stack.client_cert_path, h_traefik.client_cert_path) as client_cert_path,
            COALESCE(h_stack.client_key_path, h_traefik.client_key_path) as client_key_path,
            stack.stack_name
        FROM services s
        LEFT JOIN service_health_status shs ON s.id = shs.service_id
        LEFT JOIN application_stacks stack ON s.target_stack_id = stack.id
        LEFT JOIN docker_hosts h_stack ON stack.host_id = h_stack.id
        LEFT JOIN `groups` g ON s.group_id = g.id
        LEFT JOIN docker_hosts h_traefik ON g.traefik_host_id = h_traefik.id
        WHERE s.health_check_enabled = 1
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

        // --- NEW: Logic branching based on check type ---
        if ($service['health_check_type'] === 'http') {
            // --- HTTP Check (Existing Logic) ---
            echo "  -> Tipe Check: HTTP\n";
            // 4. Get the service's URL from its router
            $stmt_router = $conn->prepare("SELECT rule FROM routers WHERE service_name = ? LIMIT 1");
            $stmt_router->bind_param("s", $service['name']);
            $stmt_router->execute();
            $router_result = $stmt_router->get_result()->fetch_assoc();
            $stmt_router->close();

            if (!$router_result || !preg_match('/Host\(`([^`]+)`\)/', $router_result['rule'], $matches)) {
                echo "  -> Tidak dapat menemukan Host rule untuk service. Melewati.\n";
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

        } elseif ($service['health_check_type'] === 'docker') {
            // --- Docker Health Check (New Logic) ---
            echo "  -> Tipe Check: Docker Internal\n";
            if (empty($service['target_stack_id']) || empty($service['host_id'])) {
                echo "  -> Target stack atau host tidak terkonfigurasi untuk Docker check. Melewati.\n";
                continue;
            }
            try {
                $dockerClient = new DockerClient($service);
                $containers = $dockerClient->listContainers();
                $target_container = null;
                foreach ($containers as $container) {
                    // Find container belonging to the target stack
                    if (($container['Labels']['com.docker.compose.project'] ?? $container['Labels']['com.docker.stack.namespace'] ?? null) === $service['stack_name']) {
                        $target_container = $container;
                        break;
                    }
                }

                if (!$target_container) {
                    throw new Exception("Container untuk stack '{$service['stack_name']}' tidak ditemukan.");
                }

                $container_details = $dockerClient->inspectContainer($target_container['Id']);
                $health_status = $container_details['State']['Health']['Status'] ?? 'unknown';
                
                $is_healthy = ($health_status === 'healthy');
                $log_message = "Container '{$target_container['Names'][0]}' health status: {$health_status}";

            } catch (Exception $e) {
                $is_healthy = false;
                $log_message = "Docker check failed: " . $e->getMessage();
            }
        }
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

        // 7. Trigger auto-healing if status changed to 'unhealthy'
        if ($new_status === 'unhealthy' && $service['status'] !== 'unhealthy') {
            echo "  -> STATUS TIDAK SEHAT TERDETEKSI! Memicu auto-healing untuk service '{$service['name']}'.\n";
            log_activity('SYSTEM', 'Auto-Healing Triggered', "Service '{$service['name']}' marked as unhealthy. Restarting tasks.", $service['host_id']);
            send_notification(
                "Service Unhealthy: " . $service['name'],
                "The service '{$service['name']}' has been marked as unhealthy. Last log: {$log_message}",
                'error',
                ['service_name' => $service['name'], 'host_name' => $service['host_name']]
            );

            try {
                if (empty($service['host_id'])) {
                    throw new Exception("Host tidak terdefinisi untuk service ini, tidak bisa melakukan auto-healing.");
                }

                $dockerClient = new DockerClient($service); // Pass the whole array
                $info = $dockerClient->getInfo();
                $is_swarm = (isset($info['Swarm']['ControlAvailable']) && $info['Swarm']['ControlAvailable']);
                
                if ($is_swarm) {
                    $services = $dockerClient->listServices(['name' => [$service['name']]]);
                    if (empty($services)) throw new Exception("Service '{$service['name']}' tidak ditemukan di Docker Swarm.");
                    
                    $target_service = $services[0];
                    $dockerClient->forceServiceUpdate($target_service['ID'], $target_service['Version']['Index']);
                    $action_message = "Restart command sent for Swarm service '{$service['name']}'.";
                } else {
                    // Standalone host: restart the container
                    // Find the service name within the compose file. It's often the first part of the container name.
                    $compose_service_name = null;
                    if (preg_match('/' . preg_quote($service['stack_name'], '/') . '-(.*?)-[0-9]+/', $target_container['Names'][0] ?? '', $matches)) {
                        $compose_service_name = $matches[1];
                    }

                    if ($compose_service_name) {
                        // Use the new method to recreate the container via docker-compose
                        $dockerClient->recreateContainerFromStack($service['stack_name'], $compose_service_name);
                        $action_message = "Recreate command sent for service '{$compose_service_name}' in stack '{$service['stack_name']}'.";
                    } else {
                        // Fallback to simple restart if we can't determine the service name
                        $dockerClient->restartContainer($target_container['Id']);
                        $action_message = "Fallback: Restart command sent for container '{$target_container['Names'][0]}'.";
                    }
                    if (!$action_message) throw new Exception("Container untuk stack '{$service['stack_name']}' tidak ditemukan.");
                }
                echo "  -> SUKSES: {$action_message}\n";
                log_activity('SYSTEM', 'Auto-Healing Action', $action_message, $service['host_id']);

            } catch (Exception $e) {
                echo "  -> ERROR saat auto-healing: " . $e->getMessage() . "\n";
                log_activity('SYSTEM', 'Auto-Healing Error', "Failed to restart service '{$service['name']}': " . $e->getMessage(), $service['host_id']);
            }
        }
    }

} catch (Exception $e) {
    echo "Terjadi error kritis pada Health Monitor: " . $e->getMessage() . "\n";
    log_activity('SYSTEM', 'Health Monitor Error', "Error kritis: " . $e->getMessage());
}

$conn->close();
echo "Health Monitor selesai pada " . date('Y-m-d H:i:s') . "\n";
?>