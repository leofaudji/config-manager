<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-calendar-week"></i> Stack Change History</h1>
</div>

<div class="card">
    <div class="card-header">
        <div class="row align-items-center">
            <div class="col-md-6">
                <p class="mb-0 small text-muted">This page shows a log of all stacks that were created, updated, or deleted, grouped by host and date.</p>
            </div>
            <div class="col-md-6">
                <form id="filter-form" class="d-flex justify-content-end align-items-center">
                    <div class="input-group input-group-sm me-2" style="width: auto;">
                        <input type="text" class="form-control" id="search-input" placeholder="Search by stack, user, or host...">
                    </div>
                    <select class="form-select form-select-sm me-2" id="change-type-filter" style="width: auto;">
                        <option value="">All Types</option>
                        <option value="created">Created</option>
                        <option value="updated">Updated</option>
                        <option value="deleted">Deleted</option>
                    </select>
                    <div class="input-group input-group-sm" style="width: auto;">
                        <input type="date" class="form-control" id="start-date" title="Start Date">
                        <input type="date" class="form-control" id="end-date" title="End Date">
                        <button class="btn btn-outline-primary" type="submit" id="filter-btn"><i class="bi bi-funnel-fill"></i> Filter</button>
                        <button class="btn btn-outline-secondary" type="button" id="reset-filter-btn" title="Reset Filter"><i class="bi bi-x-lg"></i></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="card-body" id="stack-changes-container">
        <!-- Content will be loaded by AJAX -->
        <div class="text-center">
            <div class="spinner-border" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
        </div>
    </div>
    <div class="card-footer d-flex justify-content-between align-items-center">
        <div class="text-muted small" id="stack-changes-info"></div>
        <div class="d-flex align-items-center">
            <nav id="stack-changes-pagination"></nav>
            <div class="ms-3">
                <select name="limit_stack_changes" class="form-select form-select-sm" id="stack-changes-limit-selector" style="width: auto;">
                    <option value="15">15</option>
                    <option value="30">30</option>
                    <option value="50">50</option>
                    <option value="-1">All</option>
                </select>
            </div>
        </div>
    </div>
</div>

<!-- Modal for Stack Change Details -->
<div class="modal fade" id="stackChangeDetailModal" tabindex="-1" aria-labelledby="stackChangeDetailModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="stackChangeDetailModalLabel">Change Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <dl class="row">
          <dt class="col-sm-4">Stack Name</dt>
          <dd class="col-sm-8" id="detail-stack-name"></dd>

          <dt class="col-sm-4">Change Type</dt>
          <dd class="col-sm-8" id="detail-change-type"></dd>

          <dt class="col-sm-4">Timestamp</dt>
          <dd class="col-sm-8" id="detail-created-at"></dd>

          <dt class="col-sm-4">Changed By</dt>
          <dd class="col-sm-8" id="detail-changed-by"></dd>

          <dt class="col-sm-4">Details</dt>
          <dd class="col-sm-8"><pre id="detail-details" class="mb-0" style="white-space: pre-wrap;"></pre></dd>
        </dl>
      </div>
    </div>
  </div>
</div>

<!-- Deployment Log Modal -->
<div class="modal fade" id="deploymentLogModal" tabindex="-1" aria-labelledby="deploymentLogModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="deploymentLogModalLabel">Deployment Log</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body bg-dark text-light font-monospace">
        <pre id="deployment-log-content" class="mb-0" style="white-space: pre-wrap; word-break: break-all;"></pre>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script>
