<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/header.php';

$php_path = PHP_BINARY;
$collect_stats_path = PROJECT_ROOT . '/collect_stats.php';
$autoscaler_path = PROJECT_ROOT . '/autoscaler.php';
$health_monitor_path = PROJECT_ROOT . '/health_monitor.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-clock-history"></i> Cron Job Management</h1>
</div>

<form id="main-form" action="<?= base_url('/api/cron') ?>" method="POST" data-redirect="/cron-jobs">
    <div class="card mb-4">
        <div class="card-header">
            <h5><i class="bi bi-graph-up"></i> Host Stats Collector</h5>
        </div>
        <div class="card-body">
            <p class="text-muted">Skrip ini mengumpulkan statistik penggunaan CPU dan Memori dari semua host Docker Anda secara periodik. Data ini digunakan untuk menampilkan grafik di dashboard.</p>
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" role="switch" id="collect_stats_enabled" name="collect_stats[enabled]" value="1">
                <label class="form-check-label" for="collect_stats_enabled">
                    Enable Stats Collector
                    <span id="collect_stats_status_badge" class="badge rounded-pill ms-2"></span>
                </label>
            </div>
            <div class="mb-3">
                <label for="collect_stats_schedule" class="form-label">Schedule (Format Cron)</label>
                <input type="text" class="form-control" id="collect_stats_schedule" name="collect_stats[schedule]" placeholder="*/5 * * * *">
                <small class="form-text text-muted">Contoh: <code>*/5 * * * *</code> untuk berjalan setiap 5 menit. <code>0 * * * *</code> untuk berjalan setiap jam.</small>
            </div>
            <div class="bg-light p-2 rounded">
                <small class="font-monospace text-muted">Perintah yang akan dijalankan: <code><?= htmlspecialchars($collect_stats_path) ?></code></small>
            </div>
            <div class="mt-2">
                <button type="button" class="btn btn-sm btn-outline-info view-log-btn" data-script="collect_stats"><i class="bi bi-card-text"></i> View Log</button>
                <button type="button" class="btn btn-sm btn-outline-danger clear-log-btn" data-script="collect_stats"><i class="bi bi-eraser-fill"></i> Clear Log</button>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header">
            <h5><i class="bi bi-arrows-angle-expand"></i> Service Autoscaler</h5>
        </div>
        <div class="card-body">
            <p class="text-muted">Skrip ini memeriksa penggunaan CPU host dan secara otomatis menambah atau mengurangi jumlah replika service pada host Docker sesuai aturan yang Anda tetapkan.</p>
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" role="switch" id="autoscaler_enabled" name="autoscaler[enabled]" value="1">
                <label class="form-check-label" for="autoscaler_enabled">
                    Enable Autoscaler
                    <span id="autoscaler_status_badge" class="badge rounded-pill ms-2"></span>
                </label>
            </div>
            <div class="mb-3">
                <label for="autoscaler_schedule" class="form-label">Schedule (Format Cron)</label>
                <input type="text" class="form-control" id="autoscaler_schedule" name="autoscaler[schedule]" placeholder="*/5 * * * *">
                <small class="form-text text-muted">Direkomendasikan untuk berjalan setiap 5 menit.</small>
            </div>
            <div class="bg-light p-2 rounded">
                <small class="font-monospace text-muted">Perintah yang akan dijalankan: <code><?= htmlspecialchars($autoscaler_path) ?></code></small>
            </div>
            <div class="mt-2">
                <button type="button" class="btn btn-sm btn-outline-info view-log-btn" data-script="autoscaler"><i class="bi bi-card-text"></i> View Log</button>
                <button type="button" class="btn btn-sm btn-outline-danger clear-log-btn" data-script="autoscaler"><i class="bi bi-eraser-fill"></i> Clear Log</button>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h5><i class="bi bi-heart-pulse"></i> Service Health Monitor</h5>
        </div>
        <div class="card-body">
            <p class="text-muted">Skrip ini secara aktif memeriksa kesehatan layanan Anda dan secara otomatis me-restart layanan yang tidak responsif (auto-healing).</p>
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" role="switch" id="health_monitor_enabled" name="health_monitor[enabled]" value="1">
                <label class="form-check-label" for="health_monitor_enabled">
                    Enable Health Monitor
                    <span id="health_monitor_status_badge" class="badge rounded-pill ms-2"></span>
                </label>
            </div>
            <div class="mb-3">
                <label for="health_monitor_schedule" class="form-label">Schedule (Format Cron)</label>
                <input type="text" class="form-control" id="health_monitor_schedule" name="health_monitor[schedule]" placeholder="* * * * *">
                <small class="form-text text-muted">Direkomendasikan untuk berjalan setiap 1 menit.</small>
            </div>
            <div class="bg-light p-2 rounded">
                <small class="font-monospace text-muted">Perintah yang akan dijalankan: <code><?= htmlspecialchars($health_monitor_path) ?></code></small>
            </div>
            <div class="mt-2">
                <button type="button" class="btn btn-sm btn-outline-info view-log-btn" data-script="health_monitor"><i class="bi bi-card-text"></i> View Log</button>
                <button type="button" class="btn btn-sm btn-outline-danger clear-log-btn" data-script="health_monitor"><i class="bi bi-eraser-fill"></i> Clear Log</button>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header">
            <h5><i class="bi bi-trash3"></i> System Cleanup</h5>
        </div>
        <div class="card-body">
            <p class="text-muted">Skrip ini secara otomatis membersihkan data lama dari database untuk menjaga performa, seperti riwayat konfigurasi yang diarsipkan, log aktivitas, dan riwayat statistik host.</p>
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" role="switch" id="system_cleanup_enabled" name="system_cleanup[enabled]" value="1">
                <label class="form-check-label" for="system_cleanup_enabled">
                    Enable System Cleanup
                    <span id="system_cleanup_status_badge" class="badge rounded-pill ms-2"></span>
                </label>
            </div>
            <div class="mb-3">
                <label for="system_cleanup_schedule" class="form-label">Schedule (Format Cron)</label>
                <input type="text" class="form-control" id="system_cleanup_schedule" name="system_cleanup[schedule]" placeholder="0 3 * * *">
                <small class="form-text text-muted">Direkomendasikan untuk berjalan sekali sehari, misalnya pada jam 3 pagi (<code>0 3 * * *</code>).</small>
            </div>
            <div class="bg-light p-2 rounded">
                <small class="font-monospace text-muted">Perintah yang akan dijalankan: <code><?= htmlspecialchars(PROJECT_ROOT . '/system_cleanup.php') ?></code></small>
            </div>
            <div class="mt-2">
                <button type="button" class="btn btn-sm btn-outline-info view-log-btn" data-script="system_cleanup"><i class="bi bi-card-text"></i> View Log</button>
                <button type="button" class="btn btn-sm btn-outline-danger clear-log-btn" data-script="system_cleanup"><i class="bi bi-eraser-fill"></i> Clear Log</button>
            </div>
        </div>
    </div>

    <div class="mt-4">
        <button type="submit" class="btn btn-primary" id="save-cron-btn">Save Crontab</button>
    </div>
