<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$host_id = $_GET['id'] ?? null;
if (!$host_id) {
    header("Location: " . base_url('/hosts?status=error&message=Host ID not specified.'));
    exit;
}

$conn = Database::getInstance()->getConnection();
$stmt = $conn->prepare("SELECT * FROM docker_hosts WHERE id = ?");
$stmt->bind_param("i", $host_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    header("Location: " . base_url('/hosts?status=error&message=Host not found.'));
    exit;
}
$host = $result->fetch_assoc();
$stmt->close();

$active_page = 'dashboard'; // Set this for the navigation tabs
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/host_nav.php';
?>

<div class="row mb-4">
    <div class="col-lg-8 mb-4 mb-lg-0">
        <div class="card h-100">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-motherboard-fill"></i> Helper Agents</h5>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0 align-middle">
                    <thead>
                        <tr>
                            <th>Agent</th>
                            <th>Status</th>
                            <th>Last Report</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Health Agent Row -->
                        <tr>
                            <td>
                                <strong>Health Agent</strong>
                                <p class="small text-muted mb-0">Monitors container health and performs auto-healing.</p>
                            </td>
                            <td><span id="agent-status-badge" class="badge text-bg-secondary">Checking...</span></td>
                            <td><span id="agent-last-report" class="text-muted small" data-timestamp="">Never</span></td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm" role="group">
                                    <button id="deploy-agent-btn" class="btn btn-outline-success" data-host-id="<?= $host_id ?>" data-action="deploy" title="Deploy/Redeploy"><i class="bi bi-cloud-arrow-down-fill"></i></button>
                                    <button id="restart-agent-btn" class="btn btn-outline-warning" data-host-id="<?= $host_id ?>" data-action="restart" style="display: none;" title="Restart"><i class="bi bi-arrow-clockwise"></i></button>
                                    <button id="remove-agent-btn" class="btn btn-outline-danger" data-host-id="<?= $host_id ?>" data-action="remove" style="display: none;" title="Remove"><i class="bi bi-trash-fill"></i></button>
                                    <button id="run-agent-btn" class="btn btn-outline-primary" data-host-id="<?= $host_id ?>" data-action="run" style="display: none;" title="Run Check Now"><i class="bi bi-play-fill"></i></button>
                                </div>
                                <div class="btn-group btn-group-sm ms-2" role="group">
                                    <button class="btn btn-outline-secondary view-container-logs-btn" data-container-name="cm-health-agent" data-bs-toggle="modal" data-bs-target="#viewLogsModal" title="Container Log"><i class="bi bi-card-text"></i></button>
                                    <a href="<?= base_url('/health-agent-workflow') ?>" class="btn btn-sm btn-outline-info" target="_blank" title="View Deployment Workflow">
                                        <i class="bi bi-diagram-3"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <!-- CPU Reader Row -->
                        <tr>
                            <td>
                                <strong>CPU Reader</strong>
                                <p class="small text-muted mb-0">Reads overall host CPU usage for the resource chart.</p>
                            </td>
                            <td><span id="cpu-reader-status-badge" class="badge text-bg-secondary">Checking...</span></td>
                            <td><span id="cpu-reader-last-report" class="text-muted small" data-timestamp="">Never</span></td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm" role="group">
                                    <button id="deploy-cpu-reader-btn" class="btn btn-outline-success" data-host-id="<?= $host_id ?>" data-action="deploy" title="Deploy/Redeploy"><i class="bi bi-cloud-arrow-down-fill"></i></button>
                                    <button id="restart-cpu-reader-btn" class="btn btn-outline-warning" data-host-id="<?= $host_id ?>" data-action="restart" style="display: none;" title="Restart"><i class="bi bi-arrow-clockwise"></i></button>
                                    <button id="remove-cpu-reader-btn" class="btn btn-outline-danger" data-host-id="<?= $host_id ?>" data-action="remove" style="display: none;" title="Remove"><i class="bi bi-trash-fill"></i></button>
                                    <button id="run-cpu-reader-btn" class="btn btn-outline-primary" data-host-id="<?= $host_id ?>" data-action="run" style="display: none;" title="Run Collection Now"><i class="bi bi-play-fill"></i></button>
                                </div>
                                <div class="btn-group btn-group-sm ms-2" role="group">
                                    <button class="btn btn-outline-secondary view-container-logs-btn" data-container-name="host-cpu-reader" data-bs-toggle="modal" data-bs-target="#viewLogsModal" title="Container Log"><i class="bi bi-card-text"></i></button>
                                    <a href="<?= base_url('/cpu-reader-workflow') ?>" class="btn btn-sm btn-outline-info" target="_blank" title="View Deployment Workflow">
                                        <i class="bi bi-diagram-3"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-info-circle-fill"></i> Host Information</h5>
            </div>
            <div class="card-body">
                <dl class="row">
                    <dt class="col-sm-3">API URL</dt>
                    <dd class="col-sm-9 font-monospace"><?= htmlspecialchars($host['docker_api_url']) ?></dd>

                    <dt class="col-sm-3">TLS Enabled</dt>
                    <dd class="col-sm-9"><?= $host['tls_enabled'] ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>' ?></dd>

                    <dt class="col-sm-3">Description</dt>
                    <dd class="col-sm-9"><?= htmlspecialchars($host['description'] ?: 'N/A') ?></dd>

                    <dt class="col-sm-3">Workflow</dt>
                    <dd class="col-sm-9"><a href="<?= base_url('/container-management-workflow') ?>" target="_blank">Lihat Alur Kerja Manajemen Kontainer</a></dd>
                </dl>
            </div>
        </div>
    </div>