(function() { // IIFE to ensure script runs on AJAX load
    const container = document.getElementById('stack-changes-container');
    const filterForm = document.getElementById('filter-form');
    const startDateInput = document.getElementById('start-date');
    const endDateInput = document.getElementById('end-date');
    const searchInput = document.getElementById('search-input');
    const changeTypeFilter = document.getElementById('change-type-filter');
    const resetFilterBtn = document.getElementById('reset-filter-btn');
    const deploymentLogModal = new bootstrap.Modal(document.getElementById('deploymentLogModal'));
    const deploymentLogContent = document.getElementById('deployment-log-content');
    const deploymentLogModalLabel = document.getElementById('deploymentLogModalLabel');
    const paginationContainer = document.getElementById('stack-changes-pagination');
    const infoContainer = document.getElementById('stack-changes-info');
    const limitSelector = document.getElementById('stack-changes-limit-selector');

    let currentPage = 1;

    function loadStackChanges(page = 1) {
        currentPage = page;
        const limit = limitSelector.value;
        container.innerHTML = `<div class="text-center"><div class="spinner-border" role="status"><span class="visually-hidden">Loading...</span></div></div>`;

        const startDate = startDateInput.value;
        const endDate = endDateInput.value;
        const changeType = changeTypeFilter.value;
        const searchTerm = searchInput.value.trim();

        // Save current filters to localStorage
        localStorage.setItem('stack_changes_page', page);
        localStorage.setItem('stack_changes_limit', limit);
        localStorage.setItem('stack_changes_start_date', startDate);
        localStorage.setItem('stack_changes_end_date', endDate);
        localStorage.setItem('stack_changes_change_type', changeType);
        localStorage.setItem('stack_changes_search', searchTerm);

        let apiUrl = '<?= base_url('/api/stack-changes') ?>';
        const params = new URLSearchParams();
        params.append('page', page);
        params.append('limit', limit);

        if (startDate) {
            params.append('start_date', startDate);
        }
        if (endDate) {
            params.append('end_date', endDate);
        }
        if (changeType) {
            params.append('change_type', changeType);
        }
        if (searchTerm) {
            params.append('search', searchTerm);
        }

        if (params.toString()) {
            apiUrl += '?' + params.toString();
        }

        fetch(apiUrl)
            .then(response => response.json())
            .then(result => {
                if (result.status === 'error') {
                    throw new Error(result.message);
                }

                infoContainer.textContent = result.info || '';
                renderPagination(result.total_pages, result.current_page);

                container.innerHTML = ''; // Clear spinner

                if (Object.keys(result.data).length === 0) {
                    container.innerHTML = '<div class="alert alert-info">No stack changes recorded yet.</div>';
                    return;
                }

                let html = '';
                // Data is grouped by host_name -> date -> changes
                for (const hostName in result.data) {
                    html += `<h4 class="mt-4"><i class="bi bi-hdd-network-fill me-2"></i>${hostName}</h4>`;
                    
                    const dates = result.data[hostName];
                    for (const date in dates) {
                        html += `<h5 class="mt-3 text-muted">${date}</h5>`;
                        html += '<ul class="list-group">';
                        
                        const changes = dates[date];
                        changes.forEach(change => {
                            const badgeClass = { created: 'success', updated: 'warning', deleted: 'danger' }[change.change_type] || 'secondary';
                            const icon = { created: 'plus-circle', updated: 'arrow-repeat', deleted: 'trash' }[change.change_type] || 'info-circle';

                            const shortDetails = (change.details && change.details.length > 80) ? change.details.substring(0, 80) + '...' : (change.details || '');
                            const escapedDetails = (change.details || '').replace(/"/g, '&quot;');

                            let durationHtml = '';
                            if (change.duration_seconds !== null && change.duration_seconds > 0) {
                                durationHtml = `<span class="badge bg-light text-dark ms-2" title="Deployment Duration"><i class="bi bi-stopwatch"></i> ${change.duration_seconds}s</span>`;
                            }

                            html += `
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <div>
                                        <span class="badge bg-${badgeClass} me-2"><i class="bi bi-${icon} me-1"></i> ${change.change_type}</span>
                                        <strong>${change.stack_name}</strong>
                                        <small class="d-block text-muted">${shortDetails} (by ${change.changed_by})</small>
                                    </div>
                                    <div class="d-flex align-items-center">
                                        ${durationHtml}
                                        <small class="text-muted me-3">${new Date(change.created_at).toLocaleTimeString()}</small>
                                        <button class="btn btn-sm btn-outline-secondary view-log-btn me-2" 
                                                data-host-name="${change.host_name}" 
                                                data-stack-name="${change.stack_name}" 
                                                title="View Deployment Log">
                                            <i class="bi bi-card-text"></i></button>
                                        <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#stackChangeDetailModal" data-stack-name="${change.stack_name}" data-change-type="${change.change_type}" data-details="${escapedDetails}" data-changed-by="${change.changed_by}" data-created-at="${change.created_at}" title="View Details"><i class="bi bi-info-circle"></i></button>
                                    </div>
                                 </li>
                            `;
                        });
                        html += '</ul>';
                    }
                }
                container.innerHTML = html;
            })
            .catch(error => {
                container.innerHTML = `<div class="alert alert-danger">Failed to load stack changes: ${error.message}</div>`;
            });
    }

    function renderPagination(totalPages, currentPage) {
        let html = '';
        if (totalPages > 1) {
            html += '<ul class="pagination pagination-sm mb-0">';
            // Previous button
            html += `<li class="page-item ${currentPage <= 1 ? 'disabled' : ''}"><a class="page-link" href="#" data-page="${currentPage - 1}">«</a></li>`;
            // Page numbers
            for (let i = 1; i <= totalPages; i++) {
                html += `<li class="page-item ${currentPage == i ? 'active' : ''}"><a class="page-link" href="#" data-page="${i}">${i}</a></li>`;
            }
            // Next button
            html += `<li class="page-item ${currentPage >= totalPages ? 'disabled' : ''}"><a class="page-link" href="#" data-page="${parseInt(currentPage) + 1}">»</a></li>`;
            html += '</ul>';
        }
        paginationContainer.innerHTML = html;
    }

    filterForm.addEventListener('submit', function(e) {
        e.preventDefault();
        loadStackChanges(1);
    });

    resetFilterBtn.addEventListener('click', function() {
        startDateInput.value = '';
        endDateInput.value = '';
        searchInput.value = '';
        changeTypeFilter.value = '';
        // Clear saved filters from localStorage as well
        localStorage.removeItem('stack_changes_start_date');
        localStorage.removeItem('stack_changes_end_date');
        localStorage.removeItem('stack_changes_change_type');
        localStorage.removeItem('stack_changes_search');
        loadStackChanges(1);
    });

    paginationContainer.addEventListener('click', function(e) {
        if (e.target.matches('.page-link')) {
            e.preventDefault();
            loadStackChanges(e.target.dataset.page);
        }
    });

    limitSelector.addEventListener('change', () => loadStackChanges(1));

    searchInput.addEventListener('input', debounce(() => loadStackChanges(1), 400));

    container.addEventListener('click', function(e) {
        const viewBtn = e.target.closest('.view-log-btn');
        if (viewBtn) {
            const hostName = viewBtn.dataset.hostName;
            const stackName = viewBtn.dataset.stackName;
            const safeHostName = hostName.replace(/[^a-zA-Z0-9_.-]/g, '_');
            const logFilePath = `${safeHostName}/${stackName}/deployment.log`;

            deploymentLogModalLabel.textContent = `Deployment Log for: ${stackName} on ${hostName}`;
            deploymentLogContent.textContent = 'Loading log...';
            deploymentLogModal.show();

            fetch(`<?= base_url('/api/deployment-logs') ?>?file=${encodeURIComponent(logFilePath)}`)
                .then(response => response.json())
                .then(result => {
                    if (result.status !== 'success') throw new Error(result.message);
                    deploymentLogContent.textContent = result.content || 'Log file is empty or not found.';
                })
                .catch(error => {
                    deploymentLogContent.textContent = `Error loading log: ${error.message}`;
                });
        }
    });
    // --- Initial Load with Saved State ---
    function initialize() {
        const savedLimit = localStorage.getItem('stack_changes_limit') || '15';
        const savedPage = localStorage.getItem('stack_changes_page') || '1';
        const savedStartDate = localStorage.getItem('stack_changes_start_date') || '';
        const savedEndDate = localStorage.getItem('stack_changes_end_date') || '';
        const savedChangeType = localStorage.getItem('stack_changes_change_type') || '';
        const savedSearch = localStorage.getItem('stack_changes_search') || '';

        limitSelector.value = savedLimit;
        startDateInput.value = savedStartDate;
        endDateInput.value = savedEndDate;
        changeTypeFilter.value = savedChangeType;
        searchInput.value = savedSearch;

        loadStackChanges(savedPage);
    }
    initialize();

    const stackChangeDetailModal = document.getElementById('stackChangeDetailModal');
    if (stackChangeDetailModal) {
        stackChangeDetailModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            const modalTitle = stackChangeDetailModal.querySelector('.modal-title');
            
            const stackName = button.dataset.stackName;
            const changeType = button.dataset.changeType;
            const details = button.dataset.details;
            const changedBy = button.dataset.changedBy;
            const createdAt = new Date(button.dataset.createdAt).toLocaleString();

            modalTitle.textContent = `Details for: ${stackName}`;
            
            document.getElementById('detail-stack-name').textContent = stackName;
            document.getElementById('detail-change-type').textContent = changeType;
            document.getElementById('detail-created-at').textContent = createdAt;
            document.getElementById('detail-changed-by').textContent = changedBy;
            document.getElementById('detail-details').textContent = details.split(' | ').join('\n');
        });
    }
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>