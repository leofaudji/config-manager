<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-display"></i> Host Overview</h1>
    <div class="btn-toolbar mb-2 mb-md-0">
        <div class="btn-group me-2">
            <button id="view-mode-table" class="btn btn-sm btn-outline-secondary active" title="Table View"><i class="bi bi-table"></i></button>
            <button id="view-mode-card" class="btn btn-sm btn-outline-secondary" title="Card View"><i class="bi bi-grid-3x3-gap-fill"></i></button>
        </div>
        <button class="btn btn-sm btn-outline-primary" id="refresh-btn">
            <i class="bi bi-arrow-clockwise"></i> Refresh Now
        </button>
    </div>
</div>

<!-- Container for Table View -->
<div id="host-table-view">
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover table-sm" id="host-overview-table">
                    <thead>
                        <tr>
                            <th class="sortable asc" data-sort="name">Host Name</th>
                            <th class="sortable" data-sort="status">Connection</th>
                            <th class="sortable" data-sort="swarm_status">Swarm Status</th>
                            <th class="sortable" data-sort="running_containers">Containers</th>
                            <th class="sortable" data-sort="sla_percentage_raw">SLA (30d)</th>
                            <th class="sortable" data-sort="cpus">CPU</th>
                            <th class="sortable" data-sort="memory">Memory</th>
                            <th class="sortable" data-sort="uptime">Uptime</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="hosts-overview-container">
                        <!-- Data will be loaded here by JavaScript -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Container for Card View -->
<div id="host-card-view" class="row d-flex align-items-stretch" style="display: none;">
    <!-- Host cards will be loaded here by JavaScript -->
</div>
<style>
/* Ensure host name truncates properly within the flex container */
.card-header h6 {
    min-width: 0; /* Allows the element to shrink and truncate */
}
</style>


