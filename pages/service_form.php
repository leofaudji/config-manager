<?php
require_once __DIR__ . '/../includes/bootstrap.php';
// Sesi dan otentikasi/otorisasi sudah ditangani oleh Router.
$conn = Database::getInstance()->getConnection();

// --- Determine Mode: 'add', 'edit', 'clone' ---
$page_mode = 'add';
$page_title = 'Tambah Service';
$form_action = base_url('/services/new');
$submit_button_text = 'Simpan';
$original_name_for_clone = '';

$service = [
    'id' => '',
    'name' => get_setting('default_service_prefix', 'service-'),
    'pass_host_header' => 1,
    'load_balancer_method' => 'roundRobin',
    'group_id' => getDefaultGroupId(),
    'health_check_enabled' => 0,
    'health_check_endpoint' => '/health',
    'health_check_interval' => 30,
    'health_check_timeout' => 5,
    'unhealthy_threshold' => 3,
    'healthy_threshold' => 2,
    'health_check_type' => 'http',
    'target_stack_id' => null,
];
$servers = [];

if (isset($_GET['id'])) { // Edit Mode
    $page_mode = 'edit';
    $id = $_GET['id'];
    $stmt = $conn->prepare("SELECT * FROM services WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        header('Location: ' . base_url('/services?status=error&message=' . urlencode('Service not found.')));
        exit;
    }
    $service = $result->fetch_assoc();
    $stmt->close();

    // Ambil server yang terhubung
    $stmt_servers = $conn->prepare("SELECT * FROM servers WHERE service_id = ? ORDER BY url ASC");
    $stmt_servers->bind_param("i", $id);
    $stmt_servers->execute();
    $servers_result = $stmt_servers->get_result();
    $servers = $servers_result->fetch_all(MYSQLI_ASSOC);
    $stmt_servers->close();
 
    $page_title = 'Edit Service';
    $form_action = base_url('/services/' . htmlspecialchars($service['id']) . '/edit');
    $submit_button_text = 'Update';

} elseif (isset($_GET['clone_id'])) { // Clone Mode
    $page_mode = 'clone';
    $id = $_GET['clone_id'];
    $stmt = $conn->prepare("SELECT * FROM services WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        header('Location: ' . base_url('/services?status=error&message=' . urlencode('Service to clone not found.')));
        exit;
    }
    $service = $result->fetch_assoc();
    $stmt->close();

    // Fetch its servers
    $stmt_servers = $conn->prepare("SELECT url FROM servers WHERE service_id = ? ORDER BY url ASC");
    $stmt_servers->bind_param("i", $id);
    $stmt_servers->execute();
    $servers_result = $stmt_servers->get_result();
    $servers = $servers_result->fetch_all(MYSQLI_ASSOC);
    $stmt_servers->close();

    $original_name_for_clone = $service['name'];

    // Suggest a new unique name
    $clone_count = 1;
    do {
        $new_name = $original_name_for_clone . '-clone' . ($clone_count > 1 ? $clone_count : '');
        $stmt_check = $conn->prepare("SELECT id FROM services WHERE name = ?");
        $stmt_check->bind_param("s", $new_name);
        $stmt_check->execute();
        $name_exists = $stmt_check->get_result()->num_rows > 0;
        $stmt_check->close();
        $clone_count++;
    } while ($name_exists);

    $service['name'] = $new_name;
    $service['id'] = ''; // This will be a new entry

    $page_title = 'Clone Service: ' . htmlspecialchars($original_name_for_clone);
    $form_action = base_url('/services/new');
    $submit_button_text = 'Save as New Service';
}

// Ambil daftar grup untuk dropdown
$groups_result = $conn->query("SELECT id, name FROM `groups` ORDER BY name ASC");

// Ambil daftar application stacks untuk dropdown health check tipe Docker
$stacks_result = $conn->query("SELECT s.id, s.stack_name, h.name as host_name FROM application_stacks s JOIN docker_hosts h ON s.host_id = h.id ORDER BY h.name ASC, s.stack_name ASC");

require_once __DIR__ . '/../includes/header.php';
?>
<h3><?= $page_title ?></h3>
<hr>

