<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-display"></i> Host Overview</h1>
    <div class="btn-toolbar mb-2 mb-md-0">
        <button class="btn btn-sm btn-outline-primary" id="refresh-hosts-btn">
            <i class="bi bi-arrow-clockwise"></i> Refresh Now
        </button>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h5 class="mb-0">All Monitored Hosts</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-sm" id="host-overview-table">
                <thead>
                    <tr>
                        <th class="sortable asc" data-sort="name">Host Name</th>
                        <th class="sortable" data-sort="status">Connection</th>
                        <th class="sortable" data-sort="swarm_status">Swarm Status</th>
                        <th class="sortable" data-sort="running_containers">Containers</th>
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

<script>
window.pageInit = function() {
    const container = document.getElementById('hosts-overview-container');
    const refreshBtn = document.getElementById('refresh-hosts-btn');
    const table = document.getElementById('host-overview-table');

    function formatUptime(seconds) {
        if (!seconds || seconds <= 0) return 'N/A';
        const d = Math.floor(seconds / (3600*24));
        const h = Math.floor(seconds % (3600*24) / 3600);
        const m = Math.floor(seconds % 3600 / 60);
        let parts = [];
        if (d > 0) parts.push(`${d}d`);
        if (h > 0) parts.push(`${h}h`);
        if (m > 0) parts.push(`${m}m`);
        return parts.length > 0 ? parts.join(' ') : '< 1m';
    }

    function loadHostOverview() {
        const originalBtnContent = refreshBtn.innerHTML;
        refreshBtn.disabled = true;
        refreshBtn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Refreshing...`;
        container.innerHTML = `<tr><td colspan="8" class="text-center"><div class="spinner-border spinner-border-sm" role="status"></div></td></tr>`;

        fetch(`<?= base_url('/api/dashboard-stats') ?>`)
            .then(response => response.json())
            .then(result => {
                if (result.status !== 'success' || !result.data.per_host_stats) {
                    throw new Error(result.message || 'Failed to load host data.');
                }

                let html = '';
                if (result.data.per_host_stats.length > 0) {
                    result.data.per_host_stats.forEach(host => {
                        const statusBadge = host.status === 'Reachable' 
                            ? `<span class="badge bg-success">Reachable</span>`
                            : `<span class="badge bg-danger" title="${host.error || 'Connection failed'}">Unreachable</span>`;

                        const swarmBadge = host.swarm_status 
                            ? `<span class="badge bg-info">${host.swarm_status}</span>`
                            : `<span class="badge bg-secondary">N/A</span>`;

                        const containers = host.status === 'Reachable' ? `${host.running_containers} / ${host.total_containers}` : 'N/A';
                        const cpus = host.cpus !== 'N/A' ? `${host.cpus} vCPUs` : 'N/A';
                        const memory = host.memory !== 'N/A' ? formatBytes(host.memory) : 'N/A';

                        html += `
                            <tr data-sort-name="${host.name.toLowerCase()}" 
                                data-sort-status="${host.status}" 
                                data-sort-swarm_status="${host.swarm_status || 'zzz'}"
                                data-sort-running_containers="${host.running_containers || 0}"
                                data-sort-cpus="${host.cpus || 0}"
                                data-sort-memory="${host.memory || 0}"
                                data-sort-uptime="${host.uptime_timestamp || 0}">
                                <td><a href="<?= base_url('/hosts/') ?>${host.id}/details">${host.name}</a></td>
                                <td>${statusBadge}</td>
                                <td>${swarmBadge}</td>
                                <td>${containers}</td>
                                <td>${cpus}</td>
                                <td>${memory}</td>
                                <td>${formatUptime(host.uptime_timestamp)}</td>
                                <td class="text-end">
                                    <a href="<?= base_url('/hosts/') ?>${host.id}/details" class="btn btn-sm btn-outline-primary" title="Manage Host"><i class="bi bi-box-arrow-in-right"></i> Manage</a>
                                </td>
                            </tr>
                        `;
                    });
                } else {
                    html = `<tr><td colspan="8" class="text-center">No hosts found. <a href="<?= base_url('/hosts/new') ?>">Add one now</a>.</td></tr>`;
                }
                container.innerHTML = html;
            })
            .catch(error => {
                container.innerHTML = `<tr><td colspan="8" class="text-center text-danger">Error loading hosts: ${error.message}</td></tr>`;
            })
            .finally(() => {
                refreshBtn.disabled = false;
                refreshBtn.innerHTML = originalBtnContent;
            });
    }

    refreshBtn.addEventListener('click', loadHostOverview);

    // Sorting logic
    table.querySelectorAll('thead th.sortable').forEach(headerCell => {
        headerCell.addEventListener('click', () => {
            const sortKey = headerCell.dataset.sort;
            const isAsc = headerCell.classList.contains('asc');
            const newOrder = isAsc ? 'desc' : 'asc';

            table.querySelectorAll('thead th.sortable').forEach(th => th.classList.remove('asc', 'desc'));
            headerCell.classList.add(newOrder);

            const rows = Array.from(container.querySelectorAll('tr'));
            rows.sort((a, b) => {
                const valA = a.dataset[sortKey];
                const valB = b.dataset[sortKey];
                const isNumeric = !isNaN(parseFloat(valA)) && isFinite(valA);
                const comparison = isNumeric ? parseFloat(valA) - parseFloat(valB) : valA.localeCompare(valB);
                return newOrder === 'asc' ? comparison : -comparison;
            });

            rows.forEach(row => container.appendChild(row));
        });
    });

    loadHostOverview();
};
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>