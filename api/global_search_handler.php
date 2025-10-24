<?php
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/DockerClient.php';
if (!isset($_SESSION['role'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
    exit;
}

// --- Caching Configuration ---
$cache_dir = PROJECT_ROOT . '/cache';
if (!is_dir($cache_dir)) {
    // Attempt to create the directory. The '@' suppresses the default PHP warning.
    if (!@mkdir($cache_dir, 0755, true) && !is_dir($cache_dir)) {
        // If mkdir fails and the directory still doesn't exist, we can't cache.
        // We'll proceed without caching and let the script run, but log the error.
        error_log("Config Manager: Failed to create cache directory at '{$cache_dir}'. Please check file permissions.");
    }
}
$cache_ttl = 60; // Cache lifetime in seconds

function get_cached_data(string $key, callable $fetch_callback, int $ttl) {
    global $cache_dir;
    // If the cache directory isn't writable, skip caching and just fetch the data.
    if (!is_writable($cache_dir)) {
        return $fetch_callback();
    }

    $cache_file = $cache_dir . '/' . md5($key) . '.json';
    if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $ttl) {
        return json_decode(file_get_contents($cache_file), true);
    }
    $data = $fetch_callback();
    @file_put_contents($cache_file, json_encode($data)); // Use '@' to suppress warnings on failure
    return $data;
}

$query = trim($_GET['q'] ?? '');
$command = trim($_GET['command'] ?? '');
$argument = trim($_GET['arg'] ?? '');

if (empty($query) && empty($argument)) {
    echo json_encode(['status' => 'success', 'data' => []]);
    exit;
}

// If a command is used, the argument becomes the main search query
$search_query = !empty($command) ? $argument : $query;

$conn = Database::getInstance()->getConnection();
$results = [];
$search_param = "%{$search_query}%";

/**
 * Calculates a relevance score for a search result.
 * Higher score is better.
 * @param string $name The name of the item being searched.
 * @param string $query The user's search query.
 * @return int The relevance score.
 */
function calculate_score(string $name, string $query): int {
    $name = strtolower($name);
    $query = strtolower($query);
    if ($name === $query) return 100; // Exact match
    if (str_starts_with($name, $query)) return 50; // Starts with
    return 10; // Contains
}

try {
    // 1. Search Hosts
    $stmt_hosts = $conn->prepare("SELECT id, name FROM docker_hosts WHERE name LIKE ? LIMIT 5");
    $stmt_hosts->bind_param("s", $search_param);
    $stmt_hosts->execute();
    $res_hosts = $stmt_hosts->get_result();
    while ($row = $res_hosts->fetch_assoc()) {
        $results[] = [
            'category' => 'Hosts',
            'name' => $row['name'],
            'url' => base_url('/hosts/' . $row['id'] . '/details'),
            'score' => calculate_score($row['name'], $search_query),
            'actions' => [
                ['name' => 'Copy Name', 'type' => 'copy', 'value' => $row['name'], 'icon' => 'bi-clipboard'],
                ['name' => 'View Stacks', 'type' => 'link', 'url' => base_url('/hosts/' . $row['id'] . '/stacks'), 'icon' => 'bi-stack'],
                ['name' => 'View Containers', 'type' => 'link', 'url' => base_url('/hosts/' . $row['id'] . '/containers'), 'icon' => 'bi-box-seam'],
                ['name' => 'View Images', 'type' => 'link', 'url' => base_url('/hosts/' . $row['id'] . '/images'), 'icon' => 'bi-file-earmark-image'],
            ],
            'icon' => 'bi-hdd-network-fill'
        ];
    }
    $stmt_hosts->close();

    // 2. Search Routers
    $stmt_routers = $conn->prepare("SELECT id, name, rule FROM routers WHERE name LIKE ? OR rule LIKE ? OR entry_points LIKE ? LIMIT 5");
    $stmt_routers->bind_param("sss", $search_param, $search_param, $search_param);
    $stmt_routers->execute();
    $res_routers = $stmt_routers->get_result();
    while ($row = $res_routers->fetch_assoc()) {
        $results[] = [
            'category' => 'Traefik',
            'name' => $row['name'],
            'description' => $row['rule'],
            'score' => calculate_score($row['name'], $search_query),
            'url' => base_url('/routers?search=' . urlencode($row['name']) . '&highlight=true'),
            'actions' => [
                ['name' => 'Copy Rule', 'type' => 'copy', 'value' => $row['rule'], 'icon' => 'bi-clipboard-plus'],
            ],
            'icon' => 'bi-sign-turn-right-fill'
        ];
    }
    $stmt_routers->close();

    // 3. Search Services
    $stmt_services = $conn->prepare("
        SELECT s.id, s.name, sv.url as server_url, COUNT(srv.id) as server_count
        FROM services s 
        LEFT JOIN servers sv ON s.id = sv.service_id AND sv.url LIKE ?
        LEFT JOIN servers srv ON s.id = srv.service_id
        WHERE s.name LIKE ? OR sv.url IS NOT NULL
        GROUP BY s.id, s.name, sv.url
        LIMIT 10
    ");
    $stmt_services->bind_param("ss", $search_param, $search_param);
    $stmt_services->execute();
    $res_services = $stmt_services->get_result();
    while ($row = $res_services->fetch_assoc()) {
        $description = ($row['server_count'] > 0) ? "{$row['server_count']} server(s)" : 'No servers';
        $results[] = [
            'category' => 'Traefik',
            'name' => $row['name'],
            'description' => isset($row['server_url']) && stripos($row['server_url'], $search_query) !== false ? 'Server URL: ' . htmlspecialchars($row['server_url']) : $description,
            'score' => calculate_score($row['name'], $search_query),
            'url' => base_url('/services?search=' . urlencode($row['name']) . '&highlight=true'),
            'actions' => [
                ['name' => 'Copy Name', 'type' => 'copy', 'value' => $row['name'], 'icon' => 'bi-clipboard'],
            ],
            'icon' => 'bi-hdd-stack-fill'
        ];
    }
    $stmt_services->close();

    // Search Middlewares
    $stmt_middlewares = $conn->prepare("SELECT id, name FROM middlewares WHERE name LIKE ? LIMIT 5");
    $stmt_middlewares->bind_param("s", $search_param);
    $stmt_middlewares->execute();
    $res_middlewares = $stmt_middlewares->get_result();
    while ($row = $res_middlewares->fetch_assoc()) {
        $results[] = [
            'category' => 'Traefik',
            'name' => $row['name'],
            'score' => calculate_score($row['name'], $search_query),
            'url' => base_url('/middlewares?search=' . urlencode($row['name']) . '&highlight=true'),
            'actions' => [
                ['name' => 'Copy Name', 'type' => 'copy', 'value' => $row['name'], 'icon' => 'bi-clipboard'],
            ],
            'icon' => 'bi-sliders'
        ];
    }
    $stmt_middlewares->close();

    // Search Groups
    $stmt_groups = $conn->prepare("SELECT id, name FROM `groups` WHERE name LIKE ? LIMIT 5");
    $stmt_groups->bind_param("s", $search_param);
    $stmt_groups->execute();
    $res_groups = $stmt_groups->get_result();
    while ($row = $res_groups->fetch_assoc()) {
        $results[] = [
            'category' => 'Traefik',
            'name' => $row['name'],
            'score' => calculate_score($row['name'], $search_query),
            'url' => base_url('/groups?search=' . urlencode($row['name']) . '&highlight=true'),
            'actions' => [
                ['name' => 'Copy Name', 'type' => 'copy', 'value' => $row['name'], 'icon' => 'bi-clipboard'],
            ],
            'icon' => 'bi-collection-fill'
        ];
    }
    $stmt_groups->close();

    // 4. Search Pages (Static List)
    $pages = [
        ['name' => 'Dashboard', 'url' => base_url('/'), 'icon' => 'bi-speedometer2'],
        ['name' => 'Hosts', 'url' => base_url('/hosts'), 'icon' => 'bi-hdd-network-fill'],
        ['name' => 'App Launcher', 'url' => base_url('/app-launcher'), 'icon' => 'bi-rocket-launch-fill'],
        ['name' => 'Routers', 'url' => base_url('/routers'), 'icon' => 'bi-sign-turn-right-fill'],
        ['name' => 'Services', 'url' => base_url('/services'), 'icon' => 'bi-hdd-stack-fill'],
        ['name' => 'Middlewares', 'url' => base_url('/middlewares'), 'icon' => 'bi-sliders'],
        ['name' => 'Service Health', 'url' => base_url('/health-status'), 'icon' => 'bi-heart-pulse'],
        ['name' => 'SLA Reports', 'url' => base_url('/sla-report'), 'icon' => 'bi-clipboard-data-fill'],
        ['name' => 'Incident Reports', 'url' => base_url('/incident-reports'), 'icon' => 'bi-shield-fill-exclamation'],
        ['name' => 'Cron Jobs', 'url' => base_url('/cron-jobs'), 'icon' => 'bi-clock-history'],
        ['name' => 'Users', 'url' => base_url('/users'), 'icon' => 'bi-people-fill'],
        ['name' => 'Settings', 'url' => base_url('/settings'), 'icon' => 'bi-gear-fill'],
    ];

    foreach ($pages as $page) {
        if (stripos($page['name'], $search_query) !== false) {
            $results[] = array_merge($page, ['category' => 'Navigation', 'score' => calculate_score($page['name'], $search_query)]);
        }
    }

    // --- IDE: Add Actions & Settings to search ---
    $actions_and_settings = [
        // Actions
        ['name' => 'Add New Host', 'url' => base_url('/hosts/new'), 'icon' => 'bi-hdd-network-fill', 'category' => 'Actions'],
        ['name' => 'Add New Router', 'url' => base_url('/routers/new'), 'icon' => 'bi-plus-circle-fill', 'category' => 'Actions'],
        ['name' => 'Add New Service', 'url' => base_url('/services/new'), 'icon' => 'bi-plus-circle-fill', 'category' => 'Actions'],
        ['name' => 'Launch New App', 'url' => base_url('/app-launcher'), 'icon' => 'bi-rocket-launch-fill', 'category' => 'Actions'],
        ['name' => 'Clear Search Cache', 'url' => '#', 'icon' => 'bi-stars', 'category' => 'Actions', 'js_action' => 'clear-search-cache'],

        // Settings
        ['name' => 'General & Traefik Settings', 'url' => base_url('/settings#general-tab'), 'icon' => 'bi-gear-wide-connected', 'category' => 'Settings'],
        ['name' => 'Health & Agent Settings', 'url' => base_url('/settings#agent-tab'), 'icon' => 'bi-heart-pulse-fill', 'category' => 'Settings'],
        ['name' => 'Git & Webhook Settings', 'url' => base_url('/settings#git-tab'), 'icon' => 'bi-git', 'category' => 'Settings'],
        ['name' => 'Notification Settings', 'url' => base_url('/settings#notifications-tab'), 'icon' => 'bi-bell-fill', 'category' => 'Settings'],
        ['name' => 'Data & History Settings', 'url' => base_url('/settings#data-tab'), 'icon' => 'bi-clock-history', 'category' => 'Settings'],
    ];

    foreach ($actions_and_settings as $item) {
        if (stripos($item['name'], $search_query) !== false) {
            // FIX: Add score to these static items
            $results[] = array_merge($item, ['score' => calculate_score($item['name'], $search_query)]);
        }
    }
    // --- End IDE ---

    // --- IDE: Add Cron Jobs to search ---
    $cron_jobs = [
        ['name' => 'Health Monitor Cron', 'url' => base_url('/cron-jobs'), 'icon' => 'bi-heart-pulse-fill', 'description' => 'Manages the health monitoring cron job.'],
        ['name' => 'Autoscaler Cron', 'url' => base_url('/cron-jobs'), 'icon' => 'bi-arrows-angle-expand', 'description' => 'Manages the service autoscaler cron job.'],
        ['name' => 'System Cleanup Cron', 'url' => base_url('/cron-jobs'), 'icon' => 'bi-trash3-fill', 'description' => 'Manages the database and log cleanup cron job.'],
        ['name' => 'Automatic Backup Cron', 'url' => base_url('/cron-jobs'), 'icon' => 'bi-database-down', 'description' => 'Manages the automatic backup cron job.'],
        ['name' => 'Scheduled Deployment Cron', 'url' => base_url('/cron-jobs'), 'icon' => 'bi-calendar-check', 'description' => 'Manages the scheduled deployment runner.'],
    ];

    foreach ($cron_jobs as $item) {
        if (stripos($item['name'], $search_query) !== false || stripos($item['description'], $search_query) !== false) {
            $results[] = array_merge($item, [
                'category' => 'Cron Jobs',
                'score' => calculate_score($item['name'], $search_query)
            ]);
        }
    }
    // --- End IDE ---


    // 5. Search Stacks
    $stmt_stacks = $conn->prepare("
        SELECT s.id, s.stack_name, s.source_type, h.id as host_id, h.name as host_name 
        FROM application_stacks s
        JOIN docker_hosts h ON s.host_id = h.id
        WHERE s.stack_name LIKE ? 
        LIMIT 5
    ");
    $stmt_stacks->bind_param("s", $search_param);
    $stmt_stacks->execute();
    $res_stacks = $stmt_stacks->get_result();

    while ($row = $res_stacks->fetch_assoc()) {
        $stack_actions = [['name' => 'Copy Name', 'type' => 'copy', 'value' => $row['stack_name'], 'icon' => 'bi-clipboard']];
        $is_primary = false;

        if ($command === 'deploy') {
            $stack_actions[] = ['name' => 'Update Stack', 'type' => 'link', 'url' => base_url('/hosts/' . $row['host_id'] . '/stacks/' . $row['id'] . '/update'), 'is_primary' => true, 'icon' => 'bi-arrow-repeat'];
            $is_primary = true;
        }

        if ($row['source_type'] === 'builder') {
            $edit_action = ['name' => 'Edit Stack', 'type' => 'link', 'url' => base_url('/hosts/' . $row['host_id'] . '/stacks/' . $row['id'] . '/edit'), 'icon' => 'bi-pencil-square'];
            if ($command === 'edit') { $edit_action['is_primary'] = true; $is_primary = true; }
            $stack_actions[] = $edit_action;
        } else {
            // --- IDE: Add "Update Stack" button for non-builder stacks ---
            $stack_actions[] = ['name' => 'Update Stack', 'type' => 'link', 'url' => base_url('/hosts/' . $row['host_id'] . '/stacks/' . $row['id'] . '/update'), 'icon' => 'bi-arrow-repeat'];
            $edit_action = ['name' => 'Edit Compose', 'type' => 'link', 'url' => base_url('/hosts/' . $row['host_id'] . '/stacks/' . $row['id'] . '/edit-compose'), 'icon' => 'bi-file-code'];
            if ($command === 'edit') { $edit_action['is_primary'] = true; $is_primary = true; }
            $stack_actions[] = $edit_action;
            // --- End IDE ---
        }

        $results[] = [
            'category' => 'Stacks',
            'name' => $row['stack_name'],
            'description' => 'on host ' . $row['host_name'],
            'url' => base_url('/hosts/' . $row['host_id'] . '/stacks?search=' . urlencode($row['stack_name']) . '&highlight=true'),
            'score' => calculate_score($row['stack_name'], $search_query),
            'actions' => $stack_actions,
            'is_primary_action' => $is_primary,
            'icon' => 'bi-stack'
        ];
    }
    $stmt_stacks->close();

    // 5. Search Containers on all hosts
    $container_limit = 5;
    $container_count = 0;

    $stmt_all_hosts = $conn->prepare("SELECT * FROM docker_hosts");
    $stmt_all_hosts->execute();
    $all_hosts_result = $stmt_all_hosts->get_result();

    while ($host = $all_hosts_result->fetch_assoc()) {
        if ($container_count >= $container_limit) break;
        try {
            $cache_key = "host_{$host['id']}_containers";
            $containers = get_cached_data($cache_key, function() use ($host) {
                $dockerClient = new DockerClient($host);
                return $dockerClient->listContainers();
            }, $cache_ttl);

            foreach ($containers as $container) {
                $container_name = ltrim($container['Names'][0] ?? '', '/');
                $found = false;
                $match_description = "{$container['State']} on host " . $host['name'];

                // --- IDE: Search by IP Address ---
                if (!$command && !empty($container['NetworkSettings']['Networks'])) { // Don't search IP if using a command
                    foreach ($container['NetworkSettings']['Networks'] as $net) {
                        if (isset($net['IPAddress']) && stripos($net['IPAddress'], $search_query) !== false) {
                            $found = true;
                            $match_description = 'IP: ' . htmlspecialchars($net['IPAddress']) . ' on host ' . $host['name'];
                            break; // Found a match, no need to check other networks for this container
                        }
                    }
                }
                // --- End IDE ---

                // Also search by container name if not already found by IP
                if (!$found && stripos($container_name, $search_query) !== false) {
                    $found = true;
                }

                // --- IDE: Search by Image Name ---
                if (!$command && !$found && isset($container['Image']) && stripos($container['Image'], $search_query) !== false) { // Don't search image if using a command
                    $found = true;
                    $match_description = 'Image: ' . htmlspecialchars($container['Image']) . ' on host ' . $host['name'];
                }

                if ($found) {
                    $results[] = [
                        'category' => 'Containers',
                        'name' => $container_name,
                        'description' => $match_description,
                        'url' => base_url('/hosts/' . $host['id'] . '/containers?search=' . urlencode($container_name) . '&highlight=true'),
                        'score' => calculate_score($container_name, $search_query),
                        'actions' => get_container_actions($command, $container, $container_name, $host['id']),
                        'is_primary_action' => in_array($command, ['restart', 'logs']),
                        'icon' => 'bi-box-seam'
                    ];
                    $container_count++;
                    if ($container_count >= $container_limit) break;
                }
            }
        } catch (Exception $e) {
            // Ignore hosts that are unreachable during search
            continue;
        }
    }
    $stmt_all_hosts->close();

    // 6. Search Images on all hosts
    $image_limit = 5;
    $image_count = 0;

    $stmt_all_hosts_img = $conn->prepare("SELECT * FROM docker_hosts");
    $stmt_all_hosts_img->execute();
    $all_hosts_result_img = $stmt_all_hosts_img->get_result();

    while ($host = $all_hosts_result_img->fetch_assoc()) {
        if ($image_count >= $image_limit) break;
        try {
            $cache_key = "host_{$host['id']}_images";
            $images = get_cached_data($cache_key, function() use ($host) {
                $dockerClient = new DockerClient($host);
                return $dockerClient->listImages();
            }, $cache_ttl);

            foreach ($images as $image) {
                if (empty($image['RepoTags'])) continue;

                foreach ($image['RepoTags'] as $tag) {
                    if ($tag === '<none>:<none>') continue; // Skip dangling images
                    if (stripos($tag, $search_query) !== false) {
                        $results[] = [
                            'category' => 'Images',
                            'name' => $tag,
                            'description' => 'on host ' . $host['name'],
                            'score' => calculate_score($tag, $search_query),
                            'url' => base_url('/hosts/' . $host['id'] . '/images?search=' . urlencode($tag) . '&highlight=true'),
                            'icon' => 'bi-file-earmark-image'
                        ];
                        $image_count++;
                        if ($image_count >= $image_limit) break 2; // Break out of both loops
                    }
                }
            }
        } catch (Exception $e) {
            // Ignore hosts that are unreachable during search
            continue;
        }
    }
    $stmt_all_hosts_img->close();

    // 7. Search Networks on all hosts
    $network_limit = 5;
    $network_count = 0;

    $stmt_all_hosts_net = $conn->prepare("SELECT * FROM docker_hosts");
    $stmt_all_hosts_net->execute();
    $all_hosts_result_net = $stmt_all_hosts_net->get_result();

    while ($host = $all_hosts_result_net->fetch_assoc()) {
        if ($network_count >= $network_limit) break;
        try {
            $dockerClient = new DockerClient($host);
            $networks = $dockerClient->listNetworks();

            foreach ($networks as $network) {
                if (empty($network['Name'])) continue;
                if (stripos($network['Name'], $search_query) !== false) {
                    $results[] = [
                        'category' => 'Networks',
                        'name' => $network['Name'],
                        'description' => 'on host ' . $host['name'],
                        'score' => calculate_score($network['Name'], $search_query),
                        'url' => base_url('/hosts/' . $host['id'] . '/networks?search=' . urlencode($network['Name']) . '&highlight=true'),
                        'icon' => 'bi-diagram-3'
                    ];
                    $network_count++;
                    if ($network_count >= $network_limit) break 2; // Break out of both loops
                }
            }
        } catch (Exception $e) { continue; }
    }
    $stmt_all_hosts_net->close();

    // 8. Search Volumes on all hosts
    $volume_limit = 5;
    $volume_count = 0;

    $stmt_all_hosts_vol = $conn->prepare("SELECT * FROM docker_hosts");
    $stmt_all_hosts_vol->execute();
    $all_hosts_result_vol = $stmt_all_hosts_vol->get_result();

    while ($host = $all_hosts_result_vol->fetch_assoc()) {
        if ($volume_count >= $volume_limit) break;
        try {
            $dockerClient = new DockerClient($host);
            $volumes_response = $dockerClient->listVolumes();
            $volumes = $volumes_response['Volumes'] ?? [];

            foreach ($volumes as $volume) {
                if (empty($volume['Name'])) continue;
                if (stripos($volume['Name'], $search_query) !== false) {
                    $results[] = [
                        'category' => 'Volumes',
                        'name' => $volume['Name'],
                        'description' => 'on host ' . $host['name'],
                        'score' => calculate_score($volume['Name'], $search_query),
                        'url' => base_url('/hosts/' . $host['id'] . '/volumes?search=' . urlencode($volume['Name']) . '&highlight=true'),
                        'icon' => 'bi-database'
                    ];
                    $volume_count++;
                    if ($volume_count >= $volume_limit) break 2; // Break out of both loops
                }
            }
        } catch (Exception $e) { continue; }
    }
    $stmt_all_hosts_vol->close();

    // 9. Search Users (for admins)
    if ($_SESSION['role'] === 'admin') {
        $stmt_users = $conn->prepare("SELECT id, username FROM users WHERE username LIKE ? LIMIT 3");
        $stmt_users->bind_param("s", $search_param);
        $stmt_users->execute();
        $res_users = $stmt_users->get_result();
        while ($row = $res_users->fetch_assoc()) {
            $results[] = [
                'category' => 'System',
                'name' => $row['username'],
                'score' => calculate_score($row['username'], $search_query),
                'url' => base_url('/users'), // User page doesn't have individual edit pages in SPA yet
                'icon' => 'bi-person-fill'
            ];
        }
        $stmt_users->close();
    }

    // 10. Search Templates
    $stmt_templates = $conn->prepare("SELECT id, name FROM configuration_templates WHERE name LIKE ? LIMIT 3");
    $stmt_templates->bind_param("s", $search_param);
    $stmt_templates->execute();
    $res_templates = $stmt_templates->get_result();
    while ($row = $res_templates->fetch_assoc()) {
        $results[] = [
            'category' => 'Traefik',
            'name' => $row['name'],
            'description' => 'Configuration Template',
            'score' => calculate_score($row['name'], $search_query),
            'url' => base_url('/templates?search=' . urlencode($row['name']) . '&highlight=true'),
            'icon' => 'bi-file-earmark-code'
        ];
    }
    $stmt_templates->close();

    // 11. Search Incidents
    $stmt_incidents = $conn->prepare("SELECT id, target_name, incident_type FROM incident_reports WHERE target_name LIKE ? ORDER BY start_time DESC LIMIT 3");
    $stmt_incidents->bind_param("s", $search_param);
    $stmt_incidents->execute();
    $res_incidents = $stmt_incidents->get_result();
    while ($row = $res_incidents->fetch_assoc()) {
        $results[] = [
            'category' => 'Monitoring',
            'name' => "Incident for " . $row['target_name'],
            'description' => 'Type: ' . ucfirst($row['incident_type']),
            'score' => calculate_score($row['target_name'], $search_query),
            'url' => base_url('/incidents/' . $row['id']),
            'icon' => 'bi-shield-fill-exclamation'
        ];
    }
    $stmt_incidents->close();

    // --- IDE: Search Security Events ---
    $stmt_sec_events = $conn->prepare("
        SELECT id, rule, output, host_id, container_name 
        FROM security_events 
        WHERE rule LIKE ? OR output LIKE ? 
        ORDER BY event_time DESC 
        LIMIT 5
    ");
    $stmt_sec_events->bind_param("ss", $search_param, $search_param);
    $stmt_sec_events->execute();
    $res_sec_events = $stmt_sec_events->get_result();
    while ($row = $res_sec_events->fetch_assoc()) {
        $results[] = [
            'category' => 'Security',
            'name' => $row['rule'],
            'description' => 'Container: ' . ($row['container_name'] ?? 'N/A') . ' - ' . substr($row['output'], 0, 100) . '...',
            'score' => calculate_score($row['rule'], $search_query),
            'url' => base_url('/security-events?search=' . urlencode($row['rule'])),
            'icon' => 'bi-shield-lock-fill'
        ];
    }
    $stmt_sec_events->close();
    // --- NEW: Sort results by score DESC, then by name ASC ---
    usort($results, function($a, $b) {
        // FIX: Handle cases where score might be missing as a fallback
        $scoreA = $a['score'] ?? 0;
        $scoreB = $b['score'] ?? 0;
        if ($scoreA !== $scoreB) return $scoreB <=> $scoreA;
        return strnatcasecmp($a['name'], $b['name']);
    });

    echo json_encode(['status' => 'success', 'data' => $results]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server error while searching: ' . $e->getMessage()]);
} finally {
    $conn->close();
}

function get_container_actions($command, $container, $container_name, $host_id) {
    $actions = [
        ['name' => 'Copy Name', 'type' => 'copy', 'value' => $container_name, 'icon' => 'bi-clipboard'],
    ];

    $restart_action = ['name' => 'Restart', 'type' => 'api', 'url' => base_url("/api/hosts/{$host_id}/containers/{$container['Id']}/restart"), 'icon' => 'bi-arrow-repeat'];
    $logs_action = ['name' => 'View Logs', 'type' => 'modal', 'target' => '#viewLogsModal', 'data' => ['container-id' => $container['Id'], 'container-name' => $container_name, 'host-id' => $host_id], 'icon' => 'bi-card-text'];

    if ($command === 'restart') {
        $restart_action['is_primary'] = true;
        $actions[] = $restart_action;
    } elseif ($command === 'logs') {
        $logs_action['is_primary'] = true;
        $actions[] = $logs_action;
    }

    $actions[] = ['name' => 'Live Stats', 'type' => 'modal', 'target' => '#liveStatsModal', 'data' => ['container-id' => $container['Id'], 'container-name' => $container_name, 'host-id' => $host_id], 'icon' => 'bi-bar-chart-line-fill'];
    $actions[] = ['name' => 'Console', 'type' => 'modal', 'target' => '#execCommandModal', 'data' => ['container-id' => $container['Id'], 'container-name' => $container_name, 'host-id' => $host_id], 'icon' => 'bi-terminal-fill'];
    if ($command !== 'logs') $actions[] = $logs_action;
    if ($command !== 'restart') $actions[] = $restart_action;

    return $actions;
} 