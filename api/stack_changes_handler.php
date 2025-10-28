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
    $limit_get = isset($_GET['limit']) ? (int)$_GET['limit'] : 15;
    $limit = ($limit_get == -1) ? 1000000 : $limit_get;
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $search = $_GET['search'] ?? '';
    $start_date = $_GET['start_date'] ?? '';
    $end_date = $_GET['end_date'] ?? '';
    $change_type = $_GET['change_type'] ?? '';
    $offset = ($page - 1) * $limit;

    $where_clauses = [];
    $params = [];
    $types = '';

    if (!empty($search)) {
        $where_clauses[] = "(scl.stack_name LIKE ? OR scl.changed_by LIKE ? OR h.name LIKE ?)";
        $search_param = "%{$search}%";
        $params = [$search_param, $search_param, $search_param];
        $types = 'sss';
    }

    if (!empty($change_type)) {
        $where_clauses[] = "scl.change_type = ?";
        $params[] = $change_type;
        $types .= 's';
    }
    
    if (!empty($start_date)) {
        $where_clauses[] = "scl.created_at >= ?";
        $params[] = $start_date . ' 00:00:00';
        $types .= 's';
    }

    if (!empty($end_date)) {
        $where_clauses[] = "scl.created_at <= ?";
        $params[] = $end_date . ' 23:59:59';
        $types .= 's';
    }

    $where_sql = empty($where_clauses) ? '' : 'WHERE ' . implode(' AND ', $where_clauses);

    // Get total count with filter
    $stmt_count = $conn->prepare("SELECT COUNT(*) as count FROM stack_change_log scl LEFT JOIN docker_hosts h ON scl.host_id = h.id {$where_sql}");
    if (!empty($params)) {
        $stmt_count->bind_param($types, ...$params);
    }
    $stmt_count->execute();
    $total_items = $stmt_count->get_result()->fetch_assoc()['count'];
    $stmt_count->close();

    $total_pages = ($limit_get == -1) ? 1 : ceil($total_items / $limit);

    // Get paginated data
    $sql = "
        SELECT scl.*, h.name as host_name
        FROM stack_change_log scl
        LEFT JOIN docker_hosts h ON scl.host_id = h.id
        {$where_sql}
        ORDER BY scl.created_at DESC
        LIMIT ? OFFSET ?
    ";

    $params[] = $limit;
    $params[] = $offset;
    $types .= 'ii';

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // --- NEW: Group data by host and then by date ---
    $grouped_data = [];
    foreach ($result as $row) {
        $host_name = $row['host_name'] ?? 'Unknown Host';
        $date = date('Y-m-d', strtotime($row['created_at']));

        if (!isset($grouped_data[$host_name])) {
            $grouped_data[$host_name] = [];
        }
        if (!isset($grouped_data[$host_name][$date])) {
            $grouped_data[$host_name][$date] = [];
        }
        $grouped_data[$host_name][$date][] = $row;
    }
    // --- End of grouping logic ---

    echo json_encode([
        'status' => 'success',
        'data' => $grouped_data,
        'total_pages' => $total_pages,
        'current_page' => $page,
        'info' => "Showing " . count($result) . " of " . $total_items . " records."
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();
?>