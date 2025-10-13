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
            <a href="<?= base_url('/app-launcher?host_id=' . $id) ?>" class="btn btn-sm btn-primary ms-2" id="launch-app-btn" data-bs-toggle="tooltip" title="Launch a new application on this host.">
                <i class="bi bi-rocket-launch-fill"></i> Launch New App
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
                        <th class="sortable" data-sort="RunningServices">Status</th>
                        <th class="sortable" data-sort="ThresholdUp">Autoscaling</th>
                        <th class="sortable" data-sort="PlacementConstraint">Placement</th>
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

<!-- View Stack Details Modal -->
<div class="modal fade" id="viewStackDetailsModal" tabindex="-1" aria-labelledby="viewStackDetailsModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="viewStackDetailsModalLabel">Stack Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" id="stack-details-content-container">
        <!-- Details will be loaded here -->
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>
 
<script>
(function() { // IIFE to ensure script runs on AJAX load
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
        stacksContainer.innerHTML = '<tr><td colspan="8" class="text-center"><div class="spinner-border spinner-border-sm" role="status"><span class="visually-hidden">Loading...</span></div></td></tr>';

        const searchTerm = searchInput.value.trim();
        fetch(`${basePath}/api/hosts/${hostId}/stacks?search=${encodeURIComponent(searchTerm)}&page=${page}&limit=${limit}&sort=${currentSort}&order=${currentOrder}`)
            .then(response => response.json())
            .then(result => {
                if (result.status === 'error') throw new Error(result.message);
                
                const isSwarmManager = result.is_swarm_manager;
                let html = '';
                if (result.data && result.data.length > 0) {
                    result.data.forEach(stack => {
                        let statusHtml = '';
                        if (isSwarmManager) {
                            const running = stack.RunningServices || 0;
                            const desired = stack.DesiredServices || 0;
                            let statusClass = 'text-danger';
                            if (running === desired && desired > 0) {
                                statusClass = 'text-success';
                            } else if (running > 0) {
                                statusClass = 'text-warning';
                            }
                            statusHtml = `<span class="fw-bold ${statusClass}">${running}/${desired}</span> Running`;
                        } else { // Standalone
                            const running = stack.RunningServices || 0;
                            const stopped = stack.StoppedServices || 0;
                            let parts = [];
                            if (running > 0) {
                                parts.push(`<span class="text-success fw-bold">${running} Running</span>`);
                            }
                            if (stopped > 0) {
                                parts.push(`<span class="text-danger fw-bold">${stopped} Stopped</span>`);
                            }
                            statusHtml = parts.length > 0 ? parts.join(' / ') : '<span class="text-muted">N/A</span>';
                        }

                        // Make the status clickable, linking to the filtered container view
                        const containerLink = `${basePath}/hosts/${hostId}/containers?search=${encodeURIComponent(stack.Name)}`;

                        let autoscalingHtml = '<span class="text-muted">Disabled</span>';
                        if (stack.AutoscalingEnabled) {
                            autoscalingHtml = `<span class="text-success" title="Scale Up Threshold"><i class="bi bi-arrow-up-circle"></i> ${stack.ThresholdUp}%</span> / <span class="text-danger" title="Scale Down Threshold"><i class="bi bi-arrow-down-circle"></i> ${stack.ThresholdDown}%</span>`;
                        }

                        let placementHtml = '<span class="text-muted">Any Node</span>';
                        if (stack.PlacementConstraint) {
                            if (stack.PlacementConstraint === 'node.role==worker') {
                                placementHtml = '<span class="badge bg-primary">Worker Only</span>';
                            } else if (stack.PlacementConstraint === 'node.role==manager') {
                                placementHtml = '<span class="badge bg-warning text-dark">Manager Only</span>';
                            }
                        }


                        const stackDbId = stack.DbId;
                        const sourceType = stack.SourceType;
                        const detailsButton = isSwarmManager ? `<button class="btn btn-sm btn-outline-primary view-stack-details-btn" data-bs-toggle="modal" data-bs-target="#viewStackDetailsModal" data-stack-name="${stack.Name}" title="View Task Details"><i class="bi bi-list-task"></i></button>` : '';
                        let sourceHtml = '<span class="badge bg-secondary" data-bs-toggle="tooltip" title="Discovered on host (unmanaged)."><i class="bi bi-question-circle"></i> Unknown</span>';

                        if (sourceType === 'git') {
                            sourceHtml = '<span class="badge bg-dark" data-bs-toggle="tooltip" title="Deployed from a Git repository."><i class="bi bi-git me-1"></i> Git</span>';
                        } else if (sourceType === 'image') {
                            sourceHtml = '<span class="badge bg-primary" data-bs-toggle="tooltip" title="Deployed from an existing image on the host."><i class="bi bi-hdd-stack-fill me-1"></i> Host Image</span>';
                        } else if (sourceType === 'hub') {
                            sourceHtml = '<span class="badge bg-info text-dark" data-bs-toggle="tooltip" title="Deployed from a Docker Hub image."><i class="bi bi-box-seam me-1"></i> Docker Hub</span>';
                        } else if (sourceType === 'builder') {
                            sourceHtml = '<span class="badge bg-success" data-bs-toggle="tooltip" title="Created with the Stack Builder form."><i class="bi bi-tools me-1"></i> Builder</span>';
                        } else if (sourceType === 'editor') {
                            sourceHtml = '<span class="badge" style="background-color: #6f42c1; color: white;" data-bs-toggle="tooltip" title="Deployed from the YAML editor."><i class="bi bi-code-square me-1"></i> Editor</span>';
                        }

                        let updateButton = '';
                        if (stackDbId) {
                            if (sourceType === 'builder') {
                                updateButton = `<a href="${basePath}/hosts/${hostId}/stacks/${stackDbId}/edit" class="btn btn-sm btn-outline-warning" title="Edit Stack"><i class="bi bi-pencil-square"></i></a>`;
                            } else {
                                updateButton = `<a href="${basePath}/hosts/${hostId}/stacks/${stackDbId}/update" class="btn btn-sm btn-outline-warning" title="Update Stack"><i class="bi bi-arrow-repeat"></i></a>
                                                <a href="${basePath}/hosts/${hostId}/stacks/${stackDbId}/edit-compose" class="btn btn-sm btn-outline-secondary" data-bs-toggle="tooltip" title="Edit Raw Compose File"><i class="bi bi-file-code"></i></a>`;
                            }
                        }
                        const deleteButton = `<button class="btn btn-sm btn-outline-danger delete-stack-btn" data-stack-name="${stack.Name}" title="Delete Stack"><i class="bi bi-trash"></i></button>`;

                        html += `<tr>
                                    <td><input class="form-check-input stack-checkbox" type="checkbox" value="${stack.Name}"></td>
                                    <td><a href="#" class="view-stack-spec-btn" data-bs-toggle="modal" data-bs-target="#viewStackSpecModal" data-stack-name="${stack.Name}">${stack.Name}</a></td>
                                    <td>${sourceHtml}</td>
                                    <td><a href="${containerLink}" class="text-decoration-none" title="View containers for this stack">${statusHtml}</a></td>
                                    <td>${placementHtml}</td>
                                    <td>${autoscalingHtml}</td>
                                    <td>${new Date(stack.CreatedAt).toLocaleString()}</td>
                                    <td class="text-end">
                                        <button class="btn btn-sm btn-outline-info view-stack-spec-btn" data-bs-toggle="modal" data-bs-target="#viewStackSpecModal" data-stack-name="${stack.Name}" title="View Spec"><i class="bi bi-eye"></i></button>
                                        ${detailsButton}
                                        ${updateButton}
                                        ${deleteButton}
                                    </td>
                                 </tr>`;
                    });
                } else {
                    html = '<tr><td colspan="8" class="text-center">No stacks found on this host.</td></tr>';
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

            })
            .catch(error => stacksContainer.innerHTML = `<tr><td colspan="8" class="text-center text-danger">Failed to load stacks: ${error.message}</td></tr>`);
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
        const detailsBtn = e.target.closest('.view-stack-details-btn');

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

        if (detailsBtn) {
            e.preventDefault();
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

    // Handle the "View Stack Details" modal
    document.body.addEventListener('show.bs.modal', function(event) {
        if (event.target.id !== 'viewStackDetailsModal') return;

        const modal = event.target;
        const button = event.relatedTarget;
        const contentContainer = modal.querySelector('#stack-details-content-container');
        const modalLabel = modal.querySelector('#viewStackDetailsModalLabel');

        if (!button || !contentContainer || !modalLabel) return;

        const stackName = button.dataset.stackName;
        modalLabel.textContent = `Details for Stack: ${stackName}`;
        contentContainer.innerHTML = '<div class="text-center"><div class="spinner-border"></div><p>Loading details...</p></div>';

        fetch(`${basePath}/api/hosts/${hostId}/stacks/${stackName}/details`)
            .then(response => response.json())
            .then(result => {
                if (result.status !== 'success') throw new Error(result.message);

                let html = '';
                if (result.data.length === 0) {
                    html = '<div class="alert alert-warning">No services found for this stack.</div>';
                } else {
                    result.data.forEach(service => {
                        html += `<h5 class="mt-3">Service: ${service.Name}</h5>`;
                        html += `<p class="small text-muted">Image: <code>${service.Image}</code></p>`;
                        html += '<div class="table-responsive"><table class="table table-sm table-bordered"><thead><tr><th>Node</th><th>Origin</th><th>Current State</th><th>Desired State</th><th>Last Update</th><th>Message / Error</th><th class="text-end" style="width: 100px;">Actions</th></tr></thead><tbody>';
                        // Check if service.Tasks exists and is an array before checking its length
                        if (!service.Tasks || service.Tasks.length === 0) {
                            html += '<tr><td colspan="7" class="text-center text-muted">No tasks found for this service.</td></tr>';
                        } else {
                            service.Tasks.forEach(task => {
                                let originBadge = '';
                                if (task.Origin === 'Autoscaled') {
                                    originBadge = '<span class="badge bg-info" title="This task was created by the autoscaler."><i class="bi bi-magic me-1"></i>Autoscaled</span>';
                                } else {
                                    originBadge = '<span class="badge bg-secondary" title="This task was part of the initial deployment."><i class="bi bi-box-arrow-in-down me-1"></i>Deployment</span>';
                                }

                                let stateClass = 'text-secondary';
                                if (task.CurrentState === 'running') stateClass = 'text-success';
                                else if (['failed', 'rejected'].includes(task.CurrentState)) stateClass = 'text-danger';
                                else if (task.CurrentState === 'pending') stateClass = 'text-warning';

                                const errorMessage = task.Error ? `<br><small class="text-danger font-monospace">${task.Error}</small>` : '';

                                const logButton = `<button class="btn btn-sm btn-outline-secondary view-task-log-btn" data-bs-toggle="modal" data-bs-target="#viewLogsModal" data-task-id="${task.ID}" data-task-name="${service.Name}.${task.ID.substring(0, 8)}" title="View Task Logs"><i class="bi bi-card-text"></i></button>`;
                                const downloadLogButton = `<a href="${basePath}/api/hosts/${hostId}/tasks/${task.ID}/logs?download=true" class="btn btn-sm btn-outline-success" download="task_${task.ID.substring(0,12)}_logs.txt" title="Download Task Logs"><i class="bi bi-download"></i></a>`;
                                const actionButtons = `<div class="btn-group btn-group-sm">${logButton}${downloadLogButton}</div>`;

                                html += `<tr>
                                            <td><strong>${task.Node}</strong></td>
                                            <td>${originBadge}</td>
                                            <td><span class="fw-bold ${stateClass}">${task.CurrentState}</span></td>
                                            <td>${task.DesiredState}</td>
                                            <td>${new Date(task.Timestamp).toLocaleString()}</td>
                                            <td>${task.Message}${errorMessage}</td>
                                            <td class="text-end">${actionButtons}</td>
                                         </tr>`;
                            });
                        }
                        html += '</tbody></table></div>';
                    });
                }
                contentContainer.innerHTML = html;
            })
            .catch(error => {
                contentContainer.innerHTML = `<div class="alert alert-danger">Error: ${error.message}</div>`;
            });
    });

    // Handle the "View Logs" modal for Swarm Tasks
    const viewLogsModal = document.getElementById('viewLogsModal');
    if (viewLogsModal) {
        const logContentContainer = document.getElementById('log-content-container');
        const logModalLabel = document.getElementById('viewLogsModalLabel');

        viewLogsModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            // Only handle if it's a task log button
            if (!button.classList.contains('view-task-log-btn')) return;

            const taskId = button.dataset.taskId;
            const taskName = button.dataset.taskName;

            logModalLabel.textContent = `Logs for Task: ${taskName}`;
            logContentContainer.textContent = 'Loading logs...';

            const url = `${basePath}/api/hosts/${hostId}/tasks/${taskId}/logs`;

            fetch(url)
                .then(response => response.json().then(data => ({ ok: response.ok, data })))
                .then(({ ok, data }) => {
                    if (ok) {
                        logContentContainer.textContent = data.logs || 'No logs found or logs are empty.';
                    } else {
                        throw new Error(data.message || 'Failed to fetch logs.');
                    }
                })
                .catch(error => {
                    logContentContainer.textContent = `Error: ${error.message}`;
                });
        });
    }

    function initialize() {
        const initialPage = parseInt(localStorage.getItem(`host_${hostId}_stacks_page`)) || 1;
        const initialLimit = parseInt(localStorage.getItem(`host_${hostId}_stacks_limit`)) || 10;
        currentSort = localStorage.getItem(`host_${hostId}_stacks_sort`) || 'Name';
        currentOrder = localStorage.getItem(`host_${hostId}_stacks_order`) || 'asc';
        
        limitSelector.value = initialLimit;

        loadStacks(initialPage, initialLimit);
    }
    initialize();
})();
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>