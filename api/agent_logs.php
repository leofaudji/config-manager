<?php
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');

try {
    $conn = Database::getInstance()->getConnection();

    // --- Pagination & Filtering ---
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && (int)$_GET['limit'] > 0 ? (int)$_GET['limit'] : 50;
    $offset = ($page - 1) * $limit;
    $host_id_filter = $_GET['host_id'] ?? '';
    $search_filter = $_GET['search'] ?? '';

    // --- Base Query ---
    $base_sql = "
        FROM activity_log al
        JOIN docker_hosts h ON al.host_id = h.id
    ";

    // --- Filtering Logic ---
    $where_clauses = ["al.username = 'health-agent'"]; // FIX: The source is stored in the 'username' column
    $params = [];
    $types = '';

    if (!empty($host_id_filter)) {
        $where_clauses[] = "al.host_id = ?";
        $params[] = $host_id_filter;
        $types .= 'i';
    }
    if (!empty($search_filter)) {
        $where_clauses[] = "al.details LIKE ?";
        $params[] = "%" . $search_filter . "%";
        $types .= 's';
    } 

    $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);

    // --- Get Total Count ---
    $count_stmt = $conn->prepare("SELECT COUNT(*) as total " . $base_sql . $where_sql);
    if (!empty($params)) $count_stmt->bind_param($types, ...$params);
    $count_stmt->execute();
    $total_items = $count_stmt->get_result()->fetch_assoc()['total'];
    $total_pages = $limit > 0 ? ceil($total_items / $limit) : 1;

    // --- Get Paginated Data ---
    $limit_sql = $limit > 0 ? "LIMIT ? OFFSET ?" : "";
    $data_stmt = $conn->prepare("SELECT al.details as log_content, al.created_at, h.name as host_name " . $base_sql . $where_sql . " ORDER BY al.id DESC " . $limit_sql);
    if ($limit > 0) {
        $params[] = $limit;
        $params[] = $offset;
        $types .= 'ii';
    }
    if (!empty($params)) $data_stmt->bind_param($types, ...$params);
    $data_stmt->execute();
    $data = $data_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $info = "Showing " . count($data) . " of " . $total_items . " log entries.";
    echo json_encode(['status' => 'success', 'data' => $data, 'total_pages' => $total_pages, 'current_page' => $page, 'info' => $info]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}