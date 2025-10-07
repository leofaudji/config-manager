<?php
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');
$conn = Database::getInstance()->getConnection();

try {
    // 1. Authenticate the request via API Key in header
    $provided_api_key = $_SERVER['HTTP_X_API_KEY'] ?? '';
    $stored_api_key = get_setting('health_agent_api_token');

    if (empty($provided_api_key) || empty($stored_api_key) || !hash_equals($stored_api_key, $provided_api_key)) {
        log_activity('Health Agent', 'Auth Failed', 'A health report submission was rejected due to an invalid API token. Source IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'));
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized: Invalid or missing API Key.']);
        exit;
    }

    // 2. Get and decode the JSON payload
    $json_payload = file_get_contents('php://input');
    $data = json_decode($json_payload, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        log_activity('Health Agent', 'Processing Failed', 'Received an invalid JSON payload. Source IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'));
        throw new Exception("Invalid JSON payload received.");
    }

    // Log the received payload for debugging purposes
    error_log("Health report received for host_id: " . ($data['host_id'] ?? 'N/A') . ". Payload: " . $json_payload);

    $host_id = isset($data['host_id']) ? (int)$data['host_id'] : null;
    $reports = $data['reports'] ?? [];

    if (!$host_id || !is_array($reports)) {
        log_activity('Health Agent', 'Processing Failed', 'Received an invalid report format. Host ID was missing or reports array was not found. Payload: ' . $json_payload);
        throw new Exception("Invalid report format. 'host_id' and 'reports' array are required.");
    }

    // --- NEW: Defensive Check ---
    // Verify that the host_id sent by the agent actually exists in our database.
    // This is the most likely point of failure if the DB is not updating.
    $stmt_check_host = $conn->prepare("SELECT id FROM docker_hosts WHERE id = ?");
    $stmt_check_host->bind_param("i", $host_id);
    $stmt_check_host->execute();
    $host_exists = $stmt_check_host->get_result()->num_rows > 0;
    $stmt_check_host->close();
    if (!$host_exists) {
        throw new Exception("Report rejected. The provided host_id '{$host_id}' does not exist in the database. The agent may be misconfigured or the host was deleted.");
    }

    // 3. Update the host's last report timestamp
    $stmt_update_host = $conn->prepare("UPDATE docker_hosts SET last_report_at = NOW() WHERE id = ?");
    if (!$stmt_update_host) {
        throw new Exception("Failed to prepare host update statement: " . $conn->error);
    }
    $stmt_update_host->bind_param("i", $host_id); // "i" for integer
    if (!$stmt_update_host->execute()) {
        throw new Exception("Failed to update host last_report_at timestamp for host_id {$host_id}: " . $stmt_update_host->error);
    }
    $stmt_update_host->close();

    // Log the successful reception of the report
    log_activity('Health Agent', 'Report Received', "Successfully received health report from host ID: {$host_id}.");

    // 4. Process and save each container health report
    // Get default thresholds from settings
    $healthy_threshold = (int)get_setting('health_check_default_healthy_threshold', 2);
    $unhealthy_threshold = (int)get_setting('health_check_default_unhealthy_threshold', 3);

    // Prepare statements for fetching current status and updating it
    $stmt_fetch = $conn->prepare("SELECT status, consecutive_failures, consecutive_successes FROM container_health_status WHERE container_id = ?");
    $stmt_update = $conn->prepare(
        "INSERT INTO container_health_status (container_id, host_id, container_name, status, consecutive_failures, consecutive_successes, last_checked_at, last_log) 
         VALUES (?, ?, ?, ?, ?, ?, NOW(), ?) 
         ON DUPLICATE KEY UPDATE
            host_id=VALUES(host_id),
            container_name=VALUES(container_name),
            status=VALUES(status),
            consecutive_failures=VALUES(consecutive_failures),
            consecutive_successes=VALUES(consecutive_successes),
            last_checked_at=VALUES(last_checked_at),
            last_log=VALUES(last_log)"
    );

    foreach ($reports as $report) {
        $container_id = $report['container_id'];
        $container_name = $report['container_name'];
        $is_healthy = $report['is_healthy'];
        $log_message = $report['log_message'];
        
        // Fetch current status from DB
        $stmt_fetch->bind_param("s", $container_id);
        $stmt_fetch->execute();
        $current_status_rec = $stmt_fetch->get_result()->fetch_assoc();

        $new_status = $current_status_rec['status'] ?? 'unknown';
        $failures = $current_status_rec['consecutive_failures'] ?? 0;
        $successes = $current_status_rec['consecutive_successes'] ?? 0;

        if ($is_healthy === true) {
            $successes++;
            $failures = 0;
            if ($successes >= $healthy_threshold) {
                $new_status = 'healthy';
            }
        } elseif ($is_healthy === false) {
            $failures++;
            $successes = 0;
            if ($failures >= $unhealthy_threshold) {
                $new_status = 'unhealthy';
            }
        } else { // is_healthy is null (unknown)
            // Don't change counters for unknown status, just update the log and timestamp
            $new_status = 'unknown';
        }

        $stmt_update->bind_param("sisssis", $container_id, $host_id, $container_name, $new_status, $failures, $successes, $log_message);
        $stmt_update->execute();
    }
    $stmt_fetch->close();
    $stmt_update->close();

    echo json_encode(['status' => 'success', 'message' => 'Report received and processed.']);

} catch (Exception $e) {
    log_activity('Health Agent', 'Processing Error', 'An error occurred while processing a health report. Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();