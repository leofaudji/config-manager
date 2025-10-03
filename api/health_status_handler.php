<?php
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');
$conn = Database::getInstance()->getConnection();

try {
    $limit_get = isset($_GET['limit']) ? (int)$_GET['limit'] : 15;
    $limit = ($limit_get == -1) ? 1000000 : $limit_get;
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $search = $_GET['search'] ?? '';
    $sort = $_GET['sort'] ?? 'status';
    $order = $_GET['order'] ?? 'asc';
    $offset = ($page - 1) * $limit;

    // Validate sort and order
    $valid_sort_columns = ['status', 'name', 'group_name', 'health_check_type', 'last_checked_at'];
    if (!in_array($sort, $valid_sort_columns)) {
        $sort = 'status';
    }
    if (!in_array(strtolower($order), ['asc', 'desc'])) {
        $order = 'asc';
    }

    $where_conditions = [];
    $params = [];
    $types = '';

    // Get the global health check setting
    $global_health_check_enabled = (int)get_setting('health_check_global_enable', 0);

    // --- Build Query for Services ---
    $service_where = "WHERE (s.health_check_enabled = 1 OR ? = 1)";
    $service_params = [$global_health_check_enabled];
    $service_types = 'i';
    if (!empty($search)) {
        $service_where .= " AND s.name LIKE ?";
        $service_params[] = "%{$search}%";
        $service_types .= 's';
    }

    $services_sql = "
        SELECT 
            s.id, s.name, s.health_check_type,
            shs.status, shs.last_log, shs.last_checked_at,
            g.name as group_name,
            'service' as source_type
        FROM services s
        LEFT JOIN service_health_status shs ON s.id = shs.service_id
        LEFT JOIN `groups` g ON s.group_id = g.id
        {$service_where}
    ";

    // --- Build Query for Containers (only if global is enabled) ---
    $containers_sql = "";
    $container_params = [];
    $container_types = '';
    if ($global_health_check_enabled) {
        $container_where = "WHERE 1=1"; // Start with a true condition
        if (!empty($search)) {
            // Note: We can't easily search container name here without another join,
            // so we'll filter in PHP for simplicity.
        }
        $containers_sql = "
            SELECT 
                chs.container_id as id, chs.container_name as name, 'docker' as health_check_type,
                chs.status, chs.last_log, chs.last_checked_at,
                h.name as group_name,
                'container' as source_type
            FROM container_health_status chs
            JOIN docker_hosts h ON chs.host_id = h.id
            {$container_where}
        ";
    }

    // --- Combine Queries ---
    $full_sql = $services_sql;
    $all_params = $service_params;
    $all_types = $service_types;
    if (!empty($containers_sql)) {
        $full_sql = "({$services_sql}) UNION ALL ({$containers_sql})";
        $all_params = array_merge($service_params, $container_params);
        $all_types = $service_types . $container_types;
    }

    // --- Get total count ---
    // We need to get the full result set to count it, as UNION queries are complex for COUNT(*)
    $stmt_all = $conn->prepare($full_sql);
    if (!empty($all_params)) {
        $stmt_all->bind_param($all_types, ...$all_params);
    }
    $stmt_all->execute();
    $all_results = $stmt_all->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_all->close();

    if (!empty($search)) {
        $all_results = array_filter($all_results, fn($item) => stripos($item['name'], $search) !== false);
    }

    $total_items = count($all_results);
    $total_pages = ($limit_get == -1) ? 1 : ceil($total_items / $limit);

    // --- Sort and Paginate in PHP ---
    usort($all_results, function($a, $b) use ($sort, $order) {
        // Custom sorting for 'status' column to prioritize unhealthy/unknown
        if ($sort === 'status') {
            $statusOrder = ['unhealthy' => 1, 'unknown' => 2, 'healthy' => 3];
            $valA = $statusOrder[$a['status'] ?? 'unknown'] ?? 99;
            $valB = $statusOrder[$b['status'] ?? 'unknown'] ?? 99;
            
            if ($valA === $valB) {
                // If statuses are the same, sort by name as a secondary criterion
                return strnatcasecmp($a['name'] ?? '', $b['name'] ?? '');
            }
            
            $cmp = $valA <=> $valB; // Use spaceship operator for numeric comparison
            return ($order === 'asc') ? $cmp : -$cmp;
        }

        // Default sorting for other columns
        $valA = $a[$sort] ?? null;
        $valB = $b[$sort] ?? null;

        // Handle date sorting correctly
        $cmp = ($sort === 'last_checked_at') ? (strtotime($valA ?? 0) <=> strtotime($valB ?? 0)) : strnatcasecmp((string)$valA, (string)$valB);
        
        return ($order === 'asc') ? $cmp : -$cmp;
    });

    $paginated_results = array_slice($all_results, $offset, $limit);

    echo json_encode([
        'status' => 'success',
        'data' => $paginated_results,
        'total_pages' => $total_pages,
        'current_page' => $page,
        'limit' => $limit_get,
        'info' => "Showing <strong>" . count($paginated_results) . "</strong> of <strong>{$total_items}</strong> monitored items."
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
$conn->close();
?>