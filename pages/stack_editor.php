<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$host_id = $_GET['id'] ?? null;
$stack_db_id = $_GET['stack_db_id'] ?? null;

if (!$host_id || !$stack_db_id) {
    header("Location: " . base_url('/hosts?status=error&message=Invalid request.'));
    exit;
}

$conn = Database::getInstance()->getConnection();

// Fetch host details
$stmt_host = $conn->prepare("SELECT * FROM docker_hosts WHERE id = ?");
$stmt_host->bind_param("i", $host_id);
$stmt_host->execute();
$host = $stmt_host->get_result()->fetch_assoc();
$stmt_host->close();

// Fetch stack details
$stmt_stack = $conn->prepare("SELECT * FROM application_stacks WHERE id = ? AND host_id = ?");
$stmt_stack->bind_param("ii", $stack_db_id, $host_id);
$stmt_stack->execute();
$stack = $stmt_stack->get_result()->fetch_assoc();
$stmt_stack->close();

if (!$host || !$stack) {
    header("Location: " . base_url('/hosts?status=error&message=Host or Stack not found.'));
    exit;
}

// Construct the path to the docker-compose.yml file
$base_compose_path = get_setting('default_compose_path', '');
$safe_host_name = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $host['name']);
$deployment_dir = rtrim($base_compose_path, '/') . '/' . $safe_host_name . '/' . $stack['stack_name'];
$compose_file_path = $deployment_dir . '/docker-compose.yml';

$compose_content = '';
if (file_exists($compose_file_path)) {
    $compose_content = file_get_contents($compose_file_path);
} else {
    $compose_content = "# ERROR: Compose file not found at:\n# " . htmlspecialchars($compose_file_path);
}

$active_page = 'stacks';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/host_nav.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-file-code"></i> Edit Compose: <?= htmlspecialchars($stack['stack_name']) ?></h1>
</div>

<form id="main-form" action="<?= base_url('/api/stacks/' . $stack_db_id . '/edit-compose') ?>" method="POST" data-redirect="<?= base_url('/hosts/' . $host_id . '/stacks') ?>">
    <input type="hidden" name="stack_id" value="<?= $stack_db_id ?>">
    <div class="card">
        <div class="card-body">
            <div class="mb-3">
                <label class="form-label"><strong>docker-compose.yml</strong></label>
                <textarea id="compose_content_editor" name="compose_content"><?= htmlspecialchars($compose_content) ?></textarea>
                <small class="form-text text-danger">Warning: Editing this file directly can lead to unexpected behavior. Changes will trigger an immediate redeployment of the stack.</small>
            </div>
        </div>
        <div class="card-footer">
            <a href="<?= base_url('/hosts/' . $host_id . '/stacks') ?>" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save and Redeploy</button>
        </div>
    </div>
</form>

<script>
window.pageInit = function() {
    const editor = CodeMirror.fromTextArea(document.getElementById('compose_content_editor'), {
        lineNumbers: true,
        mode: 'yaml',
        theme: document.body.classList.contains('dark-mode') ? 'monokai' : 'default',
        lineWrapping: true,
        indentUnit: 2,
        tabSize: 2
    });
    // Set a specific height for the editor
    editor.setSize(null, '60vh');

    // This is crucial: CodeMirror needs to be told to save its content back to the original textarea
    // before the form is submitted.
    const form = document.getElementById('main-form');
    form.addEventListener('submit', () => {
        editor.save();
    });
};
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>