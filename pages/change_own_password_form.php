<?php
require_once __DIR__ . '/../includes/bootstrap.php';
// Sesi dan otentikasi/otorisasi sudah ditangani oleh Router.
require_once __DIR__ . '/../includes/header.php';
?>

<h3>Change My Password</h3>
<hr>

<div class="card">
    <div class="card-body">
        <form id="main-form" action="<?= base_url('/my-profile/change-password') ?>" method="POST" data-redirect="/">
            <div class="mb-3">
                <label for="current_password" class="form-label">Current Password</label>
                <input type="password" class="form-control" id="current_password" name="current_password" required>
            </div>
            <div class="mb-3">
                <label for="new_password" class="form-label">New Password</label>
                <input type="password" class="form-control" id="new_password" name="new_password" required>
            </div>
            <div class="mb-3">
                <label for="confirm_password" class="form-label">Confirm New Password</label>
                <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
            </div>
            <a href="<?= base_url('/') ?>" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">Update Password</button>
        </form>
    </div>
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>