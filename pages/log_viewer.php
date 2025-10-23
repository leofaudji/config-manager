<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/header.php';

$cron_scripts = ['system_cleanup', 'health_monitor', 'collect_stats', 'autoscaler', 'system_backup', 'scheduled_deployment_runner'];
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-journals"></i> Log Viewer</h1>
    <div class="btn-toolbar mb-2 mb-md-0">
        <div class="form-check form-switch me-3" data-bs-toggle="tooltip" title="Automatically refresh logs every 15 seconds">
            <input class="form-check-input" type="checkbox" role="switch" id="auto-refresh-switch">
            <label class="form-check-label" for="auto-refresh-switch">Auto Refresh</label>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <ul class="nav nav-tabs card-header-tabs" id="logTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="activity-log-tab" data-bs-toggle="tab" data-bs-target="#activity-log-pane" type="button" role="tab" aria-controls="activity-log-pane" aria-selected="true">
                    <i class="bi bi-person-lines-fill"></i> Activity Log
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="agent-log-tab" data-bs-toggle="tab" data-bs-target="#agent-log-pane" type="button" role="tab" aria-controls="agent-log-pane" aria-selected="false">
                    <i class="bi bi-heart-pulse"></i> Health Agent Logs
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="system-log-tab" data-bs-toggle="tab" data-bs-target="#system-log-pane" type="button" role="tab" aria-controls="system-log-pane" aria-selected="false">
                    <i class="bi bi-robot"></i> System Logs
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="cron-log-tab" data-bs-toggle="tab" data-bs-target="#cron-log-pane" type="button" role="tab" aria-controls="cron-log-pane" aria-selected="false">
                    <i class="bi bi-clock-history"></i> Cron Job Logs
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="auto-deploy-log-tab" data-bs-toggle="tab" data-bs-target="#auto-deploy-log-pane" type="button" role="tab" aria-controls="auto-deploy-log-pane" aria-selected="false">Auto-Deploy Logs</button>
            </li>
        </ul>
    </div>
    <div class="card-body">
        <div class="tab-content" id="logTabsContent">
            <!-- Activity Log Pane -->
            <div class="tab-pane fade show active" id="activity-log-pane" role="tabpanel" aria-labelledby="activity-log-tab" tabindex="0">
                <div class="table-responsive" style="max-height: 65vh;">
                    <table class="table table-striped table-sm table-hover">
                        <thead>
                            <tr>
                                <th>Timestamp</th>
                                <th>User</th>
                                <th>Action</th>
                                <th>Details</th>
                                <th>IP Address</th>
                            </tr>
                        </thead>
                        <tbody id="activity-log-container">
                            <!-- Data will be loaded by AJAX -->
                        </tbody>
                    </table>
                </div>
                <div class="d-flex justify-content-between align-items-center mt-3">
                    <div class="text-muted small" id="activity-log-info"></div>
                    <div class="d-flex align-items-center">
                        <a href="<?= base_url('/api/logs/view?type=activity&download=true') ?>" class="btn btn-sm btn-outline-secondary me-2 no-spa download-log-btn" download="activity_log.txt"><i class="bi bi-download"></i> Download Full Log</a>
                        <nav id="activity-log-pagination"></nav>
                    </div>
                </div>
            </div>

            <!-- Agent Log Pane -->
            <div class="tab-pane fade" id="agent-log-pane" role="tabpanel" aria-labelledby="agent-log-tab" tabindex="0">
                <div class="table-responsive" style="max-height: 65vh;">
                    <table class="table table-striped table-sm table-hover">
                        <thead>
                            <tr>
                                <th>Timestamp</th>
                                <th>Host</th>
                                <th>Log Message</th>
                            </tr>
                        </thead>
                        <tbody id="agent-log-container">
                            <!-- Data will be loaded by AJAX -->
                        </tbody>
                    </table>
                </div>
                <div class="d-flex justify-content-between align-items-center mt-3">
                    <div class="text-muted small" id="agent-log-info"></div>
                    <div class="d-flex align-items-center">
                        <a href="<?= base_url('/api/logs/view?type=agent&download=true') ?>" class="btn btn-sm btn-outline-secondary me-2 no-spa download-log-btn" download="agent_log.txt"><i class="bi bi-download"></i> Download Full Log</a>
                        <nav id="agent-log-pagination"></nav>
                    </div>
                </div>
            </div>

            <!-- System Log Pane -->
            <div class="tab-pane fade" id="system-log-pane" role="tabpanel" aria-labelledby="system-log-tab" tabindex="0">
                <div class="table-responsive" style="max-height: 65vh;">
                    <table class="table table-striped table-sm table-hover">
                        <thead>
                            <tr>
                                <th>Timestamp</th>
                                <th>Action</th>
                                <th>Details</th>
                                <th>Associated Host</th>
                            </tr>
                        </thead>
                        <tbody id="system-log-container">
                            <!-- Data will be loaded by AJAX -->
                        </tbody>
                    </table>
                </div>
                <div class="d-flex justify-content-between align-items-center mt-3">
                    <div class="text-muted small" id="system-log-info"></div>
                    <div class="d-flex align-items-center">
                        <a href="<?= base_url('/api/logs/view?type=system&download=true') ?>" class="btn btn-sm btn-outline-secondary me-2 no-spa download-log-btn" download="system_log.txt"><i class="bi bi-download"></i> Download Full Log</a>
                        <nav id="system-log-pagination"></nav>
                    </div>
                </div>
            </div>

            <!-- Cron Job Log Pane -->
            <div class="tab-pane fade" id="cron-log-pane" role="tabpanel" aria-labelledby="cron-log-tab" tabindex="0">
                <div class="row">
                    <div class="col-md-4">
                        <div class="list-group">
                            <?php foreach ($cron_scripts as $script): ?>
                                <a href="javascript:void(0);" class="list-group-item list-group-item-action cron-log-select" data-script="<?= $script ?>">
                                    <?= ucwords(str_replace('_', ' ', $script)) ?>.log
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="col-md-8">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h5 id="cron-log-title" class="mb-0">Select a log file to view</h5>
                            <a href="javascript:void(0);" id="cron-log-download-btn" class="btn btn-sm btn-outline-secondary no-spa download-log-btn" style="display: none;" download><i class="bi bi-download"></i> Download Log</a>
                        </div>
                        <pre id="cron-log-content" class="bg-dark text-light p-3 rounded" style="white-space: pre-wrap; word-break: break-all; min-height: 400px; max-height: 65vh; overflow-y: auto;"></pre>
                    </div>
                </div> 
            </div>

        <!-- Auto-Deploy Log Pane -->
            <div class="tab-pane fade" id="auto-deploy-log-pane" role="tabpanel" aria-labelledby="auto-deploy-log-tab">
                <div class="card mt-3">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Auto-Deploy Logs</h5>
                        <div>
                            <button id="refresh-auto-deploy-log-btn" class="btn btn-sm btn-outline-primary"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
                            <a href="<?= base_url('/api/logs/view?type=auto_deploy&download=true') ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-download"></i> Download</a>
                        </div>
                    </div>
                    <div class="card-body">
                        <pre id="auto-deploy-log-content" class="bg-dark text-light p-3 rounded" style="min-height: 50vh; max-height: 70vh; overflow-y: auto; white-space: pre-wrap; word-break: break-all;">Loading logs...</pre>
                    </div>
                </div>
            </div>  

        </div>
    </div>
