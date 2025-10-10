<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$is_download_request = isset($_GET['download']) && $_GET['download'] === 'true';

if (!$is_download_request) {
    header('Content-Type: application/json');
}

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
    exit;
}

$conn = Database::getInstance()->getConnection();
$type = $_GET['type'] ?? 'activity';

try {
    switch ($type) {
        case 'activity':
            $limit = $is_download_request ? 10000 : 100; // Fetch more for download
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            $offset = ($page - 1) * $limit;

            $total_items_result = $conn->query("SELECT COUNT(*) as count FROM activity_log WHERE username NOT IN ('health-agent', 'SYSTEM')");
            $total_items = $total_items_result->fetch_assoc()['count'];
            $total_pages = ceil($total_items / $limit);

            $stmt = $conn->prepare("SELECT * FROM activity_log WHERE username NOT IN ('health-agent', 'SYSTEM') ORDER BY id DESC LIMIT ? OFFSET ?");
            $stmt->bind_param("ii", $limit, $offset);
            $stmt->execute();
            $result = $stmt->get_result();

            $html = '';
            if ($is_download_request) {
                header('Content-Type: text/plain');
                header('Content-Disposition: attachment; filename="activity_log.txt"');
                $output = fopen('php://output', 'w');
                fputcsv($output, ['Timestamp', 'User', 'Action', 'Details', 'IP Address'], "\t");
                while ($log = $result->fetch_assoc()) {
                    fputcsv($output, [
                        $log['created_at'],
                        $log['username'],
                        $log['action'],
                        $log['details'] ?? '',
                        $log['ip_address'] ?? ''
                    ], "\t");
                }
                fclose($output);
                exit;
            }
            while ($log = $result->fetch_assoc()) {
                $html .= '<tr>';
                $html .= '<td>' . $log['created_at'] . '</td>';
                $html .= '<td>' . htmlspecialchars($log['username']) . '</td>';
                $html .= '<td>' . htmlspecialchars($log['action']) . '</td>';
                $html .= '<td>' . htmlspecialchars($log['details'] ?? '') . '</td>';
                $html .= '<td>' . htmlspecialchars($log['ip_address'] ?? '') . '</td>';
                $html .= '</tr>';
            }
            $stmt->close();

            echo json_encode([
                'html' => $html,
                'total_pages' => $total_pages,
                'current_page' => $page,
                'info' => "Showing " . $result->num_rows . " of " . $total_items . " records."
            ]);
            break;

        case 'agent':
            $limit = $is_download_request ? 10000 : 100; // Fetch more for download
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            $offset = ($page - 1) * $limit;

            $total_items_result = $conn->query("SELECT COUNT(*) as count FROM activity_log WHERE username = 'health-agent'");
            $total_items = $total_items_result->fetch_assoc()['count'];
            $total_pages = ceil($total_items / $limit);

            $sql = "SELECT al.id, al.created_at, al.details as log_message, h.name as host_name 
                    FROM activity_log al 
                    LEFT JOIN docker_hosts h ON al.host_id = h.id
                    WHERE al.username = 'health-agent' 
                    ORDER BY al.id DESC LIMIT ? OFFSET ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ii", $limit, $offset);
            $stmt->execute();
            $result = $stmt->get_result();

            $html = '';
            if ($is_download_request) {
                header('Content-Type: text/plain');
                header('Content-Disposition: attachment; filename="agent_log.txt"');
                $output = fopen('php://output', 'w');
                fputcsv($output, ['Timestamp', 'Host', 'Log Message'], "\t");
                while ($log = $result->fetch_assoc()) {
                    $log_lines = json_decode($log['log_message'], true);
                    if (json_last_error() !== JSON_ERROR_NONE || !is_array($log_lines)) {
                        $log_content = $log['log_message'];
                    } else {
                        $log_content = implode("\n", $log_lines);
                    }
                    fputcsv($output, [
                        $log['created_at'],
                        $log['host_name'] ?? 'Unknown Host',
                        $log_content
                    ], "\t");
                }
                fclose($output);
                exit;
            }
            while ($log = $result->fetch_assoc()) {
                $log_lines = json_decode($log['log_message'], true);
                if (json_last_error() !== JSON_ERROR_NONE || !is_array($log_lines)) {
                    $log_content = htmlspecialchars($log['log_message']);
                } else {
                    $log_content = htmlspecialchars(implode("\n", $log_lines));
                }
                $html .= '<tr>';
                $html .= '<td>' . $log['created_at'] . '</td>';
                $html .= '<td>' . htmlspecialchars($log['host_name'] ?? 'Unknown Host') . '</td>';
                $html .= '<td><pre class="mb-0">' . $log_content . '</pre></td>';
                $html .= '</tr>';
            }
            $stmt->close();

            echo json_encode([
                'html' => $html,
                'total_pages' => $total_pages,
                'current_page' => $page,
                'info' => "Showing " . $result->num_rows . " of " . $total_items . " records."
            ]);
            break;

        case 'cron':
            $script_name = $_GET['script'] ?? '';
            if (empty($script_name) || !in_array($script_name, ['collect_stats', 'autoscaler', 'health_monitor', 'system_cleanup'])) {
                throw new Exception('Invalid script name provided.');
            }

            $log_path = get_setting('cron_log_path', '/var/log');
            $log_file_path = rtrim($log_path, '/') . "/{$script_name}.log";

            if ($is_download_request) {
                header('Content-Type: text/plain');
                header('Content-Disposition: attachment; filename="' . basename($log_file_path) . '"');
                readfile($log_file_path);
                exit;
            }

            if (!file_exists($log_file_path) || !is_readable($log_file_path)) {
                echo json_encode(['status' => 'success', 'log_content' => "Log file not found or is not readable at:\n{$log_file_path}"]);
                exit;
            }

            $lines_to_show = 200;
            $command = "tail -n " . $lines_to_show . " " . escapeshellarg($log_file_path) . " 2>&1";
            $log_content = shell_exec($command);

            if (empty(trim($log_content))) {
                $log_content = "The log file is currently empty.\n\nThis could mean:\n1. The cron job has not run yet.\n2. The script ran successfully but produced no output.";
            }

            echo json_encode(['status' => 'success', 'log_content' => $log_content]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid log type specified.']);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();
?>