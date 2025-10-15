<?php
// Meskipun router sudah memulai sesi, untuk endpoint AJAX, lebih aman untuk memastikannya di sini.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'includes/bootstrap.php';
require_once 'includes/DockerClient.php';
// Sesi dan otentikasi/otorisasi sudah ditangani oleh Router.

header('Content-Type: application/json');
$conn = Database::getInstance()->getConnection();

$type = $_GET['type'] ?? 'routers';
$limit_get = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
$limit = ($limit_get == -1) ? 1000000 : $limit_get;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$search = $_GET['search'] ?? '';
$group_id = $_GET['group_id'] ?? '';
$show_archived = isset($_GET['show_archived']) && $_GET['show_archived'] === 'true';
$sort = $_GET['sort'] ?? 'name';
$order = $_GET['order'] ?? 'asc';
$offset = ($page - 1) * $limit;

$response = [
    'html' => '',
    'pagination_html' => '',
    'total_pages' => 0,
    'current_page' => $page,
    'limit' => $limit_get
];

$is_admin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';

function format_uptime(int $seconds): string {
    if ($seconds <= 0) {
        return 'N/A';
    }

    $days = floor($seconds / 86400);
    $seconds %= 86400;
    $hours = floor($seconds / 3600);
    $seconds %= 3600;
    $minutes = floor($seconds / 60);

    $parts = [];
    if ($days > 0) $parts[] = "{$days}d";
    if ($hours > 0) $parts[] = "{$hours}h";
    if ($minutes > 0) $parts[] = "{$minutes}m";

    return empty($parts) ? '< 1m' : implode(' ', $parts);
}

function getHostSwarmStatus(array $host): string {
    try {
        $dockerClient = new DockerClient($host);
        $info = $dockerClient->getInfo();
        if (isset($info['Swarm']['LocalNodeState']) && $info['Swarm']['LocalNodeState'] !== 'inactive') {
            return (isset($info['Swarm']['ControlAvailable']) && $info['Swarm']['ControlAvailable']) ? 'manager' : 'worker';
        }
        return 'standalone';
    } catch (Exception $e) {
        return 'unreachable';
    }
}


