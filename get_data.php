<?php
// Meskipun router sudah memulai sesi, untuk endpoint AJAX, lebih aman untuk memastikannya di sini.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'includes/bootstrap.php';
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
$offset = ($page - 1) * $limit;

$response = [
    'html' => '',
    'pagination_html' => '',
    'total_pages' => 0,
    'current_page' => $page,
    'limit' => $limit_get
];

$is_admin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';

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
    $sql = "SELECT r.*, g.name as group_name, 
                   GROUP_CONCAT(m.name ORDER BY rm.priority) as middleware_names,
                   GROUP_CONCAT(m.config_json SEPARATOR '|||') as middleware_configs
            FROM routers r 
            LEFT JOIN `groups` g ON r.group_id = g.id
            LEFT JOIN router_middleware rm ON r.id = rm.router_id
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
            $html .= '<td class="table-actions">';
            $html .= '<a href="' . base_url('/routers/' . $row['id'] . '/clone') . '" class="btn btn-info btn-sm" data-bs-toggle="tooltip" title="Clone Router"><i class="bi bi-copy"></i></a> ';
            $html .= '<a href="' . base_url('/routers/' . $row['id'] . '/edit') . '" class="btn btn-warning btn-sm" data-bs-toggle="tooltip" title="Edit Router"><i class="bi bi-pencil-square"></i></a> ';
            $html .= '<button class="btn btn-danger btn-sm delete-btn" data-id="' . $row['id'] . '" data-url="' . base_url('/routers/' . $row['id'] . '/delete') . '" data-type="routers" data-confirm-message="Yakin ingin menghapus router ini?"><i class="bi bi-trash"></i></button>';
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
    // Get total count
    $total_items = $conn->query("SELECT COUNT(*) as count FROM `docker_hosts`")->fetch_assoc()['count'];
    $total_pages = ($limit_get == -1) ? 1 : ceil($total_items / $limit);

    // Get data
    $stmt = $conn->prepare("SELECT * FROM `docker_hosts` ORDER BY name ASC LIMIT ? OFFSET ?");
    $stmt->bind_param("ii", $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();

    $html = '';
    while ($host = $result->fetch_assoc()) {
        $html .= '<tr>';
        $html .= '<td><a href="' . base_url('/hosts/' . $host['id'] . '/details') . '">' . htmlspecialchars($host['name']) . '</a></td>';
        $html .= '<td><code>' . htmlspecialchars($host['docker_api_url']) . '</code></td>';
        
        $tls_badge = $host['tls_enabled'] 
            ? '<span class="badge bg-success">Enabled</span>' 
            : '<span class="badge bg-secondary">Disabled</span>';
        $html .= '<td>' . $tls_badge . '</td>';

        $html .= '<td>' . htmlspecialchars($host['description'] ?? '') . '</td>';
        $html .= '<td>' . $host['updated_at'] . '</td>';
        $html .= '<td class="text-end">';
        $html .= '<a href="' . base_url('/hosts/' . $host['id'] . '/details') . '" class="btn btn-sm btn-outline-primary" data-bs-toggle="tooltip" title="Manage Host"><i class="bi bi-box-arrow-in-right"></i></a> ';
        $html .= '<button class="btn btn-sm btn-outline-info test-connection-btn" data-id="' . $host['id'] . '" data-bs-toggle="tooltip" title="Test Connection"><i class="bi bi-plug-fill"></i></button> ';
        $html .= '<a href="' . base_url('/hosts/' . $host['id'] . '/edit') . '" class="btn btn-sm btn-outline-warning" data-bs-toggle="tooltip" title="Edit Host"><i class="bi bi-pencil-square"></i></a> ';
        $html .= '<button class="btn btn-sm btn-outline-danger delete-btn" data-id="' . $host['id'] . '" data-url="' . base_url('/hosts/' . $host['id'] . '/delete') . '" data-type="hosts" data-confirm-message="Are you sure you want to delete host \'' . htmlspecialchars($host['name']) . '\'?"><i class="bi bi-trash"></i></button>';
        $html .= '</td></tr>';
    }

    $response['html'] = $html;
    $response['total_pages'] = $total_pages;
    $response['info'] = "Showing <strong>{$result->num_rows}</strong> of <strong>{$total_items}</strong> hosts.";
}

$conn->close();
echo json_encode($response);
?>