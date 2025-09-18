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
$active_page = 'stacks';
require_once __DIR__ . '/../includes/host_nav.php';
?>

<div class="card mt-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-stack"></i> Application Stacks</h5>
        <div class="d-flex align-items-center" id="stack-actions-container">
            <div id="bulk-actions-container" class="dropdown me-2" style="display: none;">
                <button class="btn btn-secondary btn-sm dropdown-toggle" type="button" id="bulk-actions-btn" data-bs-toggle="dropdown" aria-expanded="false">
                    Bulk Actions
                </button>
                <ul class="dropdown-menu" aria-labelledby="bulk-actions-btn">
                    <li><a class="dropdown-item text-danger bulk-action-trigger" href="#" data-action="delete">Delete Selected</a></li>
                </ul>
            </div>
            <form class="search-form me-2" data-type="stacks" id="stack-search-form" onsubmit="return false;">
                <div class="input-group input-group-sm">
                    <input type="text" name="search_stacks" class="form-control" placeholder="Search by name...">
                    <button class="btn btn-outline-secondary" type="submit" title="Search"><i class="bi bi-search"></i></button>
                    <button class="btn btn-outline-secondary reset-search-btn" type="button" title="Reset"><i class="bi bi-x-lg"></i></button>
                </div>
            </form>
            <a href="<?= base_url('/hosts/' . $id . '/deploy/git') ?>" class="btn btn-sm btn-outline-info" id="deploy-git-btn" data-bs-toggle="tooltip" title="Deploy a new stack from a Git repository.">
                <i class="bi bi-github"></i> Deploy from Git
            </a>
            <a href="<?= base_url('/hosts/' . $id . '/stacks/new') ?>" class="btn btn-sm btn-outline-primary ms-2" id="add-stack-btn" data-bs-toggle="tooltip" title="Create a new stack using a form builder.">
                <i class="bi bi-plus-circle"></i> Add New Stack
            </a>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive table-responsive-sticky">
            <table class="table table-striped table-sm table-hover">
                <thead>
                    <tr>
                        <th><input class="form-check-input" type="checkbox" id="select-all-stacks" title="Select all stacks"></th>
                        <th class="sortable asc" data-sort="Name">Name</th>
                        <th class="sortable" data-sort="SourceType">Source</th>
                        <th class="sortable" data-sort="Services">Services</th>
                        <th class="sortable" data-sort="CreatedAt">Created At</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody id="stacks-container">
                    <!-- Stacks data will be loaded here by AJAX -->
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer d-flex justify-content-between align-items-center">
        <div class="text-muted small" id="stacks-info"></div>
        <div class="d-flex align-items-center">
            <nav id="stacks-pagination"></nav>
            <div class="ms-3">
                <select name="limit_stacks" class="form-select form-select-sm" id="stacks-limit-selector" style="width: auto;">
                    <option value="10">10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="-1">All</option>
                </select>
            </div>
        </div>
    </div>
</div>

