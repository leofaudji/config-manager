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
$active_page = 'volumes';
require_once __DIR__ . '/../includes/host_nav.php';
?>

<div class="card mt-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-database"></i> Volume Management</h5>
        <div class="d-flex align-items-center" id="volume-actions-container">
            <div id="bulk-actions-container" class="dropdown me-2" style="display: none;">
                <button class="btn btn-secondary btn-sm dropdown-toggle" type="button" id="bulk-actions-btn" data-bs-toggle="dropdown" aria-expanded="false">
                    Bulk Actions
                </button>
                <ul class="dropdown-menu" aria-labelledby="bulk-actions-btn">
                    <li><a class="dropdown-item text-danger bulk-action-trigger" href="#" data-action="delete">Delete Selected</a></li>
                </ul>
            </div>
            <form class="search-form me-2" data-type="volumes" id="volume-search-form" onsubmit="return false;">
                <div class="input-group input-group-sm">
                    <input type="text" name="search_volumes" class="form-control" placeholder="Search by name...">
                    <button class="btn btn-outline-secondary" type="submit" title="Search"><i class="bi bi-search"></i></button>
                    <button class="btn btn-outline-secondary reset-search-btn" type="button" title="Reset"><i class="bi bi-x-lg"></i></button>
                </div>
            </form>
            <button id="prune-volumes-btn" class="btn btn-sm btn-outline-warning me-2">
                <i class="bi bi-trash3"></i> Prune Unused
            </button>
            <button id="refresh-volumes-btn" class="btn btn-sm btn-outline-primary me-2"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
            <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#volumeModal">
                <i class="bi bi-plus-circle"></i> Add New Volume
            </button>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-sm table-hover">
                <thead>
                    <tr>
                        <th><input class="form-check-input" type="checkbox" id="select-all-volumes" title="Select all volumes"></th>
                        <th class="sortable asc" data-sort="Name">Name</th>
                        <th class="sortable" data-sort="Driver">Driver</th>
                        <th class="sortable" data-sort="Mountpoint">Mountpoint</th>
                        <th class="sortable" data-sort="CreatedAt">Created</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody id="volumes-container">
                    <!-- Volume data will be loaded here by AJAX -->
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer d-flex justify-content-between align-items-center">
        <div class="text-muted small" id="volumes-info"></div>
        <div class="d-flex align-items-center">
            <nav id="volumes-pagination"></nav>
            <div class="ms-3">
                <select name="limit_volumes" class="form-select form-select-sm" id="volumes-limit-selector" style="width: auto;">
                    <option value="10">10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="-1">All</option>
                </select>
            </div>
        </div>
    </div>
</div>

<!-- View Volume Details Modal -->
<div class="modal fade" id="viewVolumeDetailsModal" tabindex="-1" aria-labelledby="viewVolumeDetailsModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="viewVolumeDetailsModalLabel">Volume Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <pre><code id="volume-details-content-container" class="language-json">Loading...</code></pre>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- Volume Modal (for Add) -->
<div class="modal fade" id="volumeModal" tabindex="-1" aria-labelledby="volumeModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="volumeModalLabel">Add New Volume</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form id="volume-form">
            <input type="hidden" name="action" value="create_volume">
            <div class="mb-3">
                <label for="volume-name" class="form-label">Volume Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="volume-name" name="name" required>
            </div>
            <div class="mb-3">
                <label for="volume-driver" class="form-label">Driver</label>
                <input type="text" class="form-control" id="volume-driver" name="driver" placeholder="local (default)">
            </div>
            <hr>
            <h6>Driver Options</h6>
            <div id="volume-driver-opts-container">
                <!-- Driver options will be added here dynamically -->
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="add-volume-driver-opt-btn">Add Option</button>
            <hr>
            <h6>Labels</h6>
            <div id="volume-labels-container">
                <!-- Labels will be added here dynamically -->
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="add-volume-label-btn">Add Label</button>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="save-volume-btn">Create Volume</button>
      </div>
    </div>
  </div>
</div>

<!-- Template for key-value inputs -->
<template id="key-value-template">
    <div class="input-group input-group-sm mb-2">
        <input type="text" class="form-control" name="KEY_NAME[KEY_INDEX][key]" placeholder="Key">
        <span class="input-group-text">=</span>
        <input type="text" class="form-control" name="KEY_NAME[KEY_INDEX][value]" placeholder="Value">
        <button class="btn btn-outline-danger remove-item-btn" type="button"><i class="bi bi-trash"></i></button>
    </div>