</div>

<!-- Summary Widgets -->
<div class="row mb-4">
    <div class="col-lg-4 col-md-6 mb-3">
        <a href="<?= base_url('/hosts/' . $host_id . '/containers') ?>" class="text-decoration-none">
            <div class="card text-white bg-primary h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="card-title mb-0" id="total-containers-widget">...</h3>
                            <p class="card-text mb-0">Total Containers</p>
                        </div>
                        <i class="bi bi-box-seam fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-lg-4 col-md-6 mb-3">
        <a href="<?= base_url('/hosts/' . $host_id . '/containers') ?>" class="text-decoration-none">
            <div class="card text-white bg-success h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="card-title mb-0" id="running-containers-widget">...</h3>
                            <p class="card-text mb-0">Running</p>
                        </div>
                        <i class="bi bi-play-circle fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-lg-4 col-md-6 mb-3">
        <a href="<?= base_url('/hosts/' . $host_id . '/containers') ?>" class="text-decoration-none">
            <div class="card text-white bg-danger h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="card-title mb-0" id="stopped-containers-widget">...</h3>
                            <p class="card-text mb-0">Stopped</p>
                        </div>
                        <i class="bi bi-stop-circle fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-lg-4 col-md-6 mb-3">
        <a href="<?= base_url('/hosts/' . $host_id . '/stacks') ?>" class="text-decoration-none">
            <div class="card text-white bg-info h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="card-title mb-0" id="total-stacks-widget">...</h3>
                            <p class="card-text mb-0">Application Stacks</p>
                        </div>
                        <i class="bi bi-stack fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-lg-4 col-md-6 mb-3">
        <a href="<?= base_url('/hosts/' . $host_id . '/networks') ?>" class="text-decoration-none">
            <div class="card text-white bg-secondary h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="card-title mb-0" id="total-networks-widget">...</h3>
                            <p class="card-text mb-0">Networks</p>
                        </div>
                        <i class="bi bi-diagram-3 fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-lg-4 col-md-6 mb-3">
        <a href="<?= base_url('/hosts/' . $host_id . '/images') ?>" class="text-decoration-none">
            <div class="card text-white bg-dark h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="card-title mb-0" id="total-images-widget">...</h3>
                            <p class="card-text mb-0">Images</p>
                        </div>
                        <i class="bi bi-box-seam fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
        </a>
    </div>
