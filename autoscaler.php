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
                "SELECT AVG(cpu_usage_percent) as avg_cpu
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

            if (!isset($info['Swarm']['ControlAvailable']) || !$info['Swarm']['ControlAvailable']) {
                echo "  -> INFO: Host '{$stack['host_name']}' adalah Standalone. Melewati proses autoscaling berbasis replika.\n";
                continue;
            }

            $services = $dockerClient->listServices();
            $target_service = null;
            foreach ($services as $service) {
                // Mencocokkan service berdasarkan label 'com.docker.stack.namespace'
                if (isset($service['Spec']['Labels']['com.docker.stack.namespace']) && $service['Spec']['Labels']['com.docker.stack.namespace'] === $stack['stack_name']) {
                    $target_service = $service;
                    break;
                }
            }

            if (!$target_service) {
                echo "  -> Tidak dapat menemukan service yang cocok untuk stack '{$stack['stack_name']}'. Melewati.\n";
                continue;
            }

            $service_id = $target_service['ID'];
            $current_replicas = $target_service['Spec']['Mode']['Replicated']['Replicas'];
            $service_version = $target_service['Version']['Index'];

            echo "  -> Menemukan service '{$target_service['Spec']['Name']}' (Replika saat ini: {$current_replicas})\n";

            // 5. Terapkan logika scaling
            $new_replicas = $current_replicas;
            if ($avg_cpu > $stack['autoscaling_cpu_threshold_up'] && $current_replicas < $stack['autoscaling_max_replicas']) {
                $new_replicas = $current_replicas + 1;
                echo "    -> KEPUTUSAN: SCALING NAIK. CPU ({$avg_cpu}%) > Threshold ({$stack['autoscaling_cpu_threshold_up']}%). Mengubah replika menjadi {$new_replicas}\n";
            } elseif ($avg_cpu < $stack['autoscaling_cpu_threshold_down'] && $current_replicas > $stack['autoscaling_min_replicas']) {
                $new_replicas = $current_replicas - 1;
                echo "    -> KEPUTUSAN: SCALING TURUN. CPU ({$avg_cpu}%) < Threshold ({$stack['autoscaling_cpu_threshold_down']}%). Mengubah replika menjadi {$new_replicas}\n";
            }

            // 6. Lakukan update jika jumlah replika berubah
            if ($new_replicas !== $current_replicas) {
                $dockerClient->updateServiceReplicas($service_id, $service_version, $new_replicas);
                echo "    -> SUKSES: Service berhasil di-update ke {$new_replicas} replika.\n";
                log_activity('autoscaler', 'Service Scaled', "Service '{$target_service['Spec']['Name']}' di-scale ke {$new_replicas} replika karena utilisasi CPU host.");
            }
        } catch (Exception $e) {
            echo "  -> ERROR memproses stack '{$stack['stack_name']}': " . $e->getMessage() . "\n";
            log_activity('autoscaler', 'Autoscaler Error', "Gagal memproses stack '{$stack['stack_name']}': " . $e->getMessage());
        }
    }
} catch (Exception $e) {
    echo "Terjadi error kritis pada skrip autoscaler: " . $e->getMessage() . "\n";
    log_activity('autoscaler', 'Autoscaler Error', "Error kritis: " . $e->getMessage());
}

$conn->close();
echo "Pengecekan autoscaler selesai pada " . date('Y-m-d H:i:s') . "\n";
?>