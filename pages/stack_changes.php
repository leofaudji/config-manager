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
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-sm">
                <thead>
                    <tr>
                        <th class="sortable asc" data-sort="created_at">Timestamp</th>
                        <th class="sortable" data-sort="changed_by">User</th>
                        <th class="sortable" data-sort="host_name">Host</th>
                        <th class="sortable" data-sort="stack_name">Stack Name</th>
                        <th class="sortable" data-sort="change_type">Type</th>
                        <th>Details</th>
                        <th class="sortable" data-sort="duration_seconds">Duration</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody id="stack-changes-container">
                    <!-- Content will be loaded by AJAX -->
                    <tr class="text-center">
                        <td colspan="8"><div class="spinner-border" role="status"><span class="visually-hidden">Loading...</span></div></td>
                    </tr>
                </tbody>
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
    let deploymentLogModal; // Initialize later
    const tableHeader = document.querySelector('#stack-changes-container').closest('table').querySelector('thead');

    const paginationContainer = document.getElementById('stack-changes-pagination');
    const infoContainer = document.getElementById('stack-changes-info');
    const limitSelector = document.getElementById('stack-changes-limit-selector');

    let currentPage = 1;

    const debounce = (func, delay) => {
        let timeout;
        return function(...args) {
            const context = this;
            clearTimeout(timeout);
            timeout = setTimeout(() => func.apply(context, args), delay);
        };
    };

    function loadStackChanges(page = 1) {
        let currentSort = localStorage.getItem('stack_changes_sort') || 'created_at';
        let currentOrder = localStorage.getItem('stack_changes_order') || 'desc'; // Default to desc for history
        currentPage = page;
        const limit = limitSelector.value;
        container.innerHTML = `<div class="text-center"><div class="spinner-border" role="status"><span class="visually-hidden">Loading...</span></div></div>`;

        const startDate = startDateInput.value;
        const endDate = endDateInput.value;
        const changeType = changeTypeFilter.value;
        const searchTerm = searchInput.value.trim();

        // Save current filters to localStorage
        localStorage.setItem('stack_changes_page', currentPage);
        localStorage.setItem('stack_changes_limit', limit);
        localStorage.setItem('stack_changes_start_date', startDate);
        localStorage.setItem('stack_changes_end_date', endDate);
        localStorage.setItem('stack_changes_change_type', changeType);
        localStorage.setItem('stack_changes_search', searchTerm);
        localStorage.setItem('stack_changes_sort', currentSort);
        localStorage.setItem('stack_changes_order', currentOrder);

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
        } else {
            params.append('sort', currentSort);
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
                    container.innerHTML = '<tr><td colspan="8" class="text-center">No stack changes recorded yet.</td></tr>';
                    return;
                }

                let html = '';
                result.data.forEach(change => {
                    const badgeClass = { created: 'success', updated: 'warning', deleted: 'danger' }[change.change_type] || 'secondary';
                    const icon = { created: 'plus-circle', updated: 'arrow-repeat', deleted: 'trash' }[change.change_type] || 'info-circle';

                    const shortDetails = (change.details && change.details.length > 80) ? change.details.substring(0, 80) + '...' : (change.details || '');

                    let durationHtml = '';
                    if (change.duration_seconds !== null && change.duration_seconds > 0) {
                        durationHtml = `${change.duration_seconds}s`;
                    } else {
                        durationHtml = 'N/A';
                    }

                    const logButton = change.log_id
                        ? `<button class="btn btn-sm btn-outline-secondary view-log-btn" data-log-id="${change.log_id}" data-stack-name="${change.stack_name}" data-host-name="${change.host_name || 'N/A'}" title="View Deployment Log"><i class="bi bi-card-text"></i></button>`
                        : `<button class="btn btn-sm btn-outline-secondary" disabled title="No deployment log available"><i class="bi bi-card-text"></i></button>`;

                    html += `
                        <tr>
                            <td>${new Date(change.created_at).toLocaleString()}</td>
                            <td>${change.changed_by}</td>
                            <td>${change.host_name || 'N/A'}</td>
                            <td>${change.stack_name}</td>
                            <td><span class="badge bg-${badgeClass}"><i class="bi bi-${icon} me-1"></i> ${change.change_type}</span></td>
                            <td title="${change.details}">${shortDetails}</td>
                            <td>${durationHtml}</td>
                            <td class="text-end">
                                ${logButton}
                            </td>
                        </tr>
                    `;
                });
                container.innerHTML = html;
                // Update sort indicators in header
                tableHeader.querySelectorAll('th.sortable').forEach(th => {
                    th.classList.remove('asc', 'desc');
                    if (th.dataset.sort === currentSort) {
                        th.classList.add(currentOrder);
                    }
                });
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

    tableHeader.addEventListener('click', function(e) {
        const th = e.target.closest('th.sortable');
        if (!th) return;

        const sortField = th.dataset.sort;
        if (currentSort === sortField) {
            currentOrder = currentOrder === 'asc' ? 'desc' : 'asc';
        } else {
            currentSort = sortField;
            currentOrder = 'desc'; // Default to descending for new sort
        }
        loadStackChanges(1);
    });

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
        localStorage.removeItem('stack_changes_sort');
        localStorage.removeItem('stack_changes_order');
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
            e.preventDefault();
            const logId = viewBtn.dataset.logId;
            const stackName = viewBtn.dataset.stackName;
            const hostName = viewBtn.dataset.hostName;
            const deploymentLogModalLabel = document.getElementById('deploymentLogModalLabel');
            const deploymentLogContent = document.getElementById('deployment-log-content');
            deploymentLogModalLabel.textContent = `Deployment Log for: ${stackName} on ${hostName}`;
            deploymentLogContent.textContent = 'Loading log...';
            deploymentLogModal.show();

            fetch(`<?= base_url('/api/deployment-logs') ?>?id=${logId}`)
                .then(response => { // NOSONAR
                    if (!response.ok) return response.text().then(text => { throw new Error(text || 'Server error'); }); // NOSONAR
                    const processStatus = response.headers.get('X-Process-Status') || 'unknown'; // NOSONAR
                    let statusBadge = '';
                    if (processStatus === 'running') {
                        statusBadge = '<span class="badge bg-primary ms-2"><span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Running</span>';
                    } else if (processStatus === 'finished') {
                        statusBadge = '<span class="badge bg-success ms-2">Finished</span>';
                    } else if (processStatus === 'failed') {
                        statusBadge = '<span class="badge bg-danger ms-2">Failed</span>';
                    } else {
                        statusBadge = '<span class="badge bg-secondary ms-2">Unknown</span>';
                    }
                    deploymentLogModalLabel.innerHTML = `Deployment Log for: ${stackName} on ${hostName} ${statusBadge}`;
                    return response.text();
                })
                .then(logContent => deploymentLogContent.textContent = logContent || 'Log file is empty.')
                .catch(error => deploymentLogContent.textContent = `Error loading log: ${error.message}`);
        }
    });
    // --- Initial Load with Saved State ---
    function initialize() {
        const savedLimit = localStorage.getItem('stack_changes_limit') || '15';
        currentPage = parseInt(localStorage.getItem('stack_changes_page')) || 1;
        const savedStartDate = localStorage.getItem('stack_changes_start_date') || '';
        const savedEndDate = localStorage.getItem('stack_changes_end_date') || '';
        const savedChangeType = localStorage.getItem('stack_changes_change_type') || '';
        const savedSearch = localStorage.getItem('stack_changes_search') || '';
        currentSort = localStorage.getItem('stack_changes_sort') || 'created_at';
        currentOrder = localStorage.getItem('stack_changes_order') || 'desc';

        limitSelector.value = savedLimit;
        startDateInput.value = savedStartDate;
        endDateInput.value = savedEndDate;
        changeTypeFilter.value = savedChangeType;
        searchInput.value = savedSearch;

        // Initialize the modal instance here, when everything is loaded.
        deploymentLogModal = new window.bootstrap.Modal(document.getElementById('deploymentLogModal'));

        loadStackChanges(currentPage);
    }

    // Panggil fungsi inisialisasi untuk memuat data saat halaman dibuka
    initialize();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>