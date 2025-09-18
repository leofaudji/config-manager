<?php
// Meskipun router sudah memulai sesi, untuk endpoint AJAX, lebih aman untuk memastikannya di sini.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/bootstrap.php';
// Sesi dan otentikasi/otorisasi sudah ditangani oleh Router.

header('Content-Type: application/json');
$conn = Database::getInstance()->getConnection();

$limit_get = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
$limit = ($limit_get == -1) ? 1000000 : $limit_get;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$search = $_GET['search'] ?? '';
$offset = ($page - 1) * $limit;

$response = [
    'html' => '',
    'pagination_html' => '',
    'total_pages' => 0,
    'current_page' => $page,
    'limit' => $limit_get
];

$where_clause = '';
$params = [];
$types = '';

if (!empty($search)) {
    $where_clause = " WHERE username LIKE ? OR action LIKE ?";
    $search_param = "%{$search}%";
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= 'ss';
}

// Get total count
$stmt_count = $conn->prepare("SELECT COUNT(*) as count FROM activity_log" . $where_clause);
if (!empty($search)) {
    $stmt_count->bind_param($types, ...$params);
}
$stmt_count->execute();
$total_items = $stmt_count->get_result()->fetch_assoc()['count'];
$stmt_count->close();

$total_pages = ($limit_get == -1) ? 1 : ceil($total_items / $limit);

// Get data
$stmt = $conn->prepare("SELECT * FROM activity_log" . $where_clause . " ORDER BY created_at DESC LIMIT ? OFFSET ?");
$params[] = $limit;
$params[] = $offset;
$types .= 'ii';
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$html = '';
while ($row = $result->fetch_assoc()) {
    $html .= '<tr><td>' . $row['created_at'] . '</td><td>' . htmlspecialchars($row['username']) . '</td><td>' . htmlspecialchars($row['action']) . '</td><td>' . htmlspecialchars($row['details']) . '</td><td>' . htmlspecialchars($row['ip_address']) . '</td></tr>';
}

$response['html'] = $html;
$response['total_pages'] = $total_pages;
$response['info'] = "Showing <strong>{$result->num_rows}</strong> of <strong>{$total_items}</strong> activity logs.";

$conn->close();
echo json_encode($response);
?>