<!-- View Stack Spec Modal -->
<div class="modal fade" id="viewStackSpecModal" tabindex="-1" aria-labelledby="viewStackSpecModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="viewStackSpecModalLabel">Stack Specification</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <pre><code id="stack-spec-content-container" class="language-yaml">Loading...</code></pre>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const hostId = <?= $id ?>;
    const stacksContainer = document.getElementById('stacks-container');
    const bulkActionsContainer = document.getElementById('bulk-actions-container');
    const selectAllCheckbox = document.getElementById('select-all-stacks');
    const searchForm = document.getElementById('stack-search-form');
    const searchInput = searchForm.querySelector('input[name="search_stacks"]');
    const resetBtn = searchForm.querySelector('.reset-search-btn');
    const paginationContainer = document.getElementById('stacks-pagination');
    const infoContainer = document.getElementById('stacks-info');
    const limitSelector = document.getElementById('stacks-limit-selector');
    const tableHeader = document.querySelector('#stacks-container').closest('table').querySelector('thead');

    let currentPage = 1;
    let currentLimit = 10;
    let currentSort = 'Name';
    let currentOrder = 'asc';

    function reloadCurrentView() {
        loadStacks(parseInt(currentPage), parseInt(currentLimit));
    }

    function loadStacks(page = 1, limit = 10) {
        currentPage = parseInt(page) || 1;
        currentLimit = parseInt(limit) || 10;
        stacksContainer.innerHTML = '<tr><td colspan="6" class="text-center"><div class="spinner-border spinner-border-sm" role="status"><span class="visually-hidden">Loading...</span></div></td></tr>';

        const searchTerm = searchInput.value.trim();
        fetch(`${basePath}/api/hosts/${hostId}/stacks?search=${encodeURIComponent(searchTerm)}&page=${page}&limit=${limit}&sort=${currentSort}&order=${currentOrder}`)
            .then(response => response.json())
            .then(result => {
                if (result.status === 'error') throw new Error(result.message);
                
                const isSwarmManager = result.is_swarm_manager;
                let html = '';
                if (result.data && result.data.length > 0) {
                    result.data.forEach(stack => {
                        const stackDbId = stack.DbId;
                        const sourceType = stack.SourceType;
                        let sourceHtml = '<span class="badge bg-secondary" data-bs-toggle="tooltip" title="Discovered on host (unmanaged)."><i class="bi bi-question-circle"></i> Unknown</span>';

                        if (sourceType === 'git') {
                            sourceHtml = '<span class="badge bg-dark" data-bs-toggle="tooltip" title="Deployed from a Git repository."><i class="bi bi-github me-1"></i> Git</span>';
                        } else if (sourceType === 'image') {
                            sourceHtml = '<span class="badge bg-primary" data-bs-toggle="tooltip" title="Deployed from an existing image on the host."><i class="bi bi-hdd-stack-fill me-1"></i> Host Image</span>';
                        } else if (sourceType === 'hub') {
                            sourceHtml = '<span class="badge bg-info text-dark" data-bs-toggle="tooltip" title="Deployed from a Docker Hub image."><i class="bi bi-box-seam me-1"></i> Docker Hub</span>';
                        } else if (sourceType === 'builder') {
                            sourceHtml = '<span class="badge bg-success" data-bs-toggle="tooltip" title="Created with the Stack Builder form."><i class="bi bi-tools me-1"></i> Builder</span>';
                        }

                        let updateButton = '';
                        if (stackDbId) {
                            if (sourceType === 'builder') {
                                updateButton = `<a href="${basePath}/hosts/${hostId}/stacks/${stackDbId}/edit" class="btn btn-sm btn-outline-warning" title="Edit Stack"><i class="bi bi-pencil-square"></i></a>`;
                            } else {
                                updateButton = `<a href="${basePath}/hosts/${hostId}/stacks/${stackDbId}/update" class="btn btn-sm btn-outline-warning" title="Update Stack"><i class="bi bi-arrow-repeat"></i></a>`;
                            }
                        }
                        const deleteButton = `<button class="btn btn-sm btn-outline-danger delete-stack-btn" data-stack-name="${stack.Name}" title="Delete Stack"><i class="bi bi-trash"></i></button>`;

                        html += `<tr>
                                    <td><input class="form-check-input stack-checkbox" type="checkbox" value="${stack.Name}"></td>
                                    <td><a href="#" class="view-stack-spec-btn" data-bs-toggle="modal" data-bs-target="#viewStackSpecModal" data-stack-name="${stack.Name}">${stack.Name}</a></td>
                                    <td>${sourceHtml}</td>
                                    <td>${stack.Services}</td>
                                    <td>${new Date(stack.CreatedAt).toLocaleString()}</td>
                                    <td class="text-end">
                                        <button class="btn btn-sm btn-outline-info view-stack-spec-btn" data-bs-toggle="modal" data-bs-target="#viewStackSpecModal" data-stack-name="${stack.Name}" title="View Spec"><i class="bi bi-eye"></i></button>
                                        ${updateButton}
                                        ${deleteButton}
                                    </td>
                                 </tr>`;
                    });
                } else {
                    html = '<tr><td colspan="6" class="text-center">No stacks found on this host.</td></tr>';
                }
                stacksContainer.innerHTML = html;
                // Re-initialize tooltips for the new content
                const tooltipTriggerList = stacksContainer.querySelectorAll('[data-bs-toggle="tooltip"]');
                [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl));
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
                localStorage.setItem(`host_${hostId}_stacks_page`, result.current_page);
                localStorage.setItem(`host_${hostId}_stacks_limit`, result.limit);

                // Update sort indicators in header
                tableHeader.querySelectorAll('th.sortable').forEach(th => {
                    th.classList.remove('asc', 'desc');
                    if (th.dataset.sort === currentSort) {
                        th.classList.add(currentOrder);
                    }
                });

                // --- UI Clarification for Standalone Hosts ---
                // Change button text and tooltips to reflect their actual function on standalone hosts.
                const deployGitBtn = document.getElementById('deploy-git-btn');
                const addStackBtn = document.getElementById('add-stack-btn');
                if (!isSwarmManager) {
                    if (deployGitBtn) {
                        deployGitBtn.innerHTML = '<i class="bi bi-github"></i> Generate Project from Git';
                        deployGitBtn.title = 'Generate a downloadable project archive from a Git repository.';
                    }
                    if (addStackBtn) {
                        addStackBtn.innerHTML = '<i class="bi bi-plus-circle"></i> Generate Compose File';
                        addStackBtn.title = 'Generate a downloadable docker-compose.yml file using a form builder.';
                    }
                }
            })
            .catch(error => stacksContainer.innerHTML = `<tr><td colspan="6" class="text-center text-danger">Failed to load stacks: ${error.message}</td></tr>`);
    }

    paginationContainer.addEventListener('click', function(e) {
        const pageLink = e.target.closest('.page-link');
        if (pageLink) {
            e.preventDefault();
            loadStacks(parseInt(pageLink.dataset.page), limitSelector.value);
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
        localStorage.setItem(`host_${hostId}_stacks_sort`, currentSort);
        localStorage.setItem(`host_${hostId}_stacks_order`, currentOrder);
        loadStacks(1, limitSelector.value);
    });

    limitSelector.addEventListener('change', function() {
        loadStacks(1, this.value);
    });

    function updateBulkActionsVisibility() {
        const checkedBoxes = stacksContainer.querySelectorAll('.stack-checkbox:checked');
        if (checkedBoxes.length > 0) {
            bulkActionsContainer.style.display = 'block';
        } else {
            bulkActionsContainer.style.display = 'none';
        }
    }

    stacksContainer.addEventListener('change', (e) => {
        if (e.target.matches('.stack-checkbox')) {
            updateBulkActionsVisibility();
        }
    });

    selectAllCheckbox.addEventListener('change', function() {
        const isChecked = this.checked;
        stacksContainer.querySelectorAll('.stack-checkbox').forEach(checkbox => {
            checkbox.checked = isChecked;
        });
        updateBulkActionsVisibility();
    });

    bulkActionsContainer.addEventListener('click', function(e) {
        const trigger = e.target.closest('.bulk-action-trigger');
        if (!trigger) return;

        e.preventDefault();
        const action = trigger.dataset.action;
        const checkedBoxes = Array.from(stacksContainer.querySelectorAll('.stack-checkbox:checked'));
        const stackNames = checkedBoxes.map(cb => cb.value);

        if (stackNames.length === 0) {
            showToast('No stacks selected.', false);
            return;
        }

        if (!confirm(`Are you sure you want to ${action} ${stackNames.length} selected stack(s)? This action cannot be undone.`)) {
            return;
        }

        let completed = 0;
        const total = stackNames.length;
        showToast(`Performing bulk action '${action}' on ${total} stacks...`, true);

        stackNames.forEach(stackName => {
            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('stack_name', stackName);

            fetch(`${basePath}/api/hosts/${hostId}/stacks`, { method: 'POST', body: formData })
                .catch(error => console.error(`Error during bulk delete for stack ${stackName}:`, error))
                .finally(() => {
                    completed++;
                    if (completed === total) {
                        showToast(`Bulk delete completed.`, true);
                        setTimeout(reloadCurrentView, 2000);
                    }
                });
        });
    });

    stacksContainer.addEventListener('click', function(e) {
        const deleteBtn = e.target.closest('.delete-stack-btn');
        const specBtn = e.target.closest('.view-stack-spec-btn');

        if (specBtn) {
            e.preventDefault();
        }

        if (deleteBtn) {
            const stackName = deleteBtn.dataset.stackName;
            if (!confirm(`Are you sure you want to delete the stack "${stackName}" from the host? This action cannot be undone.`)) return;

            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('stack_name', stackName);

            fetch(`${basePath}/api/hosts/${hostId}/stacks`, { method: 'POST', body: formData })
                .then(response => response.json().then(data => ({ ok: response.ok, data })))
                .then(({ ok, data }) => {
                    showToast(data.message, ok);
                    if (ok) reloadCurrentView();
                });
        }
    });

    // Handle the "View Spec" modal using event delegation for robustness
    document.body.addEventListener('show.bs.modal', function(event) {
        // Check if the modal being shown is the one we care about
        if (event.target.id !== 'viewStackSpecModal') {
            return;
        }

        const modal = event.target;
        const button = event.relatedTarget;
        const contentContainer = modal.querySelector('#stack-spec-content-container');
        const modalLabel = modal.querySelector('#viewStackSpecModalLabel');

        // Ensure all required elements are present before proceeding
        if (!button || !contentContainer || !modalLabel) {
            console.error('Modal or its components are missing.');
            if(contentContainer) contentContainer.textContent = 'Error: Modal components not found.';
            return;
        }

        const stackName = button.dataset.stackName;
        modalLabel.textContent = `Specification for Stack: ${stackName}`;
        contentContainer.textContent = 'Loading...';

        fetch(`${basePath}/api/hosts/${hostId}/stacks/${stackName}/spec`)
            .then(response => response.json())
            .then(result => {
                if (result.status === 'success') {
                    contentContainer.textContent = result.content;
                    Prism.highlightElement(contentContainer);
                } else {
                    throw new Error(result.message);
                }
            })
            .catch(error => {
                contentContainer.textContent = `Error: ${error.message}`;
            });
    });

    function initialize() {
        const initialPage = parseInt(localStorage.getItem(`host_${hostId}_stacks_page`)) || 1;
        const initialLimit = parseInt(localStorage.getItem(`host_${hostId}_stacks_limit`)) || 10;
        currentSort = localStorage.getItem(`host_${hostId}_stacks_sort`) || 'Name';
        currentOrder = localStorage.getItem(`host_${hostId}_stacks_order`) || 'asc';
        
        limitSelector.value = initialLimit;

        loadStacks(initialPage, initialLimit);
    }
    initialize();
});
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>