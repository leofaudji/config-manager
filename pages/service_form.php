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
    'group_id' => getDefaultGroupId()
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

require_once __DIR__ . '/../includes/header.php';
?>
<h3><?= $page_title ?></h3>
<hr>

<div class="card">
    <div class="card-body">
        <form id="main-form" action="<?= $form_action ?>" method="POST" data-redirect="/services">
            <input type="hidden" name="id" value="<?= htmlspecialchars($service['id'] ?? '') ?>">
            <div class="mb-3">
                <label for="name" class="form-label">Service Name</label>
                <input type="text" class="form-control" id="name" name="name" value="<?= htmlspecialchars($service['name']) ?>" required>
            </div>
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

            <div class="mt-4">
                <a href="<?= base_url('/services') ?>" class="btn btn-secondary">Batal</a>
                <button type="submit" class="btn btn-primary"><?= $submit_button_text ?></button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const serversContainer = document.getElementById('servers_container');
    const addServerBtn = document.getElementById('add_server_btn');
    const lbGroup = document.getElementById('load_balancer_method_group');
    const serverTypeIndividual = document.getElementById('server_type_individual');
    const serverTypeCidr = document.getElementById('server_type_cidr');
    const individualServersSection = document.getElementById('individual_servers_section');
    const cidrServerSection = document.getElementById('cidr_server_section');

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

    toggleServerInputMethod(); // Initial check
});
</script>

<?php
$conn->close();
require_once __DIR__ . '/../includes/footer.php';
?>