<?php
require_once __DIR__ . '/../includes/bootstrap.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
    exit;
}

$conn = Database::getInstance()->getConnection();
$request_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$basePath = BASE_PATH;
if ($basePath && strpos($request_path, $basePath) === 0) {
    $request_path = substr($request_path, strlen($basePath));
}

// --- Backup Logic ---
if ($request_path === '/api/system/backup' && ($_SERVER['REQUEST_METHOD'] === 'GET' || $_SERVER['REQUEST_METHOD'] === 'POST')) {
    $backup_type = $_GET['type'] ?? 'download'; // Default to download for backward compatibility

    try {
        $tables_to_backup = [
            // Core Config
            'settings', 'users', 'groups', 'traefik_hosts', 'docker_hosts', 
            'application_stacks', 'middlewares', 'services', 'servers', 'routers', 
            'router_middleware', 'configuration_templates', 'transports',
            // Monitoring & History Data
            'incident_reports', 'container_health_history', 'service_health_status', 'container_health_status'
        ];

        $backup_data = [];

        foreach ($tables_to_backup as $table) {
            $result = $conn->query("SELECT * FROM `{$table}`");
            if ($result) {
                $backup_data[$table] = $result->fetch_all(MYSQLI_ASSOC);
            } else {
                $backup_data[$table] = [];
            }
        }

        if ($backup_type === 'download') {
            $filename = 'config-manager-backup-' . date('Y-m-d_H-i-s') . '.json';
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            echo json_encode($backup_data, JSON_PRETTY_PRINT);
            exit;
        } elseif ($backup_type === 'manual') {
            $backup_path = get_setting('backup_path', '/var/www/html/config-manager/backups');
            if (!is_dir($backup_path)) {
                if (!@mkdir($backup_path, 0755, true)) {
                    throw new Exception("Backup path '{$backup_path}' does not exist and could not be created.");
                }
            }
            if (!is_writable($backup_path)) {
                throw new Exception("Backup path '{$backup_path}' is not writable.");
            }

            $filename = 'config-manager-backup-' . date('Y-m-d_H-i-s') . '.json';
            $file_path = rtrim($backup_path, '/') . '/' . $filename;

            if (file_put_contents($file_path, json_encode($backup_data, JSON_PRETTY_PRINT))) {
                log_activity($_SESSION['username'], 'Manual Backup Success', "Manual backup created at {$file_path}.");
                echo json_encode(['status' => 'success', 'message' => 'Manual backup created successfully.']);
            } else {
                throw new Exception("Failed to write backup file to: {$file_path}");
            }
            exit;
        }

    } catch (Exception $e) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Backup failed: ' . $e->getMessage()]);
        exit;
    }
}

// --- Restore Logic ---
if ($request_path === '/api/system/restore' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'No backup file uploaded or upload error.']);
        exit;
    }

    $json_content = file_get_contents($_FILES['backup_file']['tmp_name']);
    $backup_data = json_decode($json_content, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid JSON file.']);
        exit;
    }

    $tables_to_restore = [
        // Restore in order of dependency (child tables first)
        'incident_reports', 'container_health_history', 'service_health_status', 'container_health_status',
        'router_middleware', 'routers', 'servers', 'services', 'middlewares', 
        'application_stacks', 'docker_hosts', 'groups', 'traefik_hosts', 
        'users', 'settings', 'configuration_templates', 'transports',
        // Note: activity_log and stats history are not restored by design.
    ];

    $conn->begin_transaction();
    try {
        $conn->query("SET FOREIGN_KEY_CHECKS=0");

        foreach ($tables_to_restore as $table) {
            if (!isset($backup_data[$table])) {
                throw new Exception("Backup file is missing data for table '{$table}'.");
            }

            // Truncate the table to clear existing data
            $conn->query("TRUNCATE TABLE `{$table}`");

            if (empty($backup_data[$table])) {
                continue; // Skip if there's no data for this table
            }

            // Prepare the INSERT statement
            $first_row = $backup_data[$table][0];
            $columns = array_keys($first_row);
            $column_list = '`' . implode('`, `', $columns) . '`';
            $placeholders = implode(', ', array_fill(0, count($columns), '?'));
            $types = str_repeat('s', count($columns)); // Treat all as strings for simplicity

            $stmt = $conn->prepare("INSERT INTO `{$table}` ({$column_list}) VALUES ({$placeholders})");

            foreach ($backup_data[$table] as $row) {
                // Ensure the row has all columns, fill with null if missing
                $values = [];
                foreach ($columns as $col) {
                    $values[] = $row[$col] ?? null;
                }
                $stmt->bind_param($types, ...$values);
                if (!$stmt->execute()) {
                    throw new Exception("Failed to insert data into '{$table}': " . $stmt->error);
                }
            }
            $stmt->close();
        }

        $conn->query("SET FOREIGN_KEY_CHECKS=1");
        $conn->commit();

        log_activity($_SESSION['username'], 'System Restore', 'Restored system configuration from backup file: ' . $_FILES['backup_file']['name']);
        echo json_encode(['status' => 'success', 'message' => 'System has been successfully restored from backup. You will be logged out for security reasons.']);

    } catch (Exception $e) {
        $conn->rollback();
        $conn->query("SET FOREIGN_KEY_CHECKS=1");
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Restore failed: ' . $e->getMessage()]);
    } finally {
        $conn->close();
    }
    exit;
}