</form>

<script>
window.pageInit = function() {
    const cronLogModal = new bootstrap.Modal(document.getElementById('cronLogModal'));

    function loadCronJobs() {
        const collectStatsBadge = document.getElementById('collect_stats_status_badge');
        const autoscalerBadge = document.getElementById('autoscaler_status_badge');
        const healthMonitorBadge = document.getElementById('health_monitor_status_badge');
        const systemCleanupBadge = document.getElementById('system_cleanup_status_badge');

        fetch('<?= base_url('/api/cron') ?>')
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    const { collect_stats, autoscaler, health_monitor, system_cleanup } = data.jobs;
                    if (collect_stats) {
                        document.getElementById('collect_stats_enabled').checked = collect_stats.enabled;
                        document.getElementById('collect_stats_schedule').value = collect_stats.schedule;
                        if (collect_stats.schedule) {
                            collectStatsBadge.textContent = collect_stats.enabled ? 'Scheduled' : 'Disabled';
                            collectStatsBadge.className = `badge rounded-pill ms-2 text-bg-${collect_stats.enabled ? 'success' : 'secondary'}`;
                        } else {
                            collectStatsBadge.textContent = 'Not Set';
                            collectStatsBadge.className = 'badge rounded-pill ms-2 text-bg-light';
                        }
                    }
                    if (autoscaler) {
                        document.getElementById('autoscaler_enabled').checked = autoscaler.enabled;
                        document.getElementById('autoscaler_schedule').value = autoscaler.schedule;
                        if (autoscaler.schedule) {
                            autoscalerBadge.textContent = autoscaler.enabled ? 'Scheduled' : 'Disabled';
                            autoscalerBadge.className = `badge rounded-pill ms-2 text-bg-${autoscaler.enabled ? 'success' : 'secondary'}`;
                        } else {
                            autoscalerBadge.textContent = 'Not Set';
                            autoscalerBadge.className = 'badge rounded-pill ms-2 text-bg-light';
                        }
                    }
                    if (health_monitor) {
                        document.getElementById('health_monitor_enabled').checked = health_monitor.enabled;
                        document.getElementById('health_monitor_schedule').value = health_monitor.schedule;
                        if (health_monitor.schedule) {
                            healthMonitorBadge.textContent = health_monitor.enabled ? 'Scheduled' : 'Disabled';
                            healthMonitorBadge.className = `badge rounded-pill ms-2 text-bg-${health_monitor.enabled ? 'success' : 'secondary'}`;
                        } else {
                            healthMonitorBadge.textContent = 'Not Set';
                            healthMonitorBadge.className = 'badge rounded-pill ms-2 text-bg-light';
                        }
                    }
                    if (system_cleanup) {
                        document.getElementById('system_cleanup_enabled').checked = system_cleanup.enabled;
                        document.getElementById('system_cleanup_schedule').value = system_cleanup.schedule;
                        if (system_cleanup.schedule) {
                            systemCleanupBadge.textContent = system_cleanup.enabled ? 'Scheduled' : 'Disabled';
                            systemCleanupBadge.className = `badge rounded-pill ms-2 text-bg-${system_cleanup.enabled ? 'success' : 'secondary'}`;
                        } else {
                            systemCleanupBadge.textContent = 'Not Set';
                            systemCleanupBadge.className = 'badge rounded-pill ms-2 text-bg-light';
                        }
                    }
                } else {
                    showToast(data.message, false);
                }
            })
            .catch(error => showToast('Failed to load cron jobs: ' + error.message, false));
    }

    document.querySelectorAll('.view-log-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const scriptName = this.dataset.script;
            const modalTitle = document.getElementById('cronLogModalLabel');
            const modalBody = document.getElementById('cron-log-content');

            modalTitle.textContent = `Log for: ${scriptName}.php`;
            modalBody.textContent = 'Loading log...';
            cronLogModal.show();

            fetch(`<?= base_url('/api/cron/log') ?>?script=${scriptName}`)
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        modalBody.textContent = data.log_content || 'Log file is empty or does not exist.';
                    } else {
                        throw new Error(data.message);
                    }
                })
                .catch(error => modalBody.textContent = 'Error loading log: ' + error.message);
        });
    });

    document.querySelectorAll('.clear-log-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const scriptName = this.dataset.script;
            if (!confirm(`Are you sure you want to clear the log file for '${scriptName}.php'? This action cannot be undone.`)) {
                return;
            }

            const originalBtnText = this.innerHTML;
            this.disabled = true;
            this.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Clearing...`;

            const formData = new FormData();
            formData.append('action', 'clear');
            formData.append('script', scriptName);

            fetch('<?= base_url('/api/cron/log') ?>', { method: 'POST', body: formData })
                .then(response => response.json().then(data => ({ ok: response.ok, data })))
                .then(({ ok, data }) => showToast(data.message, ok))
                .catch(error => showToast('An error occurred: ' + error.message, false))
                .finally(() => {
                    this.disabled = false;
                    this.innerHTML = originalBtnText;
                });
        });
    });

    // Add event listeners to the enable/disable switches
    ['collect_stats', 'autoscaler', 'health_monitor', 'system_cleanup'].forEach(scriptKey => {
        const enableSwitch = document.getElementById(`${scriptKey}_enabled`);
        const scheduleInput = document.getElementById(`${scriptKey}_schedule`);
        if (enableSwitch && scheduleInput) {
            enableSwitch.addEventListener('change', function() {
                scheduleInput.value = scheduleInput.value.replace(/^#\s*/, '');
            });
        }
    });

    loadCronJobs();
};

</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>