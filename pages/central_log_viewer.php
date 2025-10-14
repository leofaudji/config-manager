<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$conn = Database::getInstance()->getConnection();
$hosts_result = $conn->query("SELECT id, name FROM docker_hosts ORDER BY name ASC");
$hosts = $hosts_result->fetch_all(MYSQLI_ASSOC);
$conn->close();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-journals"></i> Centralized Log Viewer</h1>
</div>

<div class="row">
    <div class="col-md-4 mb-3">
        <div class="input-group">
            <label class="input-group-text" for="host-select"><i class="bi bi-hdd-network-fill"></i></label>
            <select id="host-select" class="form-select">
                <option value="">-- Select a Host --</option>
                <?php foreach ($hosts as $host): ?>
                    <option value="<?= htmlspecialchars($host['id']) ?>"><?= htmlspecialchars($host['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="col-md-4 mb-3">
        <div class="input-group">
            <label class="input-group-text" for="container-select"><i class="bi bi-box-seam"></i></label>
            <select id="container-select" class="form-select" disabled>
                <option value="">-- Select a Container --</option>
            </select>
        </div>
    </div>
    <div class="col-md-4 mb-3">
        <div class="input-group me-3">
            <label class="input-group-text" for="log-search-input"><i class="bi bi-search"></i></label>
            <input type="text" id="log-search-input" class="form-control" placeholder="Filter logs..." disabled>
        </div>
    </div>
    <div class="col-md-12 mb-3 d-flex align-items-center">
        <div class="form-check form-switch me-3">
            <input class="form-check-input" type="checkbox" role="switch" id="auto-refresh-logs">
            <label class="form-check-label" for="auto-refresh-logs">Auto-Refresh (10s)</label>
        </div>
        <button id="refresh-logs-btn" class="btn btn-sm btn-outline-primary" disabled><i class="bi bi-arrow-clockwise"></i> Refresh</button>
        <button id="download-logs-btn" class="btn btn-sm btn-outline-secondary ms-2" disabled><i class="bi bi-download"></i> Download</button>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h5 class="mb-0" id="log-viewer-title">Logs</h5>
    </div>
    <div class="card-body">
        <pre id="log-container" class="bg-dark text-light p-3 rounded" style="min-height: 60vh; max-height: 70vh; overflow-y: auto; white-space: pre-wrap; word-break: break-all;">Select a host and container to view logs.</pre>
    </div>
</div>

<script>
window.pageInit = function() {
    const hostSelect = document.getElementById('host-select');
    const containerSelect = document.getElementById('container-select');
    const logContainer = document.getElementById('log-container');
    const logTitle = document.getElementById('log-viewer-title');
    const refreshBtn = document.getElementById('refresh-logs-btn');
    const downloadBtn = document.getElementById('download-logs-btn');
    const searchInput = document.getElementById('log-search-input');
    const autoRefreshSwitch = document.getElementById('auto-refresh-logs');
    let autoRefreshInterval = null;

    function saveState() {
        localStorage.setItem('log_viewer_host_id', hostSelect.value);
        localStorage.setItem('log_viewer_container_id', containerSelect.value);
        localStorage.setItem('log_viewer_search_term', searchInput.value);
        localStorage.setItem('log_viewer_auto_refresh', autoRefreshSwitch.checked);
    }

    hostSelect.addEventListener('change', function() {
        const hostId = this.value;
        containerSelect.innerHTML = '<option value="">-- Loading containers... --</option>';
        containerSelect.disabled = true;
        logContainer.textContent = 'Select a host and container to view logs.';
        logTitle.textContent = 'Logs';
        refreshBtn.disabled = true;
        downloadBtn.disabled = true;
        searchInput.disabled = true;
        saveState(); // Save host change

        if (!hostId) {
            containerSelect.innerHTML = '<option value="">-- Select a Host --</option>';
            return;
        }

        fetch(`<?= base_url('/api/hosts/') ?>${hostId}/containers?raw=true`)
            .then(response => response.json())
            .then(result => {
                if (result.status !== 'success' || !result.data) {
                    throw new Error(result.message || 'No container data returned.');
                }
                let optionsHtml = '<option value="">-- Select a Container --</option>';
                (result.data || []).forEach(container => {
                    if (container.State === 'running') { // Only show running containers
                        const containerName = container.Names[0].substring(1);
                        optionsHtml += `<option value="${container.Id}">${containerName}</option>`;
                    }
                });
                containerSelect.innerHTML = optionsHtml;
                containerSelect.disabled = false;
                searchInput.disabled = false;

                // Restore container selection if it exists for this host
                const savedContainerId = localStorage.getItem('log_viewer_container_id');
                if (savedContainerId && containerSelect.querySelector(`option[value="${savedContainerId}"]`)) {
                    containerSelect.value = savedContainerId; // Automatically select the saved container
                    containerSelect.dispatchEvent(new Event('change'));
                }
            })
            .catch(error => {
                containerSelect.innerHTML = '<option value="">-- Error: No containers found or host is unreachable --</option>';
                showToast(`Failed to load containers: ${error.message}`, false);
            });
    });

    function fetchLogs(isAutoRefresh = false) {
        const hostId = hostSelect.value;
        const containerId = containerSelect.value;
        const searchTerm = searchInput.value.trim();
        saveState(); // Save state on fetch

        if (!hostId || !containerId) return;

        // Only show loading state on manual refresh
        if (!isAutoRefresh) {
            const containerName = containerSelect.options[containerSelect.selectedIndex].text;
            logTitle.textContent = `Logs for: ${containerName}`;
            logContainer.textContent = 'Loading logs...';
        }

        refreshBtn.disabled = true;
        downloadBtn.disabled = true;
        searchInput.disabled = true;

        fetch(`<?= base_url('/api/hosts/') ?>${hostId}/containers/${containerId}/logs?search=${encodeURIComponent(searchTerm)}`)
            .then(response => response.json())
            .then(result => {
                if (result.status !== 'success') throw new Error(result.message);
                const isScrolledToBottom = logContainer.scrollHeight - logContainer.clientHeight <= logContainer.scrollTop + 1;
                logContainer.textContent = result.logs || 'No logs available for this container.';
                if (isScrolledToBottom) {
                    logContainer.scrollTop = logContainer.scrollHeight; // Auto-scroll only if user was at the bottom
                }
            })
            .catch(error => {
                logContainer.textContent = `Error loading logs: ${error.message}`;
            })
            .finally(() => {
                refreshBtn.disabled = false;
                downloadBtn.disabled = false;
                searchInput.disabled = false;
            });
    }

    containerSelect.addEventListener('change', () => {
        fetchLogs();
    });
    refreshBtn.addEventListener('click', fetchLogs);

    downloadBtn.addEventListener('click', function() {
        const hostId = hostSelect.value;
        const containerId = containerSelect.value;
        if (!hostId || !containerId) return;

        const url = `<?= base_url('/api/hosts/') ?>${hostId}/containers/${containerId}/logs?download=true`;
        window.open(url, '_blank');
    });

    searchInput.addEventListener('input', debounce(() => {
        fetchLogs();
    }, 400));

    autoRefreshSwitch.addEventListener('change', function() {
        saveState(); // Save auto-refresh state
        if (this.checked) {
            if (autoRefreshInterval) clearInterval(autoRefreshInterval);
            autoRefreshInterval = setInterval(() => fetchLogs(true), 10000); // Pass true for smooth refresh
            if (document.body.contains(autoRefreshSwitch)) showToast('Auto-refresh enabled.', true);
        } else {
            if (autoRefreshInterval) {
                clearInterval(autoRefreshInterval);
                autoRefreshInterval = null;
                if (document.body.contains(autoRefreshSwitch)) showToast('Auto-refresh disabled.', true);
            }
        }
    });

    // --- Initial Load ---
    const savedHostId = localStorage.getItem('log_viewer_host_id');
    const savedSearchTerm = localStorage.getItem('log_viewer_search_term');
    const savedAutoRefresh = localStorage.getItem('log_viewer_auto_refresh') === 'true';

    if (savedHostId) hostSelect.value = savedHostId;
    if (savedSearchTerm) searchInput.value = savedSearchTerm;
    autoRefreshSwitch.checked = savedAutoRefresh;

    // Trigger change on host select to load containers and potentially logs
    if (hostSelect.value) {
        hostSelect.dispatchEvent(new Event('change'));
    }
};
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>