<script>
window.pageInit = function() {
    const tableContainer = document.getElementById('hosts-overview-container');
    const cardContainer = document.getElementById('host-card-view');
    const tableView = document.getElementById('host-table-view');
    const cardView = document.getElementById('host-card-view');
    const refreshBtn = document.getElementById('refresh-btn');
    const viewModeTableBtn = document.getElementById('view-mode-table');
    const viewModeCardBtn = document.getElementById('view-mode-card');
    const minimumSla = parseFloat(<?= json_encode(get_setting('minimum_sla_percentage', '99.9')) ?>);
    let currentViewMode = localStorage.getItem('host_overview_mode') || 'table';
    let hostDataCache = [];

    function formatBytes(bytes, decimals = 2) {
        if (!+bytes) return '0 Bytes';
        const k = 1024;
        const dm = decimals < 0 ? 0 : decimals;
        const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return `${parseFloat((bytes / Math.pow(k, i)).toFixed(dm))} ${sizes[i]}`;
    }

    function renderData() {
        if (currentViewMode === 'table') {
            renderTableView(hostDataCache);
            tableView.style.display = 'block';
            cardView.style.display = 'none';
            viewModeTableBtn.classList.add('active');
            viewModeCardBtn.classList.remove('active');
        } else {
            renderCardView(hostDataCache);
            tableView.style.display = 'none';
            cardView.style.display = 'block';
            viewModeTableBtn.classList.remove('active');
            viewModeCardBtn.classList.add('active');
        }
    }

    function loadHostData() {
        const originalBtnContent = refreshBtn.innerHTML;
        refreshBtn.disabled = true;
        refreshBtn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Refreshing...`;
        
        // Show loading indicator only for the current view
        if (currentViewMode === 'table') {
            tableView.style.display = 'block';
            cardView.style.display = 'none';
            tableContainer.innerHTML = `<tr><td colspan="9" class="text-center p-5"><div class="spinner-border text-primary" role="status"></div></td></tr>`;
        } else {
            tableView.style.display = 'none';
            cardView.style.display = 'block';
            cardContainer.innerHTML = `<div class="col-12 text-center p-5"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div></div>`;
        }

        fetch(`<?= base_url('/api/dashboard-stats') ?>`)
            .then(response => response.json())
            .then(result => {
                if (result.status !== 'success' || !result.data.per_host_stats) {
                    throw new Error(result.message || 'Failed to load host data.');
                }
                hostDataCache = result.data.per_host_stats;                renderData();            })            .catch(error => {                tableContainer.innerHTML = `<tr><td colspan="9" class="text-center text-danger">Error: ${error.message}</td></tr>`;                cardContainer.innerHTML = `<div class="col-12"><div class="alert alert-danger">Failed to load host data: ${error.message}</div></div>`;            })
            .finally(() => {
                refreshBtn.disabled = false;
                refreshBtn.innerHTML = originalBtnContent;
            });
    }

    function renderTableView(data) {
        let html = '';
        if (data.length > 0) {
            data.forEach(host => {
                const statusBadge = host.status === 'Reachable' 
                    ? `<span class="badge bg-success">Reachable</span>`
                    : `<span class="badge bg-danger" title="${host.error || 'Connection failed'}">Unreachable</span>`;

                const swarmBadge = host.swarm_status 
                    ? `<span class="badge bg-info">${host.swarm_status}</span>`
                    : `<span class="badge bg-secondary">N/A</span>`;

                const containers = host.status === 'Reachable' ? `${host.running_containers} / ${host.total_containers}` : 'N/A';
                const cpus = host.cpus !== 'N/A' ? `${host.cpus} vCPUs` : 'N/A';
                const memory = host.memory !== 'N/A' ? formatBytes(host.memory) : 'N/A';

                let slaHtml = '<span class="text-muted">N/A</span>';
                if (host.sla_percentage_raw !== null) {
                    let slaColorClass = 'text-success';
                    if (host.sla_percentage_raw < minimumSla) slaColorClass = 'text-danger';
                    else if (host.sla_percentage_raw < 99.9) slaColorClass = 'text-warning';
                    slaHtml = `<a href="#" class="export-host-sla-pdf text-decoration-none" data-host-id="${host.id}" title="Export 30-day SLA Summary PDF">
                                 <strong class="${slaColorClass}">${host.sla_percentage}%</strong>
                               </a>`;
                }

                html += `
                    <tr data-sort-name="${host.name.toLowerCase()}" 
                        data-sort-status="${host.status}" 
                        data-sort-swarm_status="${host.swarm_status || 'zzz'}"
                        data-sort-running_containers="${host.running_containers || 0}"
                        data-sort-sla_percentage_raw="${host.sla_percentage_raw || -1}"
                        data-sort-cpus="${host.cpus || 0}"
                        data-sort-memory="${host.memory || 0}"
                        data-sort-uptime="${host.uptime_timestamp || 0}">
                        <td><a href="<?= base_url('/hosts/') ?>${host.id}/details">${host.name}</a></td>
                        <td>${statusBadge}</td>
                        <td>${swarmBadge}</td>
                        <td>${containers}</td>
                        <td>${slaHtml}</td>
                        <td>${cpus}</td>
                        <td>${memory}</td>
                        <td>${host.uptime}</td>
                        <td class="text-end">
                            <a href="<?= base_url('/hosts/') ?>${host.id}/details" class="btn btn-sm btn-outline-primary" title="Manage Host"><i class="bi bi-box-arrow-in-right"></i> Manage</a>
                        </td>
                    </tr>
                `;
            });
        } else {
            html = `<tr><td colspan="9" class="text-center">No hosts found. <a href="<?= base_url('/hosts/new') ?>">Add one now</a>.</td></tr>`;
        }
        tableContainer.innerHTML = html;
    }

    function renderCardView(data) {
        let html = '';
        if (data.length > 0) {
            data.forEach(host => {
                const isReachable = host.status === 'Reachable';
                const statusBadgeClass = isReachable ? 'bg-success' : 'bg-danger';
                const borderClass = isReachable ? '' : 'border-danger';

                let slaHtml = '<span class="text-muted">N/A</span>';
                if (host.sla_percentage_raw !== null) {
                    let slaColorClass = 'text-success';
                    if (host.sla_percentage_raw < minimumSla) slaColorClass = 'text-danger';
                    else if (host.sla_percentage_raw < 99.9) slaColorClass = 'text-warning';
                    slaHtml = `<a href="#" class="export-host-sla-pdf text-decoration-none" data-host-id="${host.id}" title="Export 30-day SLA Summary PDF">
                                 <strong class="${slaColorClass}">${host.sla_percentage}%</strong>
                               </a>`;
                }

                html += `
                    <div class="col-xl-3 col-lg-4 col-md-6 mb-4">
                        <div class="card h-100 ${borderClass} d-flex flex-column">
                            <div class="card-header d-flex justify-content-between align-items-center py-2 gap-2">
                                <h6 class="mb-0 text-truncate flex-grow-1" title="${host.name}">
                                    <a href="${basePath}/hosts/${host.id}/details" class="text-decoration-none">${host.name}</a>
                                </h6>
                                <span class="badge ${statusBadgeClass} flex-shrink-0">${host.status}</span>
                            </div>
                            <div class="card-body py-2 px-3 flex-grow-1">
                                <div class="row text-center">
                                    <div class="col-4"><div class="h5 mb-0">${isReachable ? host.running_containers : 'N/A'}</div><small class="text-muted">Running</small></div>
                                    <div class="col-4"><div class="h5 mb-0">${isReachable ? host.total_containers : 'N/A'}</div><small class="text-muted">Total</small></div>
                                    <div class="col-4"><div class="h5 mb-0">${slaHtml}</div><small class="text-muted">SLA (30d)</small></div>
                                </div>
                                <hr class="my-2">
                                <ul class="list-unstyled text-muted small mb-0">
                                    <li><i class="bi bi-cpu me-2"></i> ${isReachable ? host.cpus + ' vCPUs' : 'N/A'}</li>
                                    <li><i class="bi bi-memory me-2"></i> ${isReachable ? formatBytes(host.memory) : 'N/A'}</li>
                                    <li><i class="bi bi-clock-history me-2"></i> ${host.uptime}</li>
                                </ul>
                            </div>
                            <div class="card-footer text-end py-2">
                                <a href="${basePath}/hosts/${host.id}/details" class="btn btn-sm btn-outline-primary">Manage <i class="bi bi-arrow-right-short"></i></a>
                            </div>
                        </div>
                    </div>
                `;
            });
        } else {
            html = '<div class="col-12"><div class="alert alert-info">No hosts found.</div></div>';
        }
        cardContainer.innerHTML = html;
    }

    viewModeTableBtn.addEventListener('click', () => {
        currentViewMode = 'table';
        localStorage.setItem('host_overview_mode', 'table');
        renderData();
    });

    viewModeCardBtn.addEventListener('click', () => {
        currentViewMode = 'card';
        localStorage.setItem('host_overview_mode', 'card');
        renderData();
    });

    function handleSlaExportClick(event) {
        const exportLink = event.target.closest('.export-host-sla-pdf');
        if (exportLink) {
            event.preventDefault();
            const hostId = exportLink.dataset.hostId;
            const originalContent = exportLink.innerHTML;
            exportLink.innerHTML = `<span class="spinner-border spinner-border-sm" role="status"></span>`;

            const formData = new FormData();
            formData.append('report_type', 'sla_report');
            formData.append('host_id', hostId);
            formData.append('container_id', 'all'); // For summary report
            formData.append('date_range', `${moment().subtract(29, 'days').format('YYYY-MM-DD')} - ${moment().format('YYYY-MM-DD')}`);

            fetch('<?= base_url('/api/pdf') ?>', { method: 'POST', body: formData })
                .then(res => res.blob())
                .then(blob => {
                    const url = window.URL.createObjectURL(blob);
                    window.open(url, '_blank');
                })
                .catch(err => showToast('Error exporting PDF: ' + err.message, false))
                .finally(() => exportLink.innerHTML = originalContent);
        }
    }

    // Attach the same handler to both views
    tableContainer.addEventListener('click', handleSlaExportClick);
    cardContainer.addEventListener('click', handleSlaExportClick);

    refreshBtn.addEventListener('click', loadHostData);

    // Sorting logic
    tableView.querySelectorAll('thead th.sortable').forEach(headerCell => {
        headerCell.addEventListener('click', () => {
            const sortKey = headerCell.dataset.sort;
            const isAsc = headerCell.classList.contains('asc');
            const newOrder = isAsc ? 'desc' : 'asc';

            tableView.querySelectorAll('thead th.sortable').forEach(th => th.classList.remove('asc', 'desc'));
            headerCell.classList.add(newOrder);

            const rows = Array.from(container.querySelectorAll('tr'));
            rows.sort((a, b) => {
                const valA = a.dataset[sortKey];
                const valB = b.dataset[sortKey];
                const isNumeric = !isNaN(parseFloat(valA)) && isFinite(valA);
                const comparison = isNumeric ? parseFloat(valA) - parseFloat(valB) : valA.localeCompare(valB);
                return (newOrder === 'asc') ? comparison : -comparison;
            });

            rows.forEach(row => tableContainer.appendChild(row));
        });
    });

    loadHostData();
};
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>