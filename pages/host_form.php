<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$conn = Database::getInstance()->getConnection();

$is_edit = false;
$host = [
    'id' => '',
    'name' => '',
    'docker_api_url' => 'tcp://',
    'description' => '',
    'tls_enabled' => 0,
    'ca_cert_path' => '',
    'client_cert_path' => '',
    'client_key_path' => '',
    'default_volume_path' => '/opt/stacks',
    'registry_url' => '',
    'registry_username' => '',
    'registry_password' => ''
];

if (isset($_GET['id'])) {
    $is_edit = true;
    $id = $_GET['id'];
    $stmt = $conn->prepare("SELECT * FROM docker_hosts WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $host = $result->fetch_assoc();
    }
    $stmt->close();
}

require_once __DIR__ . '/../includes/header.php';
?>

<h3><?= $is_edit ? 'Edit' : 'Add' ?> Docker Host</h3>
<p class="text-muted">Configure a remote Docker host to enable remote container management.</p>
<hr>

<div class="card">
    <div class="card-body">
        <form id="main-form" action="<?= base_url($is_edit ? '/hosts/' . $host['id'] . '/edit' : '/hosts/new') ?>" method="POST" data-redirect="/hosts">
            <input type="hidden" name="id" value="<?= htmlspecialchars($host['id']) ?>">
            
            <div class="mb-3">
                <label for="host-name" class="form-label">Host Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="host-name" name="name" value="<?= htmlspecialchars($host['name']) ?>" required>
                <small class="form-text text-muted">A friendly name for this host, e.g., `Production Server 1`.</small>
            </div>

            <div class="mb-3">
                <label for="docker_api_url" class="form-label">Docker API URL <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="docker_api_url" name="docker_api_url" value="<?= htmlspecialchars($host['docker_api_url']) ?>" placeholder="e.g., tcp://192.168.1.100:2376" required>
                <small class="form-text text-muted">The endpoint for the Docker daemon API. Use `tcp://...` for remote hosts.</small>
            </div>

            <div class="mb-3">
                <label for="host-description" class="form-label">Description</label>
                <input type="text" class="form-control" id="host-description" name="description" value="<?= htmlspecialchars($host['description']) ?>">
            </div>

            <div class="mb-3">
                <label for="default_volume_path" class="form-label">Default Volume Path</label>
                <input type="text" class="form-control" id="default_volume_path" name="default_volume_path" value="<?= htmlspecialchars($host['default_volume_path']) ?>" placeholder="/opt/stacks">
                <small class="form-text text-muted">Base path on the host where application data volumes will be created (e.g., `/opt/stacks`).</small>
            </div>

            <hr>
            <h5 class="mb-3">Private Registry Credentials (Optional)</h5>
            <div class="alert alert-warning small">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <strong>Warning:</strong> Credentials are saved as plain text in the database. Use access tokens with limited permissions where possible.
            </div>
            <div class="row">
                <div class="col-md-12 mb-3">
                    <label for="registry_url" class="form-label">Registry URL</label>
                    <input type="text" class="form-control" id="registry_url" name="registry_url" value="<?= htmlspecialchars($host['registry_url'] ?? '') ?>" placeholder="e.g., docker.io (for Docker Hub), ghcr.io">
                </div>
                <div class="col-md-6 mb-3">
                    <label for="registry_username" class="form-label">Username</label>
                    <input type="text" class="form-control" id="registry_username" name="registry_username" value="<?= htmlspecialchars($host['registry_username'] ?? '') ?>">
                </div>
                <div class="col-md-6 mb-3">
                    <label for="registry_password" class="form-label">Password / Access Token</label>
                    <input type="password" class="form-control" id="registry_password" name="registry_password" value="<?= htmlspecialchars($host['registry_password'] ?? '') ?>">
                </div>
            </div>

            <hr>
            <h5 class="mb-3">Security</h5>
            <div class="form-check form-switch mb-3">
                <input type="hidden" name="tls_enabled" value="0">
                <input class="form-check-input" type="checkbox" role="switch" id="tls_enabled" name="tls_enabled" value="1" <?= ($host['tls_enabled'] ?? 0) == 1 ? 'checked' : '' ?>>
                <label class="form-check-label" for="tls_enabled">Enable TLS Client Verification</label>
                <small class="form-text text-muted d-block">Required for secure remote connections (port 2376).</small>
            </div>

            <div id="tls-settings-container" style="<?= ($host['tls_enabled'] ?? 0) == 1 ? '' : 'display: none;' ?>">
                <div class="mb-3">
                    <label for="ca_cert_path" class="form-label">CA Certificate Path</label>
                    <input type="text" class="form-control" id="ca_cert_path" name="ca_cert_path" value="<?= htmlspecialchars($host['ca_cert_path']) ?>" placeholder="/path/on/server/to/ca.pem">
                    <small class="form-text text-muted">Absolute path to the CA certificate file on the application server.</small>
                </div>
                <div class="mb-3">
                    <label for="client_cert_path" class="form-label">Client Certificate Path</label>
                    <input type="text" class="form-control" id="client_cert_path" name="client_cert_path" value="<?= htmlspecialchars($host['client_cert_path']) ?>" placeholder="/path/on/server/to/cert.pem">
                    <small class="form-text text-muted">Absolute path to the client certificate file on the application server.</small>
                </div>
                <div class="mb-3">
                    <label for="client_key_path" class="form-label">Client Key Path</label>
                    <input type="text" class="form-control" id="client_key_path" name="client_key_path" value="<?= htmlspecialchars($host['client_key_path']) ?>" placeholder="/path/on/server/to/key.pem">
                    <small class="form-text text-muted">Absolute path to the client key file on the application server.</small>
                </div>
            </div>

            <a href="<?= base_url('/hosts') ?>" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary"><?= $is_edit ? 'Update Host' : 'Save Host' ?></button>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const tlsToggle = document.getElementById('tls_enabled');
    const tlsContainer = document.getElementById('tls-settings-container');

    tlsToggle.addEventListener('change', function() {
        tlsContainer.style.display = this.checked ? 'block' : 'none';
    });
});
</script>

<?php
$conn->close();
require_once __DIR__ . '/../includes/footer.php';
?>