<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/DeploymentRunner.php';

header('Content-Type: application/json');

try {
    // --- 1. Security Validation ---
    $provided_token = $_GET['token'] ?? '';
    $expected_token = get_setting('webhook_secret_token');

    if (empty($provided_token) || empty($expected_token) || !hash_equals($expected_token, $provided_token)) {
        http_response_code(401);
        log_activity('webhook_bot', 'Webhook Failed', 'Invalid token provided from IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'));
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
        exit;
    }

    // --- 2. Parse Git Provider Payload ---
    $payload = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        log_activity('webhook_bot', 'Webhook Failed', 'Invalid JSON payload from IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'));
        echo json_encode(['status' => 'error', 'message' => 'Invalid JSON payload']);
        exit;
    }

    // --- NEW: Handle GitHub/Gitea 'ping' event for successful webhook setup ---
    $event_type = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? $_SERVER['HTTP_X_GITEA_EVENT'] ?? null;
    if ($event_type === 'ping') {
        http_response_code(200);
        log_activity('webhook_bot', 'Webhook Ping', 'Received successful ping from IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'));
        echo json_encode(['status' => 'success', 'message' => 'Pong! Webhook is configured correctly.']);
        exit;
    }

    //print_r($payload) ;
    // Extract repository URL and branch from payload (supports GitHub and GitLab)
    $repo_url = $payload['repository']['ssh_url'] ?? $payload['repository']['git_ssh_url'] ?? $payload['repository']['clone_url'] ?? $payload['project']['git_ssh_url'] ?? null;
    // --- NEW: Add support for HTTPS URL from payload ---
    $repo_https_url = $payload['repository']['html_url'] ?? $payload['repository']['web_url'] ?? $payload['project']['web_url'] ?? null;
    // --- NEW: Also check for the HTTPS clone URL which often ends in .git ---
    $repo_https_clone_url = $payload['repository']['clone_url'] ?? null;

    $ref = $payload['ref'] ?? null; // e.g., "refs/heads/main"

    if (!$repo_url || !$ref) {
        log_activity('webhook_bot', 'Webhook Failed', 'Payload missing repository URL or ref from IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'));
        // Respon sebelum exit
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Payload missing repository URL or ref.']);
        exit;
    }

    $branch_pushed = str_replace('refs/heads/', '', $ref);
    log_activity('webhook_bot', 'Webhook Received', "Webhook received for repo '{$repo_url}' on branch '{$branch_pushed}'.");

    // --- LANGKAH KRUSIAL: Kirim respons ke Git SEKARANG ---
    // Mengirim HTTP 202 Accepted menandakan permintaan diterima dan akan diproses.
    http_response_code(202);
    echo json_encode(['status' => 'accepted', 'message' => 'Webhook accepted. Deployment will proceed in the background.']);

    // Pastikan output terkirim dan tutup koneksi ke client (Git provider)
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        ob_flush();
        flush();
    }
    // --- 3. Find Matching Stacks in DB ---
    $conn = Database::getInstance()->getConnection();
    // --- FIX: Join with docker_hosts to get the host_name for logging ---
    $stmt_stacks = $conn->prepare(
        "SELECT s.id, s.deployment_details, s.stack_name, s.host_id, s.last_webhook_triggered_at, s.webhook_update_policy, h.name as host_name
         FROM application_stacks s
         JOIN docker_hosts h ON s.host_id = h.id
         WHERE s.source_type = 'git' 
           AND (
                JSON_UNQUOTE(JSON_EXTRACT(s.deployment_details, '$.git_url')) = ? 
                OR JSON_UNQUOTE(JSON_EXTRACT(s.deployment_details, '$.git_url')) = ? 
                OR JSON_UNQUOTE(JSON_EXTRACT(s.deployment_details, '$.git_url')) = ? 
           )
           AND JSON_UNQUOTE(JSON_EXTRACT(s.deployment_details, '$.git_branch')) = ?"
    );
    // --- FIX: Bind all possible URL formats to the query ---
    $stmt_stacks->bind_param("ssss", $repo_url, $repo_https_url, $repo_https_clone_url, $branch_pushed);
    $stmt_stacks->execute();
    $stacks_to_update = $stmt_stacks->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_stacks->close();

    if (empty($stacks_to_update)) {
        log_activity('webhook_bot', 'Webhook Ignored', "No stacks found matching repo '{$repo_url}' and branch '{$branch_pushed}'.");
        exit;
    }

    // --- 4. Lanjutkan proses di latar belakang ---
    // Sekarang kita memicu deployment setelah respons dikirim.
    // --- NEW: Separate stacks based on their update policy ---
    $realtime_stacks = [];
    $scheduled_stacks = [];

    foreach ($stacks_to_update as $stack) {
        $deployment_details = json_decode($stack['deployment_details'], true);
        if (!$deployment_details) {
            log_activity('webhook_bot', 'Webhook Ignored', "Stack '{$stack['stack_name']}' skipped: could not decode deployment details.", $stack['host_id']);
            continue;
        }

        // Add necessary details from the main stack table
        $stack['deployment_details_decoded'] = $deployment_details;

        if (($stack['webhook_update_policy'] ?? 'realtime') === 'scheduled') {
            $scheduled_stacks[] = $stack;
        } else {
            $realtime_stacks[] = $stack;
        }
    }

    // --- Handle Real-time Deployments ---
    if (!empty($realtime_stacks)) {
        // Group realtime stacks by repo and branch to clone only once
        $grouped_realtime_stacks = [];
        foreach ($realtime_stacks as $stack) {
            $deployment_details = $stack['deployment_details_decoded'];
            // Add host and stack name for the worker to use
            $deployment_details['host_id'] = $stack['host_id'];
            $deployment_details['stack_name'] = $stack['stack_name'];
            $deployment_details['host_name'] = $stack['host_name']; // Pass host name for logging

            // Create a unique key for each repo+branch combination
            $group_key = md5($deployment_details['git_url'] . ':' . $deployment_details['git_branch']);
            if (!isset($grouped_realtime_stacks[$group_key])) {
                $grouped_realtime_stacks[$group_key] = [
                'git_url' => $deployment_details['git_url'],
                'git_branch' => $deployment_details['git_branch'],
                'stacks' => []
                ];
            }
            $grouped_realtime_stacks[$group_key]['stacks'][] = $deployment_details;
        }

        $triggered_count = 0;
        foreach ($grouped_realtime_stacks as $group) {
            try {
                // This payload will be sent to a single background worker.
                $group_payload = [
                'git_url' => $group['git_url'],
                'git_branch' => $group['git_branch'],
                'stacks' => $group['stacks'],
                'build_from_dockerfile' => (bool)get_setting('webhook_build_image_enabled', false),
                'db_config' => [
                    'DB_SERVER'   => Config::get('DB_SERVER'),
                    'DB_USERNAME' => Config::get('DB_USERNAME'),
                    'DB_PASSWORD' => Config::get('DB_PASSWORD'),
                    'DB_NAME'     => Config::get('DB_NAME'),
                ],
                // --- NEW: Flag to tell the worker this is a grouped deployment ---
                    'is_grouped_deployment' => true
                ];

                // Generate a single log file for this entire group deployment.
                $log_dir = get_setting('deployment_log_path', LOGS_PATH . '/deployments');
                if (!is_dir($log_dir)) {
                    @mkdir($log_dir, 0755, true);
                }
                // Create a more generic log file name for the group
                $repo_name_for_log = basename($group['git_url'], '.git');
                $log_file_path = $log_dir . "/deploy-group-{$repo_name_for_log}-" . date('Ymd-His') . '-' . uniqid() . ".log";
                $group_payload['log_file_path'] = $log_file_path;

                // Use the runner to execute the grouped deployment in a single background process
                $pid = DeploymentRunner::runInBackground($group_payload);
                
                // Log a single activity for the entire group
                $stack_names_in_group = implode(', ', array_column($group['stacks'], 'stack_name'));
                $log_message = "Triggered grouped background deployment for repo '{$group['git_url']}' affecting stacks: {$stack_names_in_group}. PID {$pid}.";
                log_activity('webhook_bot', 'Webhook Triggered', $log_message, null, $log_file_path, $pid);

                $triggered_count++;
            } catch (Exception $e) {
                $error_message = "Failed to trigger deployment for group '{$group['git_url']}': " . $e->getMessage();
                log_activity('webhook_bot', 'Webhook Failed', $error_message, null);
            }
        }
    }

    // --- Handle Scheduled Deployments ---
    if (!empty($scheduled_stacks)) {
        $stmt_schedule = $conn->prepare("UPDATE application_stacks SET webhook_pending_update = 1, webhook_pending_since = NOW() WHERE id = ?");
        foreach ($scheduled_stacks as $stack) {
            $stmt_schedule->bind_param("i", $stack['id']);
            $stmt_schedule->execute();
            $log_message = "Update for stack '{$stack['stack_name']}' on host '{$stack['host_name']}' has been scheduled.";
            log_activity('webhook_bot', 'Webhook Scheduled', $log_message, $stack['host_id']);
        }
        $stmt_schedule->close();
    }

    // Skrip akan berakhir di sini. Tidak ada output lagi yang dikirim ke client.
    exit;

} catch (Exception $e) {
    http_response_code(500); 
    echo json_encode(['status' => 'error', 'message' => 'An internal server error occurred.']);
}

?>