<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-lightning-charge-fill"></i> Quick Access</h1>
</div>

<div class="row">
    <!-- Traefik Management -->
    <div class="col-md-6 mb-4">
        <div class="card h-100">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-sign-turn-right-fill me-2"></i>Traefik Management</h5>
            </div>
            <div class="list-group list-group-flush">
                <a href="<?= base_url('/routers/new') ?>" class="list-group-item list-group-item-action d-flex align-items-center">
                    <i class="bi bi-plus-circle-fill me-3 fs-4 text-success"></i>
                    <div>
                        <strong>Add New Router</strong>
                        <small class="d-block text-muted">Define a new entry point for your traffic.</small>
                    </div>
                </a>
                <a href="<?= base_url('/services/new') ?>" class="list-group-item list-group-item-action d-flex align-items-center">
                    <i class="bi bi-plus-circle-fill me-3 fs-4 text-success"></i>
                    <div>
                        <strong>Add New Service</strong>
                        <small class="d-block text-muted">Create a new backend service or load balancer.</small>
                    </div>
                </a>
                <a href="<?= base_url('/middlewares') ?>" class="list-group-item list-group-item-action d-flex align-items-center">
                    <i class="bi bi-puzzle-fill me-3 fs-4 text-primary"></i>
                    <div>
                        <strong>Manage Middlewares</strong>
                        <small class="d-block text-muted">View and manage all available middlewares.</small>
                    </div>
                </a>
            </div>
        </div>
    </div>

    <!-- Container Management -->
    <div class="col-md-6 mb-4">
        <div class="card h-100">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-box-seam me-2"></i>Container Management</h5>
            </div>
            <div class="list-group list-group-flush">
                <a href="<?= base_url('/app-launcher') ?>" class="list-group-item list-group-item-action d-flex align-items-center">
                    <i class="bi bi-rocket-launch-fill me-3 fs-4 text-primary"></i>
                    <div>
                        <strong>Launch New App</strong>
                        <small class="d-block text-muted">Deploy a new application from Git or an image.</small>
                    </div>
                </a>
                <a href="<?= base_url('/hosts/new') ?>" class="list-group-item list-group-item-action d-flex align-items-center">
                    <i class="bi bi-hdd-network-fill me-3 fs-4 text-success"></i>
                    <div>
                        <strong>Add New Host</strong>
                        <small class="d-block text-muted">Register a new Docker host to manage.</small>
                    </div>
                </a>
                <a href="<?= base_url('/hosts') ?>" class="list-group-item list-group-item-action d-flex align-items-center">
                    <i class="bi bi-hdd-network me-3 fs-4 text-info"></i>
                    <div>
                        <strong>View All Hosts</strong>
                        <small class="d-block text-muted">See and manage all registered hosts.</small>
                    </div>
                </a>
            </div>
        </div>
    </div>

    <!-- Monitoring -->
    <div class="col-md-6 mb-4">
        <div class="card h-100">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-display-fill me-2"></i>Monitoring</h5>
            </div>
            <div class="list-group list-group-flush">
                <a href="<?= base_url('/health-status') ?>" class="list-group-item list-group-item-action d-flex align-items-center">
                    <i class="bi bi-heart-pulse-fill me-3 fs-4 text-danger"></i>
                    <div>
                        <strong>Service Health</strong>
                        <small class="d-block text-muted">View the health status of all monitored items.</small>
                    </div>
                </a>
                <a href="<?= base_url('/host-overview') ?>" class="list-group-item list-group-item-action d-flex align-items-center">
                    <i class="bi bi-display me-3 fs-4 text-info"></i>
                    <div>
                        <strong>Host Overview</strong>
                        <small class="d-block text-muted">Get a summary of all connected hosts.</small>
                    </div>
                </a>
                <a href="<?= base_url('/resource-hotspots') ?>" class="list-group-item list-group-item-action d-flex align-items-center">
                    <i class="bi bi-fire me-3 fs-4 text-danger"></i>
                    <div>
                        <strong>Resource Hotspots</strong>
                        <small class="d-block text-muted">Identify top CPU and Memory consuming containers.</small>
                    </div>
                </a>
            </div>
        </div>
    </div>

    <!-- System & Tools -->
    <div class="col-md-6 mb-4">
        <div class="card h-100">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-tools me-2"></i>System & Tools</h5>
            </div>
            <div class="list-group list-group-flush">
                <a href="<?= base_url('/central-logs') ?>" class="list-group-item list-group-item-action d-flex align-items-center">
                    <i class="bi bi-journals me-3 fs-4 text-warning"></i>
                    <div>
                        <strong>Centralized Logs</strong>
                        <small class="d-block text-muted">View logs from any container on any host.</small>
                    </div>
                </a>
                <a href="<?= base_url('/settings') ?>" class="list-group-item list-group-item-action d-flex align-items-center">
                    <i class="bi bi-sliders me-3 fs-4 text-secondary"></i>
                    <div>
                        <strong>General Settings</strong>
                        <small class="d-block text-muted">Configure the application and its integrations.</small>
                    </div>
                </a>
                <a href="<?= base_url('/users') ?>" class="list-group-item list-group-item-action d-flex align-items-center">
                    <i class="bi bi-people-fill me-3 fs-4 text-info"></i>
                    <div>
                        <strong>User Management</strong>
                        <small class="d-block text-muted">Add, edit, or remove application users.</small>
                    </div>
                </a>
            </div>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>