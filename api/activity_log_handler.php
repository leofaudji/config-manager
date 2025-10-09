<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');
$conn = Database::getInstance()->getConnection();

try {
    $limit_get = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
    $limit = ($limit_get == -1) ? 1000000 : $limit_get;
    if ($limit <= 0) $limit = 50;
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $search = $_GET['search'] ?? '';
    $offset = ($page - 1) * $limit;

    // --- Filtering Logic ---
    // Start by always excluding 'health-agent' logs.
    $where_clauses = ["username != ?"];
    $params = [];
    $types = '';
    $params[] = 'health-agent';
    $types .= 's';

    if (!empty($search)) {
        // Add search conditions. Note the parentheses for correct logical grouping.
        $where_clauses[] = "(username LIKE ? OR action LIKE ? OR details LIKE ?)";
        $search_param = "%{$search}%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $types .= 'sss';
    }

    $where_sql = ' WHERE ' . implode(' AND ', $where_clauses);

    // Get total count
    $stmt_count = $conn->prepare("SELECT COUNT(*) as count FROM activity_log" . $where_sql);
    if (!empty($params)) $stmt_count->bind_param($types, ...$params);
    $stmt_count->execute();
    $total_items = $stmt_count->get_result()->fetch_assoc()['count'];
    $stmt_count->close();

    $total_pages = ($limit_get == -1) ? 1 : ceil($total_items / $limit);

    // Get paginated data
    // We rebuild the params and types for this specific query to include limit and offset.
    $data_params = $params;
    $data_types = $types;
    $data_params[] = $limit;
    $data_params[] = $offset;
    $data_types .= 'ii'; // Use 'i' for integer for limit/offset

    $stmt = $conn->prepare("SELECT * FROM activity_log" . $where_sql . " ORDER BY created_at DESC LIMIT ? OFFSET ?");
    $stmt->bind_param($data_types, ...$data_params);
    $stmt->execute();
    $result = $stmt->get_result();

    $html = '';
    while ($row = $result->fetch_assoc()) {
        $html .= '<tr><td>' . $row['created_at'] . '</td><td>' . htmlspecialchars($row['username']) . '</td><td>' . htmlspecialchars($row['action']) . '</td><td>' . htmlspecialchars($row['details']) . '</td><td>' . htmlspecialchars($row['ip_address']) . '</td></tr>';
    }

    echo json_encode([
        'status' => 'success',
        'html' => $html,
        'total_pages' => $total_pages,
        'current_page' => $page,
        'limit' => $limit_get,
        'info' => "Showing <strong>{$result->num_rows}</strong> of <strong>{$total_items}</strong> activity logs."
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>
