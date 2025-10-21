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
if ($request_path === '/api/system/backup') {
    try {
        $tables_to_backup = [
            'settings', 'users', 'groups', 'traefik_hosts', 'docker_hosts', 
            'application_stacks', 'middlewares', 'services', 'servers', 'routers', 
            'router_middleware', 'configuration_templates', 'transports'
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

        $filename = 'config-manager-backup-' . date('Y-m-d_H-i-s') . '.json';
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo json_encode($backup_data, JSON_PRETTY_PRINT);
        exit;

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
        'router_middleware', 'routers', 'servers', 'services', 'middlewares', 
        'application_stacks', 'docker_hosts', 'groups', 'traefik_hosts', 
        'users', 'settings', 'configuration_templates', 'transports'
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

http_response_code(404);
echo json_encode(['status' => 'error', 'message' => 'Endpoint not found.']);
?>