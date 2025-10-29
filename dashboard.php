<?php
// File ini adalah view untuk dashboard utama.
// Sesi dan otentikasi sudah diperiksa oleh Router.
require_once 'includes/bootstrap.php';
$conn = Database::getInstance()->getConnection();
// Ambil daftar grup untuk modal "Move to Group"
$groups_result = $conn->query("SELECT id, name FROM `groups` ORDER BY name ASC");
require_once 'includes/header.php';

?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Dashboard</h1>
    <div class="btn-toolbar mb-2 mb-md-0">
        <a href="<?= base_url('/quick-access') ?>" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-lightning-charge-fill"></i> Quick Access
        </a>
    </div>
</div>

<!-- Pesan Sukses/Error (jika ada dari redirect) -->
<?php if (isset($_GET['status'])): ?>
<div class="alert alert-<?= $_GET['status'] == 'success' ? 'success' : 'danger' ?> alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($_GET['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<!-- Top Row: High-Level Overview -->
<div class="row mb-4" id="high-level-overview">
    <div class="col-lg-3 col-md-6 mb-3">
        <a href="<?= base_url('/') ?>" class="text-decoration-none">
            <div class="card text-white bg-primary h-100 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="card-title mb-0" id="total-routers-widget">...</h3>
                            <p class="card-text mb-0">Total Routers</p>
                        </div>
                        <i class="bi bi-sign-turn-right fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-lg-3 col-md-6 mb-3">
        <a href="<?= base_url('/services') ?>" class="text-decoration-none">
            <div class="card text-white bg-info h-100 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="card-title mb-0" id="total-services-widget">...</h3>
                            <p class="card-text mb-0">Total Services</p>
                        </div>
                        <i class="bi bi-hdd-stack fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
        </a>
    </div>
     <div class="col-lg-3 col-md-6 mb-3">
        <a href="<?= base_url('/hosts') ?>" class="text-decoration-none">
            <div class="card text-white bg-dark h-100 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="card-title mb-0" id="total-hosts-widget">...</h3>
                            <p class="card-text mb-0">Managed Hosts</p>
                        </div>
                        <i class="bi bi-hdd-network fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-lg-3 col-md-6 mb-3">
        <a href="<?= base_url('/health-status?status=unhealthy') ?>" class="text-decoration-none" id="health-check-card" title="Click to view unhealthy items">
            <div class="card text-white bg-danger h-100 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="card-title mb-0" id="unhealthy-items-widget">...</h3>
                            <p class="card-text mb-0">Unhealthy Items</p>
                        </div>
                        <i class="bi bi-heartbreak-fill fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
        </a>
    </div>
</div>

<style>
    .blinking-badge {
        animation: blinker 1.5s linear infinite;
    }
    @keyframes blinker {
        50% {
            opacity: 0.3;
        }
    }
</style>

<hr>
<h4 class="mb-3">Infrastructure Overview</h4>
<div class="row mb-4">
    <div class="col-lg-3 col-md-6 mb-3" title="Click to manage hosts and containers">
        <a href="<?= base_url('/hosts') ?>" class="text-decoration-none">
            <div class="card text-white bg-dark h-100 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="card-title mb-1">Aggregated Containers</h5>
                            <h3 class="card-title mb-0" id="agg-total-containers-widget">...</h3>
                            <p class="card-text mb-0" id="agg-container-status-widget">
                                <!-- Running / Stopped counts will be injected here -->
                            </p>
                        </div>
                        <i class="bi bi-box-seam fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card h-100 shadow-sm">
            <div class="card-body d-flex justify-content-center align-items-center">
                <canvas id="containerStatusChart" style="max-height: 150px;"></canvas>
            </div>
        </div>
    </div>
    <!-- Swarm Cluster Overview (Conditional) -->
    <div class="col-lg-3 col-md-6 mb-3" id="swarm-overview-section" style="display: none;" title="Click to view node details">
        <a href="<?= base_url('/swarm/details') ?>" class="text-decoration-none">
            <div class="card text-white bg-info h-100 shadow-sm">
                <div class="card-body" id="swarm-status-widget">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="card-title mb-1" id="swarm-status-title">Swarm Cluster</h5>
                            <p class="card-text mb-0">
                                <span id="swarm-total-nodes-widget">...</span> Nodes (<span id="swarm-manager-worker-widget">...</span>)
                            </p>
                        </div>
                        <i class="bi bi-hdd-stack-fill fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
        </a>
    </div>


</div>

<hr>
<h4 class="mb-3">Container Distribution per Host</h4>
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-body" style="height: 30vh; min-height: 250px;">
                <canvas id="containersPerHostChart"></canvas>
            </div>
        </div>
    </div>
</div>


