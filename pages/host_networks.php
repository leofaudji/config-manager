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
$active_page = 'networks';
require_once __DIR__ . '/../includes/host_nav.php';
?>

<div class="card mt-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-diagram-3"></i> Network Management</h5>
        <div class="d-flex align-items-center" id="network-actions-container">
            <div id="bulk-actions-container" class="dropdown me-2" style="display: none;">
                <button class="btn btn-secondary btn-sm dropdown-toggle" type="button" id="bulk-actions-btn" data-bs-toggle="dropdown" aria-expanded="false">
                    Bulk Actions
                </button>
                <ul class="dropdown-menu" aria-labelledby="bulk-actions-btn">
                    <li><a class="dropdown-item text-danger bulk-action-trigger" href="#" data-action="delete">Delete Selected</a></li>
                </ul>
            </div>
            <form class="search-form me-2" data-type="networks" id="network-search-form" onsubmit="return false;">
                <div class="input-group input-group-sm">
                    <input type="text" name="search_networks" class="form-control" placeholder="Search by name, subnet...">
                    <button class="btn btn-outline-secondary" type="submit" title="Search"><i class="bi bi-search"></i></button>
                    <button class="btn btn-outline-secondary reset-search-btn" type="button" title="Reset"><i class="bi bi-x-lg"></i></button>
                </div>
            </form>
            <button id="prune-networks-btn" class="btn btn-sm btn-outline-warning me-2">
                <i class="bi bi-trash3"></i> Prune Unused
            </button>
            <button id="refresh-networks-btn" class="btn btn-sm btn-outline-primary me-2"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
            <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#networkModal">
                <i class="bi bi-plus-circle"></i> Add New Network
            </button>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-sm table-hover">
                <thead>
                    <tr>
                        <th><input class="form-check-input" type="checkbox" id="select-all-networks" title="Select all networks"></th>
                        <th class="sortable asc" data-sort="Name">Name</th>
                        <th>ID</th>
                        <th class="sortable" data-sort="Driver">Driver</th>
                        <th class="sortable" data-sort="Scope">Scope</th>
                        <th>IP Network (Subnet)</th>
                        <th>Gateway</th>
                        <th>Connected Containers</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody id="networks-container">
                    <!-- Network data will be loaded here by AJAX -->
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer d-flex justify-content-between align-items-center">
        <div class="text-muted small" id="networks-info"></div>
        <div class="d-flex align-items-center">
            <nav id="networks-pagination"></nav>
            <div class="ms-3">
                <select name="limit_networks" class="form-select form-select-sm" id="networks-limit-selector" style="width: auto;">
                    <option value="10">10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="-1">All</option>
                </select>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const hostId = <?= $id ?>;
    const networksContainer = document.getElementById('networks-container');
    const pruneBtn = document.getElementById('prune-networks-btn');
    const refreshNetworksBtn = document.getElementById('refresh-networks-btn');
    const bulkActionsContainer = document.getElementById('bulk-actions-container');
    const selectAllCheckbox = document.getElementById('select-all-networks');
    const searchForm = document.getElementById('network-search-form');
    const searchInput = searchForm.querySelector('input[name="search_networks"]');
    const resetBtn = searchForm.querySelector('.reset-search-btn');
    const paginationContainer = document.getElementById('networks-pagination');
    const infoContainer = document.getElementById('networks-info');
    const limitSelector = document.getElementById('networks-limit-selector');
    const tableHeader = document.querySelector('#networks-container').closest('table').querySelector('thead');

    let currentPage = 1;
    let currentLimit = 10;
    let currentSort = 'Name';
    let currentOrder = 'asc';

    function reloadCurrentView() {
        loadNetworks(parseInt(currentPage), parseInt(currentLimit));
    }

    function loadNetworks(page = 1, limit = 10) {
        currentPage = parseInt(page) || 1;
        currentLimit = parseInt(limit) || 10;
        const originalBtnContent = refreshNetworksBtn.innerHTML;
        refreshNetworksBtn.disabled = true;
        refreshNetworksBtn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>`;
        networksContainer.innerHTML = '<tr><td colspan="9" class="text-center"><div class="spinner-border spinner-border-sm" role="status"><span class="visually-hidden">Loading...</span></div></td></tr>';

        const searchTerm = searchInput.value.trim();
        fetch(`${basePath}/api/hosts/${hostId}/networks?search=${encodeURIComponent(searchTerm)}&page=${page}&limit=${limit}&sort=${currentSort}&order=${currentOrder}`)
            .then(response => response.json())
            .then(result => {
                if (result.status === 'error') throw new Error(result.message);
                
                let html = '';
                if (result.data && result.data.length > 0) {
                    result.data.forEach(net => {
                        const name = net.Name;
                        const id = net.Id.substring(0, 12);
                        const driver = net.Driver;
                        const scope = net.Scope;
                        const isDefaultNetwork = ['bridge', 'host', 'none'].includes(name);
                        const isUnused = !net.Containers || Object.keys(net.Containers).length <= 0;
                        
                        let subnetHtml = '<span class="text-muted small">N/A</span>';
                        let gatewayHtml = '<span class="text-muted small">N/A</span>';
                        if (net.IPAM && net.IPAM.Config && net.IPAM.Config.length > 0) {
                            const ipamConfig = net.IPAM.Config[0];
                            if (ipamConfig.Subnet) {
                                subnetHtml = `<code>${ipamConfig.Subnet}</code>`;
                            }
                            if (ipamConfig.Gateway) {
                                gatewayHtml = `<code>${ipamConfig.Gateway}</code>`;
                            }
                        }

                        let containersHtml = '<span class="text-muted small">None</span>';
                        if (net.Containers && Object.keys(net.Containers).length > 0) {
                            containersHtml = Object.values(net.Containers).map(c => 
                                `<span class="badge bg-primary me-1">${c.Name}</span>`
                            ).join(' ');
                        }

                        const unusedBadge = isUnused && !isDefaultNetwork ? ' <span class="badge bg-secondary">Unused</span>' : '';

                        html += `<tr>
                                    <td><input class="form-check-input network-checkbox" type="checkbox" value="${net.Id}" data-name="${name}" ${isDefaultNetwork ? 'disabled' : ''}></td>
                                    <td>${name}${unusedBadge}</td>
                                    <td><code>${id}</code></td>
                                    <td><span class="badge bg-info">${driver}</span></td>
                                    <td>${scope}</td>
                                    <td>${subnetHtml}</td>
                                    <td>${gatewayHtml}</td>
                                    <td>${containersHtml}</td>
                                    <td class="text-end">
                                        <button class="btn btn-sm btn-outline-danger delete-network-btn" data-network-id="${net.Id}" data-network-name="${name}" ${isDefaultNetwork ? 'disabled title="Default networks cannot be removed."' : ''}><i class="bi bi-trash"></i></button>
                                    </td>
                                 </tr>`;
                    });
                } else {
                    html = '<tr><td colspan="9" class="text-center">No custom networks found on this host.</td></tr>';
                }
                networksContainer.innerHTML = html;
                infoContainer.innerHTML = result.info;

                // Build pagination
                let paginationHtml = '';
                if (result.total_pages > 1) {
                    paginationHtml += '<ul class="pagination pagination-sm mb-0">';
                    paginationHtml += `<li class="page-item ${result.current_page <= 1 ? 'disabled' : ''}"><a class="page-link" href="#" data-page="${result.current_page - 1}">«</a></li>`;
                    for (let i = 1; i <= result.total_pages; i++) {
                        paginationHtml += `<li class="page-item ${result.current_page == i ? 'active' : ''}"><a class="page-link" href="#" data-page="${i}">${i}</a></li>`;
                    }
                    paginationHtml += `<li class="page-item ${result.current_page >= result.total_pages ? 'disabled' : ''}"><a class="page-link" href="#" data-page="${parseInt(result.current_page) + 1}">»</a></li>`;
                    paginationHtml += '</ul>';
                }
                paginationContainer.innerHTML = paginationHtml;

                // Save state
                localStorage.setItem(`host_${hostId}_networks_page`, result.current_page);
                localStorage.setItem(`host_${hostId}_networks_limit`, result.limit);

                // Update sort indicators in header
                tableHeader.querySelectorAll('th.sortable').forEach(th => {
                    th.classList.remove('asc', 'desc');
                    if (th.dataset.sort === currentSort) {
                        th.classList.add(currentOrder);
                    }
                });

            })
            .catch(error => networksContainer.innerHTML = `<tr><td colspan="9" class="text-center text-danger">Failed to load networks: ${error.message}</td></tr>`)
            .finally(() => {
                refreshNetworksBtn.disabled = false;
                refreshNetworksBtn.innerHTML = originalBtnContent;
            });
    }

    if (networksContainer) {
        paginationContainer.addEventListener('click', function(e) {
            const pageLink = e.target.closest('.page-link');
            if (pageLink) {
                e.preventDefault();
                loadNetworks(parseInt(pageLink.dataset.page), limitSelector.value);
            }
        });

        tableHeader.addEventListener('click', function(e) {
            const th = e.target.closest('th.sortable');
            if (!th) return;

            const sortField = th.dataset.sort;
            if (currentSort === sortField) {
                currentOrder = currentOrder === 'asc' ? 'desc' : 'asc';
            } else {
                currentSort = sortField;
                currentOrder = 'asc';
            }
            localStorage.setItem(`host_${hostId}_networks_sort`, currentSort);
            localStorage.setItem(`host_${hostId}_networks_order`, currentOrder);
            loadNetworks(1, limitSelector.value);
        });

        limitSelector.addEventListener('change', function() {
            loadNetworks(1, this.value);
        });

        function updateBulkActionsVisibility() {
            const checkedBoxes = networksContainer.querySelectorAll('.network-checkbox:checked');
            if (checkedBoxes.length > 0) {
                bulkActionsContainer.style.display = 'block';
            } else {
                bulkActionsContainer.style.display = 'none';
            }
        }

        networksContainer.addEventListener('change', (e) => {
            if (e.target.matches('.network-checkbox')) {
                updateBulkActionsVisibility();
            }
        });

        selectAllCheckbox.addEventListener('change', function() {
            const isChecked = this.checked;
            networksContainer.querySelectorAll('.network-checkbox:not(:disabled)').forEach(checkbox => {
                checkbox.checked = isChecked;
            });
            updateBulkActionsVisibility();
        });

        bulkActionsContainer.addEventListener('click', function(e) {
            const trigger = e.target.closest('.bulk-action-trigger');
            if (!trigger) return;

            e.preventDefault();
            const action = trigger.dataset.action;
            const checkedBoxes = Array.from(networksContainer.querySelectorAll('.network-checkbox:checked'));
            const networkIds = checkedBoxes.map(cb => cb.value);
            const networkNames = checkedBoxes.map(cb => cb.dataset.name).join(', ');

            if (networkIds.length === 0) {
                showToast('No networks selected.', false);
                return;
            }

            if (!confirm(`Are you sure you want to ${action} ${networkIds.length} selected network(s): ${networkNames}? This action cannot be undone.`)) {
                return;
            }

            let completed = 0;
            const total = networkIds.length;
            showToast(`Performing bulk action '${action}' on ${total} networks...`, true);

            networkIds.forEach(networkId => {
                const formData = new FormData();
                formData.append('action', 'delete');
                formData.append('network_id', networkId);

                fetch(`${basePath}/api/hosts/${hostId}/networks`, { method: 'POST', body: formData })
                    .catch(error => console.error(`Error during bulk delete for network ${networkId}:`, error))
                    .finally(() => {
                        completed++;
                        if (completed === total) {
                            showToast(`Bulk delete completed.`, true);
                            setTimeout(reloadCurrentView, 2000);
                        }
                    });
            });
        });

        searchForm.addEventListener('submit', function(e) {
            e.preventDefault();
            loadNetworks();
        });

        resetBtn.addEventListener('click', function() {
            if (searchInput.value !== '') {
                searchInput.value = '';
                loadNetworks();
            }
        });

        searchInput.addEventListener('input', debounce(() => {
            loadNetworks();
        }, 400));

        refreshNetworksBtn.addEventListener('click', reloadCurrentView);

        pruneBtn.addEventListener('click', function() {
            if (!confirm('Are you sure you want to remove all unused networks? This action cannot be undone.')) {
                return;
            }

            const originalBtnContent = this.innerHTML;
            this.disabled = true;
            this.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Pruning...`;

            const url = `${basePath}/api/hosts/${hostId}/networks/prune`;

            fetch(url, { method: 'POST' })
                .then(response => response.json().then(data => ({ ok: response.ok, data })))
                .then(({ ok, data }) => {
                    showToast(data.message, ok);
                    if (ok) {
                        reloadCurrentView();
                    }
                })
                .catch(error => showToast(error.message || 'An unknown error occurred during prune.', false))
                .finally(() => {
                    this.disabled = false;
                    this.innerHTML = originalBtnContent;
                });
        });

        networksContainer.addEventListener('click', function(e) {
            const deleteBtn = e.target.closest('.delete-network-btn');
            if (!deleteBtn) return;

            const networkId = deleteBtn.dataset.networkId;
            const networkName = deleteBtn.dataset.networkName;

            if (!confirm(`Are you sure you want to delete the network "${networkName}"?`)) {
                return;
            }

            const originalIcon = deleteBtn.innerHTML;
            deleteBtn.disabled = true;
            deleteBtn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>`;

            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('network_id', networkId);

            const url = `${basePath}/api/hosts/${hostId}/networks`;

            fetch(url, { method: 'POST', body: formData })
                .then(response => response.json().then(data => ({ ok: response.ok, data })))
                .then(({ ok, data }) => {
                    showToast(data.message, ok);
                    if (ok) reloadCurrentView();
                })
                .catch(error => showToast(error.message || 'An unknown network error occurred.', false))
                .finally(() => {
                    deleteBtn.disabled = false;
                    deleteBtn.innerHTML = originalIcon;
                });
        });
    }

    // --- Create Network Modal Logic ---
    const networkModal = document.getElementById('networkModal');
    if (networkModal) {
        const driverSelect = document.getElementById('network-driver');
        const ipamContainer = document.getElementById('network-ipam-container');
        const macvlanContainer = document.getElementById('network-macvlan-container');
        const saveBtn = document.getElementById('save-network-btn');
        const networkForm = document.getElementById('network-form');

        function toggleDriverSpecificOptions() {
            const driver = driverSelect.value;
            
            if (driver === 'macvlan') {
                macvlanContainer.style.display = 'block';
            } else {
                macvlanContainer.style.display = 'none';
            }

            // IPAM is not supported/relevant for host or none drivers
            if (['host', 'none'].includes(driver)) {
                ipamContainer.style.display = 'none';
            } else {
                ipamContainer.style.display = 'block';
            }
        }

        driverSelect.addEventListener('change', toggleDriverSpecificOptions);

        networkModal.addEventListener('show.bs.modal', function() {
            networkForm.reset();
            // Reset to default visibility
            driverSelect.value = 'bridge';
            toggleDriverSpecificOptions();
            document.getElementById('network-labels-container').innerHTML = '';
        });

        saveBtn.addEventListener('click', function() {
            const formData = new FormData(networkForm);
            const url = `${basePath}/api/hosts/${hostId}/networks`;

            const originalBtnContent = this.innerHTML;
            this.disabled = true;
            this.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Creating...`;

            fetch(url, { method: 'POST', body: formData })
                .then(response => response.json().then(data => ({ ok: response.ok, data })))
                .then(({ ok, data }) => {
                    showToast(data.message, ok);
                    if (ok) {
                        bootstrap.Modal.getInstance(networkModal).hide();
                        reloadCurrentView();
                    }
                })
                .catch(error => showToast(error.message || 'An unknown error occurred.', false))
                .finally(() => {
                    this.disabled = false;
                    this.innerHTML = originalBtnContent;
                });
        });

        // Logic for adding/removing labels in the modal
        document.getElementById('add-network-label-btn').addEventListener('click', () => {
            const container = document.getElementById('network-labels-container');
            const index = container.children.length;
            const html = `<div class="input-group input-group-sm mb-2">
                            <input type="text" class="form-control" name="labels[${index}]" placeholder="e.g., com.example.foo=bar">
                            <button class="btn btn-outline-danger remove-item-btn" type="button"><i class="bi bi-trash"></i></button>
                          </div>`;
            container.insertAdjacentHTML('beforeend', html);
        });

        // Generic remove button for dynamic items inside the modal
        networkModal.addEventListener('click', e => {
            if (e.target.closest('.remove-item-btn')) {
                e.target.closest('.input-group').remove();
            }
        });
    }

    function initialize() {
        const initialPage = parseInt(localStorage.getItem(`host_${hostId}_networks_page`)) || 1;
        const initialLimit = parseInt(localStorage.getItem(`host_${hostId}_networks_limit`)) || 10;
        currentSort = localStorage.getItem(`host_${hostId}_networks_sort`) || 'Name';
        currentOrder = localStorage.getItem(`host_${hostId}_networks_order`) || 'asc';
        
        limitSelector.value = initialLimit;

        loadNetworks(initialPage, initialLimit);
    }
    initialize();
});
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>