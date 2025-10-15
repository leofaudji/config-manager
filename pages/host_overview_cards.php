<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-grid-3x3-gap-fill"></i> Host Overview (Card View)</h1>
    <div class="btn-toolbar mb-2 mb-md-0">
        <button class="btn btn-sm btn-outline-primary" id="refresh-hosts-btn">
            <i class="bi bi-arrow-clockwise"></i> Refresh Now
        </button>
    </div>
</div>

<div id="hosts-overview-container" class="row row-cols-1 row-cols-md-2 row-cols-lg-3 row-cols-xl-4 g-4">
    <!-- Host cards will be loaded here by JavaScript -->
</div>

<script>
window.pageInit = function() {
    const container = document.getElementById('hosts-overview-container');
    const refreshBtn = document.getElementById('refresh-hosts-btn');

    function formatBytes(bytes, decimals = 2) {
        if (!+bytes) return 'N/A';
        const k = 1024;
        const dm = decimals < 0 ? 0 : decimals;
        const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return `${parseFloat((bytes / Math.pow(k, i)).toFixed(dm))} ${sizes[i]}`;
    }

    function loadHostCards() {
        const originalBtnContent = refreshBtn.innerHTML;
        refreshBtn.disabled = true;
        refreshBtn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Refreshing...`;
        container.innerHTML = `<div class="col-12 text-center"><div class="spinner-border" role="status"><span class="visually-hidden">Loading...</span></div></div>`;

        fetch(`<?= base_url('/api/dashboard-stats') ?>`)
            .then(response => response.json())
            .then(result => {
                if (result.status !== 'success' || !result.data.per_host_stats) {
                    throw new Error(result.message || 'Failed to load host data.');
                }

                let html = '';
                if (result.data.per_host_stats.length > 0) {
                    result.data.per_host_stats.forEach(host => {
                        const isReachable = host.status === 'Reachable';
                        const statusBadge = isReachable
                            ? `<span class="badge bg-success">Reachable</span>`
                            : `<span class="badge bg-danger" title="${host.error || 'Connection failed'}">Unreachable</span>`;

                        const swarmBadge = host.swarm_status
                            ? `<span class="badge bg-info">${host.swarm_status}</span>`
                            : `<span class="badge bg-secondary">N/A</span>`;

                        const containers = isReachable ? `${host.running_containers} / ${host.total_containers}` : 'N/A';
                        const cpus = host.cpus !== 'N/A' ? `${host.cpus} vCPUs` : 'N/A';
                        const memory = host.memory !== 'N/A' ? formatBytes(host.memory) : 'N/A';

                        html += `
                            <div class="col">
                                <div class="card h-100">
                                    <div class="card-header d-flex justify-content-between align-items-center">
                                        <h5 class="mb-0 text-truncate" title="${host.name}">${host.name}</h5>
                                        ${statusBadge}
                                    </div>
                                    <div class="card-body">
                                        <p class="card-text">
                                            <strong>Swarm:</strong> ${swarmBadge}<br>
                                            <strong>Containers:</strong> ${containers}<br>
                                            <strong>CPU:</strong> ${cpus}<br>
                                            <strong>Memory:</strong> ${memory}
                                        </p>
                                    </div>
                                    <div class="card-footer text-center">
                                        <a href="<?= base_url('/hosts/') ?>${host.id}/details" class="btn btn-sm btn-primary w-100">Manage Host</a>
                                    </div>
                                </div>
                            </div>
                        `;
                    });
                } else {
                    html = `<div class="col-12"><div class="alert alert-info">No hosts found. <a href="<?= base_url('/hosts/new') ?>">Add one now</a>.</div></div>`;
                }
                container.innerHTML = html;
            })
            .catch(error => {
                container.innerHTML = `<div class="col-12"><div class="alert alert-danger">Error loading hosts: ${error.message}</div></div>`;
            })
            .finally(() => {
                refreshBtn.disabled = false;
                refreshBtn.innerHTML = originalBtnContent;
            });
    }

    refreshBtn.addEventListener('click', loadHostCards);
    loadHostCards();
};
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>