<div class="card">
    <div class="card-header">
        <ul class="nav nav-tabs card-header-tabs" id="service-form-tabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="general-tab" data-bs-toggle="tab" data-bs-target="#general-tab-pane" type="button" role="tab" aria-controls="general-tab-pane" aria-selected="true">General</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="health-check-tab" data-bs-toggle="tab" data-bs-target="#health-check-tab-pane" type="button" role="tab" aria-controls="health-check-tab-pane" aria-selected="false">Health Check</button>
            </li>
        </ul>
    </div>
    <div class="card-body">
        <form id="main-form" action="<?= $form_action ?>" method="POST" data-redirect="/services">
            <input type="hidden" name="id" value="<?= htmlspecialchars($service['id'] ?? '') ?>">
            <div class="mb-3">
                <label for="name" class="form-label">Service Name</label>
                <input type="text" class="form-control" id="name" name="name" value="<?= htmlspecialchars($service['name']) ?>" required>
            </div>
            <div class="tab-content" id="service-form-tab-content">
                <div class="tab-pane fade show active" id="general-tab-pane" role="tabpanel" aria-labelledby="general-tab" tabindex="0">
                    <div class="mb-3 form-check">
                        <input type="hidden" name="pass_host_header" value="0">
                        <input type="checkbox" class="form-check-input" id="pass_host_header" name="pass_host_header" value="1" <?= $service['pass_host_header'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="pass_host_header">Pass Host Header</label>
                    </div>
                    <div class="mb-3" id="load_balancer_method_group">
                        <label for="load_balancer_method" class="form-label">Load Balancer Method</label>
                        <select class="form-select" id="load_balancer_method" name="load_balancer_method" required>
                            <?php 
                            $methods = ['roundRobin', 'leastConn', 'ipHash', 'leastTime', 'leastBandwidth'];
                            foreach ($methods as $method): ?>
                                <option value="<?= $method ?>" <?= ($service['load_balancer_method'] ?? 'roundRobin') == $method ? 'selected' : '' ?>><?= $method ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="group_id" class="form-label">Group</label>
                        <select class="form-select" id="group_id" name="group_id" required>
                            <option value="">-- Pilih Grup --</option>
                            <?php while($group = $groups_result->fetch_assoc()): ?>
                                <option value="<?= htmlspecialchars($group['id']) ?>" <?= ($service['group_id'] ?? getDefaultGroupId()) == $group['id'] ? 'selected' : '' ?>><?= htmlspecialchars($group['name']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <hr>
                    <h6>Metode Input Server <span class="text-danger">*</span></h6>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="server_input_type" id="server_type_individual" value="individual" checked>
                        <label class="form-check-label" for="server_type_individual">URL Individual</label>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="radio" name="server_input_type" id="server_type_cidr" value="cidr">
                        <label class="form-check-label" for="server_type_cidr">Rentang Jaringan (CIDR)</label>
                    </div>

                    <!-- Opsi A: URL Individual -->
                    <div id="individual_servers_section">
                        <h5>Servers</h5>
                        <div id="servers_container">
                            <?php if (empty($servers)): ?>
                                <div class="input-group mb-2">
                                    <input type="url" class="form-control" name="server_urls[]" placeholder="http://10.0.0.1:8080" required>
                                </div>
                            <?php else: ?>
                                <?php foreach ($servers as $server): ?>
                                    <div class="input-group mb-2">
                                        <input type="url" class="form-control" name="server_urls[]" value="<?= htmlspecialchars($server['url']) ?>" required>
                                        <button class="btn btn-outline-danger" type="button" onclick="this.parentElement.remove()">Hapus</button>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <button type="button" class="btn btn-outline-success btn-sm" id="add_server_btn"><i class="bi bi-plus-circle"></i> Tambah Server</button>
                    </div>

                    <!-- Opsi B: CIDR -->
                    <div id="cidr_server_section" style="display: none;">
                        <h5>Detail Jaringan</h5>
                        <div class="mb-2"><input type="text" class="form-control" name="cidr_address" placeholder="Contoh: 192.168.0.0/24"></div>
                        <div class="row">
                            <div class="col-md-8"><select name="cidr_protocol_prefix" class="form-select">
                                    <option value="http://">http://</option>
                                    <option value="https://">https://</option>
                                </select></div>
                            <div class="col-md-4"><input type="number" class="form-control" name="cidr_port" placeholder="Port (e.g., 8080)"></div>
                        </div>
                    </div>
                </div>
                <div class="tab-pane fade" id="health-check-tab-pane" role="tabpanel" aria-labelledby="health-check-tab" tabindex="0">
                    <div class="form-check form-switch mb-3">
                        <input type="hidden" name="health_check_enabled" value="0">
                        <input class="form-check-input" type="checkbox" role="switch" id="health_check_enabled" name="health_check_enabled" value="1" <?= $service['health_check_enabled'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="health_check_enabled">Enable Health Check & Auto-Healing</label>
                        <small class="form-text text-muted d-block">If enabled, the system will periodically check the service's health and restart it if it becomes unresponsive.</small>
                    </div>

                    <div id="health-check-options" style="<?= $service['health_check_enabled'] ? '' : 'display: none;' ?>">
                        <hr>
                        <h6>Health Check Type</h6>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="health_check_type" id="health_check_type_http" value="http" <?= ($service['health_check_type'] ?? 'http') === 'http' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="health_check_type_http">HTTP Endpoint</label>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="radio" name="health_check_type" id="health_check_type_docker" value="docker" <?= ($service['health_check_type'] ?? '') === 'docker' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="health_check_type_docker">Docker Internal Health</label>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="radio" name="health_check_type" id="health_check_type_tcp" value="tcp">
                            <label class="form-check-label" for="health_check_type_tcp">TCP Port Connection</label>
                        </div>

                        <div id="http-options-section" class="mb-3">
                            <label for="health_check_endpoint" class="form-label">Health Check Endpoint</label>
                            <input type="text" class="form-control" id="health_check_endpoint" name="health_check_endpoint" value="<?= htmlspecialchars($service['health_check_endpoint']) ?>" placeholder="/health">
                            <small class="form-text text-muted">The HTTP endpoint to check (e.g., `/health`, `/api/status`). It must return a 2xx status code for the service to be considered healthy.</small>
                        </div>

                        <div id="docker-options-section" class="mb-3" style="display: none;">
                            <label for="target_stack_id" class="form-label">Target Application Stack</label>
                            <select class="form-select" id="target_stack_id" name="target_stack_id">
                                <option value="">-- Select the stack to monitor --</option>
                                <?php while($stack = $stacks_result->fetch_assoc()): ?>
                                    <option value="<?= $stack['id'] ?>" <?= ($service['target_stack_id'] ?? '') == $stack['id'] ? 'selected' : '' ?>><?= htmlspecialchars($stack['host_name'] . ' / ' . $stack['stack_name']) ?></option>
                                <?php endwhile; ?>
                            </select>
                            <small class="form-text text-muted">Select the application stack whose internal `HEALTHCHECK` status should be monitored.</small>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="health_check_interval" class="form-label">Check Interval (seconds)</label>
                                <input type="number" class="form-control" id="health_check_interval" name="health_check_interval" value="<?= htmlspecialchars($service['health_check_interval']) ?>" min="5">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="health_check_timeout" class="form-label">Check Timeout (seconds)</label>
                                <input type="number" class="form-control" id="health_check_timeout" name="health_check_timeout" value="<?= htmlspecialchars($service['health_check_timeout']) ?>" min="1">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="unhealthy_threshold" class="form-label">Unhealthy Threshold</label>
                                <input type="number" class="form-control" id="unhealthy_threshold" name="unhealthy_threshold" value="<?= htmlspecialchars($service['unhealthy_threshold']) ?>" min="1">
                                <small class="form-text text-muted">Number of consecutive failures to be marked as unhealthy.</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="healthy_threshold" class="form-label">Healthy Threshold</label>
                                <input type="number" class="form-control" id="healthy_threshold" name="healthy_threshold" value="<?= htmlspecialchars($service['healthy_threshold']) ?>" min="1">
                                <small class="form-text text-muted">Number of consecutive successes to be marked as healthy.</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-4">
                <a href="<?= base_url('/services') ?>" class="btn btn-secondary">Batal</a>
                <button type="submit" class="btn btn-primary"><?= $submit_button_text ?></button>
            </div>
        </form>
    </div>
</div>

<script>
(function() { // IIFE to ensure script runs on AJAX load
    const serversContainer = document.getElementById('servers_container');
    const addServerBtn = document.getElementById('add_server_btn');
    const lbGroup = document.getElementById('load_balancer_method_group');
    const serverTypeIndividual = document.getElementById('server_type_individual');
    const serverTypeCidr = document.getElementById('server_type_cidr');
    const individualServersSection = document.getElementById('individual_servers_section');
    const cidrServerSection = document.getElementById('cidr_server_section');
    const healthCheckToggle = document.getElementById('health_check_enabled');
    const healthCheckOptions = document.getElementById('health-check-options');
    const httpTypeRadio = document.getElementById('health_check_type_http');
    const dockerTypeRadio = document.getElementById('health_check_type_docker');
    const tcpTypeRadio = document.getElementById('health_check_type_tcp');
    const httpOptionsSection = document.getElementById('http-options-section');
    const dockerOptionsSection = document.getElementById('docker-options-section');
    const httpEndpointInput = document.getElementById('health_check_endpoint');
    const dockerStackSelect = document.getElementById('target_stack_id');

    function updateLBVisibility() {
        const serverInputs = serversContainer.querySelectorAll('input[name="server_urls[]"]');
        lbGroup.style.display = (serverInputs.length > 1 || serverTypeCidr.checked) ? 'block' : 'none';
    }

    function toggleServerInputMethod() {
        const serverUrlInputs = individualServersSection.querySelectorAll('input[name="server_urls[]"]');
        const cidrAddressInput = document.querySelector('input[name="cidr_address"]');
        const cidrPortInput = document.querySelector('input[name="cidr_port"]');

        if (serverTypeCidr.checked) {
            individualServersSection.style.display = 'none';
            cidrServerSection.style.display = 'block';
            serverUrlInputs.forEach(input => input.required = false);
            cidrAddressInput.required = true;
            cidrPortInput.required = true;
        } else { // Individual is checked
            individualServersSection.style.display = 'block';
            cidrServerSection.style.display = 'none';
            // Require the first input if it's the only one
            if (serverUrlInputs.length > 0) {
                serverUrlInputs[0].required = true;
            }
            cidrAddressInput.required = false;
            cidrPortInput.required = false;
        }
        updateLBVisibility();
    }

    addServerBtn.addEventListener('click', function() {
        serversContainer.insertAdjacentHTML('beforeend', `<div class="input-group mb-2"><input type="url" class="form-control" name="server_urls[]" placeholder="http://10.0.0.2:8080" required><button class="btn btn-outline-danger" type="button" onclick="this.parentElement.remove()">Hapus</button></div>`);
        updateLBVisibility();
    });

    serversContainer.addEventListener('click', function(e) {
        if (e.target.closest('.btn-outline-danger')) {
            // If the last server input is removed, we should not make it non-required
            if (serversContainer.querySelectorAll('input').length === 1) {
                 e.target.closest('.input-group').querySelector('input').required = false;
            }
            e.target.closest('.input-group').remove();
            setTimeout(updateLBVisibility, 50);
        }
    });

    serverTypeIndividual.addEventListener('change', toggleServerInputMethod);
    serverTypeCidr.addEventListener('change', toggleServerInputMethod);

    healthCheckToggle.addEventListener('change', function() {
        healthCheckOptions.style.display = this.checked ? 'block' : 'none';
    });

    function toggleHealthCheckType() {
        // Hide all sections first
        httpOptionsSection.style.display = 'none';
        dockerOptionsSection.style.display = 'none';
        // Make inputs not required
        httpEndpointInput.required = false;
        dockerStackSelect.required = false;

        if (dockerTypeRadio.checked) {
            dockerOptionsSection.style.display = 'block';
            dockerStackSelect.required = true;
        } else if (httpTypeRadio.checked) {
            httpOptionsSection.style.display = 'block';
            httpEndpointInput.required = true;
        } else { // TCP is checked
            // No extra options needed for TCP check
        }
    }

    httpTypeRadio.addEventListener('change', toggleHealthCheckType);
    dockerTypeRadio.addEventListener('change', toggleHealthCheckType);
    tcpTypeRadio.addEventListener('change', toggleHealthCheckType);

    toggleServerInputMethod(); // Initial check
    toggleHealthCheckType(); // Initial check for health check type

})();
</script>

<?php
$conn->close();
require_once __DIR__ . '/../includes/footer.php';
?>