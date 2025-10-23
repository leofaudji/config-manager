<?php
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
    exit;
}

$conn = Database::getInstance()->getConnection();

try {
    // --- Pagination & Filtering ---
    $limit_get = isset($_GET['limit']) ? (int)$_GET['limit'] : 25;
    $limit = ($limit_get == -1) ? 1000000 : $limit_get;
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $offset = ($page - 1) * $limit;

    $search = trim($_GET['search'] ?? '');
    $host_id = filter_input(INPUT_GET, 'host_id', FILTER_VALIDATE_INT);
    $priority = trim($_GET['priority'] ?? '');

    // --- Build Query ---
    $where_clauses = [];
    $params = [];
    $types = '';

    if (!empty($search)) {
        $where_clauses[] = "(se.rule LIKE ? OR se.output LIKE ?)";
        $search_param = "%{$search}%";
        $params[] = $search_param;
        $params[] = $search_param;
        $types .= 'ss';
    }

    if ($host_id) {
        $where_clauses[] = "se.host_id = ?";
        $params[] = $host_id;
        $types .= 'i';
    }

    if (!empty($priority)) {
        $where_clauses[] = "se.priority = ?";
        $params[] = $priority;
        $types .= 's';
    }

    $where_sql = empty($where_clauses) ? '' : 'WHERE ' . implode(' AND ', $where_clauses);

    // Get total count with filter
    $stmt_count = $conn->prepare("SELECT COUNT(*) as count FROM security_events se {$where_sql}");
    if (!empty($params)) $stmt_count->bind_param($types, ...$params);
    $stmt_count->execute();
    $total_items = $stmt_count->get_result()->fetch_assoc()['count'];
    $total_pages = ($limit_get == -1) ? 1 : ceil($total_items / $limit);
    $stmt_count->close();

    // Get paginated data
    $stmt = $conn->prepare("SELECT se.*, h.name as host_name FROM security_events se LEFT JOIN docker_hosts h ON se.host_id = h.id {$where_sql} ORDER BY se.event_time DESC LIMIT ? OFFSET ?");
    $params[] = $limit;
    $params[] = $offset;
    $types .= 'ii';
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    echo json_encode([
        'status' => 'success',
        'data' => $data,
        'total_pages' => $total_pages,
        'current_page' => $page,
        'info' => "Showing " . count($data) . " of " . $total_items . " events."
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();
?>