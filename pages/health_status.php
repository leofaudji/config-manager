<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$conn = Database::getInstance()->getConnection();

// Query for groups
$groups_result = $conn->query("SELECT name FROM `groups` ORDER BY name ASC");
$groups = $groups_result->fetch_all(MYSQLI_ASSOC);

// Query for hosts
$hosts_result = $conn->query("SELECT name FROM `docker_hosts` ORDER BY name ASC");
$hosts = $hosts_result->fetch_all(MYSQLI_ASSOC);

// Combine and get unique names for the filter dropdown
$all_group_hosts = array_unique(array_merge(array_column($groups, 'name'), array_column($hosts, 'name')));
sort($all_group_hosts);

$active_page = 'health-status';
require_once __DIR__ . '/../includes/header.php';

?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Service Health Status</h1>
    <div class="btn-toolbar mb-2 mb-md-0">
        <button class="btn btn-sm btn-outline-secondary" id="refresh-data-btn">
            <i class="bi bi-arrow-clockwise"></i> Refresh
        </button>
    </div>
</div>

<div class="row mb-3">
    <div class="col-md-4">
        <div class="input-group">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="text" id="search-input" class="form-control" placeholder="Search by name...">
        </div>
    </div>
    <div class="col-md-4">
        <div class="input-group">
            <span class="input-group-text"><i class="bi bi-collection-fill"></i></span>
            <select id="group-host-filter" class="form-select">
                <option value="">All Groups / Hosts</option>
                <?php foreach ($all_group_hosts as $name): ?>
                    <option value="<?= htmlspecialchars($name) ?>"><?= htmlspecialchars($name) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="col-md-4">
        <div class="btn-group w-100" role="group" aria-label="Status filter">
            <input type="radio" class="btn-check" name="status_filter" id="filter-all" value="" autocomplete="off">
            <label class="btn btn-outline-secondary" for="filter-all">All</label>

            <input type="radio" class="btn-check" name="status_filter" id="filter-healthy" value="healthy" autocomplete="off">
            <label class="btn btn-outline-success" for="filter-healthy">Healthy</label>

            <input type="radio" class="btn-check" name="status_filter" id="filter-unhealthy" value="unhealthy" autocomplete="off">
            <label class="btn btn-outline-danger" for="filter-unhealthy">Unhealthy</label>

            <input type="radio" class="btn-check" name="status_filter" id="filter-unknown" value="unknown" autocomplete="off">
            <label class="btn btn-outline-secondary" for="filter-unknown">Unknown</label>
        </div>
    </div>
</div>

<div class="table-responsive">
    <table class="table table-striped table-hover">
        <thead>
            <tr>
                <th class="sortable" data-sort="status">Status</th>
                <th class="sortable" data-sort="name">Name</th>
                <th class="sortable" data-sort="group_name">Group / Host</th>
                <th class="sortable" data-sort="health_check_type">Check Type</th>
                <th>Last Log</th>
                <th class="sortable" data-sort="last_checked_at">Last Checked</th>
            </tr>
        </thead>
        <tbody id="health-status-table-body">
            <!-- Data will be loaded here by JavaScript -->
        </tbody>
    </table>
</div>

<div class="d-flex justify-content-between align-items-center mt-3">
    <div id="table-info">
        <!-- Info paginasi akan dimuat di sini -->
    </div>
    <div class="d-flex align-items-center">
        <div id="pagination-controls"></div>
        <div class="ms-3">
            <select id="limit-selector" class="form-select form-select-sm" style="width: auto;">
                <option value="10">10</option>
                <option value="50">50</option>
                <option value="100">100</option>
                <option value="-1">All</option>
            </select>
        </div>
    </div>
</div>

