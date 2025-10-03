<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-heart-pulse-fill"></i> Service Health Status</h1>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Monitored Services</h5>
        <div class="d-flex align-items-center">
            <form class="search-form me-2" data-type="health-status" id="health-status-search-form" onsubmit="return false;">
                <div class="input-group input-group-sm">
                    <input type="text" name="search" class="form-control" placeholder="Search by service name...">
                    <button class="btn btn-outline-secondary" type="submit" title="Search"><i class="bi bi-search"></i></button>
                    <button class="btn btn-outline-secondary reset-search-btn" type="button" title="Reset"><i class="bi bi-x-lg"></i></button>
                </div>
            </form>
            <button id="refresh-btn" class="btn btn-sm btn-outline-primary"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-sm table-hover">
                <thead>
                    <tr>
                        <th class="sortable asc" data-sort="status">Status</th>
                        <th class="sortable" data-sort="name">Service Name</th>
                        <th class="sortable" data-sort="group_name">Group</th>
                        <th class="sortable" data-sort="health_check_type">Check Type</th>
                        <th>Last Log</th>
                        <th class="sortable" data-sort="last_checked_at">Last Checked</th>
                    </tr>
                </thead>
                <tbody id="health-status-container">
                    <!-- Data will be loaded here by AJAX -->
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer d-flex justify-content-between align-items-center">
        <div class="text-muted small" id="health-status-info"></div>
        <div class="d-flex align-items-center">
            <nav id="health-status-pagination"></nav>
            <div class="ms-3">
                <select name="limit" class="form-select form-select-sm" id="health-status-limit-selector" style="width: auto;">
                    <option value="15">15</option>
                    <option value="30">30</option>
                    <option value="50">50</option>
                    <option value="-1">All</option>
                </select>
            </div>
        </div>
    </div>
</div>

<script>
window.pageInit = function() {
    const container = document.getElementById('health-status-container');
    const paginationContainer = document.getElementById('health-status-pagination');
    const infoContainer = document.getElementById('health-status-info');
    const limitSelector = document.getElementById('health-status-limit-selector');
    const searchForm = document.getElementById('health-status-search-form');
    const searchInput = searchForm.querySelector('input[name="search"]');
    const resetBtn = searchForm.querySelector('.reset-search-btn');
    const refreshBtn = document.getElementById('refresh-btn');
    const tableHeader = document.querySelector('#health-status-container').closest('table').querySelector('thead');

    let currentPage = 1;
    let currentLimit = 15;
    let currentSort = 'status';
    let currentOrder = 'asc';
    const storageKeyPrefix = 'health_status_dashboard';

    function loadHealthStatus(page = 1, limit = 15) {
        currentPage = parseInt(page) || 1;
        currentLimit = parseInt(limit) || 15;
        container.innerHTML = '<tr><td colspan="6" class="text-center"><div class="spinner-border spinner-border-sm" role="status"><span class="visually-hidden">Loading...</span></div></td></tr>';

        // Save state to localStorage
        localStorage.setItem(`${storageKeyPrefix}_page`, currentPage);
        localStorage.setItem(`${storageKeyPrefix}_limit`, currentLimit);
        localStorage.setItem(`${storageKeyPrefix}_sort`, currentSort);
        localStorage.setItem(`${storageKeyPrefix}_order`, currentOrder);
        localStorage.setItem(`${storageKeyPrefix}_search`, searchInput.value.trim());

        const searchTerm = searchInput.value.trim();
        const url = `${basePath}/api/health-status?search=${encodeURIComponent(searchTerm)}&page=${page}&limit=${limit}&sort=${currentSort}&order=${currentOrder}`;

        fetch(url)
            .then(response => response.json())
            .then(result => {
                if (result.status === 'error') throw new Error(result.message);

                let html = '';
                if (result.data && result.data.length > 0) {
                    result.data.forEach(item => {
                        let statusClass = 'secondary';
                        let statusIcon = 'question-circle';
                        if (item.status === 'healthy') {
                            statusClass = 'success';
                            statusIcon = 'check-circle-fill';
                        } else if (item.status === 'unhealthy') {
                            statusClass = 'danger';
                            statusIcon = 'x-circle-fill';
                        }

                        let lastLog = item.last_log ? item.last_log.substring(0, 80) + (item.last_log.length > 80 ? '...' : '') : '<i class="text-muted">N/A</i>';
                        let lastChecked = item.last_checked_at ? new Date(item.last_checked_at).toLocaleString() : '<i class="text-muted">Never</i>';

                        // Special handling for 'unknown' status to provide better info
                        if (item.status === 'unknown') {
                            lastLog = `<i class="text-muted" title="${item.last_log || 'No health check defined.'}">No health check defined.</i>`;
                            lastChecked = '<i class="text-muted">N/A</i>';
                        }

                        html += `<tr>
                                    <td><span class="badge text-bg-${statusClass}" title="Source: ${item.source_type}"><i class="bi bi-${statusIcon} me-1"></i> ${item.status || 'unknown'}</span></td>
                                    <td><strong>${item.name || item.id}</strong></td>
                                    <td><span class="badge bg-secondary">${item.group_name || 'N/A'}</span></td>
                                    <td><span class="badge bg-info">${item.health_check_type || 'N/A'}</span></td>
                                    <td><small class="font-monospace" title="${item.last_log || ''}">${lastLog}</small></td>
                                    <td>${lastChecked}</td>
                                 </tr>`;
                    });
                } else {
                    html = '<tr><td colspan="6" class="text-center">No monitored services found.</td></tr>';
                }
                container.innerHTML = html;
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

                // Update sort indicators
                tableHeader.querySelectorAll('th.sortable').forEach(th => {
                    th.classList.remove('asc', 'desc');
                    if (th.dataset.sort === currentSort) {
                        th.classList.add(currentOrder);
                    }
                });
            })
            .catch(error => container.innerHTML = `<tr><td colspan="6" class="text-center text-danger">Failed to load data: ${error.message}</td></tr>`);
    }

    paginationContainer.addEventListener('click', function(e) {
        const pageLink = e.target.closest('.page-link');
        if (pageLink) { e.preventDefault(); loadHealthStatus(pageLink.dataset.page, limitSelector.value); }
    });

    tableHeader.addEventListener('click', function(e) {
        const th = e.target.closest('th.sortable');
        if (!th) return;
        const sortField = th.dataset.sort;
        currentOrder = (currentSort === sortField && currentOrder === 'asc') ? 'desc' : 'asc';
        currentSort = sortField;
        loadHealthStatus(1, limitSelector.value);
    });

    limitSelector.addEventListener('change', () => loadHealthStatus(1, limitSelector.value));
    searchForm.addEventListener('submit', (e) => { e.preventDefault(); loadHealthStatus(); });
    resetBtn.addEventListener('click', () => { if (searchInput.value) { searchInput.value = ''; loadHealthStatus(); } });
    refreshBtn.addEventListener('click', () => loadHealthStatus(currentPage, currentLimit));
    
    function initialize() {
        const initialPage = parseInt(localStorage.getItem(`${storageKeyPrefix}_page`)) || 1;
        const initialLimit = parseInt(localStorage.getItem(`${storageKeyPrefix}_limit`)) || 15;
        currentSort = localStorage.getItem(`${storageKeyPrefix}_sort`) || 'status';
        currentOrder = localStorage.getItem(`${storageKeyPrefix}_order`) || 'asc';
        searchInput.value = localStorage.getItem(`${storageKeyPrefix}_search`) || '';
        
        limitSelector.value = initialLimit;

        loadHealthStatus(initialPage, initialLimit);
    }

    initialize();
};
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>