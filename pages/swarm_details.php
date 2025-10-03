<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-hdd-stack-fill"></i> Swarm Cluster Details</h1>
    <div class="btn-toolbar mb-2 mb-md-0">
        <a href="<?= base_url('/') ?>" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back to Dashboard
        </a>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        Node List
        <button class="btn btn-sm btn-outline-secondary" id="refresh-swarm-nodes-btn" title="Refresh node data">
            <i class="bi bi-arrow-clockwise"></i> Refresh
        </button>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-sm table-hover">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Hostname</th>
                        <th>Role</th>
                        <th>Availability</th>
                        <th>Status</th>
                        <th>Address</th>
                        <th>Docker Version</th>
                    </tr>
                </thead>
                <tbody id="swarm-nodes-container">
                    <tr>
                        <td colspan="7" class="text-center">
                            <div class="spinner-border spinner-border-sm" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                            <span class="ms-2">Loading Swarm nodes...</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>