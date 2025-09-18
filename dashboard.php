<?php
// File ini adalah view untuk dashboard utama.
// Sesi dan otentikasi sudah diperiksa oleh Router.
require_once 'includes/bootstrap.php';
$conn = Database::getInstance()->getConnection();
// Ambil daftar grup untuk modal "Move to Group"
$groups_result = $conn->query("SELECT id, name FROM `groups` ORDER BY name ASC");
require_once 'includes/header.php';

?>

<!-- Pesan Sukses/Error (jika ada dari redirect) -->
<?php if (isset($_GET['status'])): ?>
<div class="alert alert-<?= $_GET['status'] == 'success' ? 'success' : 'danger' ?> alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($_GET['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<!-- Summary Widgets -->
<div class="row mb-4">
    <div class="col-lg-3 col-md-6 mb-3">
        <a href="<?= base_url('/') ?>" class="text-decoration-none">
            <div class="card text-white bg-primary h-100">
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
            <div class="card text-white bg-info h-100">
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
        <a href="<?= base_url('/middlewares') ?>" class="text-decoration-none">
            <div class="card text-white bg-secondary h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="card-title mb-0" id="total-middlewares-widget">...</h3>
                            <p class="card-text mb-0">Total Middlewares</p>
                        </div>
                        <i class="bi bi-puzzle fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-lg-3 col-md-6 mb-3">
        <a href="<?= base_url('/hosts') ?>" class="text-decoration-none">
            <div class="card text-white bg-dark h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="card-title mb-0" id="total-hosts-widget">...</h3>
                            <p class="card-text mb-0">Total Hosts</p>
                        </div>
                        <i class="bi bi-hdd-network fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
        </a>
    </div>
</div>

<!-- Row 2 -->
<div class="row mb-4">
    <div class="col-lg-3 col-md-6 mb-3">
        <a href="<?= base_url('/users') ?>" class="text-decoration-none">
            <div class="card text-white bg-warning h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="card-title mb-0" id="total-users-widget">...</h3>
                            <p class="card-text mb-0">Total Users</p>
                        </div>
                        <i class="bi bi-people-fill fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-lg-3 col-md-6 mb-3">
        <a href="<?= base_url('/health-check') ?>" class="text-decoration-none">
            <div class="card text-white bg-danger h-100" id="health-check-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="card-title mb-0" id="health-check-widget">...</h3>
                            <p class="card-text mb-0">Health Check</p>
                        </div>
                        <i class="bi bi-heart-pulse-fill fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
        </a>
    </div>
</div>

<hr>
<h4 class="mb-3">All Hosts - Aggregated Stats</h4>
<div class="row mb-4">
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card text-white bg-primary h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h3 class="card-title mb-0" id="agg-total-containers-widget">...</h3>
                        <p class="card-text mb-0">Total Containers</p>
                    </div>
                    <i class="bi bi-box-seam fs-1 opacity-50"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card text-white bg-success h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h3 class="card-title mb-0" id="agg-running-containers-widget">...</h3>
                        <p class="card-text mb-0">Running Containers</p>
                    </div>
                    <i class="bi bi-play-circle fs-1 opacity-50"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card text-white bg-danger h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h3 class="card-title mb-0" id="agg-stopped-containers-widget">...</h3>
                        <p class="card-text mb-0">Stopped Containers</p>
                    </div>
                    <i class="bi bi-stop-circle fs-1 opacity-50"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card text-white bg-secondary h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h3 class="card-title mb-0" id="agg-reachable-hosts-widget">...</h3>
                        <p class="card-text mb-0">Reachable Hosts</p>
                    </div>
                    <i class="bi bi-hdd-network-fill fs-1 opacity-50"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<hr>
<h4 class="mb-3">Per-Host Details</h4>
<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-sm table-hover">
                <thead>
                    <tr>
                        <th>Host</th>
                        <th>Status</th>
                        <th>Containers</th>
                        <th>Total CPUs</th>
                        <th>Total Memory</th>
                        <th>Docker Version</th>
                        <th>OS</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody id="per-host-stats-container">
                    <tr>
                        <td colspan="8" class="text-center"><div class="spinner-border spinner-border-sm" role="status"><span class="visually-hidden">Loading...</span></div></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
$conn->close();
?>
<?php
require_once 'includes/footer.php';
?>