</template>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const hostId = <?= $id ?>;
    const volumesContainer = document.getElementById('volumes-container');
    const pruneBtn = document.getElementById('prune-volumes-btn');
    const refreshBtn = document.getElementById('refresh-volumes-btn');
    const bulkActionsContainer = document.getElementById('bulk-actions-container');
    const selectAllCheckbox = document.getElementById('select-all-volumes');
    const searchForm = document.getElementById('volume-search-form');
    const searchInput = searchForm.querySelector('input[name="search_volumes"]');
    const resetBtn = searchForm.querySelector('.reset-search-btn');
    const paginationContainer = document.getElementById('volumes-pagination');
    const infoContainer = document.getElementById('volumes-info');
    const limitSelector = document.getElementById('volumes-limit-selector');
    const tableHeader = document.querySelector('#volumes-container').closest('table').querySelector('thead');

    let currentPage = 1;
    let currentLimit = 10;
    let currentSort = 'Name';
    let currentOrder = 'asc';

    function reloadCurrentView() {
        loadVolumes(parseInt(currentPage), parseInt(currentLimit));
    }

    function loadVolumes(page = 1, limit = 10) {
        currentPage = parseInt(page) || 1;
        currentLimit = parseInt(limit) || 10;
        const originalBtnContent = refreshBtn.innerHTML;
        refreshBtn.disabled = true;
        refreshBtn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Refreshing...`;
        volumesContainer.innerHTML = '<tr><td colspan="6" class="text-center"><div class="spinner-border spinner-border-sm" role="status"><span class="visually-hidden">Loading...</span></div></td></tr>';

        const searchTerm = searchInput.value.trim();
        fetch(`${basePath}/api/hosts/${hostId}/volumes?search=${encodeURIComponent(searchTerm)}&page=${page}&limit=${limit}&sort=${currentSort}&order=${currentOrder}`)
            .then(response => response.json())
            .then(result => {
                if (result.status === 'error') throw new Error(result.message);
                
                let html = '';
                if (result.data && result.data.length > 0) {
                    result.data.forEach(vol => {
                        const name = vol.Name;
                        const driver = vol.Driver;
                        const mountpoint = vol.Mountpoint;
                        const created = new Date(vol.CreatedAt).toLocaleString();
                        const isUnused = !vol.UsageData || (vol.UsageData.RefCount !== null && vol.UsageData.RefCount <= 0);

                        // Shorten long names/paths for display
                        const displayName = name.length > 50 ? `<span title="${name}">${name.substring(0, 47)}...</span>` : name;
                        const displayMountpoint = mountpoint.length > 60 ? `<span title="${mountpoint}">${mountpoint.substring(0, 57)}...</span>` : mountpoint;

                        const unusedBadge = isUnused ? ' <span class="badge bg-secondary">Unused</span>' : '';

                        html += `<tr>
                                    <td><input class="form-check-input volume-checkbox" type="checkbox" value="${name}"></td>
                                    <td><a href="#" class="view-volume-details-btn" data-bs-toggle="modal" data-bs-target="#viewVolumeDetailsModal" data-volume-name="${name}"><code>${displayName}</code></a>${unusedBadge}</td>
                                    <td><span class="badge bg-info">${driver}</span></td>
                                    <td><small>${displayMountpoint}</small></td>
                                    <td>${created}</td>
                                    <td class="text-end">
                                        <button class="btn btn-sm btn-outline-info view-volume-details-btn" data-bs-toggle="modal" data-bs-target="#viewVolumeDetailsModal" data-volume-name="${name}" title="View Details"><i class="bi bi-eye"></i></button>
                                        <button class="btn btn-sm btn-outline-danger delete-volume-btn" data-volume-name="${name}" title="Remove Volume"><i class="bi bi-trash"></i></button>
                                    </td>
                                 </tr>`;
                    });
                } else {
                    html = '<tr><td colspan="6" class="text-center">No volumes found on this host.</td></tr>';
                }
                volumesContainer.innerHTML = html;
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
                localStorage.setItem(`host_${hostId}_volumes_page`, result.current_page);
                localStorage.setItem(`host_${hostId}_volumes_limit`, result.limit);

                // Update sort indicators in header
                tableHeader.querySelectorAll('th.sortable').forEach(th => {
                    th.classList.remove('asc', 'desc');
                    if (th.dataset.sort === currentSort) {
                        th.classList.add(currentOrder);
                    }
                });

            })
            .catch(error => volumesContainer.innerHTML = `<tr><td colspan="6" class="text-center text-danger">Failed to load volumes: ${error.message}</td></tr>`)
            .finally(() => {
                refreshBtn.disabled = false;
                refreshBtn.innerHTML = originalBtnContent;
            });
    }

    refreshBtn.addEventListener('click', reloadCurrentView);

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
        localStorage.setItem(`host_${hostId}_volumes_sort`, currentSort);
        localStorage.setItem(`host_${hostId}_volumes_order`, currentOrder);
        loadVolumes(1, limitSelector.value);
    });

    searchForm.addEventListener('submit', function(e) {
        e.preventDefault();
        loadVolumes();
    });

    resetBtn.addEventListener('click', function() {
        if (searchInput.value !== '') {
            searchInput.value = '';
            loadVolumes();
        }
    });

    searchInput.addEventListener('input', debounce(() => {
        loadVolumes();
    }, 400));

    paginationContainer.addEventListener('click', function(e) {
        const pageLink = e.target.closest('.page-link');
        if (pageLink) {
            e.preventDefault();
            loadVolumes(parseInt(pageLink.dataset.page), limitSelector.value);
        }
    });

    limitSelector.addEventListener('change', function() {
        loadVolumes(1, this.value);
    });

    function updateBulkActionsVisibility() {
        const checkedBoxes = volumesContainer.querySelectorAll('.volume-checkbox:checked');
        if (checkedBoxes.length > 0) {
            bulkActionsContainer.style.display = 'block';
        } else {
            bulkActionsContainer.style.display = 'none';
        }
    }

    volumesContainer.addEventListener('change', (e) => {
        if (e.target.matches('.volume-checkbox')) {
            updateBulkActionsVisibility();
        }
    });

    selectAllCheckbox.addEventListener('change', function() {
        const isChecked = this.checked;
        volumesContainer.querySelectorAll('.volume-checkbox:not(:disabled)').forEach(checkbox => {
            checkbox.checked = isChecked;
        });
        updateBulkActionsVisibility();
    });

    bulkActionsContainer.addEventListener('click', function(e) {
        const trigger = e.target.closest('.bulk-action-trigger');
        if (!trigger) return;

        e.preventDefault();
        const action = trigger.dataset.action;
        const checkedBoxes = Array.from(volumesContainer.querySelectorAll('.volume-checkbox:checked'));
        const volumeNames = checkedBoxes.map(cb => cb.value);

        if (volumeNames.length === 0) {
            showToast('No volumes selected.', false);
            return;
        }

        if (!confirm(`Are you sure you want to ${action} ${volumeNames.length} selected volume(s)? This action cannot be undone.`)) {
            return;
        }

        let completed = 0;
        const total = volumeNames.length;
        showToast(`Performing bulk action '${action}' on ${total} volumes...`, true);

        volumeNames.forEach(volumeName => {
            const formData = new FormData();
            formData.append('action', 'delete_volume');
            formData.append('volume_name', volumeName);

            fetch(`${basePath}/api/hosts/${hostId}/volumes`, { method: 'POST', body: formData })
                .catch(error => console.error(`Error during bulk delete for volume ${volumeName}:`, error))
                .finally(() => {
                    completed++;
                    if (completed === total) {
                        showToast(`Bulk delete completed.`, true);
                        setTimeout(reloadCurrentView, 2000);
                    }
                });
        });
    });

    pruneBtn.addEventListener('click', function() {
        if (!confirm('Are you sure you want to remove all unused volumes? This action cannot be undone.')) {
            return;
        }

        const originalBtnContent = this.innerHTML;
        this.disabled = true;
        this.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Pruning...`;

        const url = `${basePath}/api/hosts/${hostId}/volumes/prune`;

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

    volumesContainer.addEventListener('click', function(e) {
        const deleteBtn = e.target.closest('.delete-volume-btn');
        if (!deleteBtn) return;

        const volumeName = deleteBtn.dataset.volumeName;

        if (!confirm(`Are you sure you want to delete the volume "${volumeName}"? This action cannot be undone and may break applications using it.`)) {
            return;
        }

        const originalIcon = deleteBtn.innerHTML;
        deleteBtn.disabled = true;
        deleteBtn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>`;

        const formData = new FormData();
        formData.append('action', 'delete_volume');
        formData.append('volume_name', volumeName);

        const url = `${basePath}/api/hosts/${hostId}/volumes`;

        fetch(url, { method: 'POST', body: formData })
            .then(response => response.json().then(data => ({ ok: response.ok, data })))
            .then(({ ok, data }) => {
                showToast(data.message, ok);
                if (ok) reloadCurrentView();
            })
            .catch(error => showToast(error.message || 'An unknown error occurred while deleting the volume.', false))
            .finally(() => {
                deleteBtn.disabled = false;
                deleteBtn.innerHTML = originalIcon;
            });
    });

    volumesContainer.addEventListener('click', function(e) {
        const detailsBtn = e.target.closest('.view-volume-details-btn');
        if (detailsBtn) e.preventDefault();
    });

    // --- Create Volume Modal Logic ---
    const volumeModal = document.getElementById('volumeModal');
    const saveVolumeBtn = document.getElementById('save-volume-btn');
    const volumeForm = document.getElementById('volume-form');

    function addKeyValueInput(containerId, keyName) {
        const container = document.getElementById(containerId);
        const index = container.children.length;
        const template = document.getElementById('key-value-template').innerHTML;
        const html = template.replace(/KEY_NAME/g, keyName).replace(/KEY_INDEX/g, index);
        container.insertAdjacentHTML('beforeend', html);
    }

    document.getElementById('add-volume-driver-opt-btn').addEventListener('click', () => {
        addKeyValueInput('volume-driver-opts-container', 'driver_opts');
    });

    document.getElementById('add-volume-label-btn').addEventListener('click', () => {
        addKeyValueInput('volume-labels-container', 'labels');
    });

    document.getElementById('volume-driver-opts-container').addEventListener('click', e => {
        if (e.target.closest('.remove-item-btn')) e.target.closest('.input-group').remove();
    });
    document.getElementById('volume-labels-container').addEventListener('click', e => {
        if (e.target.closest('.remove-item-btn')) e.target.closest('.input-group').remove();
    });

    saveVolumeBtn.addEventListener('click', function() {
        const formData = new FormData(volumeForm);
        const url = `${basePath}/api/hosts/${hostId}/volumes`;

        const originalBtnContent = this.innerHTML;
        this.disabled = true;
        this.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Creating...`;

        fetch(url, { method: 'POST', body: formData })
            .then(response => response.json().then(data => ({ ok: response.ok, data })))
            .then(({ ok, data }) => {
                showToast(data.message, ok);
                if (ok) {
                    bootstrap.Modal.getInstance(volumeModal).hide();
                    reloadCurrentView();
                }
            })
            .catch(error => showToast(error.message || 'An unknown error occurred.', false))
            .finally(() => {
                this.disabled = false;
                this.innerHTML = originalBtnContent;
            });
    });

    volumeModal.addEventListener('hidden.bs.modal', function () {
        volumeForm.reset();
        document.getElementById('volume-driver-opts-container').innerHTML = '';
        document.getElementById('volume-labels-container').innerHTML = '';
    });

    const viewDetailsModal = document.getElementById('viewVolumeDetailsModal');
    if (viewDetailsModal) {
        const contentContainer = document.getElementById('volume-details-content-container');
        const modalLabel = document.getElementById('viewVolumeDetailsModalLabel');

        viewDetailsModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            const volumeName = button.dataset.volumeName;

            modalLabel.textContent = `Details for Volume: ${volumeName}`;
            contentContainer.textContent = 'Loading...';

            fetch(`${basePath}/api/hosts/${hostId}/volumes/${volumeName}`)
                .then(response => response.json())
                .then(result => {
                    if (result.status === 'success') {
                        // Pretty-print the JSON object
                        contentContainer.textContent = JSON.stringify(result.data, null, 2);
                        Prism.highlightElement(contentContainer);
                    } else {
                        throw new Error(result.message);
                    }
                })
                .catch(error => contentContainer.textContent = `Error: ${error.message}`);
        });
    }

    function initialize() {
        const initialPage = parseInt(localStorage.getItem(`host_${hostId}_volumes_page`)) || 1;
        const initialLimit = parseInt(localStorage.getItem(`host_${hostId}_volumes_limit`)) || 10;
        currentSort = localStorage.getItem(`host_${hostId}_volumes_sort`) || 'Name';
        currentOrder = localStorage.getItem(`host_${hostId}_volumes_order`) || 'asc';
        
        limitSelector.value = initialLimit;

        loadVolumes(initialPage, initialLimit);
    }
    initialize();
});
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>