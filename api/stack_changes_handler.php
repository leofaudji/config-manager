<?php
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');
$conn = Database::getInstance()->getConnection();

try {
    $limit_get = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
    $limit = ($limit_get == -1) ? 1000000 : $limit_get;
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $offset = ($page - 1) * $limit;

    $where_clauses = [];
    $params = [];
    $types = '';

    if (!empty($_GET['start_date'])) {
        $where_clauses[] = "sc.created_at >= ?";
        // Add time to include the whole day
        $params[] = $_GET['start_date'] . ' 00:00:00';
        $types .= 's';
    }

    if (!empty($_GET['end_date'])) {
        $where_clauses[] = "sc.created_at <= ?";
        // Add time to include the whole day
        $params[] = $_GET['end_date'] . ' 23:59:59';
        $types .= 's';
    }

    $where_sql = '';
    if (!empty($where_clauses)) {
        $where_sql = ' WHERE ' . implode(' AND ', $where_clauses);
    }

    // Get total count for pagination
    $count_sql = "SELECT COUNT(*) as count FROM stack_change_log sc" . $where_sql;
    $stmt_count = $conn->prepare($count_sql);
    if (!empty($params)) {
        $stmt_count->bind_param($types, ...$params);
    }
    $stmt_count->execute();
    $total_items = $stmt_count->get_result()->fetch_assoc()['count'];
    $total_pages = ($limit_get == -1) ? 1 : ceil($total_items / $limit);

    $sql = "
        SELECT 
            sc.stack_name, 
            sc.change_type, 
            sc.details, 
            sc.changed_by, 
            sc.created_at,
            h.name as host_name,
            DATE(sc.created_at) as change_date
        FROM stack_change_log sc
        JOIN docker_hosts h ON sc.host_id = h.id
    " . $where_sql;

    $sql .= "
        ORDER BY h.name ASC, sc.created_at DESC
        LIMIT ? OFFSET ?
    ";

    // Add limit and offset to params
    $params[] = $limit;
    $params[] = $offset;
    $types .= 'ii';

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    $grouped_data = [];
    while ($row = $result->fetch_assoc()) {
        $host_name = $row['host_name'];
        $change_date = date("l, F j, Y", strtotime($row['change_date']));
        
        $grouped_data[$host_name][$change_date][] = $row;
    }

    echo json_encode([
        'status' => 'success', 
        'data' => $grouped_data,
        'total_pages' => $total_pages,
        'current_page' => $page,
        'limit' => $limit_get,
        'info' => "Showing <strong>" . $result->num_rows . "</strong> of <strong>{$total_items}</strong> changes."
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to fetch stack changes: ' . $e->getMessage()]);
}

$conn->close();
?>