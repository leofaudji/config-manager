<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$conn = Database::getInstance()->getConnection();
require_once __DIR__ . '/../includes/Spyc.php';

$is_edit = false;
$stack = [
    'name' => '',
    'description' => '',
];
$details = []; // For builder data

$host_id = $_GET['id'] ?? null;
$stack_db_id = $_GET['stack_db_id'] ?? null;

// Get host name for the header
$stmt = $conn->prepare("SELECT name FROM docker_hosts WHERE id = ?");
$stmt->bind_param("i", $host_id);
$stmt->execute();
$host_result = $stmt->get_result();
if (!($host = $host_result->fetch_assoc())) {
    header("Location: " . base_url('/hosts?status=error&message=Host not found.'));
    exit;
}
$stmt->close();

if ($stack_db_id) {
    $is_edit = true;
    $stmt_stack = $conn->prepare("SELECT * FROM application_stacks WHERE id = ? AND host_id = ?");
    $stmt_stack->bind_param("ii", $stack_db_id, $host_id);
    $stmt_stack->execute();
    $stack_result = $stmt_stack->get_result();
    if (!($stack_data = $stack_result->fetch_assoc())) {
        header("Location: " . base_url('/hosts/' . $host_id . '/stacks?status=error&message=Stack not found in database.'));
        exit;
    }
    $stmt_stack->close();

    $details = json_decode($stack_data['deployment_details'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $details = [];
    }
    $stack['name'] = $details['name'] ?? '';
    $stack['description'] = $details['description'] ?? '';
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <div>
        <h1 class="h2"><?= $is_edit ? 'Edit' : 'Create' ?> Application Stack</h1>
        <p class="text-muted mb-0">For host: <a href="<?= base_url('/hosts/' . $host_id . '/details') ?>"><?= htmlspecialchars($host['name']) ?></a></p>
    </div>
    <div class="btn-toolbar mb-2 mb-md-0">
        <a href="<?= base_url('/hosts/' . $host_id . '/stacks') ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back to Stacks</a>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <form id="stack-builder-form" action="<?= base_url('/api/hosts/' . $host_id . '/stacks') ?>" method="POST" data-redirect="<?= base_url('/hosts/' . $host_id . '/stacks') ?>">
            <input type="hidden" name="action" value="<?= $is_edit ? 'update' : 'create' ?>">
            <input type="hidden" name="stack_db_id" value="<?= htmlspecialchars($stack_db_id) ?>">
            <input type="hidden" name="host_id" value="<?= htmlspecialchars($host_id) ?>">
            <!-- Stack Info -->
            <h4>Stack Information</h4>
            <div class="row mb-3">
                <div class="col-md-6 mb-3">
                    <label for="stack-name" class="form-label">Stack Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="stack-name" name="name" value="<?= htmlspecialchars($stack['name']) ?>" required <?= $is_edit ? 'readonly' : '' ?>>
                    <small class="form-text text-muted">A unique name for this application stack, e.g., `my-awesome-app`.</small>
                </div>
                <div class="col-md-6 mb-3">
                    <label for="stack-description" class="form-label">Description</label>
                    <input type="text" class="form-control" id="stack-description" name="description" value="<?= htmlspecialchars($stack['description']) ?>">
                </div>
            </div>

            <!-- Services Section -->
            <hr>
            <h4>Services</h4>
            <div id="services-container">
                <!-- Services will be added here dynamically -->
            </div>
            <button type="button" class="btn btn-outline-success mt-2" id="add-service-btn"><i class="bi bi-plus-circle"></i> Add Service</button>

            <!-- Networks Section -->
            <hr>
            <h4>Networks</h4>
            <div id="networks-container">
                <!-- Networks will be added here dynamically -->
            </div>
            <button type="button" class="btn btn-outline-success mt-2" id="add-network-btn"><i class="bi bi-plus-circle"></i> Add Network</button>

            <a href="<?= base_url('/hosts/' . $host_id . '/stacks') ?>" class="btn btn-secondary mt-3">Cancel</a>
            <button type="submit" class="btn btn-primary mt-3" id="save-stack-btn"><?= $is_edit ? 'Update Stack' : 'Deploy Stack' ?></button>
        </form>
    </div>
</div>

<!-- Templates for dynamic form elements -->
<template id="service-template">
    <div class="card mb-3 service-block">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">New Service</h5>
            <button type="button" class="btn-close remove-service-btn" aria-label="Close"></button>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Service Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control service-name-input" name="services[INDEX][name]" placeholder="e.g., web" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Image <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="services[INDEX][image]" placeholder="e.g., nginx:latest" required>
                </div>
            </div>
            
            <h6>Deployment</h6>
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label">Replicas</label>
                    <input type="number" class="form-control" name="services[INDEX][deploy][replicas]" placeholder="1" min="1">
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">CPU Limit</label>
                    <input type="text" class="form-control" name="services[INDEX][deploy][resources][limits][cpus]" placeholder="e.g., 0.50">
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Memory Limit</label>
                    <input type="text" class="form-control" name="services[INDEX][deploy][resources][limits][memory]" placeholder="e.g., 512M">
                </div>
            </div>

            <h6>Ports</h6>
            <div class="ports-container"></div>
            <button type="button" class="btn btn-sm btn-outline-secondary add-port-btn">Add Port</button>

            <h6 class="mt-3">Environment Variables</h6>
            <div class="environment-container"></div>
            <button type="button" class="btn btn-sm btn-outline-secondary add-env-btn">Add Variable</button>

            <h6 class="mt-3">Volumes</h6>
            <div class="volumes-container"></div>
            <button type="button" class="btn btn-sm btn-outline-secondary add-volume-btn">Add Volume</button>

            <h6 class="mt-3">Networks</h6>
            <div class="service-networks-container"></div>
            <button type="button" class="btn btn-sm btn-outline-secondary add-service-network-btn">Attach Network</button>

            <h6 class="mt-3">Depends On</h6>
            <div class="depends-on-container"></div>
            <button type="button" class="btn btn-sm btn-outline-secondary add-depends-on-btn">Add Dependency</button>
        </div>
    </div>
</template>

<template id="port-template">
    <div class="input-group input-group-sm mb-2">
        <input type="text" class="form-control" name="services[INDEX][ports][PORT_INDEX]" placeholder="e.g., 8080:80">
        <button class="btn btn-outline-danger remove-item-btn" type="button"><i class="bi bi-trash"></i></button>
    </div>
</template>

<template id="env-template">
    <div class="input-group input-group-sm mb-2">
        <input type="text" class="form-control" name="services[INDEX][environment][ENV_INDEX]" placeholder="e.g., DB_HOST=database">
        <button class="btn btn-outline-danger remove-item-btn" type="button"><i class="bi bi-trash"></i></button>
    </div>
</template>

<template id="volume-template">
    <div class="input-group input-group-sm mb-2">
        <input type="text" class="form-control" name="services[INDEX][volumes][VOLUME_INDEX]" placeholder="e.g., ./data:/var/lib/mysql">
        <button class="btn btn-outline-danger remove-item-btn" type="button"><i class="bi bi-trash"></i></button>
    </div>
</template>

<template id="service-network-template">
    <div class="input-group input-group-sm mb-2">
        <select class="form-select" name="services[INDEX][networks][NETWORK_INDEX]">
            <!-- Options will be populated by JS -->
        </select>
        <button class="btn btn-outline-danger remove-item-btn" type="button"><i class="bi bi-trash"></i></button>
    </div>
</template>

<template id="depends-on-template">
    <div class="input-group input-group-sm mb-2">
        <input type="text" class="form-control" name="services[INDEX][depends_on][DEPENDS_ON_INDEX]" placeholder="e.g., database">
        <button class="btn btn-outline-danger remove-item-btn" type="button"><i class="bi bi-trash"></i></button>
    </div>
</template>

<template id="network-template">
    <div class="input-group mb-2">
        <span class="input-group-text">Network Name</span>
        <input type="text" class="form-control" name="networks[NETWORK_INDEX][name]" placeholder="e.g., my-app-net">
        <button class="btn btn-outline-danger remove-item-btn" type="button"><i class="bi bi-trash"></i></button>
    </div>
</template>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const servicesContainer = document.getElementById('services-container');
    const addServiceBtn = document.getElementById('add-service-btn');
    const networksContainer = document.getElementById('networks-container');
    const addNetworkBtn = document.getElementById('add-network-btn');
    const hostId = <?= $host_id ?>;
    let isSwarmManager = false;
    const deploymentDetails = <?= json_encode($details) ?>;
    let availableNetworks = [];

    // Fetch available networks on page load to populate dropdowns
    fetch(`${basePath}/api/hosts/${hostId}/networks`)
        .then(response => response.json())
        .then(result => {
            if (result.status === 'success' && result.data) {
                availableNetworks = result.data.map(net => net.Name);
            }
        })
        .catch(error => {
            console.error("Failed to fetch host networks:", error);
            showToast("Could not load available networks for the host.", false);
        });

    // Check if host is a swarm manager to adapt the form's action
    const submitButton = document.getElementById('save-stack-btn');
    fetch(`${basePath}/api/hosts/${hostId}/stats`)
        .then(response => response.json())
        .then(result => {
            if (result.status === 'success' && result.data.is_swarm_manager) {
                isSwarmManager = true;
                submitButton.textContent = '<?= $is_edit ? 'Update Stack' : 'Deploy Stack' ?>';
            } else {
                isSwarmManager = false;
                submitButton.textContent = 'Generate Compose File';
            }
        }).catch(err => {
            console.error("Could not determine host type, defaulting to file generation.", err);
            submitButton.textContent = 'Generate Compose File';
        });

    let serviceIndex = 0;
    let networkIndex = 0;

    function addService(data = {}) {
        const template = document.getElementById('service-template').innerHTML;
        const serviceHtml = template.replace(/INDEX/g, serviceIndex);
        servicesContainer.insertAdjacentHTML('beforeend', serviceHtml);

        const newServiceBlock = servicesContainer.lastElementChild;
        const serviceNameInput = newServiceBlock.querySelector('.service-name-input');
        serviceNameInput.addEventListener('input', function() {
            newServiceBlock.querySelector('.card-header h5').textContent = this.value || 'New Service';
        });

        // Populate data if editing
        if (data.name) {
            serviceNameInput.value = data.name;
            serviceNameInput.dispatchEvent(new Event('input')); // Trigger update
            newServiceBlock.querySelector('input[name$="[image]"]').value = data.image || '';

            if (data.deploy) {
                if (data.deploy.replicas) {
                    newServiceBlock.querySelector('input[name$="[deploy][replicas]"]').value = data.deploy.replicas;
                }
                if (data.deploy.resources && data.deploy.resources.limits) {
                    if (data.deploy.resources.limits.cpus) {
                        newServiceBlock.querySelector('input[name$="[deploy][resources][limits][cpus]"]').value = data.deploy.resources.limits.cpus;
                    }
                    if (data.deploy.resources.limits.memory) {
                        newServiceBlock.querySelector('input[name$="[deploy][resources][limits][memory]"]').value = data.deploy.resources.limits.memory;
                    }
                }
            }

            const portsContainer = newServiceBlock.querySelector('.ports-container');
            (data.ports || []).forEach((port, pIndex) => {
                const template = document.getElementById('port-template').innerHTML.replace(/INDEX/g, serviceIndex).replace(/PORT_INDEX/g, pIndex);
                portsContainer.insertAdjacentHTML('beforeend', template);
                portsContainer.lastElementChild.querySelector('input').value = port;
            });

            const envContainer = newServiceBlock.querySelector('.environment-container');
            (data.environment || []).forEach((env, eIndex) => {
                const template = document.getElementById('env-template').innerHTML.replace(/INDEX/g, serviceIndex).replace(/ENV_INDEX/g, eIndex);
                envContainer.insertAdjacentHTML('beforeend', template);
                envContainer.lastElementChild.querySelector('input').value = env;
            });

            const volContainer = newServiceBlock.querySelector('.volumes-container');
            (data.volumes || []).forEach((vol, vIndex) => {
                const template = document.getElementById('volume-template').innerHTML.replace(/INDEX/g, serviceIndex).replace(/VOLUME_INDEX/g, vIndex);
                volContainer.insertAdjacentHTML('beforeend', template);
                volContainer.lastElementChild.querySelector('input').value = vol;
            });

            const netContainer = newServiceBlock.querySelector('.service-networks-container');
            (data.networks || []).forEach((net, nIndex) => {
                const template = document.getElementById('service-network-template').innerHTML.replace(/INDEX/g, serviceIndex).replace(/NETWORK_INDEX/g, nIndex);
                netContainer.insertAdjacentHTML('beforeend', template);
                netContainer.lastElementChild.querySelector('input').value = net;
            });

            const dependsOnContainer = newServiceBlock.querySelector('.depends-on-container');
            (data.depends_on || []).forEach((dep, dIndex) => {
                const template = document.getElementById('depends-on-template').innerHTML.replace(/INDEX/g, serviceIndex).replace(/DEPENDS_ON_INDEX/g, dIndex);
                dependsOnContainer.insertAdjacentHTML('beforeend', template);
                dependsOnContainer.lastElementChild.querySelector('input').value = dep;
            });

        } else {
            // If adding a new service, scroll it into view
            newServiceBlock.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        serviceIndex++;
    }

    function addNetwork(data = {}) {
        const template = document.getElementById('network-template').innerHTML;
        const networkHtml = template.replace(/NETWORK_INDEX/g, networkIndex);
        networksContainer.insertAdjacentHTML('beforeend', networkHtml);

        if (data.name) {
            networksContainer.lastElementChild.querySelector('input[name$="[name]"]').value = data.name;
        } else {
            networksContainer.lastElementChild.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
        networkIndex++;
    }

    addServiceBtn.addEventListener('click', () => addService());
    addNetworkBtn.addEventListener('click', () => addNetwork());

    servicesContainer.addEventListener('click', function(e) {
        const target = e.target;
        const serviceBlock = target.closest('.service-block');
        if (!serviceBlock) return;

        const sIndex = serviceBlock.querySelector('.service-name-input').name.match(/\[(\d+)\]/)[1];

        if (target.closest('.remove-service-btn')) {
            serviceBlock.remove();
        } else if (target.closest('.add-port-btn')) {
            const container = serviceBlock.querySelector('.ports-container');
            const pIndex = container.children.length;
            const template = document.getElementById('port-template').innerHTML.replace(/INDEX/g, sIndex).replace(/PORT_INDEX/g, pIndex);
            container.insertAdjacentHTML('beforeend', template);
        } else if (target.closest('.add-env-btn')) {
            const container = serviceBlock.querySelector('.environment-container');
            const eIndex = container.children.length;
            const template = document.getElementById('env-template').innerHTML.replace(/INDEX/g, sIndex).replace(/ENV_INDEX/g, eIndex);
            container.insertAdjacentHTML('beforeend', template);
        } else if (target.closest('.add-volume-btn')) {
            const container = serviceBlock.querySelector('.volumes-container');
            const vIndex = container.children.length;
            const template = document.getElementById('volume-template').innerHTML.replace(/INDEX/g, sIndex).replace(/VOLUME_INDEX/g, vIndex);
            container.insertAdjacentHTML('beforeend', template);
        } else if (target.closest('.add-service-network-btn')) {
            const container = serviceBlock.querySelector('.service-networks-container');
            const nIndex = container.children.length;
            const templateNode = document.getElementById('service-network-template').content.cloneNode(true);
            const selectElement = templateNode.querySelector('select');
            selectElement.name = `services[${sIndex}][networks][${nIndex}]`;

            let optionsHtml = '<option value="">-- Select a network --</option>';
            availableNetworks.forEach(netName => {
                optionsHtml += `<option value="${netName}">${netName}</option>`;
            });
            selectElement.innerHTML = optionsHtml;
            
            container.appendChild(templateNode);
        } else if (target.closest('.add-depends-on-btn')) {
            const container = serviceBlock.querySelector('.depends-on-container');
            const dIndex = container.children.length;
            const template = document.getElementById('depends-on-template').innerHTML.replace(/INDEX/g, sIndex).replace(/DEPENDS_ON_INDEX/g, dIndex);
            container.insertAdjacentHTML('beforeend', template);
        }
    });

    document.body.addEventListener('click', function(e) {
        if (e.target.closest('.remove-item-btn')) {
            e.target.closest('.input-group').remove();
        }
    });

    // --- Initial Population for Edit Mode ---
    if (deploymentDetails.services && Array.isArray(deploymentDetails.services)) {
        deploymentDetails.services.forEach(serviceData => addService(serviceData));
    }
    if (deploymentDetails.networks && Array.isArray(deploymentDetails.networks)) {
        deploymentDetails.networks.forEach(networkData => addNetwork(networkData));
    }

    function buildComposeObject() {
        const compose = { version: '3.8', services: {}, networks: {} };
        
        document.querySelectorAll('.service-block').forEach((serviceBlock) => {
            const serviceNameInput = serviceBlock.querySelector('input[name$="[name]"]');
            if (!serviceNameInput || !serviceNameInput.value) return;
            const serviceName = serviceNameInput.value;

            const serviceData = {};
            serviceData.image = serviceBlock.querySelector('input[name$="[image]"]').value || 'alpine:latest';
            
            const replicas = serviceBlock.querySelector('input[name$="[deploy][replicas]"]').value;
            const cpus = serviceBlock.querySelector('input[name$="[deploy][resources][limits][cpus]"]').value;
            const memory = serviceBlock.querySelector('input[name$="[deploy][resources][limits][memory]"]').value;
            if (replicas || cpus || memory) {
                serviceData.deploy = {};
                if (replicas) serviceData.deploy.replicas = parseInt(replicas);
                if (cpus || memory) {
                    serviceData.deploy.resources = { limits: {} };
                    if (cpus) serviceData.deploy.resources.limits.cpus = cpus;
                    if (memory) serviceData.deploy.resources.limits.memory = memory;
                }
            }

            ['ports', 'environment', 'volumes', 'depends_on'].forEach(key => {
                const containerClass = key.replace('_', '-') + '-container';
                const inputs = serviceBlock.querySelectorAll(`.${containerClass} input`);
                if (inputs.length > 0) {
                    const values = Array.from(inputs).map(input => input.value).filter(Boolean);
                    if (values.length > 0) serviceData[key] = values;
                }
            });

            const networkSelects = serviceBlock.querySelectorAll('.service-networks-container select');
            if (networkSelects.length > 0) {
                const values = Array.from(networkSelects).map(select => select.value).filter(Boolean);
                if (values.length > 0) serviceData.networks = values;
            }

            compose.services[serviceName] = serviceData;
        });

        document.querySelectorAll('#networks-container .input-group').forEach(networkBlock => {
            const networkName = networkBlock.querySelector('input[name^="networks["]').value;
            if (networkName) {
                compose.networks[networkName] = {}; // Define as external network
            }
        });

        return compose;
    }

    function generateAndDownloadCompose() {
        const submitButton = document.getElementById('save-stack-btn');
        const originalButtonText = submitButton.innerHTML;
        submitButton.disabled = true;
        submitButton.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Generating...`;

        try {
            const composeObject = buildComposeObject();
            const stackName = document.getElementById('stack-name').value || 'stack';
            const yamlString = jsyaml.dump(composeObject, { indent: 2 });

            const blob = new Blob([yamlString], { type: 'application/x-yaml' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = `${stackName}-compose.yml`;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(link.href);

            showToast('Compose file generated successfully. You can now run it on your host.', true);
        } catch (error) {
            console.error("Error generating YAML:", error);
            showToast('Failed to generate YAML file. Check console for details.', false);
        } finally {
            submitButton.disabled = false;
            submitButton.innerHTML = originalButtonText;
        }
    }

    // --- Form Submission ---
    const stackForm = document.getElementById('stack-builder-form');
    stackForm.addEventListener('submit', function(e) {
        e.preventDefault();

        if (isSwarmManager) {
            const formData = new FormData(stackForm);
            const url = stackForm.action;
            const submitButton = document.getElementById('save-stack-btn');
            const originalButtonText = submitButton.innerHTML;

            submitButton.disabled = true;
            submitButton.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Deploying...`;

            fetch(url, { method: 'POST', body: formData })
                .then(response => response.json().then(data => ({ ok: response.ok, data })))
                .then(({ ok, data }) => {
                    if (ok) {
                        showToast(data.message, true);
                        setTimeout(() => { window.location.href = stackForm.dataset.redirect; }, 1500);
                    } else {
                        throw new Error(data.message || 'An unknown error occurred.');
                    }
                })
                .catch(error => {
                    showToast(error.message, false);
                    submitButton.disabled = false;
                    submitButton.innerHTML = originalButtonText;
                });
        } else {
            // For non-swarm managers, generate and download the file
            generateAndDownloadCompose();
        }
    });

});
</script>

<?php
$conn->close();
require_once __DIR__ . '/../includes/footer.php';
?>