<hr>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Per-Host Details</h4>
    <button class="btn btn-sm btn-outline-secondary" id="refresh-host-stats-btn" title="Refresh host data">
        <i class="bi bi-arrow-clockwise"></i> Refresh All
    </button>
</div>
<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-sm table-hover">
                <thead>
                    <tr>
                        <th class="sortable" data-sort="name">Host</th>
                        <th class="sortable" data-sort="status">Status</th>
                        <th class="sortable" data-sort="running_containers">Containers</th>
                        <th class="sortable" data-sort="cpus">Total CPUs</th>
                        <th class="sortable" data-sort="memory">Total Memory</th>
                        <th class="sortable" data-sort="disk_usage">Disk Usage</th>
                        <th class="sortable" data-sort="uptime_timestamp" data-sort-default="asc">Uptime</th>
                        <th>Docker Version</th>
                        <th>OS</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody id="per-host-stats-container">
                    <tr>
                        <td colspan="10" class="text-center"><div class="spinner-border spinner-border-sm" role="status"><span class="visually-hidden">Loading...</span></div></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<hr>
<div class="row">
    <div class="col-lg-7">
        <h4 class="mb-3">Recent Activity</h4>
        <div class="card">
            <div class="card-body p-0">
                <ul class="list-group list-group-flush" id="recent-activity-container">
                    <li class="list-group-item text-center">
                        <div class="spinner-border spinner-border-sm" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </li>
                </ul>
            </div>
            <div class="card-footer text-center bg-light">
                <a href="<?= base_url('/logs') ?>" class="text-decoration-none small">View All Activity Logs &rarr;</a>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <h4 class="mb-3">System Status</h4>
        <div class="card">
            <div class="card-body p-0">
                <ul class="list-group list-group-flush" id="system-status-container">
                     <li class="list-group-item text-center">
                        <div class="spinner-border spinner-border-sm" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </li>
                </ul>
            </div>
        </div>
        <div class="card-footer text-muted small">
            <a href="<?= base_url('/health-check') ?>" class="text-decoration-none">View Full Health Check &rarr;</a>
        </div>
    </div>
</div>

<?php
$conn->close();
?>
<script>
window.pageInit = function() {
    // Function to update the System Status card
    function updateSystemStatus(systemStatusData) {
        const container = document.getElementById('system-status-container');
        if (!container) return;

        let html = '';
        systemStatusData.forEach(item => {
            const badgeClass = item.status === 'OK' ? 'success' : 'danger';
            html += `
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <span>${item.check}</span>
                    <span class="badge bg-${badgeClass}">${item.message}</span>
                </li>
            `;
        });
        container.innerHTML = html;
    }

    // Function to fetch all dashboard stats
    function fetchDashboardStats() {
        fetch(`${basePath}/api/dashboard-stats`)
            .then(response => response.json())
            .then(result => {
                if (result.status === 'success' && result.data) {
                    // Update high-level overview widgets
                    document.getElementById('total-routers-widget').textContent = result.data.total_routers;
                    document.getElementById('total-services-widget').textContent = result.data.total_services;
                    document.getElementById('total-hosts-widget').textContent = result.data.total_hosts;
                    document.getElementById('unhealthy-items-widget').textContent = result.data.total_unhealthy;

                    // Update aggregated container stats
                    if (result.data.agg_stats) {
                        document.getElementById('agg-total-containers-widget').textContent = result.data.agg_stats.total_containers;
                        document.getElementById('agg-container-status-widget').innerHTML = `${result.data.agg_stats.running_containers} Running / ${result.data.agg_stats.stopped_containers} Stopped`;
                    }

                    // Update Swarm info
                    const swarmOverviewSection = document.getElementById('swarm-overview-section');
                    if (result.data.swarm_info) {
                        swarmOverviewSection.style.display = 'block';
                        document.getElementById('swarm-total-nodes-widget').textContent = result.data.swarm_info.total_nodes;
                        document.getElementById('swarm-manager-worker-widget').textContent = `${result.data.swarm_info.managers} Managers, ${result.data.swarm_info.workers} Workers`;
                    } else {
                        swarmOverviewSection.style.display = 'none';
                    }

                    // Update recent activity (assuming this is handled elsewhere or needs to be added)
                    // ...

                    // Call the new function for system status
                    updateSystemStatus(result.data.system_status);
                }
            })
            .catch(error => {
                console.error('Error fetching dashboard stats:', error);
                document.getElementById('system-status-container').innerHTML = `<li class="list-group-item text-danger">Error loading system status.</li>`;
            });
    }

    // Initial call to fetch data when the page initializes
    fetchDashboardStats();
};
</script>
<?php
require_once 'includes/footer.php';
?>