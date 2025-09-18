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
    // 1. Instantiate the generator and generate the YAML content for Traefik
    $traefik_generator = new YamlGenerator();
    $traefik_yaml_output = $traefik_generator->generate();

    // 2. Determine deployment method: Git or Local File
    $git_enabled = (bool)get_setting('git_integration_enabled', false);
    $deploy_message = '';

    if ($git_enabled) {
        $git = new GitHelper();
        $repo_path = $git->setupRepository(); // This will clone or pull the repo

        // Write Traefik config to the repo
        $traefik_file_path = $repo_path . '/' . basename(YAML_OUTPUT_PATH);
        file_put_contents($traefik_file_path, $traefik_yaml_output);

        $commit_message = "Deploy configuration from Config Manager by " . ($_SESSION['username'] ?? 'system');
        $git->commitAndPush($repo_path, $commit_message); // The helper will now add all changes
        // Clean up only if it's a temporary path
        if (!$git->isPersistentPath($repo_path)) {
            $git->cleanup($repo_path);
        }
        $deploy_message = 'Konfigurasi berhasil di-push ke Git repository.';
    } else {
        // Fallback to writing only the Traefik config to a local file
        file_put_contents(YAML_OUTPUT_PATH, $traefik_yaml_output);
        $deploy_message = 'Konfigurasi Traefik berhasil di-deploy ke file lokal. Konfigurasi stack tidak di-deploy.';
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