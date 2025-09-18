<?php
require_once __DIR__ . '/../includes/bootstrap.php';
// Sesi dan otentikasi/otorisasi sudah ditangani oleh Router.
$conn = Database::getInstance()->getConnection();

// --- Determine Mode: 'add', 'edit', 'clone', 'combined' ---
$page_mode = 'add';
$page_title = 'Tambah Router';
$form_action = base_url('/routers/new');
$submit_button_text = 'Simpan';
$original_name_for_clone = '';

$router = [
    'id' => '',
    'name' => get_setting('default_router_prefix', 'router-'),
    'rule' => '',
    'entry_points' => 'web',
    'service_name' => '',
    'group_id' => getDefaultGroupId(),
    'tls' => 0,
    'cert_resolver' => '',
    'description' => ''
];

$request_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (isset($_GET['id'])) { // Edit Mode
    $page_mode = 'edit';
    $id = $_GET['id'];
    $stmt = $conn->prepare("SELECT * FROM routers WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        header('Location: ' . base_url('/?status=error&message=' . urlencode('Router not found.')));
        exit;
    }
    $router = $result->fetch_assoc();
    $stmt->close();

    $page_title = 'Edit Router';
    $form_action = base_url('/routers/' . htmlspecialchars($router['id']) . '/edit');
    $submit_button_text = 'Update';

} elseif (isset($_GET['clone_id'])) { // Clone Mode
    $page_mode = 'clone';
    $id = $_GET['clone_id'];
    $stmt = $conn->prepare("SELECT * FROM routers WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        header('Location: ' . base_url('/?status=error&message=' . urlencode('Router to clone not found.')));
        exit;
    }
    $router = $result->fetch_assoc();
    $stmt->close();

    $original_name_for_clone = $router['name'];

    // Suggest a new unique name
    $clone_count = 1;
    do {
        $new_name = $original_name_for_clone . '-clone' . ($clone_count > 1 ? $clone_count : '');
        $stmt_check = $conn->prepare("SELECT id FROM routers WHERE name = ?");
        $stmt_check->bind_param("s", $new_name);
        $stmt_check->execute();
        $name_exists = $stmt_check->get_result()->num_rows > 0;
        $stmt_check->close();
        $clone_count++;
    } while ($name_exists);

    $router['name'] = $new_name;
    $router['id'] = ''; // This will be a new entry

    $page_title = 'Clone Router: ' . htmlspecialchars($original_name_for_clone);
    $form_action = base_url('/routers/new');
    $submit_button_text = 'Save as New Router';

} elseif (str_contains($request_path, '/configurations/new')) { // Combined Add Mode
    $page_mode = 'combined';
    $page_title = 'Tambah Konfigurasi Baru (Router + Service)';
    $form_action = base_url('/configurations/new');
    $submit_button_text = 'Simpan Konfigurasi';
}

