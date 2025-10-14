<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$conn = Database::getInstance()->getConnection();
$hosts_result = $conn->query("SELECT id, name FROM docker_hosts ORDER BY name ASC");
$hosts = $hosts_result->fetch_all(MYSQLI_ASSOC);
$conn->close();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-diagram-3-fill"></i> Network Inspector</h1>
</div>

<div class="row">
    <div class="col-md-6 mb-3">
        <div class="input-group">
            <label class="input-group-text" for="host-select"><i class="bi bi-hdd-network-fill"></i></label>
            <select id="host-select" class="form-select">
                <option value="">-- Select a Host to Inspect --</option>
                <?php foreach ($hosts as $host): ?>
                    <option value="<?= htmlspecialchars($host['id']) ?>"><?= htmlspecialchars($host['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="col-md-6 mb-3 d-flex justify-content-end">
        <button id="prune-networks-btn" class="btn btn-sm btn-outline-danger" disabled><i class="bi bi-trash"></i> Prune Unused Networks</button>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered table-hover table-sm">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>ID</th>
                        <th>Driver</th>
                        <th>Scope</th>
                        <th>Subnet</th>
                        <th>Gateway</th>
                        <th class="text-center">Containers</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody id="networks-table-body">
                    <!-- Network details will be loaded here -->
                </tbody>
            </table>
        </div>
    </div>
</div>

<div id="loading-indicator" class="text-center mt-4" style="display: none;">
    <div class="spinner-border" role="status">
        <span class="visually-hidden">Loading...</span>
    </div>
</div>

<!-- View Containers Modal -->
<div class="modal fade" id="viewContainersModal" tabindex="-1" aria-labelledby="viewContainersModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="viewContainersModalLabel">Connected Containers</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <ul class="list-group" id="containers-list-group">
          <!-- Container list will be populated here -->
        </ul>
      </div>
    </div>
  </div>
</div>

<script>
window.pageInit = function() {
    const hostSelect = document.getElementById('host-select');
    const tableBody = document.getElementById('networks-table-body');
    const loadingIndicator = document.getElementById('loading-indicator');
    const pruneBtn = document.getElementById('prune-networks-btn');

    function renderNetworks(networks, containers) {
        // Sort networks by name
        networks.sort((a, b) => a.Name.localeCompare(b.Name));

        // Create a map of containers for easy lookup
        const containerMap = new Map(containers.map(c => [c.Id, c]));

        let html = '';
        if (networks.length === 0) {
            tableBody.innerHTML = '<tr><td colspan="8" class="text-center">No networks found on this host.</td></tr>';
            return;
        }

        networks.forEach((network, index) => {
            const subnet = network.IPAM?.Config?.[0]?.Subnet || 'N/A';
            const gateway = network.IPAM?.Config?.[0]?.Gateway || 'N/A';
            const containerCount = network.Containers ? Object.keys(network.Containers).length : 0;

            const viewContainersBtn = containerCount > 0
                ? `<button class="btn btn-sm btn-outline-primary view-containers-btn" data-bs-toggle="modal" data-bs-target="#viewContainersModal" data-network-id="${network.Id}" data-network-name="${network.Name}">${containerCount}</button>`
                : `<span class="badge bg-secondary">${containerCount}</span>`;

            html += `
                <tr>
                    <td><strong>${network.Name}</strong></td>
                    <td><code class="font-monospace">${network.Id.substring(0, 12)}</code></td>
                    <td><span class="badge bg-primary">${network.Driver}</span></td>
                    <td><span class="badge bg-info">${network.Scope}</span></td>
                    <td><code>${subnet}</code></td>
                    <td><code>${gateway}</code></td>
                    <td class="text-center">${viewContainersBtn}</td>
                    <td class="text-end">
                        <button class="btn btn-sm btn-outline-danger" disabled title="Delete Network (coming soon)"><i class="bi bi-trash"></i></button>
                    </td>
                </tr>
            `;
        });
        tableBody.innerHTML = html;
    }

    function loadNetworkData() {
        const hostId = hostSelect.value;
        if (!hostId) {
            tableBody.innerHTML = '';
            pruneBtn.disabled = true;
            return;
        }

        loadingIndicator.style.display = 'block';
        tableBody.innerHTML = '';
        pruneBtn.disabled = true;

        Promise.all([
            fetch(`<?= base_url('/api/hosts/') ?>${hostId}/networks?limit=-1&details=true`).then(res => res.json()),
            fetch(`<?= base_url('/api/hosts/') ?>${hostId}/containers?raw=true`).then(res => res.json())
        ]).then(([networksResult, containersResult]) => {
            if (networksResult.status !== 'success' || containersResult.status !== 'success') {
                throw new Error(networksResult.message || containersResult.message || 'Failed to load data.');
            }
            renderNetworks(networksResult.data, containersResult.data);
            pruneBtn.disabled = false;
        }).catch(error => {
            tableBody.innerHTML = `<tr><td colspan="8" class="text-center text-danger">Error: ${error.message}</td></tr>`;
        }).finally(() => {
            loadingIndicator.style.display = 'none';
        });
    }

    hostSelect.addEventListener('change', loadNetworkData);

    pruneBtn.addEventListener('click', function() {
        const hostId = hostSelect.value;
        if (!hostId || !confirm('Are you sure you want to remove all unused networks on this host? This action cannot be undone.')) return;

        const formData = new FormData();
        formData.append('action', 'prune');
        fetch(`<?= base_url('/api/hosts/') ?>${hostId}/networks/prune`, { method: 'POST', body: formData })
            .then(response => response.json().then(data => ({ok: response.ok, data})))
            .then(({ok, data}) => {
                showToast(data.message, ok);
                if (ok) loadNetworkData();
            })
            .catch(error => showToast(`An error occurred: ${error.message}`, false));
    });

    // --- Modal Logic ---
    const viewContainersModal = document.getElementById('viewContainersModal');
    if (viewContainersModal) {
        viewContainersModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            const networkName = button.dataset.networkName;
            const networkId = button.dataset.networkId;
            const modalTitle = document.getElementById('viewContainersModalLabel');
            const containerList = document.getElementById('containers-list-group');

            modalTitle.textContent = `Containers in: ${networkName}`;
            containerList.innerHTML = '<li class="list-group-item text-center"><div class="spinner-border spinner-border-sm"></div></li>';

            // Fetch the network details again to get the most up-to-date container list
            fetch(`<?= base_url('/api/hosts/') ?>${hostSelect.value}/networks?limit=-1&details=true`)
                .then(res => res.json())
                .then(result => {
                    const network = result.data.find(n => n.Id === networkId);
                    let html = '';
                    if (network && network.Containers && Object.keys(network.Containers).length > 0) {
                        Object.entries(network.Containers).forEach(([containerId, endpoint]) => {
                            html += `<li class="list-group-item d-flex justify-content-between align-items-center">
                                        <span><i class="bi bi-box-seam me-2"></i>${endpoint.Name}</span>
                                        <span class="font-monospace"><code>${endpoint.IPv4Address || 'N/A'}</code></span>
                                     </li>`;
                        });
                    } else {
                        html = '<li class="list-group-item text-center text-muted">No containers found.</li>';
                    }
                    containerList.innerHTML = html;
                });
        });
    }
};
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>