</div>

<!-- Logs Modal (re-using from host_containers) -->
<div class="modal fade" id="viewLogsModal" tabindex="-1" aria-labelledby="viewLogsModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="viewLogsModalLabel">Container Logs</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <pre id="log-content-container" class="bg-dark text-light p-3 rounded" style="white-space: pre-wrap; word-break: break-all;"></pre>
      </div>
    </div>
  </div>
</div>

<!-- Stats Chart -->
<div class="card mt-4">
    <div class="card-header"><h5 class="mb-0">Resource Usage (Last 24 Hours)</h5></div>
    <div class="card-body" style="height: 40vh;"><canvas id="resourceUsageChart"></canvas></div>
</div>

<script>
window.pageInit = function() {
    const hostId = <?= $host_id ?>;
    const agentStatusBadge = document.getElementById('agent-status-badge');
    const deployBtn = document.getElementById('deploy-agent-btn');
    const restartBtn = document.getElementById('restart-agent-btn');
    const removeBtn = document.getElementById('remove-agent-btn');
    const runAgentBtn = document.getElementById('run-agent-btn');
    
    const cpuReaderStatusBadge = document.getElementById('cpu-reader-status-badge');
    const logModalEl = document.getElementById('viewLogsModal');
    const logModal = new bootstrap.Modal(logModalEl);
    const logContent = document.getElementById('log-content-container');
    const logModalLabel = document.getElementById('viewLogsModalLabel');
    const deployCpuReaderBtn = document.getElementById('deploy-cpu-reader-btn');
    const restartCpuReaderBtn = document.getElementById('restart-cpu-reader-btn');
    const removeCpuReaderBtn = document.getElementById('remove-cpu-reader-btn');
    const runCpuReaderBtn = document.getElementById('run-cpu-reader-btn');

    function timeAgo(dateString) {
        if (!dateString) return 'Never';

        // The date string from MySQL doesn't have timezone info. By default, JS will parse it assuming the user's local timezone, which is what we want.
        const date = new Date(dateString);
        const now = new Date();
        const seconds = Math.round((now - date) / 1000);

        if (seconds < 0) return 'in the future';
        if (seconds < 5) return 'just now';
        if (seconds < 60) return `${seconds} seconds ago`;

        const minutes = Math.round(seconds / 60);
        if (minutes < 60) return `${minutes} minute${minutes > 1 ? 's' : ''} ago`;

        const hours = Math.round(minutes / 60);
        if (hours < 24) return `${hours} hour${hours > 1 ? 's' : ''} ago`;

        const days = Math.round(hours / 24);
        if (days < 30) return `${days} day${days > 1 ? 's' : ''} ago`;

        return date.toLocaleString(); // Fallback for very old dates
    }

    function updateHelperStatusUI(status, type) {
        const [statusBadge, deployButton, restartButton, removeButton, runButton] = type === 'agent' ? 
            [agentStatusBadge, deployBtn, restartBtn, removeBtn, runAgentBtn] : 
            [cpuReaderStatusBadge, deployCpuReaderBtn, restartCpuReaderBtn, removeCpuReaderBtn, runCpuReaderBtn];

        statusBadge.textContent = status;
        switch (status.toLowerCase()) {
            case 'running':
                statusBadge.className = 'badge text-bg-success';
                deployButton.style.display = 'block';
                deployButton.title = 'Redeploy';
                restartButton.style.display = 'inline-block';
                removeButton.style.display = 'inline-block';
                runButton.style.display = 'inline-block';
                break;
            case 'stopped':
                statusBadge.className = 'badge text-bg-warning';
                deployButton.style.display = 'inline-block';
                deployButton.title = 'Redeploy';
                restartButton.style.display = 'inline-block';
                removeButton.style.display = 'inline-block';
                runButton.style.display = 'none'; // Can't run if stopped
                break;
            case 'not deployed':
                statusBadge.className = 'badge text-bg-danger';
                deployButton.style.display = 'inline-block';
                deployButton.title = 'Deploy';
                restartButton.style.display = 'none';
                removeButton.style.display = 'none';
                runButton.style.display = 'none';
                break;
            default: // Checking, Error
                statusBadge.className = 'badge text-bg-secondary';
                // Sembunyikan semua tombol aksi saat status tidak jelas
                deployButton.style.display = 'inline-block';
                deployButton.title = 'Deploy / Redeploy';
                restartButton.style.display = 'none';
                removeButton.style.display = 'none';
                runButton.style.display = 'none';
        }
    }

    function checkHelperStatus(type) {
        const actionPath = type === 'agent' ? 'agent-status' : 'cpu-reader-status';
        updateHelperStatusUI('Checking...', type);
        fetch(`<?= base_url('/api/hosts/') ?>${hostId}/helper/${actionPath}`)
            .then(response => response.json())
            .then(result => {
                if (result.status !== 'success') {
                    throw new Error(result.message);
                }

                updateHelperStatusUI(result.agent_status, type);

                // Update last report time specifically for the health agent
                const lastReportElId = type === 'agent' ? 'agent-last-report' : 'cpu-reader-last-report';
                const lastReportEl = document.getElementById(lastReportElId);
                if (lastReportEl && result.last_report_at) {
                    lastReportEl.dataset.timestamp = result.last_report_at; // Store raw timestamp
                    lastReportEl.dataset.type = type; // Store agent type for threshold check
                    lastReportEl.textContent = timeAgo(result.last_report_at);

                    // Set initial color based on threshold
                    const seconds_ago = Math.round((new Date() - new Date(result.last_report_at)) / 1000);
                    const threshold = (type === 'agent') ? 180 : 600; // 3 mins for health-agent, 10 mins for cpu-reader
                    if (seconds_ago > threshold) {
                        lastReportEl.className = 'text-danger small fw-bold';
                    } else {
                        lastReportEl.className = 'text-success small fw-bold';
                    }
                } else if (lastReportEl) {
                    delete lastReportEl.dataset.timestamp;
                    lastReportEl.textContent = 'Never';
                    lastReportEl.className = 'text-muted small';
                }
            })
            .catch(error => {
                updateHelperStatusUI('Error', type);
                showToast(`Failed to check ${type} status: ` + error.message, false);
            });
    }

    function handleHelperAction(event, type) {
        const button = event.currentTarget;
        const action = button.dataset.action;
        const actionPath = type === 'agent' ? 'agent-action' : 'cpu-reader-action';

        let confirmMessage = `Are you sure you want to ${action} the ${type.replace('-', ' ')} on this host?`;
        if (action === 'deploy') {
            confirmMessage = `This will pull the latest image and deploy/redeploy the ${type}. Continue?`;
        }

        if (!confirm(confirmMessage)) {
            return;
        }

        const formData = new FormData();
        formData.append('action', action);

        if (action === 'deploy') {
            // --- Handle Streaming Deployment ---
            logModalLabel.textContent = `Deployment Log: ${type.replace('-', ' ')}`;
            logContent.textContent = 'Starting deployment...';
            logModal.show();

            const originalBtnText = button.innerHTML;
            button.disabled = false;
            button.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Deploying...`;

            fetch(`<?= base_url('/api/hosts/') ?>${hostId}/helper/${actionPath}`, {
                method: 'POST',
                body: formData
            })
            .then(async response => {
                const reader = response.body.getReader();
                const decoder = new TextDecoder();
                let finalStatus = 'failed';

                while (true) {
                    const { value, done } = await reader.read();
                    if (done) break;
                    const chunk = decoder.decode(value, { stream: true });
                    if (chunk.includes('_DEPLOYMENT_COMPLETE_')) finalStatus = 'success';
                    const cleanChunk = chunk.replace(/_DEPLOYMENT_(COMPLETE|FAILED)_/, '');
                    logContent.textContent += cleanChunk;
                    logContent.scrollTop = logContent.scrollHeight;
                }
                return finalStatus;
            })
            .then(status => {
                showToast(`Deployment ${status}.`, status === 'success');
                setTimeout(() => checkHelperStatus(type), 2000);
            })
            .catch(error => showToast('An error occurred: ' + error.message, false))
            .finally(() => {
                button.disabled = false;
                button.innerHTML = originalBtnText;
            });
        } else {
            // --- Handle Standard Actions (restart, remove, run) ---
            const originalBtnText = button.innerHTML;
            button.disabled = true;
            button.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>`;

            fetch(`<?= base_url('/api/hosts/') ?>${hostId}/helper/${actionPath}`, { method: 'POST', body: formData })
            .then(response => response.json().then(data => ({ ok: response.ok, data })))
            .then(({ ok, data }) => {
                showToast(data.message, ok);
                if (ok) setTimeout(() => checkHelperStatus(type), 2000);
            })
            .catch(error => showToast('An error occurred: ' + error.message, false))
            .finally(() => { button.disabled = false; button.innerHTML = originalBtnText; });
        }
    }

    deployBtn.addEventListener('click', (e) => handleHelperAction(e, 'agent'));
    restartBtn.addEventListener('click', (e) => handleHelperAction(e, 'agent'));
    removeBtn.addEventListener('click', (e) => handleHelperAction(e, 'agent'));

    deployCpuReaderBtn.addEventListener('click', (e) => handleHelperAction(e, 'cpu-reader'));
    restartCpuReaderBtn.addEventListener('click', (e) => handleHelperAction(e, 'cpu-reader'));
    removeCpuReaderBtn.addEventListener('click', (e) => handleHelperAction(e, 'cpu-reader'));
    runAgentBtn.addEventListener('click', (e) => handleHelperAction(e, 'agent'));
    runCpuReaderBtn.addEventListener('click', (e) => handleHelperAction(e, 'cpu-reader'));

    // Periodically update all relative timestamps on the page
    setInterval(() => {
        document.querySelectorAll('[data-timestamp]').forEach(el => {
            const timestamp = el.dataset.timestamp;
            const type = el.dataset.type;
            el.textContent = timeAgo(timestamp);

            // Also update color based on threshold
            const seconds_ago = Math.round((new Date() - new Date(timestamp)) / 1000);
            const threshold = (type === 'agent') ? 180 : 600; // 3 mins for health-agent, 10 mins for cpu-reader
            const is_stale = seconds_ago > threshold;
            el.classList.toggle('text-danger', is_stale);
            el.classList.toggle('text-success', !is_stale);
        });
    }, 5000); // Update every 5 seconds

    // --- Log Viewer Logic ---
    const viewLogsModal = document.getElementById('viewLogsModal');
    if (viewLogsModal) {
        const logContentContainer = document.getElementById('log-content-container');
        const logModalLabel = document.getElementById('viewLogsModalLabel');

        viewLogsModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            const logType = button.dataset.logType;
            const logTitle = button.dataset.logTitle;

            logContentContainer.textContent = 'Loading logs...';
            let url;

            if (logType === 'deployment') {
                logModalLabel.textContent = logTitle || 'Deployment Logs';
                url = `<?= base_url('/api/hosts/') ?>${hostId}/deployment-logs`;
            } else if (button.classList.contains('view-container-logs-btn')) {
                const containerName = button.dataset.containerName;
                logModalLabel.textContent = `Container Logs for: ${containerName}`;
                // We use the container name as its ID for this API call
                url = `<?= base_url('/api/hosts/') ?>${hostId}/containers/${containerName}/logs`;
            } else {
                logContentContainer.textContent = 'Invalid log type requested.';
                return;
            }

            fetch(url) // This variable is now set only within the if block
                .then(response => response.json().then(data => ({ ok: response.ok, data })))
                .then(({ ok, data }) => {
                    if (ok) {
                        logContentContainer.textContent = data.logs || data.log_content || 'No logs found or logs are empty.';
                    } else {
                        throw new Error(data.message || 'Failed to fetch logs.');
                    }
                })
                .catch(error => {
                    logContentContainer.textContent = `Error: ${error.message}`;
                });
        });
    }


    function loadDashboardStats() {
        fetch(`<?= base_url('/api/hosts/') ?>${hostId}/stats`)
            .then(response => response.json())
            .then(result => {
                if (result.status === 'success') {
                    const data = result.data;

                    // 1. Populate Widgets
                    document.getElementById('total-containers-widget').textContent = data.total_containers;
                    document.getElementById('running-containers-widget').textContent = data.running_containers;
                    document.getElementById('stopped-containers-widget').textContent = data.stopped_containers;
                    document.getElementById('total-stacks-widget').textContent = data.total_stacks;
                    document.getElementById('total-networks-widget').textContent = data.total_networks;
                    document.getElementById('total-images-widget').textContent = data.total_images;

                    // 2. Populate Chart
                    const ctx = document.getElementById('resourceUsageChart').getContext('2d');
                    const chartData = data.chart_data;

                    if (!chartData || chartData.labels.length === 0) {
                        ctx.font = "16px Arial";
                        ctx.fillText("No historical data available for the last 24 hours.", 10, 50);
                        return;
                    }
                    new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: chartData.labels,
                            datasets: [
                                {
                                    label: 'Host CPU Usage (%)',
                                    data: chartData.host_cpu_usage,
                                    borderColor: 'rgb(255, 159, 64)',
                                    backgroundColor: 'rgba(255, 159, 64, 0.2)',
                                    fill: true,
                                    tension: 0.4,
                                    pointRadius: 2,
                                    pointHoverRadius: 5,
                                    yAxisID: 'y'
                                },
                                {
                                    label: 'Container CPU Usage (%)',
                                    data: chartData.container_cpu_usage,
                                    borderColor: 'rgb(75, 192, 192)',
                                    backgroundColor: 'rgba(75, 192, 192, 0.2)',
                                    fill: true,
                                    tension: 0.4,
                                    pointRadius: 2,
                                    pointHoverRadius: 5,
                                    yAxisID: 'y1'
                                }, 
                                {
                                    label: 'Memory Usage (%)',
                                    data: chartData.memory_usage,
                                    borderColor: 'rgb(255, 99, 132)',
                                    backgroundColor: 'rgba(255, 99, 132, 0.2)',
                                    fill: true,
                                    tension: 0.4,
                                    pointRadius: 2,
                                    pointHoverRadius: 5,
                                    yAxisID: 'y'
                                }
                            ]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            scales: {
                                y: { type: 'linear', display: true, position: 'left', beginAtZero: true, max: 100, ticks: { callback: value => value + '%' } },
                                y1: { type: 'linear', display: true, position: 'right', grid: { drawOnChartArea: false } }
                            },
                            interaction: { mode: 'index', intersect: false }
                        },
                    });
                } else {
                    throw new Error(result.message);
                }
            })
            .catch(error => {
                console.error('Error fetching host dashboard data:', error);
                const ctx = document.getElementById('resourceUsageChart').getContext('2d');
                ctx.font = "16px Arial";
                ctx.fillText("An error occurred while loading dashboard data.", 10, 50);
                ['total-containers-widget', 'running-containers-widget', 'stopped-containers-widget', 'total-stacks-widget', 'total-networks-widget', 'total-images-widget'].forEach(id => {
                    const el = document.getElementById(id);
                    if (el) el.textContent = 'Error';
                });
            });
    }

    // --- Initial Load ---
    function initialize() {
        checkHelperStatus('agent');
        checkHelperStatus('cpu-reader');
        loadDashboardStats();
    }

    initialize();
};
</script>

<?php
$conn->close();
require_once __DIR__ . '/../includes/footer.php';
?>