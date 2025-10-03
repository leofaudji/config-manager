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

// Get the global health check setting
$global_health_check_enabled = (int)get_setting('health_check_global_enable', 0);

if ($global_health_check_enabled) {
    echo "INFO: Global health check is ENABLED. All services will be evaluated.\n";
}

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
        WHERE s.health_check_enabled = 1 OR ? = 1
    ";

    $stmt_services = $conn->prepare($sql_services);
    $stmt_services->bind_param("i", $global_health_check_enabled);
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
            log_activity('SYSTEM', 'Auto-Healing Triggered', "Service '{$service['name']}' marked as unhealthy. Restarting tasks.");

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
                log_activity('SYSTEM', 'Auto-Healing Action', $action_message);

            } catch (Exception $e) {
                echo "  -> ERROR saat auto-healing: " . $e->getMessage() . "\n";
                log_activity('SYSTEM', 'Auto-Healing Error', "Failed to restart service '{$service['name']}': " . $e->getMessage());
            }
        }
    }

    // --- PART 2: Check ALL containers on ALL hosts if global setting is enabled ---
    if ($global_health_check_enabled) {
        echo "\n--- Global Container Health Check ---\n";
        $hosts_result = $conn->query("SELECT * FROM docker_hosts");
        $all_hosts = $hosts_result->fetch_all(MYSQLI_ASSOC);

        foreach ($all_hosts as $host) {
            echo "Mengevaluasi host: '{$host['name']}'\n";
            try {
                $dockerClient = new DockerClient($host);
                $containers = $dockerClient->listContainers();

                foreach ($containers as $container) {
                    if ($container['State'] !== 'running') continue;

                    $container_id = $container['Id'];
                    $container_name = ltrim($container['Names'][0] ?? $container_id, '/');
                    
                    // Fetch container's health status from our DB
                    $stmt_chs = $conn->prepare("SELECT * FROM container_health_status WHERE container_id = ?");
                    $stmt_chs->bind_param("s", $container_id);
                    $stmt_chs->execute();
                    $current_status_rec = $stmt_chs->get_result()->fetch_assoc();
                    $stmt_chs->close();

                    // Inspect container to get its internal health
                    $details = $dockerClient->inspectContainer($container_id);
                    $docker_health_status = $details['State']['Health']['Status'] ?? null;

                    if (!$docker_health_status) {
                        // --- NEW: Fallback to TCP Port Check ---
                        $is_healthy = false;
                        $log_message = "Container does not have a HEALTHCHECK instruction and no public TCP ports found.";

                        // --- Smart Port Detection is now the primary fallback method ---
                        $private_port = null;
                        $image_name = strtolower($container['Image']);
                        $common_ports = [
                            'mysql' => 3306, 'mariadb' => 3306,
                            'postgres' => 5432,
                            'redis' => 6379,
                            'nginx' => 80, 'httpd' => 80,
                            'rabbitmq' => 5672,
                            'mongo' => 27017,
                            'elasticsearch' => 9200
                        ];
                        foreach ($common_ports as $keyword => $port) {
                            if (strpos($image_name, $keyword) !== false) {
                                $private_port = $port;
                                echo "    -> INFO: Port tidak terdefinisi, menggunakan port standar terdeteksi: {$private_port} untuk image '{$image_name}'.\n";
                                break;
                            }
                        }

                        $public_port = 0;
                        // Find the first published TCP port
                        if (!empty($container['Ports'])) {
                            foreach ($container['Ports'] as $port_mapping) {
                                if (($port_mapping['Type'] ?? 'tcp') === 'tcp' && isset($port_mapping['PublicPort'])) {
                                    $public_port = $port_mapping['PublicPort'];
                                    break;
                                }
                            }
                        }

                        if (!$private_port && !empty($container['Ports'])) {
                             $private_port = $container['Ports'][0]['PrivatePort'] ?? null;
                        }

                        if ($public_port > 0) {
                            // --- Check 1: Publicly exposed port ---
                            // Use parse_url for robust host extraction
                            $url_parts = parse_url($host['docker_api_url']);
                            $check_ip = $url_parts['host'] ?? '127.0.0.1';

                            // Use fsockopen for a quick TCP connection check
                            $timeout = 2; // seconds
                            $connection = @fsockopen($check_ip, $public_port, $errno, $errstr, $timeout);

                            if (is_resource($connection)) {
                                $is_healthy = true;
                                fclose($connection);
                                $log_message = "TCP check on public port {$public_port}: OK";
                            } else {
                                $log_message = "TCP check on public port {$public_port}: FAILED ({$errstr})";
                            }
                        } else if (!empty($container['NetworkSettings']['Networks'])) {
                            // --- Check 2: Internal container IP and port ---
                            // Find the first internal IP and private port
                            $internal_ip = null;
                            $private_port = null;
                            foreach($container['NetworkSettings']['Networks'] as $net) {
                                if (!empty($net['IPAddress'])) {
                                    $internal_ip = $net['IPAddress'];
                                    break;
                                }
                            }
                            foreach ($container['Ports'] as $port_mapping) {
                            }

                            if ($internal_ip && $private_port) {
                                // --- NEW: Execute the check on the remote host ---
                                // Use a helper container on the remote host to perform the TCP check.
                                // The `nc` (netcat) command is perfect for this.
                                // `nc -z -w 2 <ip> <port>` attempts to connect with a 2-second timeout.
                                $check_command = "nc -z -w 2 " . escapeshellarg($internal_ip) . " " . escapeshellarg($private_port);
                                try {
                                    // We use a generic alpine image as a temporary exec container.
                                    $dockerClient->exec('alpine:latest', $check_command, true, true);
                                    $is_healthy = true;
                                    $log_message = "TCP check on internal IP {$internal_ip}:{$private_port}: OK";
                                } catch (Exception $exec_e) {
                                    $is_healthy = false;
                                    $log_message = "TCP check on internal IP {$internal_ip}:{$private_port}: FAILED (" . strtok($exec_e->getMessage(), "\n") . ")";
                                }
                            }
                        }

                        if (!$is_healthy && !$private_port && !$public_port) {
                            $log_message = "Container does not have a HEALTHCHECK instruction and no known TCP port could be detected.";
                            echo "  -> {$log_message}\n";
                            $stmt_update_chs = $conn->prepare("INSERT INTO container_health_status (container_id, host_id, container_name, status, last_checked_at, last_log) VALUES (?, ?, ?, 'unknown', NOW(), ?) ON DUPLICATE KEY UPDATE container_name=VALUES(container_name), status='unknown', last_checked_at=VALUES(last_checked_at), last_log=VALUES(last_log)");
                            $stmt_update_chs->bind_param("isss", $container_id, $host['id'], $container_name, $log_message);
                            $stmt_update_chs->execute();
                            $stmt_update_chs->close();
                            continue; // Skip to the next container
                        }
                    } else {
                        echo "  -> Mengecek kontainer: '{$container_name}' (Status Docker: {$docker_health_status})\n";
                        $is_healthy = ($docker_health_status === 'healthy');
                        $log_message = "Container '{$container_name}' health status: {$docker_health_status}";
                    }
                    echo "    -> Hasil: {$log_message}\n";
                    // Use hardcoded thresholds for now, can be made configurable later
                    $unhealthy_threshold = 3;
                    $healthy_threshold = 2;

                    $new_status = $current_status_rec['status'] ?? 'unknown';
                    $failures = $current_status_rec['consecutive_failures'] ?? 0;
                    $successes = $current_status_rec['consecutive_successes'] ?? 0;

                    if ($is_healthy) {
                        $successes++; $failures = 0;
                        if ($successes >= $healthy_threshold) $new_status = 'healthy';
                    } else {
                        $failures++; $successes = 0;
                        if ($failures >= $unhealthy_threshold) $new_status = 'unhealthy';
                    }

                    // Update our DB
                    $stmt_update_chs = $conn->prepare("INSERT INTO container_health_status (container_id, host_id, container_name, status, consecutive_failures, consecutive_successes, last_checked_at, last_log) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?) ON DUPLICATE KEY UPDATE container_name=VALUES(container_name), status=VALUES(status), consecutive_failures=VALUES(consecutive_failures), consecutive_successes=VALUES(consecutive_successes), last_checked_at=VALUES(last_checked_at), last_log=VALUES(last_log)");
                    $stmt_update_chs->bind_param("sisssis", $container_id, $host['id'], $container_name, $new_status, $failures, $successes, $log_message);
                    $stmt_update_chs->execute();
                    $stmt_update_chs->close();

                    // Trigger auto-healing
                    if ($new_status === 'unhealthy' && ($current_status_rec['status'] ?? '') !== 'unhealthy') {
                        echo "    -> STATUS TIDAK SEHAT TERDETEKSI! Memicu auto-healing untuk kontainer '{$container_name}'.\n";
                        log_activity('SYSTEM', 'Auto-Healing Triggered', "Container '{$container_name}' on host '{$host['name']}' marked as unhealthy. Restarting.");

                        // --- NEW: Smarter auto-healing logic ---
                        $stack_name = $details['Config']['Labels']['com.docker.compose.project'] ?? $details['Config']['Labels']['com.docker.stack.namespace'] ?? null;
                        $compose_service_name = $details['Config']['Labels']['com.docker.compose.service'] ?? null;

                        // Try to extract service name if not present in labels
                        if ($stack_name && !$compose_service_name) {
                            if (preg_match('/' . preg_quote($stack_name, '/') . '-(.*?)-[0-9]+/', $container_name, $matches)) {
                                $compose_service_name = $matches[1];
                            }
                        }

                        if ($stack_name && $compose_service_name) {
                            // Prefer to recreate the service from its stack definition
                            $dockerClient->recreateContainerFromStack($stack_name, $compose_service_name);
                            log_activity('SYSTEM', 'Auto-Healing Action', "Recreate command sent for service '{$compose_service_name}' in stack '{$stack_name}'.");
                        } else {
                            // Fallback to simple restart for standalone containers
                            $dockerClient->restartContainer($container_id);
                            log_activity('SYSTEM', 'Auto-Healing Action', "Fallback: Restart command sent for container '{$container_name}'.");
                        }
                    }
                }
            } catch (Exception $e) {
                echo "  -> ERROR memproses host '{$host['name']}': " . $e->getMessage() . "\n";
                log_activity('SYSTEM', 'Health Monitor Error', "Gagal memproses host '{$host['name']}' untuk pengecekan kontainer: " . $e->getMessage());
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