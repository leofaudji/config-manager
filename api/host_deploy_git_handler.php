<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/GitHelper.php';
require_once __DIR__ . '/../includes/DockerClient.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

$conn = Database::getInstance()->getConnection();
$repo_path = null; // Initialize for cleanup
$git = new GitHelper(); // Initialize GitHelper

try {
    // --- Input Validation ---
    $host_id = $_POST['host_id'] ?? null;
    $stack_name = trim($_POST['stack_name'] ?? '');
    $git_url = trim($_POST['git_url'] ?? '');
    $git_branch = trim($_POST['git_branch'] ?? 'main');
    $compose_path = trim($_POST['compose_path'] ?? '');

    if (empty($host_id) || empty($stack_name) || empty($git_url)) {
        throw new InvalidArgumentException("Host ID, Stack Name, and Git URL are required.");
    }

    // Validate Git URL format
    $is_ssh = str_starts_with($git_url, 'git@');
    $is_https = str_starts_with($git_url, 'https://');
    if (!$is_ssh && !$is_https) {
        throw new InvalidArgumentException("Invalid Git URL format. Please use SSH (git@...) or HTTPS (https://...) format.");
    }

    // --- Get Host Details ---
    $stmt = $conn->prepare("SELECT * FROM docker_hosts WHERE id = ?");
    $stmt->bind_param("i", $host_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if (!($host = $result->fetch_assoc())) {
        throw new Exception("Host not found.");
    }
    $stmt->close();

    // --- Check if Host is Swarm Manager ---
    $dockerClient = new DockerClient($host);
    $dockerInfo = $dockerClient->getInfo();
    $is_swarm_manager = (isset($dockerInfo['Swarm']['ControlAvailable']) && $dockerInfo['Swarm']['ControlAvailable'] === true);

    if ($is_swarm_manager) {
        // --- SWARM HOST WORKFLOW ---
        $repo_path = $git->cloneOrPull($git_url, $git_branch);
        $compose_file_name = !empty($compose_path) ? $compose_path : 'docker-compose.yml';
        $compose_file_full_path = $repo_path . '/' . $compose_file_name;

        if (!file_exists($compose_file_full_path)) throw new Exception("Compose file not found at '{$compose_file_name}' in the repository.");
        $compose_content = file_get_contents($compose_file_full_path);
        if (empty($compose_content)) throw new Exception("Compose file is empty.");

        $dockerClient->createStack($stack_name, $compose_content);
        $git->cleanup($repo_path);

        log_activity($_SESSION['username'], 'Stack Deployed from Git', "Deployed stack '{$stack_name}' on host '{$host['name']}' from Git repo '{$git_url}'.");
        echo json_encode(['status' => 'success', 'message' => "Stack '{$stack_name}' is being deployed from Git."]);
    } else {
        // --- STANDALONE HOST WORKFLOW ---
        $repo_path = $git->cloneOrPull($git_url, $git_branch);
        $compose_file_name = !empty($compose_path) ? $compose_path : 'docker-compose.yml';
        $compose_file_full_path = $repo_path . '/' . $compose_file_name;
        if (!file_exists($compose_file_full_path)) throw new Exception("Compose file not found at '{$compose_file_name}' in the repository.");

        // Create a Zip archive
        $zip_file_path = rtrim(sys_get_temp_dir(), '/') . '/' . $stack_name . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($zip_file_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) throw new Exception("Cannot create zip archive.");

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($repo_path), RecursiveIteratorIterator::LEAVES_ONLY);
        foreach ($files as $name => $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen($repo_path) + 1);
                $zip->addFile($filePath, $relativePath);
            }
        }
        $zip->close();
        $git->cleanup($repo_path);

        // Send the file for download
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($zip_file_path) . '"');
        header('Content-Length: ' . filesize($zip_file_path));
        readfile($zip_file_path);
        unlink($zip_file_path); // Cleanup zip file

        log_activity($_SESSION['username'], 'Compose Project Generated from Git', "Generated compose project '{$stack_name}' for host '{$host['name']}' from Git repo '{$git_url}'.");
        exit; // Exit after file download
    }

} catch (Exception $e) {
    if (isset($git) && isset($repo_path)) $git->cleanup($repo_path);
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();
?>