// Ambil daftar service untuk dropdown
$services_result = $conn->query("SELECT name FROM services ORDER BY name ASC");
// Ambil daftar grup untuk dropdown
$groups_result = $conn->query("SELECT id, name FROM `groups` ORDER BY name ASC");
// Ambil daftar template untuk dropdown
$templates_result = $conn->query("SELECT id, name FROM `configuration_templates` ORDER BY name ASC");
// Ambil daftar middleware untuk dropdown
$middlewares_result = $conn->query("SELECT id, name FROM middlewares ORDER BY name ASC");
$all_middlewares_data = mysqli_fetch_all($middlewares_result, MYSQLI_ASSOC);
// Ambil middleware yang sudah terhubung (jika edit atau clone)
$attached_middlewares = [];
if ($page_mode === 'edit' || $page_mode === 'clone') {
    $router_id_for_mw = ($page_mode === 'edit') ? $_GET['id'] : $_GET['clone_id'];
    $stmt_mw = $conn->prepare("SELECT rm.middleware_id, rm.priority, m.name 
                               FROM router_middleware rm 
                               JOIN middlewares m ON rm.middleware_id = m.id
                               WHERE rm.router_id = ?
                               ORDER BY rm.priority ASC");
    $stmt_mw->bind_param("i", $router_id_for_mw);
    $stmt_mw->execute();
    $mw_result = $stmt_mw->get_result();
    while ($row = $mw_result->fetch_assoc()) {
        $attached_middlewares[$row['middleware_id']] = ['priority' => $row['priority'], 'name' => $row['name']];
    }
    $stmt_mw->close();
}
// If adding a new router, check for a default middleware from settings
elseif ($page_mode === 'add' || $page_mode === 'combined') {
    $default_mw_id = get_setting('default_router_middleware', 0);
    if ($default_mw_id > 0) {
        $stmt_default_mw = $conn->prepare("SELECT id, name FROM middlewares WHERE id = ?");
        $stmt_default_mw->bind_param("i", $default_mw_id);
        $stmt_default_mw->execute();
        $default_mw_result = $stmt_default_mw->get_result();
        if ($default_mw = $default_mw_result->fetch_assoc()) {
            $attached_middlewares[$default_mw['id']] = ['priority' => 10, 'name' => $default_mw['name']];
        }
        $stmt_default_mw->close();
    }
}


require_once __DIR__ . '/../includes/header.php';
?>

<!-- Pesan Error (jika ada dari redirect) -->
<?php if (isset($_GET['status']) && $_GET['status'] == 'error'): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <?= htmlspecialchars(urldecode($_GET['message'])) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<h3><?= $page_title ?></h3>
<?php if ($page_mode === 'combined'): ?>
<p class="text-muted">Definisikan sebuah router baru dan hubungkan ke service yang sudah ada atau buat service baru sekaligus.</p>
<?php endif; ?>
<hr>

<div class="card">
    <?php if ($page_mode !== 'edit'): ?>
    <div class="card-header bg-light">
        <label for="template-selector" class="form-label">Start from a Template (Optional)</label>
        <div class="input-group">
            <select class="form-select" id="template-selector">
                <option value="">-- Select a Template --</option>
                <?php while($template = $templates_result->fetch_assoc()): ?>
                    <option value="<?= $template['id'] ?>"><?= htmlspecialchars($template['name']) ?></option>
                <?php endwhile; ?>
            </select>
            <button class="btn btn-outline-primary" type="button" id="load-template-btn">Load</button>
        </div>
    </div>
    <?php endif; ?>
    <div class="card-body">
        <form id="main-form" action="<?= $form_action ?>" method="POST" data-redirect="/">
            <input type="hidden" name="id" value="<?= htmlspecialchars($router['id'] ?? '') ?>">
            <div class="mb-3">
                <label for="name" class="form-label">Router Name</label>
                <input type="text" class="form-control" id="name" name="name" value="<?= htmlspecialchars($router['name']) ?>" required>
            </div>
            <div class="mb-3">
                <label for="rule" class="form-label">Rule</label>
                <input type="text" class="form-control" id="rule" name="rule" placeholder="Contoh: Host(`example.com`)" value="<?= htmlspecialchars($router['rule']) ?>" required>
            </div>
            <div class="mb-3">
                <label for="entry_points" class="form-label">Entry Points</label>
                <input type="text" class="form-control" id="entry_points" name="entry_points" value="<?= htmlspecialchars($router['entry_points']) ?>" required>
            </div>
            <div class="mb-3 form-check">
                <input type="hidden" name="tls" value="0">
                <input type="checkbox" class="form-check-input" id="tls" name="tls" value="1" <?= !empty($router['tls']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="tls">Enable TLS</label>
            </div>
            <div class="mb-3" id="cert-resolver-group" style="<?= !empty($router['tls']) ? '' : 'display: none;' ?>">
                <label for="cert_resolver" class="form-label">Certificate Resolver</label>
                <input type="text" class="form-control" id="cert_resolver" name="cert_resolver" value="<?= htmlspecialchars($router['cert_resolver'] ?? '') ?>" placeholder="e.g., cloudflare">
            </div>
            <div class="mb-3">
                <label for="available-middlewares" class="form-label">Available Middlewares</label>
                <div class="input-group">
                    <select class="form-select" id="available-middlewares">
                        <option value="">-- Select a middleware to add --</option>
                        <?php foreach ($all_middlewares_data as $mw): ?>
                            <?php if (!array_key_exists($mw['id'], $attached_middlewares)): ?>
                                <option value="<?= $mw['id'] ?>" data-name="<?= htmlspecialchars($mw['name']) ?>"><?= htmlspecialchars($mw['name']) ?></option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn-outline-secondary" type="button" id="add-middleware-btn">Add</button>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label">Attached Middlewares</label>
                <div id="attached-middlewares-container">
                    <?php foreach ($attached_middlewares as $mw_id => $mw_data): ?>
                        <div class="d-flex align-items-center mb-2 p-2 border rounded attached-middleware-item" data-id="<?= $mw_id ?>">
                            <i class="bi bi-grip-vertical me-2 drag-handle" style="cursor: grab;"></i>
                            <span class="flex-grow-1"><?= htmlspecialchars($mw_data['name']) ?></span>
                            <input type="hidden" name="middlewares[]" value="<?= $mw_id ?>">
                            <button type="button" class="btn-close remove-middleware-btn ms-2" aria-label="Remove"></button>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="mb-3">
                <label for="service_name" class="form-label">Service</label>
                <select class="form-select" id="service_name" name="service_name" required>
                    <option value="">-- Pilih Service --</option>
                    <?php mysqli_data_seek($services_result, 0); while($service = $services_result->fetch_assoc()): ?>
                        <option value="<?= htmlspecialchars($service['name']) ?>" <?= $router['service_name'] == $service['name'] ? 'selected' : '' ?>><?= htmlspecialchars($service['name']) ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="mb-3">
                <label for="group_id" class="form-label">Group</label>
                <select class="form-select" id="group_id" name="group_id" required>
                    <option value="">-- Pilih Grup --</option>
                    <?php while($group = $groups_result->fetch_assoc()): ?>
                        <option value="<?= htmlspecialchars($group['id']) ?>" <?= ($router['group_id'] ?? getDefaultGroupId()) == $group['id'] ? 'selected' : '' ?>><?= htmlspecialchars($group['name']) ?></option>
                    <?php endwhile; ?>
                </select>
            </div>

            <hr>
            <h5>Service Configuration</h5>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="service_choice" id="choice_existing" value="existing" checked>
                <label class="form-check-label" for="choice_existing">
                    Gunakan Service yang Sudah Ada
                </label>
            </div>
            <div class="form-check mb-3">
                <input class="form-check-input" type="radio" name="service_choice" id="choice_new" value="new">
                <label class="form-check-label" for="choice_new">
                    Buat Service Baru
                </label>
            </div>

            <!-- Opsi 1: Pilih dari yang sudah ada -->
            <div id="existing_service_section">
                <label for="existing_service_name" class="form-label">Pilih Service</label>
                <select class="form-select" id="existing_service_name" name="existing_service_name">
                    <?php mysqli_data_seek($services_result, 0); // Reset pointer ?>
                    <?php while($service = $services_result->fetch_assoc()): ?>
                        <option value="<?= htmlspecialchars($service['name']) ?>" <?= $router['service_name'] == $service['name'] ? 'selected' : '' ?>><?= htmlspecialchars($service['name']) ?></option>
                    <?php endwhile; ?>
                </select>
            </div>

            <!-- Opsi 2: Buat baru (tersembunyi) -->
            <div id="new_service_section" style="display: none;">
                <div class="mb-3">
                    <label for="new_service_name" class="form-label">Nama Service Baru <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="new_service_name" name="new_service_name">
                </div>
                <div class="mb-3 form-check">
                    <input type="hidden" name="pass_host_header" value="0">
                    <input type="checkbox" class="form-check-input" id="pass_host_header" name="pass_host_header" value="1" checked>
                    <label class="form-check-label" for="pass_host_header">Pass Host Header</label>
                </div>
                <div class="mb-3" id="load_balancer_method_group" style="display: none;">
                    <label for="load_balancer_method" class="form-label">Load Balancer Method</label>
                    <select class="form-select" id="load_balancer_method" name="load_balancer_method" required>
                        <option value="roundRobin" selected>roundRobin</option>
                        <option value="leastConn">leastConn</option>
                        <option value="ipHash">ipHash</option>
                        <option value="leastTime">leastTime</option>
                        <option value="leastBandwidth">leastBandwidth</option>
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
                    <h6>Server URLs</h6>
                    <div id="servers_container">
                        <div class="input-group mb-2">
                            <input type="url" class="form-control" name="server_urls[]" placeholder="http://10.0.0.1:8080">
                        </div>
                    </div>
                    <button type="button" class="btn btn-outline-success btn-sm" id="add_server_btn"><i class="bi bi-plus-circle"></i> Tambah Server</button>
                </div>

                <!-- Opsi B: CIDR -->
                <div id="cidr_server_section" style="display: none;">
                    <h6>Detail Jaringan</h6>
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
            
            <a href="<?= base_url('/') ?>" class="btn btn-secondary">Batal</a>
            <button type="submit" class="btn btn-primary"><?= $submit_button_text ?></button>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const tlsCheckbox = document.getElementById('tls');
    const certResolverGroup = document.getElementById('cert-resolver-group');
    const entryPointsInput = document.getElementById('entry_points');
    const choiceExisting = document.getElementById('choice_existing');
    const choiceNew = document.getElementById('choice_new');
    const existingSection = document.getElementById('existing_service_section');
    const newSection = document.getElementById('new_service_section');
    const newServiceNameInput = document.getElementById('new_service_name');
    const existingServiceNameSelect = document.getElementById('existing_service_name');
    const addServerBtn = document.getElementById('add_server_btn');
    const serversContainer = document.getElementById('servers_container');
    const serverTypeIndividual = document.getElementById('server_type_individual');
    const serverTypeCidr = document.getElementById('server_type_cidr');
    const individualServersSection = document.getElementById('individual_servers_section');
    const cidrServerSection = document.getElementById('cidr_server_section');

    function updateLBVisibility() {
        const serverInputs = serversContainer.querySelectorAll('input[name="server_urls[]"]');
        const lbGroup = document.getElementById('load_balancer_method_group');
        lbGroup.style.display = (serverInputs.length > 1 || serverTypeCidr.checked) ? 'block' : 'none';
    }

    function toggleSections() {
        const serverUrlInputs = newSection.querySelectorAll('input[name="server_urls[]"]');
        const cidrAddressInput = document.querySelector('input[name="cidr_address"]');
        const cidrPortInput = document.querySelector('input[name="cidr_port"]');

        if (choiceNew.checked) {
            existingSection.style.display = 'none';
            newSection.style.display = 'block';
            newServiceNameInput.required = true;
            existingServiceNameSelect.required = false;
            toggleServerInputMethod();
        } else {
            existingSection.style.display = 'block';
            newSection.style.display = 'none';
            newServiceNameInput.required = false;
            existingServiceNameSelect.required = true;
            serverUrlInputs.forEach(input => input.required = false);
            cidrAddressInput.required = false;
            cidrPortInput.required = false;
        }
        updateLBVisibility();
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
        } else {
            individualServersSection.style.display = 'block';
            cidrServerSection.style.display = 'none';
            cidrAddressInput.required = false;
            cidrPortInput.required = false;
        }
        updateLBVisibility();
    }

    tlsCheckbox.addEventListener('change', function() {
        certResolverGroup.style.display = this.checked ? 'block' : 'none';
        entryPointsInput.value = this.checked ? 'websecure' : 'web';
    });

    addServerBtn.addEventListener('click', () => {
        serversContainer.insertAdjacentHTML('beforeend', `<div class="input-group mb-2"><input type="url" class="form-control" name="server_urls[]" placeholder="http://10.0.0.2:8080"><button class="btn btn-outline-danger" type="button" onclick="this.parentElement.remove()">Hapus</button></div>`);
        updateLBVisibility();
    });

    serversContainer.addEventListener('click', (e) => e.target.closest('.btn-outline-danger') && setTimeout(updateLBVisibility, 50));
    choiceExisting.addEventListener('change', toggleSections);
    choiceNew.addEventListener('change', toggleSections);
    serverTypeIndividual.addEventListener('change', toggleServerInputMethod);
    serverTypeCidr.addEventListener('change', toggleServerInputMethod);

    toggleSections(); // Initial call

    // --- Middleware Management UI ---
    const addMiddlewareBtn = document.getElementById('add-middleware-btn');
    const availableMiddlewareSelect = document.getElementById('available-middlewares');
    const attachedMiddlewareContainer = document.getElementById('attached-middlewares-container');

    if (attachedMiddlewareContainer) {
        new Sortable(attachedMiddlewareContainer, {
            animation: 150,
            handle: '.drag-handle',
            ghostClass: 'bg-light'
        });

        addMiddlewareBtn.addEventListener('click', function() {
            const selectedOption = availableMiddlewareSelect.options[availableMiddlewareSelect.selectedIndex];
            if (!selectedOption || !selectedOption.value) return;

            const middlewareId = selectedOption.value;
            const middlewareName = selectedOption.dataset.name;

            const newItemHTML = `
                <div class="d-flex align-items-center mb-2 p-2 border rounded attached-middleware-item" data-id="${middlewareId}">
                    <i class="bi bi-grip-vertical me-2 drag-handle" style="cursor: grab;"></i>
                    <span class="flex-grow-1">${middlewareName}</span>
                    <input type="hidden" name="middlewares[]" value="${middlewareId}">
                    <button type="button" class="btn-close remove-middleware-btn ms-2" aria-label="Remove"></button>
                </div>`;
            
            attachedMiddlewareContainer.insertAdjacentHTML('beforeend', newItemHTML);
            selectedOption.remove();
        });

        attachedMiddlewareContainer.addEventListener('click', function(e) {
            if (e.target.classList.contains('remove-middleware-btn')) {
                const itemToRemove = e.target.closest('.attached-middleware-item');
                if (!itemToRemove) return;

                const middlewareId = itemToRemove.dataset.id;
                const middlewareName = itemToRemove.querySelector('span').textContent;

                const newOption = new Option(middlewareName, middlewareId);
                newOption.dataset.name = middlewareName;
                availableMiddlewareSelect.add(newOption);

                Array.from(availableMiddlewareSelect.options)
                    .sort((a, b) => a.value === "" ? -1 : b.value === "" ? 1 : a.text.localeCompare(b.text))
                    .forEach(option => availableMiddlewareSelect.add(option));

                itemToRemove.remove();
            }
        });
    }
});
</script>

<?php
$conn->close();
require_once __DIR__ . '/../includes/footer.php';
?>