if ($type === 'routers') {
    $where_conditions = [];
    $params = [];
    $types = '';

    if (!empty($search)) {
        $where_conditions[] = "r.name LIKE ?";
        $params[] = "%{$search}%";
        $types .= 's';
    }

    if (!empty($group_id)) {
        $where_conditions[] = "r.group_id = ?";
        $params[] = $group_id;
        $types .= 'i';
    }

    $where_clause = '';
    if (!empty($where_conditions)) {
        $where_clause = " WHERE " . implode(' AND ', $where_conditions);
    }

    // Get total count
    $stmt_count = $conn->prepare("SELECT COUNT(*) as count FROM routers r" . $where_clause);
    if (!empty($params)) {
        $stmt_count->bind_param($types, ...$params);
    }
    $stmt_count->execute();
    $total_items = $stmt_count->get_result()->fetch_assoc()['count'];
    $stmt_count->close();

    $total_pages = ($limit_get == -1) ? 1 : ceil($total_items / $limit);

    // Get data
    $sql = "SELECT r.*, s.id as service_id, g.name as group_name, GROUP_CONCAT(m.name ORDER BY rm.priority) as middleware_names,
                   GROUP_CONCAT(m.config_json SEPARATOR '|||') as middleware_configs
            FROM routers r 
            LEFT JOIN `groups` g ON r.group_id = g.id
            LEFT JOIN router_middleware rm ON r.id = rm.router_id
            LEFT JOIN services s ON r.service_name = s.name
            LEFT JOIN middlewares m ON rm.middleware_id = m.id"
            . $where_clause .
            " GROUP BY r.id ORDER BY r.updated_at DESC LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    $params[] = $limit;
    $params[] = $offset;
    $types .= 'ii';
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    $html = '';
    while ($row = $result->fetch_assoc()) {
        $html .= '<tr id="router-' . $row['id'] . '">';
        $html .= '<td><input class="form-check-input router-checkbox" type="checkbox" value="' . $row['id'] . '"></td>';
        
        $tls_icon = '';
        if (!empty($row['tls'])) {
            $tls_icon = ' <i class="bi bi-shield-lock-fill text-success" title="TLS Enabled: ' . htmlspecialchars($row['cert_resolver']) . '"></i>';
        }
        $html .= '<td>' . htmlspecialchars($row['name']) . $tls_icon . '</td>';
        $html .= '<td>';
        $html .= '<div class="d-flex justify-content-between align-items-center">';
        $html .= '<code class="router-rule">' . htmlspecialchars($row['rule']) . '</code>';
        $html .= '<button class="btn btn-sm btn-outline-secondary copy-btn ms-2" data-clipboard-text="' . htmlspecialchars($row['rule'], ENT_QUOTES) . '" title="Copy Rule"><i class="bi bi-clipboard"></i></button>';
        $html .= '</div></td>';
        $html .= '<td>' . htmlspecialchars($row['entry_points']) . '</td>';
        $middlewares_html = '';
        if (!empty($row['middleware_names'])) {
            $middleware_names = explode(',', $row['middleware_names']);
            $middleware_configs = explode('|||', $row['middleware_configs'] ?? '');
            foreach ($middleware_names as $index => $mw_name) {
                $mw_config_raw = $middleware_configs[$index] ?? '{}';
                // Pretty-print the JSON for a more readable tooltip
                $mw_config_pretty = json_encode(json_decode($mw_config_raw), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                $middlewares_html .= '<span class="badge bg-info me-1" data-bs-toggle="tooltip" title="' . htmlspecialchars($mw_config_pretty) . '">' . htmlspecialchars($mw_name) . '</span>';
            }
        }
        $html .= '<td>' . $middlewares_html . '</td>';
        $html .= '<td><span class="badge text-bg-primary">' . htmlspecialchars($row['service_name']) . '</span></td>';
        $html .= '<td><span class="badge text-bg-secondary">' . htmlspecialchars($row['group_name'] ?? 'N/A') . '</span></td>';
        $html .= '<td><small class="text-muted">' . htmlspecialchars($row['updated_at'] ?? 'N/A') . '</small></td>';
        if ($is_admin) {
            $html .= '<td class="table-actions text-end">';
            $html .= '<div class="btn-group" role="group">';
            $html .= '<a href="' . base_url('/traffic-flow?service_id=' . $row['service_id']) . '" class="btn btn-sm btn-outline-info" target="_blank" title="View Traffic Flow">
                        <i class="bi bi-diagram-3"></i>
                      </a>';
            $html .= '<a href="' . base_url('/routers/' . $row['id'] . '/clone') . '" class="btn btn-info btn-sm" data-bs-toggle="tooltip" title="Clone Router"><i class="bi bi-copy"></i></a>';
            $html .= '<a href="' . base_url('/routers/' . $row['id'] . '/edit') . '" class="btn btn-warning btn-sm" data-bs-toggle="tooltip" title="Edit Router"><i class="bi bi-pencil-square"></i></a>';
            $html .= '<button class="btn btn-danger btn-sm delete-btn" data-id="' . $row['id'] . '" data-url="' . base_url('/routers/' . $row['id'] . '/delete') . '" data-type="routers" data-confirm-message="Yakin ingin menghapus router ini?"><i class="bi bi-trash"></i></button>';
            $html .= '</div>';
            $html .= '</td>';
        }
        $html .= '</tr>';
    }

    $response['html'] = $html;
    $response['total_pages'] = $total_pages;
    $response['info'] = "Showing <strong>{$result->num_rows}</strong> of <strong>{$total_items}</strong> routers.";

} elseif ($type === 'services') {
    $where_conditions = [];
    $params = [];
    $types = '';

    if (!empty($search)) {
        $where_conditions[] = "s.name LIKE ?";
        $params[] = "%{$search}%";
        $types .= 's';
    }

    if (!empty($group_id)) {
        $where_conditions[] = "s.group_id = ?";
        $params[] = $group_id;
        $types .= 'i';
    }

    $where_clause = '';
    if (!empty($where_conditions)) {
        $where_clause = " WHERE " . implode(' AND ', $where_conditions);
    }

    // Get total count
    $stmt_count = $conn->prepare("SELECT COUNT(*) as count FROM services s" . $where_clause);
    if (!empty($params)) {
        $stmt_count->bind_param($types, ...$params);
    }
    $stmt_count->execute();
    $total_items = $stmt_count->get_result()->fetch_assoc()['count'];
    $stmt_count->close();

    $total_pages = ($limit_get == -1) ? 1 : ceil($total_items / $limit);

    // Langkah 1: Dapatkan hanya ID dari service yang dipaginasi. Ini menjaga paginasi tetap akurat.
    $stmt_service_ids = $conn->prepare("SELECT s.id FROM services s" . $where_clause . " ORDER BY s.name ASC LIMIT ? OFFSET ?");
    $params[] = $limit;
    $params[] = $offset;
    $types .= 'ii';
    $stmt_service_ids->bind_param($types, ...$params);
    $stmt_service_ids->execute();
    $service_ids_result = $stmt_service_ids->get_result();
    $paginated_service_ids = [];
    while ($row = $service_ids_result->fetch_assoc()) {
        $paginated_service_ids[] = $row['id'];
    }
    $stmt_service_ids->close();

    $services_map = [];
    if (!empty($paginated_service_ids)) {
        // Langkah 2: Ambil semua data service dan server terkait dalam satu query menggunakan LEFT JOIN.
        $in_clause = implode(',', array_fill(0, count($paginated_service_ids), '?'));
        $types = str_repeat('i', count($paginated_service_ids));
        $sql = "SELECT s.id, s.name, s.pass_host_header, s.updated_at, s.load_balancer_method, g.name as group_name, sv.id as server_id, sv.url as server_url 
                FROM services s 
                LEFT JOIN servers sv ON s.id = sv.service_id 
                LEFT JOIN `groups` g ON s.group_id = g.id
                WHERE s.id IN ($in_clause) 
                ORDER BY s.name ASC, sv.url ASC";
        
        $stmt_main = $conn->prepare($sql);
        $stmt_main->bind_param($types, ...$paginated_service_ids);
        $stmt_main->execute();
        $result = $stmt_main->get_result();

        // Langkah 3: Proses hasil query gabungan dan bangun kembali struktur array di PHP.
        while ($row = $result->fetch_assoc()) {
            if (!isset($services_map[$row['id']])) {
                $services_map[$row['id']] = ['id' => $row['id'], 'name' => $row['name'], 'pass_host_header' => $row['pass_host_header'], 'updated_at' => $row['updated_at'], 'load_balancer_method' => $row['load_balancer_method'], 'group_name' => $row['group_name'], 'servers' => [], 'routers' => []];
            }
            if ($row['server_id'] !== null) {
                $services_map[$row['id']]['servers'][] = ['id' => $row['server_id'], 'url' => $row['server_url']];
            }
        }
        $stmt_main->close();
    }

    // NEW: Fetch associated routers for the services on this page
    if (!empty($services_map)) {
        $service_name_to_id_map = [];
        foreach ($services_map as $id => $service) {
            $service_name_to_id_map[$service['name']] = $id;
        }

        $service_names = array_keys($service_name_to_id_map);
        if (!empty($service_names)) {
            $in_clause_names = implode(',', array_fill(0, count($service_names), '?'));
            $types_names = str_repeat('s', count($service_names));
            $sql_routers = "SELECT name, service_name FROM routers WHERE service_name IN ($in_clause_names)";
            $stmt_routers = $conn->prepare($sql_routers);
            $stmt_routers->bind_param($types_names, ...$service_names);
            $stmt_routers->execute();
            $routers_result = $stmt_routers->get_result();
            while ($router = $routers_result->fetch_assoc()) {
                $service_id = $service_name_to_id_map[$router['service_name']] ?? null;
                if ($service_id && isset($services_map[$service_id])) {
                    $services_map[$service_id]['routers'][] = $router['name'];
                }
            }
            $stmt_routers->close();
        }
    }

    $html = '';
    foreach ($services_map as $service) {
        $html .= '<div class="service-block border rounded p-3 mb-3" id="service-' . $service['id'] . '">';
        $html .= '<div class="d-flex justify-content-between align-items-start mb-2">';
        $html .= '<div>'; // Wrapper for title, group, badge, and subtitle
        $span = '';
        if (isset($service['load_balancer_method']) && $service['load_balancer_method'] !== 'roundRobin') {
            $span = ' <span class="badge bg-info fw-normal">' . htmlspecialchars($service['load_balancer_method']) . '</span>';
        }
        $html .= '<h5 class="mb-1"><span class="service-status-indicator me-2" data-bs-toggle="tooltip" data-service-name="' . htmlspecialchars($service['name']) . '" title="Checking status..."><i class="bi bi-circle-fill text-secondary"></i></span>' . htmlspecialchars($service['name']) . $span;
        if (!empty($service['group_name'])) {
            $html .= ' <span class="badge bg-secondary fw-normal">' . htmlspecialchars($service['group_name']) . '</span>';
        }
        $html .= '</h5>';
        $html .= '<small class="text-muted ms-4">Updated: ' . htmlspecialchars($service['updated_at'] ?? 'N/A') . '</small>';
        $html .= '</div>';
        if ($is_admin) {
            $html .= '<div class="ms-2 flex-shrink-0 btn-group">';
            $html .= '<a href="' . base_url('/services/' . $service['id'] . '/clone') . '" class="btn btn-outline-info btn-sm" data-bs-toggle="tooltip" title="Clone Service"><i class="bi bi-copy"></i></a> ';
            $html .= '<a href="' . base_url('/services/' . $service['id'] . '/edit') . '" class="btn btn-outline-warning btn-sm" data-bs-toggle="tooltip" title="Edit Service"><i class="bi bi-pencil"></i></a> ';
            $html .= '<button class="btn btn-outline-danger btn-sm delete-btn" data-id="' . $service['id'] . '" data-url="' . base_url('/services/' . $service['id'] . '/delete') . '" data-type="services" data-confirm-message="Yakin ingin menghapus service ini? Semua server di dalamnya juga akan terhapus."><i class="bi bi-trash"></i></button></div>';
        }
        $html .= '</div>';
        if ($is_admin) {
            $html .= '<a href="' . base_url('/servers/new?service_id=' . $service['id']) . '" class="btn btn-primary btn-sm mb-2"><i class="bi bi-plus-circle"></i> Tambah Server</a>';
        }
        $html .= '<table class="table table-bordered table-sm mb-0"><thead class="table-light"><tr><th>Server URL</th><th class="table-actions">Actions</th></tr></thead><tbody>';
        foreach ($service['servers'] as $server) {
            $html .= '<tr id="server-' . $server['id'] . '"><td><code>' . htmlspecialchars($server['url']) . '</code></td>';
            $html .= '<td class="table-actions">';
            if ($is_admin) {
                $html .= '<a href="' . base_url('/servers/' . $server['id'] . '/edit?service_id=' . $service['id']) . '" class="btn btn-warning btn-sm" data-bs-toggle="tooltip" title="Edit Server"><i class="bi bi-pencil-square"></i></a> <button class="btn btn-danger btn-sm delete-btn" data-id="' . $server['id'] . '" data-url="' . base_url('/servers/' . $server['id'] . '/delete') . '" data-type="services" data-confirm-message="Yakin ingin menghapus server ini?"><i class="bi bi-trash"></i></button>';
            }
            $html .= '</td></tr>';
        }
        $html .= '</tbody></table>';

        // Display associated routers
        $html .= '<div class="mt-3 pt-2 border-top">';
        $html .= '<h6 class="small text-muted mb-1">Used by Routers:</h6>';
        if (!empty($service['routers'])) {
            foreach ($service['routers'] as $router_name) {
                $html .= '<span class="badge bg-primary me-1">' . htmlspecialchars($router_name) . '</span>';
            }
        } else {
            $html .= '<span class="small text-muted fst-italic">Not used by any router.</span>';
        }
        $html .= '</div>';

        $html .= '</div>'; // End of service-block
    }

    $response['html'] = $html;
    $response['total_pages'] = $total_pages;
    $response['info'] = "Showing <strong>" . count($services_map) . "</strong> of <strong>{$total_items}</strong> services.";
}

elseif ($type === 'history') {
    $where_clause = '';
    $where_conditions = [];
    $where_params = []; // Parameters for the WHERE clause
    $where_types = '';  // Data types for the WHERE clause

    if (!$show_archived) {
        $where_conditions[] = "status IN ('draft', 'active')";
    }

    if (!empty($search)) {
        $where_conditions[] = "generated_by LIKE ?";
        $where_params[] = "%{$search}%";
        $where_types .= 's';
    }

    if (!empty($where_conditions)) {
        $where_clause = " WHERE " . implode(' AND ', $where_conditions);
    }

    // Get total count
    $stmt_count = $conn->prepare("SELECT COUNT(*) as count FROM config_history" . $where_clause);
    if (!empty($where_params)) {
        $stmt_count->bind_param($where_types, ...$where_params);
    }
    $stmt_count->execute();
    $total_items = $stmt_count->get_result()->fetch_assoc()['count'];
    $stmt_count->close();

    $total_pages = ($limit_get == -1) ? 1 : ceil($total_items / $limit);

    // Get data
    $stmt = $conn->prepare("SELECT id, generated_by, created_at, status FROM config_history" . $where_clause . " ORDER BY created_at DESC LIMIT ? OFFSET ?");
    
    // Combine WHERE clause params with pagination params
    $final_params = $where_params;
    $final_params[] = $limit;
    $final_params[] = $offset;
    $final_types = $where_types . 'ii';

    $stmt->bind_param($final_types, ...$final_params);
    $stmt->execute();
    $result = $stmt->get_result();

    $html = '';
    while ($row = $result->fetch_assoc()) {
        $status_badge_class = 'secondary';
        if ($row['status'] === 'active') $status_badge_class = 'success';
        if ($row['status'] === 'archived') $status_badge_class = 'light text-dark';

        $html .= '<tr>';
        $html .= '<td><input class="form-check-input history-checkbox" type="checkbox" value="' . $row['id'] . '"></td>';
        $html .= '<td>' . $row['id'] . '</td>';
        $html .= '<td>' . $row['created_at'] . '</td>';
        $html .= '<td>' . htmlspecialchars($row['generated_by']) . '</td>';
        $html .= '<td><span class="badge text-bg-' . $status_badge_class . '">' . ucfirst($row['status']) . '</span></td>';
        $html .= '<td class="text-end">';
        $html .= '<button class="btn btn-sm btn-outline-info view-history-btn" data-id="' . $row['id'] . '" data-bs-toggle="modal" data-bs-target="#viewHistoryModal">View</button>';

        if ($row['status'] === 'draft') {
            $html .= '<button class="btn btn-sm btn-success ms-1 deploy-btn" data-id="' . $row['id'] . '">Deploy</button>';
            $html .= '<button class="btn btn-sm btn-outline-secondary ms-1 archive-btn" data-id="' . $row['id'] . '" data-status="1">Archive</button>';
        } elseif ($row['status'] === 'archived') {
            $html .= '<button class="btn btn-sm btn-outline-warning ms-1 archive-btn" data-id="' . $row['id'] . '" data-status="0">Unarchive</button>';
        }

        $html .= '<a href="' . base_url('/history/' . $row['id'] . '/download') . '" class="btn btn-sm btn-outline-primary ms-1" data-bs-toggle="tooltip" title="Download this version">Download</a>';
        $html .= '</td>';
        $html .= '</tr>';
    }

    $response['html'] = $html;
    $response['total_pages'] = $total_pages;
    $response['info'] = "Showing <strong>{$result->num_rows}</strong> of <strong>{$total_items}</strong> history records.";
}
elseif ($type === 'users') {
    $where_clause = '';
    $params = [];
    $types = '';

    if (!empty($search)) {
        $where_clause = " WHERE username LIKE ?";
        $params[] = "%{$search}%";
        $types .= 's';
    }

    // Get total count
    $stmt_count = $conn->prepare("SELECT COUNT(*) as count FROM users" . $where_clause);
    if (!empty($search)) {
        $stmt_count->bind_param($types, ...$params);
    }
    $stmt_count->execute();
    $total_items = $stmt_count->get_result()->fetch_assoc()['count'];
    $stmt_count->close();

    $total_pages = ($limit_get == -1) ? 1 : ceil($total_items / $limit);

    // Get data
    $stmt = $conn->prepare("SELECT id, username, role, created_at FROM users" . $where_clause . " ORDER BY username ASC LIMIT ? OFFSET ?");
    $params[] = $limit;
    $params[] = $offset;
    $types .= 'ii';
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    $html = '';
    while ($user = $result->fetch_assoc()) {
        $html .= '<tr>';
        $html .= '<td>' . $user['id'] . '</td>';
        $html .= '<td>' . htmlspecialchars($user['username']) . '</td>';
        $html .= '<td><span class="badge text-bg-' . ($user['role'] == 'admin' ? 'primary' : 'secondary') . '">' . htmlspecialchars(ucfirst($user['role'])) . '</span></td>';
        $html .= '<td>' . $user['created_at'] . '</td>';
        $html .= '<td class="text-end">';
        $html .= '<a href="' . base_url('/users/' . $user['id'] . '/edit') . '" class="btn btn-sm btn-outline-warning" data-bs-toggle="tooltip" title="Edit User"><i class="bi bi-pencil-square"></i></a> ';
        $html .= '<a href="' . base_url('/users/' . $user['id'] . '/change-password') . '" class="btn btn-sm btn-outline-secondary ms-1">Change Password</a> ';
        if ($_SESSION['username'] !== $user['username']) {
            $html .= '<button class="btn btn-sm btn-outline-danger delete-btn ms-1" data-id="' . $user['id'] . '" data-url="' . base_url('/users/' . $user['id'] . '/delete') . '" data-type="users" data-confirm-message="Are you sure you want to delete user \'' . htmlspecialchars($user['username']) . '\'?">Delete</button>';
        }
        $html .= '</td></tr>';
    }

    $response['html'] = $html;
    $response['total_pages'] = $total_pages;
    $response['info'] = "Showing <strong>{$result->num_rows}</strong> of <strong>{$total_items}</strong> users.";
}
elseif ($type === 'middlewares') {
    $where_clause = '';
    $params = [];
    $types = '';

    if (!empty($search)) {
        $where_clause = " WHERE name LIKE ?";
        $params[] = "%{$search}%";
        $types .= 's';
    }

    // Get total count
    $stmt_count = $conn->prepare("SELECT COUNT(*) as count FROM middlewares" . $where_clause);
    if (!empty($search)) {
        $stmt_count->bind_param($types, ...$params);
    }
    $stmt_count->execute();
    $total_items = $stmt_count->get_result()->fetch_assoc()['count'];
    $stmt_count->close();

    $total_pages = ($limit_get == -1) ? 1 : ceil($total_items / $limit);

    // Get data
    $stmt = $conn->prepare("SELECT * FROM middlewares" . $where_clause . " ORDER BY name ASC LIMIT ? OFFSET ?");
    $params[] = $limit;
    $params[] = $offset;
    $types .= 'ii';
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    $html = '';
    while ($mw = $result->fetch_assoc()) {
        $html .= '<tr>';
        $html .= '<td>' . htmlspecialchars($mw['name']) . '</td>';
        $html .= '<td><span class="badge bg-info">' . htmlspecialchars($mw['type']) . '</span></td>';
        $html .= '<td>' . htmlspecialchars($mw['description'] ?? '') . '</td>';
        $html .= '<td><small class="text-muted">' . htmlspecialchars($mw['updated_at']) . '</small></td>';
        $html .= '<td class="text-end">';
        $html .= '<button class="btn btn-sm btn-outline-warning edit-middleware-btn" 
                        data-bs-toggle="modal" 
                        data-bs-target="#middlewareModal" 
                        data-id="' . $mw['id'] . '" 
                        data-name="' . htmlspecialchars($mw['name']) . '"
                        data-type="' . htmlspecialchars($mw['type']) . '"
                        data-description="' . htmlspecialchars($mw['description'] ?? '') . '"
                        data-config_json="' . htmlspecialchars($mw['config_json']) . '"
                        data-bs-toggle="tooltip" title="Edit Middleware"><i class="bi bi-pencil-square"></i></button> ';
        $html .= '<button class="btn btn-sm btn-outline-danger delete-btn" data-id="' . $mw['id'] . '" data-url="' . base_url('/middlewares/' . $mw['id'] . '/delete') . '" data-type="middlewares" data-confirm-message="Are you sure you want to delete middleware \'' . htmlspecialchars($mw['name']) . '\'?">Delete</button>';
        $html .= '</td></tr>';
    }

    $response['html'] = $html;
    $response['total_pages'] = $total_pages;
    $response['info'] = "Showing <strong>{$result->num_rows}</strong> of <strong>{$total_items}</strong> middlewares.";
}
elseif ($type === 'templates') {
    // Get total count
    $total_items = $conn->query("SELECT COUNT(*) as count FROM `configuration_templates`")->fetch_assoc()['count'];
    $total_pages = ($limit_get == -1) ? 1 : ceil($total_items / $limit);

    // Get data
    $stmt = $conn->prepare("SELECT * FROM `configuration_templates` ORDER BY name ASC LIMIT ? OFFSET ?");
    $stmt->bind_param("ii", $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();

    $html = '';
    while ($template = $result->fetch_assoc()) {
        $html .= '<tr>';
        $html .= '<td>' . htmlspecialchars($template['name']) . '</td>';
        $html .= '<td>' . htmlspecialchars($template['description'] ?? '') . '</td>';
        $html .= '<td>' . $template['created_at'] . '</td>';
        $html .= '<td class="text-end">';
        $html .= '<button class="btn btn-sm btn-outline-warning edit-template-btn" 
                        data-bs-toggle="modal" 
                        data-bs-target="#templateModal" 
                        data-id="' . $template['id'] . '" 
                        data-name="' . htmlspecialchars($template['name']) . '"
                        data-description="' . htmlspecialchars($template['description'] ?? '') . '"
                        data-config_data="' . htmlspecialchars($template['config_data']) . '"
                        data-bs-toggle="tooltip" title="Edit Template"><i class="bi bi-pencil-square"></i></button> ';
        $html .= '<button class="btn btn-sm btn-outline-danger delete-btn" data-id="' . $template['id'] . '" data-url="' . base_url('/templates/' . $template['id'] . '/delete') . '" data-type="templates" data-confirm-message="Are you sure you want to delete template \'' . htmlspecialchars($template['name']) . '\'?"><i class="bi bi-trash"></i></button>';
        $html .= '</td></tr>';
    }

    $response['html'] = $html;
    $response['total_pages'] = $total_pages;
    $response['info'] = "Showing <strong>{$result->num_rows}</strong> of <strong>{$total_items}</strong> templates.";
}
elseif ($type === 'stacks') {
    // Get total count
    $total_items = $conn->query("SELECT COUNT(*) as count FROM `application_stacks`")->fetch_assoc()['count'];
    $total_pages = ($limit_get == -1) ? 1 : ceil($total_items / $limit);

    // Get data
    $stmt = $conn->prepare("SELECT * FROM `application_stacks` ORDER BY stack_name ASC LIMIT ? OFFSET ?");
    $stmt->bind_param("ii", $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();

    $html = '';
    while ($stack = $result->fetch_assoc()) {
        $html .= '<tr>';
        $html .= '<td><a href="' . base_url('/stacks/' . $stack['id'] . '/edit') . '">' . htmlspecialchars($stack['stack_name']) . '</a></td>';
        $html .= '<td>' . htmlspecialchars($stack['description'] ?? '') . '</td>';
        $html .= '<td>' . $stack['created_at'] . '</td>';
        $html .= '<td class="text-end">';
        $html .= '<a href="' . base_url('/stacks/' . $stack['id'] . '/edit') . '" class="btn btn-sm btn-outline-warning" data-bs-toggle="tooltip" title="Edit Stack"><i class="bi bi-pencil-square"></i></a> ';
        $html .= '<button class="btn btn-sm btn-outline-danger delete-btn" data-id="' . $stack['id'] . '" data-url="' . base_url('/stacks/' . $stack['id'] . '/delete') . '" data-type="stacks" data-confirm-message="Are you sure you want to delete stack \'' . htmlspecialchars($stack['stack_name']) . '\'?"><i class="bi bi-trash"></i></button>';
        $html .= '</td></tr>';
    }

    $response['html'] = $html;
    $response['total_pages'] = $total_pages;
    $response['info'] = "Showing <strong>{$result->num_rows}</strong> of <strong>{$total_items}</strong> stacks.";
}
elseif ($type === 'hosts') {
    $group_by = $_GET['group_by'] ?? '';

    $where_clause = '';
    $params = [];
    $types = '';

    if ($group_by === 'standalone' || $group_by === 'manager' || $group_by === 'worker') {
        $where_clause = ' WHERE swarm_status = ?';
        $params[] = $group_by;
        $types .= 's';
    } elseif ($group_by === 'registry') {
        $where_clause = " WHERE registry_url IS NOT NULL AND registry_url != ''";
    }

    // Get total count
    $stmt_count = $conn->prepare("SELECT COUNT(*) as count FROM `docker_hosts`" . $where_clause);
    if (!empty($params)) {
        $stmt_count->bind_param($types, ...$params);
    }
    $stmt_count->execute();
    $total_items = $stmt_count->get_result()->fetch_assoc()['count'];
    $stmt_count->close();

    $total_pages = ($limit_get == -1) ? 1 : ceil($total_items / $limit);

    // Get data
    $stmt = $conn->prepare("SELECT * FROM `docker_hosts`" . $where_clause . " ORDER BY name ASC LIMIT ? OFFSET ?");
    $final_params = array_merge($params, [$limit, $offset]);
    $stmt->bind_param($types . 'ii', ...$final_params);
    $stmt->execute();
    $result = $stmt->get_result();
    $all_hosts = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // --- Group hosts by their role ---
    $grouped_hosts = [
        'manager' => [], 'worker' => [], 'standalone' => [], 'registry' => []
    ];

    foreach ($all_hosts as $host) {
        $uptime_status = 'N/A';
        $uptime_timestamp = $host['host_uptime_seconds'] ?? 0;
        $manager_status_text = '';
        $swarm_status_for_db = 'unreachable'; // Default value
        $connection_status_badge = '<span class="badge bg-secondary">Unknown</span>';
        if ($uptime_timestamp > 0) {
            $uptime_status = format_uptime($uptime_timestamp);
        }
        try {
            $dockerClient = new DockerClient($host);
            $dockerInfo = $dockerClient->getInfo();
            $connection_status_badge = '<span class="badge bg-success">Reachable</span>';

            // Check Swarm status
            if (isset($dockerInfo['Swarm']['LocalNodeState']) && $dockerInfo['Swarm']['LocalNodeState'] !== 'inactive') {
                if (isset($dockerInfo['Swarm']['ControlAvailable']) && $dockerInfo['Swarm']['ControlAvailable']) {
                    $manager_status_text = 'Manager' . (isset($dockerInfo['Swarm']['IsManager']) && $dockerInfo['Swarm']['IsManager'] ? ' (Leader)' : '');
                    $swarm_status_for_db = 'manager';
                } else {
                    $manager_status_text = 'Worker';
                    $swarm_status_for_db = 'worker';
                    // --- New Logic: Automatically detect the manager from the remote host ---
                    $remote_managers = $dockerInfo['Swarm']['RemoteManagers'] ?? null;
                    if (is_array($remote_managers) && !empty($remote_managers)) {
                        $manager_addr = $remote_managers[0]['Addr'] ?? null; // Get the address of the first manager
                        if ($manager_addr) {
                            // Extract IP from the address (e.g., 192.168.1.100:2377 -> 192.168.1.100)
                            $manager_ip = explode(':', $manager_addr)[0];
                            // Find the manager's name in our database using its IP
                            $stmt_find_manager = $conn->prepare("SELECT name FROM docker_hosts WHERE docker_api_url LIKE ?");
                            $search_ip = "%{$manager_ip}%";
                            $stmt_find_manager->bind_param("s", $search_ip);
                            $stmt_find_manager->execute();
                            $found_manager = $stmt_find_manager->get_result()->fetch_assoc();
                            if ($found_manager) {
                                $manager_status_text .= ' for: ' . htmlspecialchars($found_manager['name']);
                            }
                        }
                    } elseif (!empty($host['swarm_manager_id']) && !empty($host['manager_name'])) {
                        // Fallback to the database record if remote detection fails
                        $manager_status_text .= ' for: ' . htmlspecialchars($host['manager_name']);
                    }
                }
            } else {
                $manager_status_text = 'Standalone';
                $swarm_status_for_db = 'standalone';
            }

        } catch (Exception $e) {
            // Don't overwrite uptime status if we already have it from the DB
            if ($uptime_timestamp <= 0) $uptime_status = 'Error';
            $connection_status_badge = '<span class="badge bg-danger" title="' . htmlspecialchars($e->getMessage()) . '">Unreachable</span>';
            $swarm_status_for_db = 'unreachable';
        }

        // --- NEW: Detect if a registry container is running on the host ---
        $is_registry_host = false;
        if ($swarm_status_for_db !== 'unreachable') {
            try {
                $containers = $dockerClient->listContainers();
                foreach ($containers as $container) {
                    if (isset($container['Ports']) && is_array($container['Ports'])) {
                        foreach ($container['Ports'] as $port_mapping) {
                            if (isset($port_mapping['PublicPort']) && $port_mapping['PublicPort'] == 5000) {
                                $is_registry_host = true;
                                break 2; // Break both loops once found
                            }
                        }
                    }
                }
            } catch (Exception $e) { /* Ignore if listing containers fails */ }
        }

        // Prepare and execute status update inside the loop for each host
        $stmt_update_status = $conn->prepare("UPDATE docker_hosts SET swarm_status = ? WHERE id = ?");
        $stmt_update_status->bind_param("si", $swarm_status_for_db, $host['id']);
        $stmt_update_status->execute();
        $stmt_update_status->close();


             // --- Grouping Logic: Prioritize registry, then fall back to swarm status ---
        if ($is_registry_host) {
            $grouped_hosts['registry'][] = $host;
        } elseif ($swarm_status_for_db !== 'unreachable') {
            $grouped_hosts[$swarm_status_for_db][] = $host;
        }


    } // End of foreach ($all_hosts as $host)

    // --- Generate HTML from grouped hosts ---
    $html = '';
    $group_definitions = [
        'manager' => ['title' => 'Swarm Managers', 'icon' => 'hdd-stack-fill'],
        'worker' => ['title' => 'Swarm Workers', 'icon' => 'hdd-fill'],
        'standalone' => ['title' => 'Standalone Hosts', 'icon' => 'hdd-network-fill'],
        'registry' => ['title' => 'Local Registries', 'icon' => 'database-fill']
    ];

    $total_rendered = 0;
    foreach ($group_definitions as $group_key => $group_info) {
        // If a specific group is filtered, only show that one. Otherwise, show all.
        if (!empty($group_by) && $group_by !== $group_key) {
            continue;
        }

        $hosts_in_group = $grouped_hosts[$group_key];
        if (empty($hosts_in_group)) {
            continue;
        }

        // Add group header row
        $html .= '<tr class="table-group-header"><th colspan="9"><i class="bi bi-' . $group_info['icon'] . ' me-2"></i>' . $group_info['title'] . ' (' . count($hosts_in_group) . ')</th></tr>';

        foreach ($hosts_in_group as $host) {
            // Re-calculate display values for this specific host
            $uptime_status = ($host['host_uptime_seconds'] ?? 0) > 0 ? format_uptime($host['host_uptime_seconds']) : 'N/A';
            $swarm_status_for_db = $host['swarm_status'] ?? 'unreachable';
            $connection_status_badge = '<span class="badge bg-success">Reachable</span>'; // Assume reachable if in a group
            if ($swarm_status_for_db === 'unreachable') {
                 $connection_status_badge = '<span class="badge bg-danger">Unreachable</span>';
            }

            $manager_status_text = ucfirst($host['swarm_status'] ?? 'Unknown');
            if ($manager_status_text === 'Manager' && str_contains($host['name'], 'Leader')) { // Simplified for display
                $manager_status_text = 'Manager (Leader)';
            }

        // --- Registry Browser Button ---
        $registry_browser_btn = '';
        if (!empty($host['registry_url'])) {
            $registry_browser_btn = '<a href="' . base_url('/registry-browser?host_id=' . $host['id']) . '" class="btn btn-sm btn-outline-secondary" data-bs-toggle="tooltip" title="Browse Registry"><i class="bi bi-box-seam"></i></a> ';
        }

        // --- NEW: Setup as Registry Button ---
        $setup_registry_btn = '';
        // Show this button only if the host is reachable and doesn't already have a registry URL configured.
        if ($swarm_status_for_db !== 'unreachable' && empty($host['registry_url'])) {
            $setup_registry_btn = '<button class="btn btn-sm btn-outline-success setup-registry-btn" data-host-id="' . $host['id'] . '" data-host-name="' . htmlspecialchars($host['name']) . '" title="Setup as Local Registry"><i class="bi bi-database-add"></i></button> ';
        }

        // --- Agent Status Badge ---
        $agent_status = $host['agent_status'] ?? 'Unknown';
        $agent_badge_class = 'secondary';
        $agent_badge_title = 'Status of the health agent is unknown.';
        if ($agent_status === 'Running') {
            $agent_badge_class = 'success';
            $agent_badge_title = 'Health agent is running.';
        } elseif ($agent_status === 'Not Deployed') {
            $agent_badge_class = 'danger';
            $agent_badge_title = 'Health agent is not deployed.';
        } elseif ($agent_status === 'Stopped') {
            $agent_badge_class = 'warning';
            $agent_badge_title = 'Health agent container is stopped.';
        }

        // --- CPU Reader Status Badge ---
        $cpu_reader_status = $host['cpu_reader_status'] ?? 'Unknown';
        $cpu_reader_badge_class = 'secondary';
        $cpu_reader_badge_title = 'Status of the CPU reader agent is unknown.';
        if ($cpu_reader_status === 'Running') {
            $cpu_reader_badge_class = 'success';
            $cpu_reader_badge_title = 'CPU reader agent is running.';
        } elseif ($cpu_reader_status === 'Not Deployed') {
            $cpu_reader_badge_class = 'danger';
            $cpu_reader_badge_title = 'CPU reader agent is not deployed.';
        } elseif ($cpu_reader_status === 'Stopped') {
            $cpu_reader_badge_class = 'warning';
            $cpu_reader_badge_title = 'CPU reader agent container is stopped.';
        }

        $html .= '<tr data-sort-name="' . htmlspecialchars(strtolower($host['name'])) . '" data-sort-status="' . $swarm_status_for_db . '" data-sort-uptime="' . $uptime_timestamp . '">';
        $html .= '<td><a href="' . base_url('/hosts/' . $host['id'] . '/details') . '">' . htmlspecialchars($host['name']) . '</a><br><small class="text-muted">' . $manager_status_text . '</small></td>';
        $html .= '<td>' . $connection_status_badge . '</td>';
        $html .= '<td>' . $uptime_status . '</td>';
        $html .= '<td><code>' . htmlspecialchars($host['docker_api_url']) . '</code></td>';
        
        $tls_badge = $host['tls_enabled'] 
            ? '<span class="badge bg-success">Enabled</span>' 
            : '<span class="badge bg-secondary">Disabled</span>';
        $html .= '<td>' . $tls_badge . '</td>';

        $html .= '<td><span class="badge bg-' . $agent_badge_class . '" data-bs-toggle="tooltip" title="' . $agent_badge_title . '">' . $agent_status . '</span></td>';
        $html .= '<td><span class="badge bg-' . $cpu_reader_badge_class . '" data-bs-toggle="tooltip" title="' . $cpu_reader_badge_title . '">' . $cpu_reader_status . '</span></td>';

        $html .= '<td>' . $host['updated_at'] . '</td>';
        $html .= '<td class="text-end">';
        if ($manager_status_text === 'Worker' || str_starts_with($manager_status_text, 'Worker for:')) {
            $html .= '<button class="btn btn-sm btn-outline-success node-action-btn" data-host-id="' . $host['id'] . '" data-action="promote" title="Promote to Manager"><i class="bi bi-arrow-up-square"></i></button> ';
        } elseif (str_starts_with($manager_status_text, 'Manager') && !str_contains($manager_status_text, 'Leader')) {
            $html .= '<button class="btn btn-sm btn-outline-secondary node-action-btn" data-host-id="' . $host['id'] . '" data-action="demote" title="Demote to Worker"><i class="bi bi-arrow-down-square"></i></button> ';
        } elseif ($manager_status_text === 'Standalone') {
            $html .= '<button class="btn btn-sm btn-outline-primary join-swarm-btn" data-host-id="' . $host['id'] . '" data-bs-toggle="modal" data-bs-target="#joinSwarmModal" title="Join a Swarm Cluster"><i class="bi bi-person-plus-fill"></i> Join Swarm</button> ';
        }
        $html .= $setup_registry_btn;
        $html .= $registry_browser_btn;
        $html .= '<a href="' . base_url('/hosts/' . $host['id'] . '/details') . '" class="btn btn-sm btn-outline-primary" data-bs-toggle="tooltip" title="Manage Host"><i class="bi bi-box-arrow-in-right"></i></a> ';
        $html .= '<a href="' . base_url('/hosts/' . $host['id'] . '/clone') . '" class="btn btn-sm btn-info" data-bs-toggle="tooltip" title="Clone Host"><i class="bi bi-copy"></i></a> ';
        $html .= '<button class="btn btn-sm btn-outline-info test-connection-btn" data-id="' . $host['id'] . '" data-bs-toggle="tooltip" title="Test Connection"><i class="bi bi-plug-fill"></i></button> ';
        $html .= '<a href="' . base_url('/hosts/' . $host['id'] . '/edit') . '" class="btn btn-sm btn-outline-warning" data-bs-toggle="tooltip" title="Edit Host"><i class="bi bi-pencil-square"></i></a> ';
        $html .= '<button class="btn btn-sm btn-outline-danger delete-btn" data-id="' . $host['id'] . '" data-url="' . base_url('/hosts/' . $host['id'] . '/delete') . '" data-type="hosts" data-confirm-message="Are you sure you want to delete host \'' . htmlspecialchars($host['name']) . '\'?"><i class="bi bi-trash"></i></button>';
        $html .= '</td></tr>';
            $total_rendered++;
        }
    }

    $response['html'] = $html;
    $response['total_pages'] = $total_pages;
    $response['info'] = "Showing <strong>{$total_rendered}</strong> of <strong>{$total_items}</strong> hosts.";
}
elseif ($type === 'traefik-hosts') {
    // Get total count
    $total_items = $conn->query("SELECT COUNT(*) as count FROM `traefik_hosts`")->fetch_assoc()['count'];
    $total_pages = ($limit_get == -1) ? 1 : ceil($total_items / $limit);

    // Get data
    $stmt = $conn->prepare("SELECT h.*, m.name as manager_name FROM `docker_hosts` h LEFT JOIN `docker_hosts` m ON h.swarm_manager_id = m.id ORDER BY h.name ASC LIMIT ? OFFSET ?");
    $stmt->bind_param("ii", $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();

    $html = '';
    while ($host = $result->fetch_assoc()) {
        $html .= '<tr>';
        $html .= '<td>' . $host['id'] . '</td>';
        $html .= '<td>' . htmlspecialchars($host['name']) . '</td>';
        $html .= '<td>' . htmlspecialchars($host['description'] ?? '') . '</td>';
        $html .= '<td>' . $host['created_at'] . '</td>';
        $html .= '<td class="text-end">';
        if ($host['id'] != 1) { // Don't allow editing/deleting the default Global host
            $html .= '<button class="btn btn-sm btn-outline-warning edit-traefik-host-btn" 
                            data-bs-toggle="modal" 
                            data-bs-target="#traefikHostModal" 
                            data-id="' . $host['id'] . '" 
                            data-name="' . htmlspecialchars($host['name']) . '"
                            data-description="' . htmlspecialchars($host['description'] ?? '') . '"
                            title="Edit Host"><i class="bi bi-pencil-square"></i></button> ';
            $html .= '<button class="btn btn-sm btn-outline-danger delete-btn" data-id="' . $host['id'] . '" data-url="' . base_url('/traefik-hosts/' . $host['id'] . '/delete') . '" data-type="traefik-hosts" data-confirm-message="Are you sure you want to delete Traefik host \'' . htmlspecialchars($host['name']) . '\'?"><i class="bi bi-trash"></i></button>';
        }
        $html .= '</td></tr>';
    }

    $response['html'] = $html;
    $response['total_pages'] = $total_pages;
    $response['info'] = "Showing <strong>{$result->num_rows}</strong> of <strong>{$total_items}</strong> Traefik hosts.";
}
elseif ($type === 'groups') {
    // Get total count
    $total_items = $conn->query("SELECT COUNT(*) as count FROM `groups`")->fetch_assoc()['count'];
    $total_pages = ($limit_get == -1) ? 1 : ceil($total_items / $limit);

    // Get data
    $stmt = $conn->prepare("
        SELECT g.*, th.name as traefik_host_name 
        FROM `groups` g 
        LEFT JOIN `traefik_hosts` th ON g.traefik_host_id = th.id 
        ORDER BY g.name ASC LIMIT ? OFFSET ?
    ");
    $stmt->bind_param("ii", $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();

    $html = '';
    while ($group = $result->fetch_assoc()) {
        $hosts_html = '';
        if (!empty($group['traefik_host_name'])) {
            $hosts_html = '<span class="badge bg-dark me-1">' . htmlspecialchars($group['traefik_host_name']) . '</span>';
        } else {
            $hosts_html = '<span class="badge bg-secondary">Global</span>';
        }

        $html .= '<tr>';
        $html .= '<td>' . $group['id'] . '</td>';
        $html .= '<td>' . htmlspecialchars($group['name']) . '</td>';
        $html .= '<td>' . $hosts_html . '</td>';
        $html .= '<td>' . htmlspecialchars($group['description'] ?? '') . '</td>';
        $html .= '<td>' . $group['created_at'] . '</td>';
        $html .= '<td class="text-end">';
        if ($group['id'] != 1) { // Don't allow editing/deleting the default General group
            $html .= '<button class="btn btn-sm btn-outline-info preview-group-config-btn" 
                            data-bs-toggle="modal" 
                            data-bs-target="#previewConfigModal" 
                            data-group-id="' . $group['id'] . '" 
                            data-group-name="' . htmlspecialchars($group['name']) . '"
                            title="Preview Config for this Group"><i class="bi bi-eye"></i></button> ';
            $html .= '<button class="btn btn-sm btn-outline-warning edit-group-btn"
                            data-bs-toggle="modal" 
                            data-bs-target="#groupModal" 
                            data-id="' . $group['id'] . '" 
                            data-name="' . htmlspecialchars($group['name']) . '"
                            data-description="' . htmlspecialchars($group['description'] ?? '') . '"
                            data-traefik_host_id="' . ($group['traefik_host_id'] ?? '') . '"
                            title="Edit Group"><i class="bi bi-pencil-square"></i></button> ';
            $html .= '<button class="btn btn-sm btn-outline-danger delete-btn" data-id="' . $group['id'] . '" data-url="' . base_url('/groups/' . $group['id'] . '/delete') . '" data-type="groups" data-confirm-message="Are you sure you want to delete group \'' . htmlspecialchars($group['name']) . '\'?"><i class="bi bi-trash"></i></button>';
        }
        $html .= '</td></tr>';
    }

    $response['html'] = $html;
    $response['total_pages'] = $total_pages;
    $response['info'] = "Showing <strong>{$result->num_rows}</strong> of <strong>{$total_items}</strong> groups.";
}

$conn->close();
echo json_encode($response);
?>