<?php
require_once __DIR__ . '/../includes/bootstrap.php';

// --- FIX: Change content type for streaming ---
// We will stream the raw log content directly for efficiency.
// The frontend will handle this raw text response.
header('Content-Type: text/plain; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-cache');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    // Send a plain text error for consistency
    echo "ERROR: Forbidden";
    exit;
}

ob_implicit_flush(true); // Ensure output is sent immediately

$log_id = $_GET['id'] ?? null;
if (!$log_id) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Log ID is required.']);
    exit;
}

$conn = Database::getInstance()->getConnection();

try {
    // Find the log file path from the database
    $stmt = $conn->prepare("SELECT log_file_path, pid FROM activity_log WHERE id = ?");
    $stmt->bind_param("i", $log_id);
    $stmt->execute();
    $log_data = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $log_file_path = $log_data['log_file_path'] ?? null;
    $pid = $log_data['pid'] ?? null;

    // --- FIX: Send process status as a custom header ---
    // This allows the frontend to get metadata without interfering with the raw log stream.
    if ($pid) {
        // Check if a process with this PID exists. `posix_kill` with signal 0 is a standard way to do this.
        if (function_exists('posix_kill') && posix_kill($pid, 0)) {
            header('X-Process-Status: running');
        } else {
            header('X-Process-Status: finished');
        }
    } else {
        header('X-Process-Status: unknown');
    }

    if (!$log_file_path || !file_exists($log_file_path) || !is_readable($log_file_path)) {
        throw new Exception("Log file not found, not readable, or the process has not started yet.");
    }

    // --- FIX: Stream the file instead of loading it all into memory ---
    // This is much more memory-efficient for large log files.
    readfile($log_file_path);

} catch (Exception $e) {
    http_response_code(500);
    echo "ERROR: " . $e->getMessage();
}

$conn->close();
?>