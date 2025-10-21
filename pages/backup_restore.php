<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-database-down"></i> Backup & Restore</h1>
</div>

<div class="row">
    <!-- Backup Section -->
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Create Backup</h5>
            </div>
            <div class="card-body">
                <p>Click the button below to download a full backup of your application's configuration data. This includes all routers, services, hosts, users, settings, and more.</p>
                <p>The backup will be a <code>.json</code> file. Keep this file in a safe place.</p>
                <a href="<?= base_url('/api/system/backup') ?>" class="btn btn-primary no-spa" id="create-backup-btn">
                    <i class="bi bi-download"></i> Create & Download Backup
                </a>
            </div>
        </div>
    </div>

    <!-- Restore Section -->
    <div class="col-md-6">
        <div class="card border-danger">
            <div class="card-header bg-danger text-white">
                <h5 class="mb-0">Restore from Backup</h5>
            </div>
            <div class="card-body">
                <div class="alert alert-warning">
                    <strong><i class="bi bi-exclamation-triangle-fill"></i> Warning:</strong> This is a destructive operation. Restoring from a backup will <strong>completely overwrite</strong> all existing configuration data in this application.
                </div>
                <form id="restore-form" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label for="backup-file" class="form-label">Select Backup File (.json)</label>
                        <input class="form-control" type="file" id="backup-file" name="backup_file" accept=".json" required>
                    </div>
                    <button type="submit" class="btn btn-danger w-100" id="restore-btn">
                        <i class="bi bi-upload"></i> Restore Configuration
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
window.pageInit = function() {
    const restoreForm = document.getElementById('restore-form');
    const restoreBtn = document.getElementById('restore-btn');
    const backupFile = document.getElementById('backup-file');

    restoreForm.addEventListener('submit', function(e) {
        e.preventDefault();

        if (!backupFile.files.length) {
            showToast('Please select a backup file.', false);
            return;
        }

        if (!confirm('Are you absolutely sure you want to restore? This will delete all current configurations and replace them with the data from the backup file. This action cannot be undone.')) {
            return;
        }

        const originalBtnContent = restoreBtn.innerHTML;
        restoreBtn.disabled = true;
        restoreBtn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Restoring...`;

        const formData = new FormData();
        formData.append('backup_file', backupFile.files[0]);

        fetch('<?= base_url('/api/system/restore') ?>', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json().then(data => ({ ok: response.ok, data })))
        .then(({ ok, data }) => {
            if (ok) {
                showToast(data.message, true);
                setTimeout(() => {
                    // Redirect to login after successful restore as user data might have changed
                    window.location.href = '<?= base_url('/logout') ?>';
                }, 2000);
            } else {
                throw new Error(data.message || 'An unknown error occurred.');
            }
        })
        .catch(error => {
            showToast('Restore failed: ' + error.message, false);
        })
        .finally(() => {
            restoreBtn.disabled = false;
            restoreBtn.innerHTML = originalBtnContent;
        });
    });
};
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>