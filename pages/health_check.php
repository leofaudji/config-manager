<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">System Health Check</h1>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        System Status
        <button id="rerun-checks-btn" class="btn btn-sm btn-outline-primary"><i class="bi bi-arrow-clockwise"></i> Rerun Checks</button>
    </div>
    <div class="card-body">
        <p>This page performs a series of checks to ensure the application and its dependencies are configured correctly.</p>
        <ul class="list-group" id="health-check-results">
            <li class="list-group-item text-center">
                <div class="spinner-border" role="status">
                    <span class="visually-hidden">Running checks...</span>
                </div>
                <p class="mt-2 mb-0">Running checks...</p>
            </li>
        </ul>
    </div>
    <div class="card-footer text-muted small">
        Last checked: <span id="last-checked-timestamp">N/A</span>
    </div>
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>