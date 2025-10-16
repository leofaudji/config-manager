<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-hdd-network-fill"></i> Hosts</h1>
    <div class="btn-toolbar mb-2 mb-md-0">
        <a href="<?= base_url('/hosts/new') ?>" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-plus-circle"></i> Add New Host
        </a>
    </div>
</div>

<div class="mb-3">
    <small class="text-muted">Group by:</small>
    <div class="btn-group" role="group" aria-label="Host Groups" id="host-group-filter-container">
        <input type="radio" class="btn-check" name="host-group-filter" id="group-all" value="" autocomplete="off" checked>
        <label class="btn btn-sm btn-outline-secondary" for="group-all">All</label>

        <input type="radio" class="btn-check" name="host-group-filter" id="group-standalone" value="standalone" autocomplete="off">
        <label class="btn btn-sm btn-outline-secondary" for="group-standalone">Standalone</label>

        <input type="radio" class="btn-check" name="host-group-filter" id="group-manager" value="manager" autocomplete="off">
        <label class="btn btn-sm btn-outline-secondary" for="group-manager">Swarm Managers</label>

        <input type="radio" class="btn-check" name="host-group-filter" id="group-worker" value="worker" autocomplete="off">
        <label class="btn btn-sm btn-outline-secondary" for="group-worker">Swarm Workers</label>

        <input type="radio" class="btn-check" name="host-group-filter" id="group-registry" value="registry" autocomplete="off">
        <label class="btn btn-sm btn-outline-secondary" for="group-registry">Local Registries</label>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-sm table-hover">
                <thead>
                    <tr>
                        <th class="sortable asc" data-sort="name">Name</th>
                        <th class="sortable" data-sort="status">Status</th>
                        <th class="sortable" data-sort="uptime">Host Uptime</th>
                        <th>Docker API URL</th>
                        <th>Registry URL</th>
                        <th class="sortable" data-sort="agent_status">Agent Status</th>
                        <th>Last Updated</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody id="hosts-container">
                    <!-- Data will be loaded by AJAX -->
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer d-flex justify-content-between align-items-center">
        <div class="text-muted small" id="hosts-info"></div>
        <div class="d-flex align-items-center">
            <nav id="hosts-pagination"></nav>
            <div class="ms-3">
                <select name="limit_hosts" class="form-select form-select-sm limit-selector" data-type="hosts" style="width: auto;">
                    <option value="10">10</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </div>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>