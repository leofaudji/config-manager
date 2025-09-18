<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/YamlGenerator.php';

// Sesi dan otentikasi/otorisasi sudah ditangani oleh Router.
header('Content-Type: application/json');

/**
 * Runs a series of checks on the current database configuration to find potential issues.
 * @param mysqli $conn The database connection object.
 * @return array An array containing 'errors' and 'warnings'.
 */
function runConfigurationLinter(mysqli $conn): array
{
    $errors = [];
    $warnings = [];

    // --- Data Fetching ---
    $routers_res = $conn->query("SELECT name, service_name FROM routers")->fetch_all(MYSQLI_ASSOC);
    $services_res = $conn->query("SELECT name FROM services")->fetch_all(MYSQLI_ASSOC);
    $middlewares_res = $conn->query("SELECT id FROM middlewares")->fetch_all(MYSQLI_ASSOC);
    $router_middlewares_res = $conn->query("SELECT r.name as router_name, rm.middleware_id FROM router_middleware rm JOIN routers r ON rm.router_id = r.id")->fetch_all(MYSQLI_ASSOC);
    $services_with_servers_res = $conn->query("SELECT s.name, COUNT(sv.id) as server_count FROM services s LEFT JOIN servers sv ON s.id = sv.service_id GROUP BY s.id")->fetch_all(MYSQLI_ASSOC);
    $orphaned_services_res = $conn->query("SELECT name FROM services WHERE name NOT IN (SELECT DISTINCT service_name FROM routers)")->fetch_all(MYSQLI_ASSOC);
    $orphaned_middlewares_res = $conn->query("SELECT name FROM middlewares WHERE id NOT IN (SELECT DISTINCT middleware_id FROM router_middleware)")->fetch_all(MYSQLI_ASSOC);

    $service_names = array_column($services_res, 'name');
    $middleware_ids = array_column($middlewares_res, 'id');

    // --- Run Checks ---

    // 1. Router points to a non-existent service
    foreach ($routers_res as $router) {
        if (!in_array($router['service_name'], $service_names)) {
            $errors[] = "Router <strong>" . htmlspecialchars($router['name']) . "</strong> points to a non-existent service: <code>" . htmlspecialchars($router['service_name']) . "</code>.";
        }
    }

    // 2. Service has no servers
    foreach ($services_with_servers_res as $service) {
        if ($service['server_count'] == 0) {
            $warnings[] = "Service <strong>" . htmlspecialchars($service['name']) . "</strong> has no servers configured.";
        }
    }

    // 3. Orphaned services (defined but not used)
    foreach ($orphaned_services_res as $service) {
        $warnings[] = "Service <strong>" . htmlspecialchars($service['name']) . "</strong> is defined but not used by any router.";
    }

    // 4. Orphaned middlewares (defined but not used)
    foreach ($orphaned_middlewares_res as $middleware) {
        $warnings[] = "Middleware <strong>" . htmlspecialchars($middleware['name']) . "</strong> is defined but not used by any router.";
    }

    return ['errors' => $errors, 'warnings' => $warnings];
}

try {
    $conn = Database::getInstance()->getConnection();
    $generator = new YamlGenerator();
    $yaml_output = $generator->generate();
    $linter_results = runConfigurationLinter($conn);

    echo json_encode(['status' => 'success', 'content' => $yaml_output, 'linter' => $linter_results]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => "Failed to generate preview: " . $e->getMessage()]);
}

?>