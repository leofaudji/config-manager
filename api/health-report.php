<?php
// File: /var/www/html/config-manager/api/health-report.php
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');

// 1. Validasi Input & Keamanan
$api_key = $_SERVER['HTTP_X_API_KEY'] ?? '';
$expected_api_key = get_setting('health_agent_api_token');

if (empty($api_key) || !hash_equals($expected_api_key, $api_key)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized: Invalid API Key.']);
    exit;
}

$json_data = file_get_contents('php://input');
$data = json_decode($json_data, true);

if (json_last_error() !== JSON_ERROR_NONE || !isset($data['host_id'], $data['reports'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON payload.']);
    exit;
}

$host_id = (int)$data['host_id'];
$reports = $data['reports'];
$running_container_ids_from_agent = $data['running_container_ids'] ?? [];
$container_stats = $data['container_stats'] ?? [];
$host_uptime_seconds = $data['host_uptime_seconds'] ?? null;
$host_cpu_usage_percent = $data['host_cpu_usage_percent'] ?? null;

$conn = Database::getInstance()->getConnection();
$conn->begin_transaction();

try {
    // 2. Update status host (last_report_at)
    $stmt_host = $conn->prepare("UPDATE docker_hosts SET last_report_at = NOW() WHERE id = ?");
    $stmt_host->bind_param("i", $host_id);
    $stmt_host->execute();
    $stmt_host->close();

    // 3. Simpan statistik host jika ada
    if ($host_cpu_usage_percent !== null) {
        $stmt_stats = $conn->prepare("INSERT INTO host_stats_history (host_id, host_cpu_usage_percent, host_uptime_seconds) VALUES (?, ?, ?)");
        $stmt_stats->bind_param("idi", $host_id, $host_cpu_usage_percent, $host_uptime_seconds);
        $stmt_stats->execute();
        $stmt_stats->close();
    }

    // 4. Dapatkan status terakhir semua kontainer di host ini dari DB
    $stmt_current_status = $conn->prepare("SELECT container_id, is_healthy FROM container_health_status WHERE host_id = ?");
    $stmt_current_status->bind_param("i", $host_id);
    $stmt_current_status->execute();
    $result = $stmt_current_status->get_result();
    $db_statuses = [];
    while ($row = $result->fetch_assoc()) {
        $db_statuses[$row['container_id']] = $row['is_healthy'];
    }
    $stmt_current_status->close();

    // 5. Proses setiap laporan dari agen
    $stmt_upsert_status = $conn->prepare(
        "INSERT INTO container_health_status (host_id, container_id, container_name, is_healthy, last_check_log, last_updated_at) 
         VALUES (?, ?, ?, ?, ?, NOW()) 
         ON DUPLICATE KEY UPDATE is_healthy = VALUES(is_healthy), last_check_log = VALUES(last_check_log), last_updated_at = NOW()"
    );

    $stmt_update_history = $conn->prepare(
        "UPDATE container_health_history SET end_time = NOW(), duration_seconds = TIMESTAMPDIFF(SECOND, start_time, NOW()) 
         WHERE container_id = ? AND host_id = ? AND end_time IS NULL"
    );

    $stmt_insert_history = $conn->prepare(
        "INSERT INTO container_health_history (host_id, container_id, container_name, status, start_time) VALUES (?, ?, ?, ?, NOW())"
    );

    foreach ($reports as $report) {
        $container_id = $report['container_id'];
        $container_name = $report['container_name'];
        $is_healthy_now = $report['is_healthy']; // Bisa true, false, atau null
        $log_message = $report['log_message'];

        // Konversi status untuk disimpan
        $status_now_str = $is_healthy_now === true ? 'healthy' : ($is_healthy_now === false ? 'unhealthy' : 'unknown');
        $is_healthy_db_val = $is_healthy_now === null ? null : (int)$is_healthy_now;

        // Upsert status saat ini
        $stmt_upsert_status->bind_param("issis", $host_id, $container_id, $container_name, $is_healthy_db_val, $log_message);
        $stmt_upsert_status->execute();

        // Cek apakah status berubah
        $last_status_db = $db_statuses[$container_id] ?? null; // Status sebelumnya dari DB
        $last_status_str = $last_status_db === '1' ? 'healthy' : ($last_status_db === '0' ? 'unhealthy' : 'unknown');

        if ($status_now_str !== $last_status_str) {
            // Status berubah!
            // 1. Tutup event status sebelumnya di history
            $stmt_update_history->bind_param("si", $container_id, $host_id);
            $stmt_update_history->execute();

            // 2. Buka event status baru di history
            $stmt_insert_history->bind_param("isss", $host_id, $container_id, $container_name, $status_now_str);
            $stmt_insert_history->execute();
        }
    }
    $stmt_upsert_status->close();
    $stmt_update_history->close();
    $stmt_insert_history->close();

    // 6. Tangani kontainer yang berhenti (dihapus atau di-stop)
    $running_ids_from_db = array_keys($db_statuses);
    $stopped_container_ids = array_diff($running_ids_from_db, $running_container_ids_from_agent);

    if (!empty($stopped_container_ids)) {
        $stmt_delete_status = $conn->prepare("DELETE FROM container_health_status WHERE host_id = ? AND container_id = ?");
        $stmt_update_stopped_history = $conn->prepare(
            "UPDATE container_health_history SET end_time = NOW(), duration_seconds = TIMESTAMPDIFF(SECOND, start_time, NOW()) 
             WHERE container_id = ? AND host_id = ? AND end_time IS NULL"
        );
        $stmt_insert_stopped_history = $conn->prepare(
            "INSERT INTO container_health_history (host_id, container_id, container_name, status, start_time, end_time, duration_seconds) 
             SELECT ?, ?, container_name, 'stopped', NOW(), NOW(), 0 FROM container_health_status WHERE container_id = ? AND host_id = ? LIMIT 1
             ON DUPLICATE KEY UPDATE end_time = NOW()" // Mencegah duplikasi jika skrip berjalan cepat
        );

        foreach ($stopped_container_ids as $stopped_id) {
            // Tutup event yang sedang berjalan
            $stmt_update_stopped_history->bind_param("si", $stopped_id, $host_id);
            $stmt_update_stopped_history->execute();
            // Hapus dari tabel status saat ini
            $stmt_delete_status->bind_param("is", $host_id, $stopped_id);
            $stmt_delete_status->execute();
        }
        $stmt_delete_status->close();
        $stmt_update_stopped_history->close();
        $stmt_insert_stopped_history->close();
    }

    $conn->commit();
    echo json_encode(['status' => 'success', 'message' => 'Health report processed successfully.']);

} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    log_activity('SYSTEM', 'Health Report Error', 'Error processing health report for host ' . $host_id . ': ' . $e->getMessage(), $host_id);
    echo json_encode(['status' => 'error', 'message' => 'Internal Server Error: ' . $e->getMessage()]);
}

$conn->close();
?>