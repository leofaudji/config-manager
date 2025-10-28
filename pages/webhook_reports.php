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
        <div class="d-flex align-items-center">
            <div class="form-check form-switch me-3" data-bs-toggle="tooltip" title="Automatically refresh logs every 15 seconds">
                <input class="form-check-input" type="checkbox" role="switch" id="auto-refresh-switch">
                <label class="form-check-label" for="auto-refresh-switch">Auto Refresh</label>
            </div>
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
                        <th class="text-end">Actions</th>
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
        <div class="d-flex align-items-center">
            <nav id="logs-pagination"></nav>
            <div class="ms-3">
                <select name="limit" class="form-select form-select-sm" id="limit-selector" style="width: auto;">
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
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
window.pageInit = function() {
    const container = document.getElementById('logs-container');
    const paginationContainer = document.getElementById('logs-pagination');
    const infoContainer = document.getElementById('logs-info');
    const filterForm = document.getElementById('webhook-filter-form');
    const searchInput = document.getElementById('search-input');
    const statusFilter = document.getElementById('status-filter');
    const limitSelector = document.getElementById('limit-selector');
    const deploymentLogModal = new bootstrap.Modal(document.getElementById('deploymentLogModal'));
    const deploymentLogContent = document.getElementById('deployment-log-content');
    const deploymentLogModalLabel = document.getElementById('deploymentLogModalLabel');

    function loadLogs(page = 1) {
        container.innerHTML = '<tr><td colspan="5" class="text-center"><div class="spinner-border spinner-border-sm" role="status"></div></td></tr>';

        const searchTerm = searchInput.value.trim();
        const status = statusFilter.value;
        const limit = limitSelector.value;
        const url = `<?= base_url('/api/logs/view') ?>?type=webhook&page=${page}&limit=${limit}&search=${encodeURIComponent(searchTerm)}&status=${status}`;

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

                        // --- FIX: Show button only if a log file path exists for this entry ---
                        const logButton = log.log_file_path
                            ? `<button class="btn btn-sm btn-outline-primary view-log-btn" data-log-id="${log.id}" title="View Deployment Log"><i class="bi bi-card-text"></i></button>`
                            : '';

                        html += `
                            <tr>
                                <td><small class="text-muted">${log.created_at}</small></td>
                                <td><span class="badge bg-${badgeClass}">${log.action.replace('Webhook ', '')}</span></td>
                                <td>${log.details}</td>
                                <td><code>${log.ip_address}</code></td>
                                <td class="text-end">${logButton}</td>
                            </tr>
                        `;
                    });
                } else {
                    html = '<tr><td colspan="5" class="text-center">No webhook activity found.</td></tr>';
                }
                container.innerHTML = html;
                infoContainer.innerHTML = result.info;
                renderPagination(paginationContainer, result.total_pages, result.current_page, loadLogs);
            })
            .catch(error => container.innerHTML = `<tr><td colspan="5" class="text-center text-danger">Failed to load logs: ${error.message}</td></tr>`);
    }

    container.addEventListener('click', function(e) {
        const viewBtn = e.target.closest('.view-log-btn');
        if (viewBtn) {
            const logId = viewBtn.dataset.logId;
            deploymentLogModalLabel.textContent = `Deployment Log for Activity #${logId}`;
            deploymentLogContent.textContent = 'Loading log...';
            deploymentLogModal.show();

            // --- FIX: Handle raw text response and custom headers ---
            fetch(`<?= base_url('/api/deployment-logs') ?>?id=${logId}`)
                .then(response => {
                    if (!response.ok) {
                        return response.text().then(text => { throw new Error(text || 'Server error'); });
                    }
                    const processStatus = response.headers.get('X-Process-Status') || 'unknown';
                    let statusBadge = '';
                    if (processStatus === 'running') statusBadge = '<span class="badge bg-success ms-2">Running</span>';
                    else if (processStatus === 'finished') statusBadge = '<span class="badge bg-secondary ms-2">Finished</span>';
                    deploymentLogModalLabel.innerHTML = `Deployment Log for Activity #${logId} ${statusBadge}`;
                    return response.text();
                })
                .then(logContent => deploymentLogContent.textContent = logContent || 'Log file is empty.')
                .catch(error => deploymentLogContent.textContent = `Error loading log: ${error.message}`);
        }
    });

    filterForm.addEventListener('submit', (e) => { e.preventDefault(); loadLogs(1); });
    limitSelector.addEventListener('change', () => loadLogs(1));
    loadLogs(1);
};
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>