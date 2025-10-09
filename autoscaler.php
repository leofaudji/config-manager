#!/usr/bin/php
<?php
// File: /var/www/html/config-manager/autoscaler.php
// Jalankan via cron job, misalnya setiap 5 menit:
// */5 * * * * /var/www/html/config-manager/autoscaler.php >> /var/log/autoscaler.log 2>&1

// Set batas waktu eksekusi yang panjang
set_time_limit(280); // Sedikit di bawah 5 menit

// Bootstrap aplikasi
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/DockerClient.php';

function colorize_log($message, $color = "default") {
    $colors = [
        "success" => "32", // green
        "error"   => "31", // red
        "warning" => "33", // yellow
        "info"    => "36", // cyan
        "default" => "0"   // default
    ];
    $color_code = $colors[$color] ?? $colors['default'];
    // Check if running in a TTY (like a terminal) to avoid printing color codes in files
    if (function_exists('posix_isatty') && posix_isatty(STDOUT)) {
        return "\033[{$color_code}m{$message}\033[0m";
    }
    return $message;
}

echo "Memulai pengecekan autoscaler pada " . date('Y-m-d H:i:s') . "\n";

$conn = Database::getInstance()->getConnection();

try {
    // 1. Ambil semua stack aplikasi yang mengaktifkan autoscaling
    $sql_stacks = "
        SELECT 
            s.id as stack_id, s.stack_name, s.host_id,
            s.autoscaling_min_replicas, s.autoscaling_max_replicas,
            s.autoscaling_cpu_threshold_up, s.autoscaling_cpu_threshold_down,
            h.name as host_name, h.docker_api_url, h.tls_enabled, 
            h.ca_cert_path, h.client_cert_path, h.client_key_path
        FROM application_stacks s
        JOIN docker_hosts h ON s.host_id = h.id
        WHERE s.autoscaling_enabled = 1
    ";
    $stacks_result = $conn->query($sql_stacks);

    if ($stacks_result->num_rows === 0) {
        echo "Tidak ada stack aplikasi dengan autoscaling aktif. Selesai.\n";
        exit;
    }

    $stacks_to_scale = $stacks_result->fetch_all(MYSQLI_ASSOC);

    // 2. Loop melalui setiap stack dan evaluasi
    foreach ($stacks_to_scale as $stack) {
        echo "Mengevaluasi stack: '{$stack['stack_name']}' pada host '{$stack['host_name']}'\n";
        
        try {
            // 3. Dapatkan metrik CPU terbaru untuk host terkait
            $stmt_metrics = $conn->prepare(
                "SELECT AVG(host_cpu_usage_percent) as avg_cpu
                 FROM host_stats_history
                 WHERE host_id = ? AND created_at >= NOW() - INTERVAL 10 MINUTE"
            );
            $stmt_metrics->bind_param("i", $stack['host_id']);
            $stmt_metrics->execute();
            $metrics = $stmt_metrics->get_result()->fetch_assoc();
            $stmt_metrics->close();

            if (!$metrics || $metrics['avg_cpu'] === null) {
                echo "  -> Tidak ada metrik CPU terbaru untuk host. Melewati.\n";
                continue;
            }

            $avg_cpu = (float)$metrics['avg_cpu'];
            echo "  -> Rata-rata CPU Host (10 menit terakhir): " . number_format($avg_cpu, 2) . "%\n";

            // 4. Hubungkan ke Docker host dan temukan service yang sesuai
            $dockerClient = new DockerClient($stack); // Class DockerClient menerima array host
            $info = $dockerClient->getInfo();

            // --- Logika Scaling Berdasarkan Tipe Host ---
            if (!isset($info['Swarm']['ControlAvailable']) || !$info['Swarm']['ControlAvailable']) {
                // --- STANDALONE HOST: Vertical Scaling (Ubah Resource) ---
                echo "  -> INFO: Host '{$stack['host_name']}' adalah Standalone. Menerapkan Vertical Scaling.\n";

                // Temukan kontainer yang cocok dengan nama stack
                $containers = $dockerClient->listContainers();
                $target_container = null;
                foreach ($containers as $container) {
                    // Match by project label, which is more reliable than container name
                    if (($container['Labels']['com.docker.compose.project'] ?? null) === $stack['stack_name']) {
                        $target_container = $container;
                        break;
                    }
                }

                if (!$target_container) {
                    echo "  -> WARN: Tidak dapat menemukan kontainer yang berjalan untuk stack '{$stack['stack_name']}'. Melewati.\n";
                    continue;
                }

                $container_id = $target_container['Id'];
                $container_details = $dockerClient->inspectContainer($container_id);
                $current_cpu_limit_nano = $container_details['HostConfig']['CpuQuota'] ?? 0;
                // Convert from nano-CPUs (per 100ms period) back to vCPU count
                $current_cpu_limit = $current_cpu_limit_nano > 0 ? $current_cpu_limit_nano / 100000 : ($container_details['HostConfig']['NanoCpus'] / 1e9);

                echo "  -> Menemukan kontainer '{$target_container['Names'][0]}' (Batas CPU saat ini: {$current_cpu_limit} vCPU)\n";

                $new_cpu_limit = $current_cpu_limit;
                $scaling_step = 0.25; // How much to increase/decrease CPU by at a time
                if ($avg_cpu > $stack['autoscaling_cpu_threshold_up'] && $current_cpu_limit < $stack['autoscaling_max_replicas']) {
                    // For vertical scaling, we use max_replicas as max_cpu_cores
                    $new_cpu_limit = min($current_cpu_limit + $scaling_step, $stack['autoscaling_max_replicas']);
                    echo colorize_log("    -> KEPUTUSAN: SCALING NAIK (Vertical). CPU Host ({$avg_cpu}%) > Threshold ({$stack['autoscaling_cpu_threshold_up']}%). Mengubah batas CPU menjadi {$new_cpu_limit} vCPU.\n", "success");
                } elseif ($avg_cpu < $stack['autoscaling_cpu_threshold_down'] && $current_cpu_limit > $stack['autoscaling_min_replicas']) {
                    // For vertical scaling, we use min_replicas as min_cpu_cores
                    $new_cpu_limit = max($current_cpu_limit - $scaling_step, $stack['autoscaling_min_replicas']);
                    echo "    -> KEPUTUSAN: SCALING TURUN (Vertical). CPU Host ({$avg_cpu}%) < Threshold ({$stack['autoscaling_cpu_threshold_down']}%). Mengubah batas CPU menjadi {$new_cpu_limit} vCPU.\n";
                }

                // Execute the update if the limit has changed
                if ($new_cpu_limit != $current_cpu_limit) {
                    try {
                        $dockerClient->updateContainerResources($container_id, $new_cpu_limit);
                        echo colorize_log("    -> SUKSES: Batas CPU kontainer berhasil di-update ke {$new_cpu_limit} vCPU.\n", "success");
                        log_activity('SYSTEM', 'Container Scaled (Vertical)', "Batas CPU untuk kontainer '{$target_container['Names'][0]}' diubah menjadi {$new_cpu_limit} vCPU karena utilisasi CPU host.");
                    } catch (Exception $update_e) {
                        echo colorize_log("    -> ERROR: Gagal meng-update resource kontainer: " . $update_e->getMessage() . "\n", "error");
                    }
                }

            } else {
                // --- SWARM HOST: Horizontal Scaling (Ubah Replika) ---
                echo "  -> INFO: Host '{$stack['host_name']}' adalah Swarm Manager. Menerapkan Horizontal Scaling.\n";
                $services = $dockerClient->listServices();
                $target_service = null;
                foreach ($services as $service) {
                    if (isset($service['Spec']['Labels']['com.docker.stack.namespace']) && $service['Spec']['Labels']['com.docker.stack.namespace'] === $stack['stack_name']) {
                        $target_service = $service;
                        break;
                    }
                }

                if (!$target_service) {
                    echo "  -> WARN: Tidak dapat menemukan service yang cocok untuk stack '{$stack['stack_name']}'. Melewati.\n";
                    continue;
                }

                $service_id = $target_service['ID'];
                $current_replicas = $target_service['Spec']['Mode']['Replicated']['Replicas'];
                $service_version = $target_service['Version']['Index'];

                echo "  -> Menemukan service '{$target_service['Spec']['Name']}' (Replika saat ini: {$current_replicas})\n";

                $new_replicas = $current_replicas;
                if ($avg_cpu > $stack['autoscaling_cpu_threshold_up'] && $current_replicas < $stack['autoscaling_max_replicas']) {
                    $new_replicas = $current_replicas + 1;
                    echo colorize_log("    -> KEPUTUSAN: SCALING NAIK. CPU ({$avg_cpu}%) > Threshold ({$stack['autoscaling_cpu_threshold_up']}%). Mengubah replika menjadi {$new_replicas}\n", "success");
                } elseif ($avg_cpu < $stack['autoscaling_cpu_threshold_down'] && $current_replicas > $stack['autoscaling_min_replicas']) {
                    $new_replicas = $current_replicas - 1;
                    echo colorize_log("    -> KEPUTUSAN: SCALING TURUN. CPU ({$avg_cpu}%) < Threshold ({$stack['autoscaling_cpu_threshold_down']}%). Mengubah replika menjadi {$new_replicas}\n", "warning");
                }

                if ($new_replicas !== $current_replicas) {
                    // Get the current service spec to modify it
                    $service_spec = $target_service['Spec'];

                    // Add a label to the task template so new tasks are identifiable
                    $service_spec['TaskTemplate']['ContainerSpec']['Labels']['com.config-manager.origin'] = 'autoscaled';

                    // Update the replica count in the spec
                    $service_spec['Mode']['Replicated']['Replicas'] = $new_replicas;

                    // Call the generic service update method with the modified spec
                    $dockerClient->updateServiceSpec($service_id, $service_version, $service_spec);
                    echo colorize_log("    -> SUKSES: Service '{$target_service['Spec']['Name']}' di-update ke {$new_replicas} replika dengan label autoscaled.\n", "success");
                    log_activity('SYSTEM', 'Service Scaled', "Service '{$target_service['Spec']['Name']}' di-scale ke {$new_replicas} replika karena utilisasi CPU host.");
                }
            }
        } catch (Exception $e) {
            echo colorize_log("  -> ERROR memproses stack '{$stack['stack_name']}': " . $e->getMessage() . "\n", "error");
            log_activity('SYSTEM', 'Autoscaler Error', "Gagal memproses stack '{$stack['stack_name']}': " . $e->getMessage());
        }
    }
} catch (Exception $e) {
    echo colorize_log("Terjadi error kritis pada skrip autoscaler: " . $e->getMessage() . "\n", "error");
    log_activity('SYSTEM', 'Autoscaler Error', "Error kritis: " . $e->getMessage());
}

$conn->close();
echo "Pengecekan autoscaler selesai pada " . date('Y-m-d H:i:s') . "\n";
?>