// --- Backup Status Calendar Logic ---
if ($request_path === '/api/system/backup-status' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json');
    $month = filter_input(INPUT_GET, 'month', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]]);
    $year = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT);

    if (!$month || !$year) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Month and year are required.']);
        exit;
    }

    try {
        $backup_path = get_setting('backup_path', '/var/www/html/config-manager/backups');
        $status_map = [];

        // 1. Scan the filesystem for successful backups. This is the source of truth.
        $backup_files = glob(rtrim($backup_path, '/') . '/config-manager-backup-*.json');
        foreach ($backup_files as $file) {
            if (preg_match('/config-manager-backup-(\d{4}-\d{2}-\d{2})_/', basename($file), $matches)) {
                $backup_date = $matches[1];
                // Only consider files within the requested month and year
                if (date('Y-m', strtotime($backup_date)) === sprintf('%d-%02d', $year, $month)) {
                    // If multiple backups exist for a day, the last one wins, but all are considered successful.
                    $status_map[$backup_date] = ['status' => 'success', 'filename' => basename($file)];
                }
            }
        }

        // 2. Query the database only for errors to supplement the filesystem data.
        $stmt = $conn->prepare("
            SELECT DATE(created_at) as backup_date, details
            FROM activity_log
            WHERE action = 'Automatic Backup Error'
              AND YEAR(created_at) = ?
              AND MONTH(created_at) = ?
            ORDER BY created_at DESC
        ");
        $stmt->bind_param("ii", $year, $month);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        foreach ($result as $row) {
            $error_date = $row['backup_date'];
            // Only mark as error if no successful backup file exists for that day.
            if (!isset($status_map[$error_date])) {
                $status_map[$error_date] = ['status' => 'error', 'filename' => null];
            }
        }

        echo json_encode(['status' => 'success', 'data' => $status_map]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Failed to fetch backup statuses: ' . $e->getMessage()]);
    }
    exit;
}

// --- Download Specific Backup File Logic ---
if (preg_match('/^\/api\/system\/backup\/download/', $request_path) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $filename = filter_input(INPUT_GET, 'file', FILTER_SANITIZE_STRING);

    if (empty($filename) || !preg_match('/^config-manager-backup-[\d_-]+\.json$/', $filename)) {
        http_response_code(400);
        die('Invalid or missing filename.');
    }

    $backup_path = get_setting('backup_path', '/var/www/html/config-manager/backups');
    $full_path = rtrim($backup_path, '/') . '/' . $filename;

    if (!file_exists($full_path) || !is_readable($full_path)) {
        http_response_code(404);
        die('Backup file not found or is not readable.');
    }

    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($full_path));
    header('Pragma: no-cache');
    header('Expires: 0');

    readfile($full_path);
    exit;
}

http_response_code(404);
echo json_encode(['status' => 'error', 'message' => 'Endpoint not found.']);
?>