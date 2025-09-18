<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$cleanup_days = get_setting('history_cleanup_days', 30);
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Cleanup Deployment History</h1>
    <a href="<?= base_url('/history') ?>" class="btn btn-sm btn-outline-secondary">Back to Deployment History</a>
</div>

<div class="card">
    <div class="card-body">
        <h5 class="card-title">Delete Old Archived Records</h5>
        <p class="card-text">This action will permanently delete all deployment history records that have a status of <strong>'archived'</strong> and are older than <strong><?= $cleanup_days ?> days</strong>.</p>
        <p class="text-danger"><strong>Warning:</strong> This action cannot be undone.</p>
        <button id="cleanup-btn" class="btn btn-danger">
            <i class="bi bi-trash"></i> Cleanup Now
        </button>
    </div>
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>