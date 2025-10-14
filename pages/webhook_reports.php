<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-github"></i> Webhook Reports</h1>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Webhook Activity Log</h5>
        <form class="d-flex" id="webhook-filter-form" onsubmit="return false;">
            <input type="text" class="form-control form-control-sm me-2" id="search-input" placeholder="Search details or IP...">
            <select id="status-filter" class="form-select form-select-sm me-2" style="width: auto;">
                <option value="">All Statuses</option>
                <option value="Triggered">Triggered</option>
                <option value="Ignored">Ignored</option>
                <option value="Failed">Failed</option>
            </select>
            <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-funnel-fill"></i></button>
        </form>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-sm">
                <thead>
                    <tr>
                        <th>Timestamp</th>
                        <th>Status</th>
                        <th>Details</th>
                        <th>Source IP</th>
                    </tr>
                </thead>
                <tbody id="logs-container">
                    <!-- Data will be loaded here by AJAX -->
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer d-flex justify-content-between align-items-center">
        <div class="text-muted small" id="logs-info"></div>
        <nav id="logs-pagination"></nav>
    </div>
</div>

<script>
window.pageInit = function() {
    const container = document.getElementById('logs-container');
    const paginationContainer = document.getElementById('logs-pagination');
    const infoContainer = document.getElementById('logs-info');
    const filterForm = document.getElementById('webhook-filter-form');
    const searchInput = document.getElementById('search-input');
    const statusFilter = document.getElementById('status-filter');

    function loadLogs(page = 1) {
        container.innerHTML = '<tr><td colspan="4" class="text-center"><div class="spinner-border spinner-border-sm" role="status"></div></td></tr>';

        const searchTerm = searchInput.value.trim();
        const status = statusFilter.value;
        const url = `<?= base_url('/api/logs/view') ?>?type=webhook&page=${page}&search=${encodeURIComponent(searchTerm)}&status=${status}`;

        fetch(url)
            .then(response => response.json())
            .then(result => {
                if (result.status === 'error') throw new Error(result.message);

                let html = '';
                if (result.data && result.data.length > 0) {
                    result.data.forEach(log => {
                        let badgeClass = 'secondary';
                        if (log.action.includes('Triggered')) badgeClass = 'success';
                        if (log.action.includes('Failed')) badgeClass = 'danger';
                        if (log.action.includes('Ignored')) badgeClass = 'warning';

                        html += `
                            <tr>
                                <td><small class="text-muted">${log.created_at}</small></td>
                                <td><span class="badge bg-${badgeClass}">${log.action.replace('Webhook ', '')}</span></td>
                                <td>${log.details}</td>
                                <td><code>${log.ip_address}</code></td>
                            </tr>
                        `;
                    });
                } else {
                    html = '<tr><td colspan="4" class="text-center">No webhook activity found.</td></tr>';
                }
                container.innerHTML = html;
                infoContainer.innerHTML = result.info;

                // Build pagination
                let paginationHtml = '';
                if (result.total_pages > 1) {
                    paginationHtml += '<ul class="pagination pagination-sm mb-0">';
                    for (let i = 1; i <= result.total_pages; i++) {
                        paginationHtml += `<li class="page-item ${result.current_page == i ? 'active' : ''}"><a class="page-link" href="#" data-page="${i}">${i}</a></li>`;
                    }
                    paginationHtml += '</ul>';
                }
                paginationContainer.innerHTML = paginationHtml;
            })
            .catch(error => container.innerHTML = `<tr><td colspan="4" class="text-center text-danger">Failed to load logs: ${error.message}</td></tr>`);
    }

    filterForm.addEventListener('submit', (e) => {
        e.preventDefault();
        loadLogs(1);
    });

    paginationContainer.addEventListener('click', e => {
        if (e.target.matches('.page-link')) { e.preventDefault(); loadLogs(e.target.dataset.page); }
    });

    loadLogs();
};
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>