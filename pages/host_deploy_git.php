<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$conn = Database::getInstance()->getConnection();

if (!isset($_GET['id'])) {
    header("Location: " . base_url('/hosts?status=error&message=Host ID not provided.'));
    exit;
}

$id = (int)$_GET['id'];
$stmt = $conn->prepare("SELECT * FROM docker_hosts WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
if (!($host = $result->fetch_assoc())) {
    header("Location: " . base_url('/hosts?status=error&message=Host not found.'));
    exit;
}
$stmt->close();
$conn->close();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <div>
        <h1 class="h2">Deploy New Stack from Git</h1>
        <p class="text-muted mb-0">For host: <a href="<?= base_url('/hosts/' . $id . '/details') ?>"><?= htmlspecialchars($host['name']) ?></a></p>
    </div>
    <div class="btn-toolbar mb-2 mb-md-0">
        <a href="<?= base_url('/hosts/' . $id . '/stacks') ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back to Stacks</a>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <form id="main-form" action="<?= base_url('/api/hosts/' . $id . '/deploy/git') ?>" method="POST" data-redirect="<?= base_url('/hosts/' . $id . '/stacks') ?>">
            <input type="hidden" name="host_id" value="<?= htmlspecialchars($id) ?>">
            
            <div class="mb-3">
                <label for="stack_name" class="form-label">Stack Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="stack_name" name="stack_name" required>
                <small class="form-text text-muted">A unique name for this deployment on the host.</small>
            </div>

            <hr>
            <h5 class="mb-3">Git Repository Details</h5>
            <div class="alert alert-info">
                <i class="bi bi-info-circle-fill me-2"></i>
                Ensure the application server has SSH access to the repository. The SSH key path is configured in <a href="<?= base_url('/settings') ?>">General Settings</a>.
            </div>
            <div class="mb-3">
                <label for="git_url" class="form-label">Repository URL (SSH) <span class="text-danger">*</span></label>
                <div class="input-group">
                    <input type="text" class="form-control" id="git_url" name="git_url" placeholder="e.g., git@github.com:user/repo.git or https://github.com/user/repo.git" required>
                    <button class="btn btn-outline-secondary" type="button" id="test-git-connection-btn">Test Connection</button>
                </div>
                <small class="form-text text-muted">For private HTTPS repos, credentials must be managed on the server where this app is running (e.g., using a credential helper).</small>
            </div>
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="git_branch" class="form-label">Branch</label>
                        <input type="text" class="form-control" id="git_branch" name="git_branch" value="main">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="compose_path" class="form-label">Compose File Path (optional)</label>
                        <input type="text" class="form-control" id="compose_path" name="compose_path" placeholder="e.g., deploy/docker-compose.yml">
                        <small class="form-text text-muted">Path to the compose file within the repository. Defaults to root.</small>
                    </div>
                </div>
            </div>

            <a href="<?= base_url('/hosts/' . $id . '/stacks') ?>" class="btn btn-secondary mt-3">Cancel</a>
            <button type="submit" class="btn btn-primary mt-3">Fetch & Deploy</button>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('main-form');
    if (!form) return;

    // This form uses the generic AJAX handler from main.js
    // It will either redirect on JSON success or do nothing on file download,
    // which is the desired behavior.
});
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>