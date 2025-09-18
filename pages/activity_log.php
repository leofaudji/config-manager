<?php
// Router sudah menangani otentikasi dan otorisasi.
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Activity Log</h1>
    <div class="btn-toolbar mb-2 mb-md-0">
        <form class="search-form me-2" data-type="activity_log">
            <div class="input-group input-group-sm">
                <input type="text" name="search_activity_log" class="form-control" placeholder="Cari berdasarkan user atau aksi...">
                <button class="btn btn-outline-secondary" type="submit" title="Search"><i class="bi bi-search"></i></button>
                <button class="btn btn-outline-secondary reset-search-btn" type="button" title="Reset"><i class="bi bi-x-lg"></i></button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-sm table-hover">
                <thead>
                    <tr>
                        <th>Timestamp</th>
                        <th>Username</th>
                        <th>Action</th>
                        <th>Details</th>
                        <th>IP Address</th>
                    </tr>
                </thead>
                <tbody id="activity_log-container">
                    <!-- Data will be loaded by AJAX -->
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer d-flex justify-content-between align-items-center">
        <div class="text-muted small" id="activity_log-info"></div>
        <div class="d-flex align-items-center">
            <nav id="activity_log-pagination"></nav>
            <div class="ms-3">
                <select name="limit_activity_log" class="form-select form-select-sm limit-selector" data-type="activity_log" style="width: auto;">
                    <option value="50">50</option>
                    <option value="100">100</option>
                    <option value="250">250</option>
                </select>
            </div>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>