<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/header.php';

$php_path = PHP_BINARY;
$collect_stats_path = PROJECT_ROOT . '/collect_stats.php';
$autoscaler_path = PROJECT_ROOT . '/autoscaler.php';
$health_monitor_path = PROJECT_ROOT . '/health_monitor.php';
$system_backup_path = PROJECT_ROOT . '/system_backup.php';
$scheduled_deploy_path = PROJECT_ROOT . '/scheduled_deployment_runner.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-clock-history"></i> Cron Job Management</h1>
</div>

<form id="main-form" action="<?= base_url('/api/cron') ?>" method="POST" data-redirect="/cron-jobs">
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-vcenter">
                    <thead>
                        <tr>
                            <th>Job</th>
                            <th style="width: 30%;">Description</th>
                            <th>Status</th>
                            <th style="min-width: 180px;">Schedule (Cron Format)</th>
                            <th style="min-width: 150px;">Actions</th>
                            <th>Enabled</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Host Stats Collector -->
                        <tr>
                            <td><strong><i class="bi bi-graph-up me-2"></i>Host Stats Collector</strong></td>
                            <td class="text-muted small">Collects CPU and Memory usage from all hosts for dashboard graphs.</td>
                            <td><span id="collect_stats_status_badge" class="badge rounded-pill"></span></td>
                            <td><input type="text" class="form-control form-control-sm" id="collect_stats_schedule" name="collect_stats[schedule]" placeholder="*/5 * * * *"></td>
                            <td>
                                <button type="button" class="btn btn-sm btn-outline-info view-log-btn" data-script="collect_stats" title="View Log"><i class="bi bi-card-text"></i></button>
                                <button type="button" class="btn btn-sm btn-outline-danger clear-log-btn" data-script="collect_stats" title="Clear Log"><i class="bi bi-eraser-fill"></i></button>
                            </td>
                            <td>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="collect_stats_enabled" name="collect_stats[enabled]" value="1">
                                </div>
                            </td>
                        </tr>
                        <!-- Service Autoscaler -->
                        <tr>
                            <td><strong><i class="bi bi-arrows-angle-expand me-2"></i>Service Autoscaler</strong></td>
                            <td class="text-muted small">Checks host CPU usage and scales service replicas up or down based on defined rules.</td>
                            <td><span id="autoscaler_status_badge" class="badge rounded-pill"></span></td>
                            <td><input type="text" class="form-control form-control-sm" id="autoscaler_schedule" name="autoscaler[schedule]" placeholder="*/5 * * * *"></td>
                            <td>
                                <button type="button" class="btn btn-sm btn-outline-info view-log-btn" data-script="autoscaler" title="View Log"><i class="bi bi-card-text"></i></button>
                                <button type="button" class="btn btn-sm btn-outline-danger clear-log-btn" data-script="autoscaler" title="Clear Log"><i class="bi bi-eraser-fill"></i></button>
                            </td>
                            <td>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="autoscaler_enabled" name="autoscaler[enabled]" value="1">
                                </div>
                            </td>
                        </tr>
                        <!-- Service Health Monitor -->
                        <tr>
                            <td><strong><i class="bi bi-heart-pulse me-2"></i>Service Health Monitor</strong></td>
                            <td class="text-muted small">Actively checks service health and performs auto-healing by restarting unresponsive services.</td>
                            <td><span id="health_monitor_status_badge" class="badge rounded-pill"></span></td>
                            <td><input type="text" class="form-control form-control-sm" id="health_monitor_schedule" name="health_monitor[schedule]" placeholder="* * * * *"></td>
                            <td>
                                <button type="button" class="btn btn-sm btn-outline-info view-log-btn" data-script="health_monitor" title="View Log"><i class="bi bi-card-text"></i></button>
                                <button type="button" class="btn btn-sm btn-outline-danger clear-log-btn" data-script="health_monitor" title="Clear Log"><i class="bi bi-eraser-fill"></i></button>
                            </td>
                            <td>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="health_monitor_enabled" name="health_monitor[enabled]" value="1">
                                </div>
                            </td>
                        </tr>
                        <!-- System Cleanup -->
                        <tr>
                            <td><strong><i class="bi bi-trash3 me-2"></i>System Cleanup</strong></td>
                            <td class="text-muted small">Cleans up old data from the database (archived history, activity logs, host stats) to maintain performance.</td>
                            <td><span id="system_cleanup_status_badge" class="badge rounded-pill"></span></td>
                            <td><input type="text" class="form-control form-control-sm" id="system_cleanup_schedule" name="system_cleanup[schedule]" placeholder="0 3 * * *"></td>
                            <td>
                                <button type="button" class="btn btn-sm btn-outline-info view-log-btn" data-script="system_cleanup" title="View Log"><i class="bi bi-card-text"></i></button>
                                <button type="button" class="btn btn-sm btn-outline-danger clear-log-btn" data-script="system_cleanup" title="Clear Log"><i class="bi bi-eraser-fill"></i></button>
                            </td>
                            <td>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="system_cleanup_enabled" name="system_cleanup[enabled]" value="1">
                                </div>
                            </td>
                        </tr>
                        <!-- Automatic Backup -->
                        <tr>
                            <td><strong><i class="bi bi-database-down me-2"></i>Automatic Backup</strong></td>
                            <td class="text-muted small">Creates a full backup of all application configuration data to the path specified in General Settings.</td>
                            <td><span id="system_backup_status_badge" class="badge rounded-pill"></span></td>
                            <td><input type="text" class="form-control form-control-sm" id="system_backup_schedule" name="system_backup[schedule]" placeholder="0 2 * * *"></td>
                            <td>
                                <button type="button" class="btn btn-sm btn-outline-info view-log-btn" data-script="system_backup" title="View Log"><i class="bi bi-card-text"></i></button>
                                <button type="button" class="btn btn-sm btn-outline-danger clear-log-btn" data-script="system_backup" title="Clear Log"><i class="bi bi-eraser-fill"></i></button>
                            </td>
                            <td>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="system_backup_enabled" name="system_backup[enabled]" value="1">
                                </div>
                            </td>
                        </tr>
                        <!-- Scheduled Deployment Runner -->
                        <tr>
                            <td><strong><i class="bi bi-calendar-check me-2"></i>Scheduled Deployment</strong></td>
                            <td class="text-muted small">Checks for stacks with a "Scheduled" policy that have pending updates and are due for deployment.</td>
                            <td><span id="scheduled_deployment_runner_status_badge" class="badge rounded-pill"></span></td>
                            <td><input type="text" class="form-control form-control-sm" id="scheduled_deployment_runner_schedule" name="scheduled_deployment_runner[schedule]" placeholder="* * * * *"></td>
                            <td>
                                <button type="button" class="btn btn-sm btn-outline-info view-log-btn" data-script="scheduled_deployment_runner" title="View Log"><i class="bi bi-card-text"></i></button>
                                <button type="button" class="btn btn-sm btn-outline-danger clear-log-btn" data-script="scheduled_deployment_runner" title="Clear Log"><i class="bi bi-eraser-fill"></i></button>
                            </td>
                            <td>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="scheduled_deployment_runner_enabled" name="scheduled_deployment_runner[enabled]" value="1">
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
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
        const systemBackupBadge = document.getElementById('system_backup_status_badge');
    const scheduledDeployBadge = document.getElementById('scheduled_deployment_runner_status_badge');

        fetch('<?= base_url('/api/cron') ?>')
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                const { collect_stats, autoscaler, health_monitor, system_cleanup, system_backup, scheduled_deployment_runner } = data.jobs;
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
                    if (system_backup) {
                        document.getElementById('system_backup_enabled').checked = system_backup.enabled;
                        document.getElementById('system_backup_schedule').value = system_backup.schedule;
                        if (system_backup.schedule) {
                            systemBackupBadge.textContent = system_backup.enabled ? 'Scheduled' : 'Disabled';
                            systemBackupBadge.className = `badge rounded-pill ms-2 text-bg-${system_backup.enabled ? 'success' : 'secondary'}`;
                        } else {
                            systemBackupBadge.textContent = 'Not Set';
                            systemBackupBadge.className = 'badge rounded-pill ms-2 text-bg-light';
                        }
                    }
                    if (scheduled_deployment_runner) {
                        document.getElementById('scheduled_deployment_runner_enabled').checked = scheduled_deployment_runner.enabled;
                        document.getElementById('scheduled_deployment_runner_schedule').value = scheduled_deployment_runner.schedule;
                        if (scheduled_deployment_runner.schedule) {
                            scheduledDeployBadge.textContent = scheduled_deployment_runner.enabled ? 'Scheduled' : 'Disabled';
                            scheduledDeployBadge.className = `badge rounded-pill ms-2 text-bg-${scheduled_deployment_runner.enabled ? 'success' : 'secondary'}`;
                        } else {
                            scheduledDeployBadge.textContent = 'Not Set';
                            scheduledDeployBadge.className = 'badge rounded-pill ms-2 text-bg-light';
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
    ['collect_stats', 'autoscaler', 'health_monitor', 'system_cleanup', 'system_backup', 'scheduled_deployment_runner'].forEach(scriptKey => {
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