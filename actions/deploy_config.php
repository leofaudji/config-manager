<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/Spyc.php';
// Sesi dan otentikasi/otorisasi sudah ditangani oleh Router.

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['id'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid request.']);
    exit();
}

$id_to_deploy = (int)$_POST['id'];
$conn = Database::getInstance()->getConnection();
$conn->begin_transaction();

try {
    // 1. Get the content of the configuration to deploy
    $stmt_get_content = $conn->prepare("SELECT yaml_content FROM config_history WHERE id = ?");
    $stmt_get_content->bind_param("i", $id_to_deploy);
    $stmt_get_content->execute();
    $result = $stmt_get_content->get_result();
    if ($result->num_rows === 0) {
        throw new Exception("History record #{$id_to_deploy} not found.");
    }
    $yaml_content = $result->fetch_assoc()['yaml_content'];
    $stmt_get_content->close();

    // 2. Parse the YAML content
    $data = Spyc::YAMLLoad($yaml_content);
    if (empty($data) || !is_array($data)) {
        throw new Exception("The selected history record contains invalid or empty YAML.");
    }

    // 3. Clear the current configuration from the database
    $conn->query("SET FOREIGN_KEY_CHECKS=0");
    $conn->query("TRUNCATE TABLE servers");
    $conn->query("TRUNCATE TABLE routers");
    $conn->query("TRUNCATE TABLE services");
    $conn->query("TRUNCATE TABLE transports");
    $conn->query("SET FOREIGN_KEY_CHECKS=1");

    // 4. Re-populate the database from the parsed YAML
    // 4a. Import Services and Servers
    $http_data = (isset($data['http']) && is_array($data['http'])) ? $data['http'] : [];
    $services = (isset($data['http']['services']) && is_array($data['http']['services'])) ? $data['http']['services'] : [];
    $stmtService = $conn->prepare("INSERT INTO services (name, pass_host_header, load_balancer_method, group_id) VALUES (?, ?, ?, ?)");
    $stmtServer = $conn->prepare("INSERT INTO servers (service_id, url) VALUES (?, ?)");
    foreach ($services as $serviceName => $serviceData) {
        // If serviceData is not an array (e.g., 'service:'), skip it.
        if (!is_array($serviceData)) continue;

        if (!isset($serviceData['loadBalancer']) || !is_array($serviceData['loadBalancer'])) {
            throw new Exception("Service '{$serviceName}' tidak memiliki 'loadBalancer' atau formatnya salah.");
        }
        $loadBalancer = $serviceData['loadBalancer'];
        $group_id = $serviceData['group_id'] ?? 1; // Default to group 1 if not specified in older YAMLs
        $passHostHeader = $loadBalancer['passHostHeader'] ?? true;
        $method = $loadBalancer['method'] ?? 'roundRobin';
        $stmtService->bind_param("sisi", $serviceName, $passHostHeader, $method, $group_id);
        if (!$stmtService->execute()) throw new Exception("Gagal menyimpan service '{$serviceName}': " . $stmtService->error);
        $serviceId = $conn->insert_id;
        $servers = $loadBalancer['servers'] ?? [];
        if (!is_array($servers)) {
            throw new Exception("Format YAML tidak valid: 'loadBalancer.servers' untuk service '{$serviceName}' harus berupa sebuah list/array.");
        }
        foreach ($servers as $server) {
            if (isset($server['url'])) {
                $stmtServer->bind_param("is", $serviceId, $server['url']);
                if (!$stmtServer->execute()) {
                    throw new Exception("Gagal menyimpan server untuk service '{$serviceName}': " . $stmtServer->error);
                }
            }
        }
    }
    $stmtService->close();
    $stmtServer->close();

    // 4b. Import Routers
    $routers = (isset($http_data['routers']) && is_array($http_data['routers'])) ? $http_data['routers'] : [];
    $stmtRouter = $conn->prepare("INSERT INTO routers (name, rule, entry_points, service_name, tls, cert_resolver, group_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
    foreach ($routers as $routerName => $routerData) {
        // If routerData is not an array, skip it.
        if (!is_array($routerData)) continue;

        $rule = $routerData['rule'] ?? '';
        $entryPointsRaw = $routerData['entryPoints'] ?? ['web'];
        if (!is_array($entryPointsRaw)) throw new Exception("Format YAML tidak valid: 'entryPoints' untuk router '{$routerName}' harus berupa sebuah list/array.");
        $entryPoints = implode(',', $entryPointsRaw);
        $serviceName = $routerData['service'] ?? '';
        $group_id = $routerData['group_id'] ?? 1; // Default to group 1 if not specified in older YAMLs
        $tls_enabled = 0;
        $cert_resolver = null;
        if (isset($routerData['tls']) && is_array($routerData['tls']) && !empty($routerData['tls']['certResolver'])) {
            $tls_enabled = 1;
            $cert_resolver = $routerData['tls']['certResolver'];
        }
        $stmtRouter->bind_param("ssssisi", $routerName, $rule, $entryPoints, $serviceName, $tls_enabled, $cert_resolver, $group_id);
        if (!$stmtRouter->execute()) throw new Exception("Gagal menyimpan router '{$routerName}': " . $stmtRouter->error);
    }
    $stmtRouter->close();

    // 4c. Import Transports
    $transports = (isset($data['serversTransports']) && is_array($data['serversTransports'])) ? $data['serversTransports'] : [];
    $stmtTransport = $conn->prepare("INSERT INTO transports (name, insecure_skip_verify) VALUES (?, ?)");
    foreach ($transports as $transportName => $transportData) {
        // If transportData is not an array, skip it.
        if (!is_array($transportData)) continue;

        $insecureSkipVerify = $transportData['insecureSkipVerify'] ?? false;
        $stmtTransport->bind_param("si", $transportName, $insecureSkipVerify);
        if (!$stmtTransport->execute()) throw new Exception("Gagal menyimpan transport '{$transportName}': " . $stmtTransport->error);
    }
    $stmtTransport->close();

    // 5. Archive the current active configuration
    $conn->query("UPDATE config_history SET status = 'archived' WHERE status = 'active'");

    // 6. Activate the new configuration
    $stmt_activate = $conn->prepare("UPDATE config_history SET status = 'active' WHERE id = ?");
    $stmt_activate->bind_param("i", $id_to_deploy);
    $stmt_activate->execute();
    $stmt_activate->close();

    // 7. Write the new active configuration to the file
    $active_traefik_host_id = (int)get_setting('active_traefik_host_id', 1);
    $stmt_host = $conn->prepare("SELECT name FROM traefik_hosts WHERE id = ?");
    $stmt_host->bind_param("i", $active_traefik_host_id);
    $stmt_host->execute();
    $host_result = $stmt_host->get_result()->fetch_assoc();
    $stmt_host->close();
    if (!$host_result) {
        throw new Exception("Active Traefik Host with ID {$active_traefik_host_id} not found.");
    }
    $host_name = $host_result['name'];
    $host_dir_name = strtolower(preg_replace('/[^a-zA-Z0-9_.-]/', '_', $host_name));

    // --- Unified Deployment Logic ---
    // 1. Always write to the local file path first.
    $base_output_path = get_setting('yaml_output_path', PROJECT_ROOT . '/traefik-configs');
    $final_output_dir = rtrim($base_output_path, '/') . '/' . $host_dir_name;
    if (!is_dir($final_output_dir) && !mkdir($final_output_dir, 0755, true)) {
        throw new Exception("Failed to create output directory: {$final_output_dir}. Please check permissions.");
    }
    $final_yaml_file_path = $final_output_dir . '/dynamic.yml';
    file_put_contents($final_yaml_file_path, $yaml_content);

    // 2. If Git integration is enabled, now sync the changes.
    $git_enabled = (bool)get_setting('git_integration_enabled', false);
    if ($git_enabled) {
        $git = new GitHelper();
        $repo_path = $git->setupRepository();
        $repo_output_dir = $repo_path . '/' . $host_dir_name;
        if (!is_dir($repo_output_dir)) mkdir($repo_output_dir, 0755, true);
        copy($final_yaml_file_path, $repo_output_dir . '/dynamic.yml');
        $commit_message = "Redeploy configuration #{$id_to_deploy} for {$host_name} by " . ($_SESSION['username'] ?? 'system');
        $git->commitAndPush($repo_path, $commit_message);
    }

    $conn->commit();
    log_activity($_SESSION['username'], 'Configuration Deployed', "Deployed history record #{$id_to_deploy}.");
    echo json_encode(['status' => 'success', 'message' => "Configuration #{$id_to_deploy} has been successfully deployed and synchronized with the database."]);

} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to deploy configuration: ' . $e->getMessage()]);
}

$conn->close();
?>