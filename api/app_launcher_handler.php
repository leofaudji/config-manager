<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/AppLauncherHelper.php';

// --- Streaming Setup ---
header('Content-Type: text/plain; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-cache');
@ini_set('zlib.output_compression', 0);
if (ob_get_level() > 0) {
    for ($i = 0; $i < ob_get_level(); $i++) {
        ob_end_flush();
    }
}
if (ob_get_level() > 0) { for ($i = 0; $i < ob_get_level(); $i++) { ob_end_flush(); } }
ob_implicit_flush(1);

try {
    // Call the centralized deployment logic
    AppLauncherHelper::executeDeployment($_POST);

    // --- IDE: Traefik Integration Logic ---
    if (isset($_POST['create_traefik_route']) && $_POST['create_traefik_route'] === '1') {
        AppLauncherHelper::stream_message("Traefik integration enabled. Creating router and service...");
        $conn = Database::getInstance()->getConnection();
        $conn->begin_transaction();

        try {
            // 1. Get Host IP and Published Port
            $host_id = $_POST['host_id'];
            $stmt_host = $conn->prepare("SELECT docker_api_url FROM docker_hosts WHERE id = ?");
            $stmt_host->bind_param("i", $host_id);
            $stmt_host->execute();
            $host_details = $stmt_host->get_result()->fetch_assoc();
            $stmt_host->close();

            if (!$host_details) throw new Exception("Host details not found for Traefik service creation.");

            // Extract IP from tcp://IP:PORT
            preg_match('/tcp:\/\/([^:]+):/', $host_details['docker_api_url'], $ip_matches);
            $host_ip = $ip_matches[1] ?? null;
            $published_port = $_POST['host_port'] ?? null;

            if (!$host_ip || !$published_port) {
                throw new Exception("Host IP or Published Port is missing. Cannot create Traefik service.");
            }

            // 2. Create Traefik Service
            $traefik_service_name = 'service-' . strtolower(trim($_POST['stack_name']));
            $server_url = "http://{$host_ip}:{$published_port}";

            $stmt_service = $conn->prepare("INSERT INTO services (name, pass_host_header) VALUES (?, 1) ON DUPLICATE KEY UPDATE name=name");
            $stmt_service->bind_param("s", $traefik_service_name);
            $stmt_service->execute();
            $service_id = $stmt_service->insert_id ?: $conn->query("SELECT id FROM services WHERE name = '{$traefik_service_name}'")->fetch_assoc()['id'];
            $stmt_service->close();

            $stmt_server = $conn->prepare("INSERT INTO servers (service_id, url) VALUES (?, ?)");
            $stmt_server->bind_param("is", $service_id, $server_url);
            $stmt_server->execute();
            $stmt_server->close();

            // 3. Create Traefik Router
            $router_name = $_POST['traefik_router_name'];
            $router_rule = $_POST['traefik_router_rule'];
            $group_id = $_POST['traefik_group_id'];
            $tls = isset($_POST['traefik_tls_enabled']) ? 1 : 0;
            $cert_resolver = $tls ? ($_POST['traefik_cert_resolver'] ?? null) : null;

            $stmt_router = $conn->prepare("INSERT INTO routers (name, rule, entry_points, service_name, tls, cert_resolver, group_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $entry_points = $tls ? 'websecure' : 'web';
            $stmt_router->bind_param("ssssisi", $router_name, $router_rule, $entry_points, $traefik_service_name, $tls, $cert_resolver, $group_id);
            $stmt_router->execute();
            $stmt_router->close();

            $conn->commit();
            AppLauncherHelper::stream_message("Successfully created Traefik router '{$router_name}' and service '{$traefik_service_name}'.", "SUCCESS");

            // 4. Trigger Traefik config deployment
            if (get_setting('auto_deploy_enabled', '1') == '1') {
                trigger_background_deployment($group_id);
                AppLauncherHelper::stream_message("Triggering Traefik configuration deployment for group ID #{$group_id}...");
            } else {
                set_config_dirty();
                AppLauncherHelper::stream_message("Traefik configuration has been marked as dirty. Please deploy manually.", "WARN");
            }

        } catch (Exception $e) {
            $conn->rollback();
            AppLauncherHelper::stream_message("Failed to create Traefik route: " . $e->getMessage(), "ERROR");
            // Do not re-throw, as the main deployment might have succeeded.
        }
    }
    // --- End Traefik Integration ---

    AppLauncherHelper::stream_message("---");
    AppLauncherHelper::stream_message("Deployment finished successfully!", "SUCCESS");
    echo "_DEPLOYMENT_COMPLETE_";

} catch (Throwable $e) { // Catch any throwable error
    AppLauncherHelper::stream_message($e->getMessage(), 'ERROR');
    echo "_DEPLOYMENT_FAILED_";
} finally {
    // Ensure the log file is always closed at the end of the script.
    AppLauncherHelper::closeLogFile();
}
?>