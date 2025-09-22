<?php
require_once 'includes/bootstrap.php';
require_once 'includes/GitHelper.php';
require_once 'includes/YamlGenerator.php';

// Check if it's an AJAX request
$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

if ($is_ajax) {
    header('Content-Type: application/json');
}

$conn = Database::getInstance()->getConnection();
$conn->begin_transaction();

try {
    // --- Determine which host to deploy for ---
    $group_id_from_request = isset($_GET['group_id']) ? (int)$_GET['group_id'] : 0;
    $host_id_for_deployment = 0; // Initialize to 0

    if ($group_id_from_request > 0) {
        $stmt_group_host = $conn->prepare("SELECT traefik_host_id FROM `groups` WHERE id = ?");
        $stmt_group_host->bind_param("i", $group_id_from_request);
        $stmt_group_host->execute();
        $group_host_result = $stmt_group_host->get_result()->fetch_assoc();
        $stmt_group_host->close();

        // If the group is associated with a specific host, use that host for deployment.
        if ($group_host_result && !empty($group_host_result['traefik_host_id'])) {
            $host_id_for_deployment = (int)$group_host_result['traefik_host_id'];
        } else {
            // This is a global group or a group without a host.
            // Throw an error to prevent accidentally deploying the wrong host's config.
            throw new Exception("The selected group is 'Global' and not assigned to a specific host. Please use the main 'Generate & Deploy' button to deploy the active host's configuration.");
        }
    } else {
        // No group context, so use the globally active host.
        $host_id_for_deployment = (int)get_setting('active_traefik_host_id', 1);
    }

    // 1. Instantiate the generator and generate the YAML content for Traefik
    $traefik_generator = new YamlGenerator();
    $traefik_yaml_output = $traefik_generator->generate(0, false, $host_id_for_deployment);

    // 2. Determine deployment path and method
    $git_enabled = (bool)get_setting('git_integration_enabled', false);
    $deploy_message = '';

    $stmt_host = $conn->prepare("SELECT name FROM traefik_hosts WHERE id = ?");
    $stmt_host->bind_param("i", $host_id_for_deployment);
    $stmt_host->execute();
    $host_result = $stmt_host->get_result()->fetch_assoc();
    $stmt_host->close();

    if (!$host_result) {
        throw new Exception("Target Traefik Host with ID {$host_id_for_deployment} not found.");
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
    file_put_contents($final_yaml_file_path, $traefik_yaml_output);
    $deploy_message = "Konfigurasi untuk '{$host_name}' berhasil di-deploy ke file lokal: {$final_yaml_file_path}";

    // 2. If Git integration is enabled, now sync the changes.
    if ($git_enabled) {
        $git = new GitHelper();
        $repo_path = $git->setupRepository(); // This will clone or pull the repo

        // The destination in the repo should mirror the local structure
        $repo_output_dir = $repo_path . '/' . $host_dir_name;
        if (!is_dir($repo_output_dir) && !mkdir($repo_output_dir, 0755, true)) {
            throw new Exception("Failed to create output directory inside git repo: {$final_output_dir}.");
        }
        $repo_yaml_file_path = $repo_output_dir . '/dynamic.yml';
        
        // Copy the generated file to the repo working directory
        copy($final_yaml_file_path, $repo_yaml_file_path);

        $commit_message = "Deploy configuration for {$host_name} from Config Manager by " . ($_SESSION['username'] ?? 'system');
        $git->commitAndPush($repo_path, $commit_message); // The helper will now add all changes
        // Clean up only if it's a temporary path
        if (!$git->isPersistentPath($repo_path)) {
            $git->cleanup($repo_path);
        }
        $deploy_message .= " dan berhasil di-push ke Git repository.";
    }


    // 3. Archive the current active Traefik configuration in history
    $conn->query("UPDATE config_history SET status = 'archived' WHERE status = 'active'");

    // 4. Save the new Traefik configuration to history as 'active'
    $new_history_id = 0; // Initialize
    if (!empty($traefik_yaml_output)) {
        $stmt = $conn->prepare("INSERT INTO config_history (yaml_content, generated_by, status) VALUES (?, ?, 'active')");
        $generated_by = $_SESSION['username'] ?? 'system';
        $stmt->bind_param("ss", $traefik_yaml_output, $generated_by);
        $stmt->execute();
        $new_history_id = $stmt->insert_id;
        $stmt->close();
    }

    // 5. Log this activity
    log_activity($_SESSION['username'], 'Configuration Generated & Deployed', "New active Traefik configuration (History ID #{$new_history_id}) was generated and deployed. Method: " . ($git_enabled ? 'Git' : 'File'));

    // Commit the transaction
    $conn->commit();

    if ($is_ajax) {
        echo json_encode(['status' => 'success', 'message' => $deploy_message]);
    } else {
        // 6. Set headers to trigger file download for non-AJAX requests
        header('Content-Type: application/x-yaml');
        header('Content-Disposition: attachment; filename="' . basename(YAML_OUTPUT_PATH) . '"');
        // 7. Output the Traefik content for download
        echo $traefik_yaml_output;
    }

} catch (Exception $e) {
    $conn->rollback();
    $error_message = "Failed to generate configuration: " . $e->getMessage();
    if ($is_ajax) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $error_message]);
    } else {
        header("Location: " . base_url('/?status=error&message=' . urlencode($error_message)));
    }
    exit();
}

$conn->close();
?>