</div>

<script>
window.pageInit = function() {
    const logTabs = document.getElementById('logTabs');
    let currentActivityPage = 1;
    let currentAgentPage = 1;
    let currentSystemPage = 1;
    const autoRefreshSwitch = document.getElementById('auto-refresh-switch');

    function loadActivityLogs(page = 1) {
        currentActivityPage = page;
        const url = `<?= base_url('/api/logs/view?type=activity&page=') ?>${page}`;
        fetch(url)
            .then(response => response.json())
            .then(data => {
                document.getElementById('activity-log-container').innerHTML = data.html;
                document.getElementById('activity-log-info').innerHTML = data.info;
                renderPagination('activity-log-pagination', data.total_pages, data.current_page, loadActivityLogs);
            })
            .catch(error => console.error('Error loading activity logs:', error));
    }

    function loadAgentLogs(page = 1) {
        currentAgentPage = page;
        const url = `<?= base_url('/api/logs/view?type=agent&page=') ?>${page}`;
        fetch(url)
            .then(response => response.json())
            .then(data => {
                document.getElementById('agent-log-container').innerHTML = data.html;
                document.getElementById('agent-log-info').innerHTML = data.info;
                renderPagination('agent-log-pagination', data.total_pages, data.current_page, loadAgentLogs);
            })
            .catch(error => console.error('Error loading agent logs:', error));
    }

    function loadSystemLogs(page = 1) {
        currentSystemPage = page;
        const url = `<?= base_url('/api/logs/view?type=system&page=') ?>${page}`;
        fetch(url)
            .then(response => response.json())
            .then(data => {
                document.getElementById('system-log-container').innerHTML = data.html;
                document.getElementById('system-log-info').innerHTML = data.info;
                renderPagination('system-log-pagination', data.total_pages, data.current_page, loadSystemLogs);
            })
            .catch(error => console.error('Error loading system logs:', error));
    }

    function loadCronLog(scriptName) {
        const contentEl = document.getElementById('cron-log-content');
        const titleEl = document.getElementById('cron-log-title');
        const downloadBtn = document.getElementById('cron-log-download-btn');
        contentEl.textContent = 'Loading log...';
        titleEl.textContent = `Log for: ${scriptName}.log`;
        downloadBtn.style.display = 'none';

        // Set up the download button
        downloadBtn.href = `<?= base_url('/api/logs/view?type=cron&script=') ?>${scriptName}&download=true`;
        downloadBtn.download = `${scriptName}.log`;
        
        fetch(`<?= base_url('/api/logs/view?type=cron&script=') ?>${scriptName}`)
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    contentEl.textContent = data.log_content || 'Log file is empty or does not exist.';
                    downloadBtn.style.display = 'inline-block';
                } else {
                    throw new Error(data.message);
                }
            })
            .catch(error => contentEl.textContent = 'Error loading log: ' + error.message);
    }

    // --- IDE: Refactored Auto-Refresh Logic ---
    function handleAutoRefresh() {
        if (window.currentPageInterval) {
            clearInterval(window.currentPageInterval);
            window.currentPageInterval = null;
        }
        if (autoRefreshSwitch.checked) {
            const activeTabLoader = getActiveTabLoader();
            if (activeTabLoader) {
                window.currentPageInterval = setInterval(activeTabLoader, 15000); // Refresh every 15 seconds
            }
        }
    }

    autoRefreshSwitch.addEventListener('change', function() {
        localStorage.setItem('log_viewer_auto_refresh', this.checked);
        if (this.checked) {
            showToast('Auto-refresh enabled (15s).', true);
        } else {
            showToast('Auto-refresh disabled.', true);
        }
        // Apply the new state (start or stop the interval)
        handleAutoRefresh();
    });

    function getActiveTabLoader() {
        const activeTab = logTabs.querySelector('.nav-link.active');
        if (!activeTab) return null;

        switch (activeTab.id) {
            case 'activity-log-tab': return () => loadActivityLogs(currentActivityPage);
            case 'agent-log-tab': return () => loadAgentLogs(currentAgentPage);
            case 'system-log-tab': return () => loadSystemLogs(currentSystemPage);
            case 'cron-log-tab':
                const activeCronScript = document.querySelector('.cron-log-select.active');
                return activeCronScript ? () => loadCronLog(activeCronScript.dataset.script) : null;
            case 'auto-deploy-log-tab': return loadAutoDeployLog;
            default: return null;
        }
    }

    // Add a listener for all download buttons to show a toast
    document.body.addEventListener('click', function(e) {
        const downloadBtn = e.target.closest('.download-log-btn');
        if (downloadBtn) {
            showToast('Processing your download, please wait...', true);
        }
    });

    // Event listeners for tab clicks
    logTabs.addEventListener('click', function(event) {
        if (event.target.id === 'activity-log-tab') {
            loadActivityLogs(currentActivityPage);
        } else if (event.target.id === 'agent-log-tab') {
            loadAgentLogs(currentAgentPage);
        } else if (event.target.id === 'system-log-tab') {
            loadSystemLogs(currentSystemPage);
        }
        // Restart auto-refresh for the new active tab
        handleAutoRefresh();
    });

    // Event listeners for cron log selection
    const cronLogPane = document.getElementById('cron-log-pane');
    if (cronLogPane) {
        cronLogPane.addEventListener('click', function(e) {
            const target = e.target.closest('.cron-log-select');
            if (!target) return;

            document.querySelectorAll('.cron-log-select').forEach(el => el.classList.remove('active'));
            target.classList.add('active');
            const scriptName = target.dataset.script;
            loadCronLog(scriptName);
            // Restart auto-refresh for the new active log file
            handleAutoRefresh();
        });
    }

    // Initial load for the active tab
    // --- IDE: Fix initial load logic ---
    // Load content for the initially active tab.
    const activeTab = logTabs.querySelector('.nav-link.active');
    if (activeTab) {
        const tabId = activeTab.id;
        if (tabId === 'activity-log-tab') loadActivityLogs(1);
        else if (tabId === 'agent-log-tab') loadAgentLogs(1);
        else if (tabId === 'system-log-tab') loadSystemLogs(1);
    } // Other tabs load their content when shown.

    // --- Auto-Deploy Log Logic ---
    const autoDeployLogContent = document.getElementById('auto-deploy-log-content');
    const refreshAutoDeployBtn = document.getElementById('refresh-auto-deploy-log-btn');

    function loadAutoDeployLog() {
        if (!autoDeployLogContent) return;
        autoDeployLogContent.textContent = 'Loading...';
        refreshAutoDeployBtn.disabled = true;

        fetch(`<?= base_url('/api/logs/view?type=auto_deploy') ?>`)
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    autoDeployLogContent.textContent = data.log_content;
                } else {
                    throw new Error(data.message || 'Failed to load log.');
                }
            })
            .catch(error => {
                autoDeployLogContent.textContent = 'Error: ' + error.message;
            })
            .finally(() => {
                refreshAutoDeployBtn.disabled = false;
            });
    }

    refreshAutoDeployBtn.addEventListener('click', loadAutoDeployLog);

    // Load on tab show
    const autoDeployTab = document.getElementById('auto-deploy-log-tab');
    if (autoDeployTab) {
        autoDeployTab.addEventListener('shown.bs.tab', loadAutoDeployLog);
    }
    
    // Initial load if it's the active tab (though it's not by default)
    if (autoDeployTab && autoDeployTab.classList.contains('active')) {
        loadAutoDeployLog();
    }

    // --- IDE: Ensure initial load happens reliably ---
    // This is a more robust way to ensure the initial data load occurs,
    // especially with the complex script execution flow in main.js.
    // We check if the tab is already active and load its content.
    if (document.getElementById('activity-log-tab')?.classList.contains('active')) {
        loadActivityLogs(1);
    }

    // --- IDE: Initialize auto-refresh state on page load ---
    const savedAutoRefresh = localStorage.getItem('log_viewer_auto_refresh') === 'true';
    if (savedAutoRefresh) {
        autoRefreshSwitch.checked = true;
        handleAutoRefresh();
    }
};
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>