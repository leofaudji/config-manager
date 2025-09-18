<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/DockerClient.php';

header('Content-Type: application/json');

$request_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (!preg_match('/\/api\/hosts\/(\d+)\/containers/', $request_path, $matches)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid host ID in URL.']);
    exit;
}
$id = $matches[1];

// Get pagination and filter params
$limit_get = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
$limit = ($limit_get == -1) ? 1000000 : $limit_get;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$search = trim($_GET['search'] ?? '');
$raw = isset($_GET['raw']) && $_GET['raw'] === 'true';
$filter = $_GET['filter'] ?? 'all'; // 'all', 'running', 'stopped'
$sort = $_GET['sort'] ?? 'Name';
$order = $_GET['order'] ?? 'asc';
$offset = ($page - 1) * $limit;

$conn = Database::getInstance()->getConnection();

try {
    $stmt = $conn->prepare("SELECT * FROM docker_hosts WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    if (!($host = $result->fetch_assoc())) {
        throw new Exception("Host not found.");
    }
    $stmt->close();

    // Fetch all managed stacks for this host to cross-reference
    // This allows us to link a container back to its manageable stack
    $stmt_managed = $conn->prepare("SELECT id, stack_name FROM application_stacks WHERE host_id = ?");
    $stmt_managed->bind_param("i", $id);
    $stmt_managed->execute();
    $managed_stacks_result = $stmt_managed->get_result();
    $managed_stacks_map = [];
    while ($row = $managed_stacks_result->fetch_assoc()) {
        $managed_stacks_map[$row['stack_name']] = $row['id'];
    }
    $stmt_managed->close();

    $dockerClient = new DockerClient($host);
    $containers = $dockerClient->listContainers();
    
    // Filter containers based on state
    $filteredContainers = [];
    if ($filter === 'running') {
        $filteredContainers = array_filter($containers, fn($c) => $c['State'] === 'running');
    } elseif ($filter === 'stopped') {
        $filteredContainers = array_filter($containers, fn($c) => $c['State'] === 'exited');
    } else {
        $filteredContainers = $containers;
    }

    // Filter by search term if provided
    if (!empty($search)) {
        $filteredContainers = array_filter($filteredContainers, function($c) use ($search) {
            // Check image name
            if (stripos($c['Image'], $search) !== false) {
                return true;
            }
            // Check all container names
            if (!empty($c['Names']) && is_array($c['Names'])) {
                foreach ($c['Names'] as $name) {
                    // Remove leading slash from name
                    if (stripos(ltrim($name, '/'), $search) !== false) {
                        return true;
                    }
                }
            }
            return false;
        });
    }
    $filteredContainers = array_values($filteredContainers); // Re-index array

    // Sort the data
    usort($filteredContainers, function($a, $b) use ($sort, $order) {
        $valA = null;
        $valB = null;

        if ($sort === 'Name') {
            $valA = $a['Names'][0] ?? '';
            $valB = $b['Names'][0] ?? '';
        } else {
            $valA = $a[$sort] ?? null;
            $valB = $b[$sort] ?? null;
        }

        $comparison = strnatcasecmp((string)$valA, (string)$valB);
        return ($order === 'asc') ? $comparison : -$comparison;
    });


    // If raw data is requested, return it now before pagination and HTML generation
    if ($raw) {
        echo json_encode(['status' => 'success', 'data' => $filteredContainers]);
        $conn->close();
        exit;
    }

    // Paginate the filtered array
    $total_items = count($filteredContainers);
    $total_pages = ($limit_get == -1) ? 1 : ceil($total_items / $limit);
    $paginatedContainers = array_slice($filteredContainers, $offset, $limit);

    // Generate HTML for the table rows
    $html = '';
    if (empty($paginatedContainers)) {
        $html = '<tr><td colspan="8" class="text-center">No containers found for the current filter.</td></tr>';
    } else {
        foreach ($paginatedContainers as $cont) {
            $name = $cont['Names'] && count($cont['Names']) > 0 ? htmlspecialchars(substr($cont['Names'][0], 1)) : 'N/A';
            $state = htmlspecialchars($cont['State']);
            $status = htmlspecialchars($cont['Status']);
            
            $stateBadgeClass = 'secondary';
            if ($state === 'running') $stateBadgeClass = 'success';
            elseif ($state === 'exited') $stateBadgeClass = 'danger';
            elseif ($state === 'restarting') $stateBadgeClass = 'warning';

            // Build IP Address HTML instead of Ports
            $ipAddressHtml = '';
            if (!empty($cont['NetworkSettings']['Networks'])) {
                $ip_parts = [];
                foreach ($cont['NetworkSettings']['Networks'] as $netName => $netDetails) {
                    if (!empty($netDetails['IPAddress'])) {
                        $ip_parts[] = '<code>' . htmlspecialchars($netDetails['IPAddress']) . '</code> <small class="text-muted">(' . htmlspecialchars($netName) . ')</small>';
                    }
                }
                if (!empty($ip_parts)) {
                    $ipAddressHtml = implode('<br>', $ip_parts);
                }
            }
            if (empty($ipAddressHtml)) $ipAddressHtml = '<span class="text-muted small">N/A</span>';

            // Build Networks HTML
            $networksHtml = '';
            if (!empty($cont['NetworkSettings']['Networks'])) {
                foreach ($cont['NetworkSettings']['Networks'] as $netName => $netDetails) {
                    $ip = !empty($netDetails['IPAddress']) ? " ({$netDetails['IPAddress']})" : '';
                    $networksHtml .= "<span class='badge bg-secondary me-1' data-bs-toggle='tooltip' title='" . htmlspecialchars($netName) . $ip . "'>" . htmlspecialchars($netName) . "</span>";
                }
            } else {
                $networksHtml = '<span class="text-muted small">None</span>';
            }

            // Check if this container belongs to a managed stack
            $compose_project = $cont['Labels']['com.docker.compose.project'] ?? null;
            $stack_db_id = null;
            if ($compose_project && isset($managed_stacks_map[$compose_project])) {
                $stack_db_id = $managed_stacks_map[$compose_project];
            }

            $actionButtons = '<div class="btn-group" role="group">';
            // Add the update check button first
            $actionButtons .= "<button class=\"btn btn-sm btn-outline-secondary update-check-btn\" data-container-id=\"{$cont['Id']}\" " . ($stack_db_id ? "data-stack-id=\"{$stack_db_id}\"" : "") . " title=\"Check for image update\"><i class=\"bi bi-patch-question-fill\"></i></button>";

            if ($state === 'running') {
                $actionButtons .= "<button class=\"btn btn-sm btn-outline-warning container-action-btn\" data-container-id=\"{$cont['Id']}\" data-action=\"restart\" title=\"Restart\"><i class=\"bi bi-arrow-repeat\"></i></button>";
                $actionButtons .= "<button class=\"btn btn-sm btn-outline-danger container-action-btn\" data-container-id=\"{$cont['Id']}\" data-action=\"stop\" title=\"Stop\"><i class=\"bi bi-stop-fill\"></i></button>";
                $actionButtons .= "<button class=\"btn btn-sm btn-outline-info live-stats-btn\" data-bs-toggle=\"modal\" data-bs-target=\"#liveStatsModal\" data-container-id=\"{$cont['Id']}\" data-container-name=\"{$name}\" title=\"Live Stats\"><i class=\"bi bi-bar-chart-line-fill\"></i></button>";
            } else {
                $actionButtons .= "<button class=\"btn btn-sm btn-outline-success container-action-btn\" data-container-id=\"{$cont['Id']}\" data-action=\"start\" title=\"Start\"><i class=\"bi bi-play-fill\"></i></button>";
            }
            $actionButtons .= "<button class=\"btn btn-sm btn-outline-primary view-logs-btn\" data-bs-toggle=\"modal\" data-bs-target=\"#viewLogsModal\" data-container-id=\"{$cont['Id']}\" data-container-name=\"{$name}\" title=\"View Logs\"><i class=\"bi bi-card-text\"></i></button>";
            $actionButtons .= '</div>';

            $html .= "<tr>";
            $html .= "<td><input class=\"form-check-input container-checkbox\" type=\"checkbox\" value=\"{$cont['Id']}\"></td>";
            $html .= "<td>{$name}</td>";
            $html .= "<td><small>" . htmlspecialchars($cont['Image']) . "</small></td>";
            $html .= "<td><span class=\"badge bg-{$stateBadgeClass}\">{$state}</span></td>";
            $html .= "<td>{$status}</td>";
            $html .= "<td>{$ipAddressHtml}</td>";
            $html .= "<td>{$networksHtml}</td>";
            $html .= "<td class=\"text-end\">{$actionButtons}</td>";
            $html .= "</tr>";
        }
    }

    echo json_encode([
        'status' => 'success',
        'html' => $html,
        'total_pages' => $total_pages,
        'current_page' => $page,
        'limit' => $limit_get,
        'info' => "Showing <strong>" . count($paginatedContainers) . "</strong> of <strong>{$total_items}</strong> containers."
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();
?>