<script>
window.pageInit = function() {
    const tableBody = document.getElementById('health-status-table-body');
    const searchInput = document.getElementById('search-input');
    const paginationControls = document.getElementById('pagination-controls');
    const tableInfo = document.getElementById('table-info');
    const refreshBtn = document.getElementById('refresh-data-btn');
    const groupHostFilter = document.getElementById('group-host-filter');
    const limitSelector = document.getElementById('limit-selector');
    const statusFilterRadios = document.querySelectorAll('input[name="status_filter"]');

    let currentPage = parseInt(localStorage.getItem('health_status_page')) || 1;
    let currentLimit = localStorage.getItem('health_status_limit') || '10';
    let currentStatusFilter = localStorage.getItem('health_status_status_filter') || 'unhealthy'; // Default to 'unhealthy'
    let currentGroupFilter = localStorage.getItem('health_status_group_filter') || '';
    let currentSort = 'status';
    let currentOrder = 'asc';
    let searchTimeout;

    function fetchHealthStatus() {
        const search = searchInput.value.trim();
        const statusFilter = document.querySelector('input[name="status_filter"]:checked').value;
        const groupFilter = groupHostFilter.value;
        const limit = limitSelector.value;

        const url = new URL('<?= base_url('/api/health-status') ?>', window.location.origin);
        url.searchParams.append('page', currentPage);
        url.searchParams.append('limit', limit);
        url.searchParams.append('sort', currentSort);
        url.searchParams.append('order', currentOrder);
        if (search) url.searchParams.append('search', search);
        if (groupFilter) url.searchParams.append('group_filter', groupFilter);
        if (statusFilter) url.searchParams.append('status_filter', statusFilter);

        // Save state to localStorage
        localStorage.setItem('health_status_page', currentPage);
        localStorage.setItem('health_status_limit', limit);
        localStorage.setItem('health_status_status_filter', statusFilter);
        localStorage.setItem('health_status_group_filter', groupFilter);

        tableBody.innerHTML = `<tr><td colspan="6" class="text-center">Loading...</td></tr>`;

        fetch(url)
            .then(response => response.json())
            .then(result => {
                if (result.status !== 'success') {
                    tableBody.innerHTML = `<tr><td colspan="6" class="text-center text-danger">${result.message}</td></tr>`;
                    return;
                }
                renderTable(result.data);
                renderPagination(result.total_pages, result.current_page);
                tableInfo.innerHTML = result.info;
            })
            .catch(error => {
                tableBody.innerHTML = `<tr><td colspan="6" class="text-center text-danger">Failed to load data.</td></tr>`;
                console.error('Error fetching health status:', error);
            });
    }

    function renderTable(data) {
        if (data.length === 0) {
            tableBody.innerHTML = `<tr><td colspan="6" class="text-center">No items found.</td></tr>`;
            return;
        }
        tableBody.innerHTML = data.map(item => { // The API now returns host_id
            let statusBadge;
            switch (item.status) {
                case 'healthy': statusBadge = 'bg-success'; break;
                case 'unhealthy': statusBadge = 'bg-danger'; break;
                default: statusBadge = 'bg-secondary';
            }

            // Determine if the row should be clickable and where it should link
            let rowClass = '';
            let dataHref = '';
            if (item.host_id) {
                rowClass = 'clickable-row';
                dataHref = `data-href="<?= base_url('/hosts/') ?>${item.host_id}/containers"`;
            }

            return `
                <tr class="${rowClass}" ${dataHref} title="${item.host_id ? 'Click to view containers on this host' : ''}">
                    <td><span class="badge ${statusBadge}">${item.status || 'unknown'}</span></td>
                    <td><strong>${item.name}</strong></td>
                    <td>${item.group_name}</td>
                    <td><span class="badge bg-secondary">${item.health_check_type}</span></td>
                    <td class="small text-muted" style="max-width: 300px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="${item.last_log}">${item.last_log}</td>
                    <td class="small text-muted">${item.last_checked_at ? new Date(item.last_checked_at).toLocaleString() : 'Never'}</td>
                </tr>
            `;
        }).join('');
    }

    function renderPagination(totalPages, currentPage) {
        if (totalPages <= 1) {
            paginationControls.innerHTML = '';
            return;
        }

        let paginationHtml = '<ul class="pagination pagination-sm mb-0">';
        // Previous button
        paginationHtml += `<li class="page-item ${currentPage <= 1 ? 'disabled' : ''}"><a class="page-link" href="#" data-page="${currentPage - 1}">«</a></li>`;

        // Page numbers with ellipsis
        const maxPagesToShow = 5;
        let startPage = Math.max(1, currentPage - Math.floor(maxPagesToShow / 2));
        let endPage = Math.min(totalPages, startPage + maxPagesToShow - 1);

        if (endPage - startPage + 1 < maxPagesToShow) {
            startPage = Math.max(1, endPage - maxPagesToShow + 1);
        }

        if (startPage > 1) {
            paginationHtml += `<li class="page-item"><a class="page-link" href="#" data-page="1">1</a></li>`;
            if (startPage > 2) {
                paginationHtml += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
            }
        }

        for (let i = startPage; i <= endPage; i++) {
            paginationHtml += `<li class="page-item ${currentPage == i ? 'active' : ''}"><a class="page-link" href="#" data-page="${i}">${i}</a></li>`;
        }

        if (endPage < totalPages) {
            if (endPage < totalPages - 1) {
                paginationHtml += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
            }
            paginationHtml += `<li class="page-item"><a class="page-link" href="#" data-page="${totalPages}">${totalPages}</a></li>`;
        }

        // Next button
        paginationHtml += `<li class="page-item ${currentPage >= totalPages ? 'disabled' : ''}"><a class="page-link" href="#" data-page="${parseInt(currentPage) + 1}">»</a></li>`;
        paginationHtml += '</ul>';
        paginationControls.innerHTML = paginationHtml;
    }

    searchInput.addEventListener('input', () => {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            currentPage = 1;
            fetchHealthStatus();
        }, 300);
    });

    statusFilterRadios.forEach(radio => {
        radio.addEventListener('change', () => {
            currentPage = 1;
            fetchHealthStatus();
        });
    });

    groupHostFilter.addEventListener('change', () => {
        currentPage = 1;
        fetchHealthStatus();
    });

    limitSelector.addEventListener('change', () => {
        currentPage = 1;
        fetchHealthStatus();
    });

    paginationControls.addEventListener('click', function(e) {
        e.preventDefault();
        const target = e.target.closest('.page-link');
        if (target && !target.closest('.disabled') && !target.closest('.active')) {
            currentPage = parseInt(target.dataset.page);
            fetchHealthStatus();
        }
    });

    refreshBtn.addEventListener('click', fetchHealthStatus);

    // --- Event Delegation for Clickable Rows ---
    // This is more robust than attaching listeners to each row individually,
    // as it works even after the table content is refreshed via AJAX.
    tableBody.addEventListener('click', function(e) {
        // Find the closest parent `<tr>` that has the .clickable-row class
        const row = e.target.closest('.clickable-row');
        if (row && row.dataset.href) {
            // Use the SPA navigation function for a smoother experience.
            if (typeof loadPage === 'function') {
                loadPage(row.dataset.href);
            } else {
                // Fallback for safety
                window.location.href = row.dataset.href;
            }
        }
    });

    // Initial load
    limitSelector.value = currentLimit; // Set dropdown to stored value
    groupHostFilter.value = currentGroupFilter; // Set group filter to stored value
    const storedStatusRadio = document.querySelector(`input[name="status_filter"][value="${currentStatusFilter}"]`);
    if (storedStatusRadio) {
        storedStatusRadio.checked = true;
    } else {
        // Fallback to unhealthy if the stored value is somehow invalid
        document.getElementById('filter-unhealthy').checked = true;
    }
    fetchHealthStatus();
};
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>