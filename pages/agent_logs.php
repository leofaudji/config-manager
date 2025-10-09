<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$conn = Database::getInstance()->getConnection();
$hosts_result = $conn->query("SELECT id, name FROM docker_hosts ORDER BY name ASC");
$hosts = $hosts_result->fetch_all(MYSQLI_ASSOC);
$conn->close();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-card-text"></i> Health Agent Logs</h1>
    <div class="btn-toolbar mb-2 mb-md-0">
        <div class="form-check form-switch me-3" data-bs-toggle="tooltip" title="Automatically refresh logs every 10 seconds">
            <input class="form-check-input" type="checkbox" role="switch" id="auto-refresh-switch">
            <label class="form-check-label" for="auto-refresh-switch">Auto Refresh</label>
        </div>
        <button class="btn btn-sm btn-outline-primary" id="refresh-logs-btn">
            <i class="bi bi-arrow-clockwise"></i> Refresh
        </button>
    </div>
</div>

<div class="row mb-3">
    <div class="col-md-4">
        <div class="input-group">
            <span class="input-group-text"><i class="bi bi-hdd-network-fill"></i></span>
            <select id="host-filter" class="form-select">
                <option value="">All Hosts</option>
                <?php foreach ($hosts as $host): ?>
                    <option value="<?= htmlspecialchars($host['id']) ?>"><?= htmlspecialchars($host['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="col-md-4">
        <div class="input-group">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="text" id="search-filter" class="form-control" placeholder="Search log content...">
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <pre id="log-container" class="bg-dark text-light p-3 rounded" style="white-space: pre-wrap; word-break: break-all; min-height: 50vh; max-height: 70vh; overflow-y: auto;"></pre>
    </div>
</div>

<div class="d-flex justify-content-between align-items-center mt-3">
    <div id="table-info" class="text-muted small"></div>
    <div class="d-flex align-items-center">
        <div id="pagination-controls"></div>
        <div class="ms-3">
            <select id="limit-selector" class="form-select form-select-sm" style="width: auto;">
                <option value="50">50</option>
                <option value="100">100</option>
                <option value="250">250</option>
                <option value="500">500</option>
            </select>
        </div>
    </div>
</div>

<script>
window.pageInit = function() {
    const logContainer = document.getElementById('log-container');
    const hostFilter = document.getElementById('host-filter');
    const searchFilter = document.getElementById('search-filter');
    const refreshBtn = document.getElementById('refresh-logs-btn');
    const paginationControls = document.getElementById('pagination-controls');
    const tableInfo = document.getElementById('table-info');
    const limitSelector = document.getElementById('limit-selector');
    const autoRefreshSwitch = document.getElementById('auto-refresh-switch');

    // --- Load state from localStorage ---
    let currentPage = parseInt(localStorage.getItem('agent_logs_page')) || 1;
    let autoRefreshInterval = null;

    const debouncedFetch = debounce(fetchLogs, 400);

    // Wrap fetch in a function that shows loading state
    function fetchLogs(isDebounced = false) {
        // Read current values from UI
        const hostId = hostFilter.value;
        const limit = limitSelector.value;
        const searchTerm = searchFilter.value;

        // --- Save state to localStorage ---
        localStorage.setItem('agent_logs_page', currentPage);
        localStorage.setItem('agent_logs_host_filter', hostId);
        localStorage.setItem('agent_logs_search_filter', searchTerm);
        localStorage.setItem('agent_logs_limit', limit);
        // Auto-refresh state is saved in its own handler

        const url = new URL('<?= base_url('/api/agent-logs') ?>', window.location.origin);
        url.searchParams.append('page', currentPage);
        url.searchParams.append('limit', limit);
        if (hostId) {
            url.searchParams.append('host_id', hostId);
        }
        if (searchTerm) {
            url.searchParams.append('search', searchTerm);
        }

        // Only show "Loading..." on manual fetches, not on auto-refresh
        if (!isDebounced && !autoRefreshInterval) {
            logContainer.textContent = 'Loading logs...';
        }
        const originalBtnContent = refreshBtn.innerHTML;
        refreshBtn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>`;

        fetch(url)
            .then(response => response.json())
            .then(result => {
                if (result.status !== 'success') {
                    logContainer.textContent = `Error: ${result.message}`;
                    return;
                }

                let logHtml = '';
                if (result.data.length > 0) {
                    // Use a document fragment for performance when building large HTML strings
                    const fragment = document.createDocumentFragment();
                    result.data.forEach(logEntry => {
                        const parsedLogs = JSON.parse(logEntry.log_content);
                        parsedLogs.forEach(line => {
                            const serverTimestamp = logEntry.created_at;
                            const hostName = logEntry.host_name;
                            const message = line.substring(line.indexOf(']') + 2);

                            const span = document.createElement('span');
                            span.textContent = `[${serverTimestamp} @ ${hostName}] ${message}\n`;

                            // Add color based on keywords
                            if (message.includes('Unhealthy') || message.includes('unhealthy') || message.includes('TIDAK SEHAT') || message.includes('ERROR') || message.includes('FAILED')) {
                                span.className = 'text-danger fw-bold';
                            } else if (message.includes('WARN')) {
                                span.className = 'text-warning';
                            }
                            fragment.appendChild(span);
                        });
                    });
                    logContainer.innerHTML = ''; // Clear previous content
                    logContainer.appendChild(fragment);
                } else {
                    logContainer.textContent = 'No agent logs found for the selected criteria.';
                }
                renderPagination(result.total_pages, result.current_page);
                tableInfo.textContent = result.info;
            })
            .catch(error => {
                logContainer.textContent = `Failed to load logs: ${error.message}`;
                console.error('Error fetching agent logs:', error);
            }).finally(() => {
                refreshBtn.innerHTML = originalBtnContent;
            });
    }

    function renderPagination(totalPages, currentPage) {
        if (totalPages <= 1) {
            paginationControls.innerHTML = '';
            return;
        }

        let paginationHtml = '<ul class="pagination pagination-sm mb-0">';
        paginationHtml += `<li class="page-item ${currentPage <= 1 ? 'disabled' : ''}"><a class="page-link" href="#" data-page="${currentPage - 1}">«</a></li>`;

        // Simplified pagination for logs
        const startPage = Math.max(1, currentPage - 2);
        const endPage = Math.min(totalPages, currentPage + 2);

        for (let i = startPage; i <= endPage; i++) {
            paginationHtml += `<li class="page-item ${currentPage == i ? 'active' : ''}"><a class="page-link" href="#" data-page="${i}">${i}</a></li>`;
        }

        paginationHtml += `<li class="page-item ${currentPage >= totalPages ? 'disabled' : ''}"><a class="page-link" href="#" data-page="${parseInt(currentPage) + 1}">»</a></li>`;
        paginationHtml += '</ul>';
        paginationControls.innerHTML = paginationHtml;
    }

    function handleAutoRefresh() {
        // --- Save state to localStorage ---
        localStorage.setItem('agent_logs_auto_refresh', autoRefreshSwitch.checked);

        if (autoRefreshSwitch.checked) {
            if (autoRefreshInterval) clearInterval(autoRefreshInterval); // Clear any existing interval
            autoRefreshInterval = setInterval(fetchLogs, 10000); // Refresh every 10 seconds
            showToast('Auto-refresh enabled (10s).', true);
        } else {
            if (autoRefreshInterval) {
                clearInterval(autoRefreshInterval);
                autoRefreshInterval = null;
                showToast('Auto-refresh disabled.', true);
            }
        }
    }

    hostFilter.addEventListener('change', () => { currentPage = 1; fetchLogs(); });
    limitSelector.addEventListener('change', () => { currentPage = 1; fetchLogs(); });
    searchFilter.addEventListener('input', () => { currentPage = 1; debouncedFetch(true); });
    refreshBtn.addEventListener('click', fetchLogs);
    autoRefreshSwitch.addEventListener('change', handleAutoRefresh);

    paginationControls.addEventListener('click', function(e) {
        e.preventDefault();
        const target = e.target.closest('.page-link');
        if (target && !target.closest('.disabled') && !target.closest('.active')) {
            currentPage = parseInt(target.dataset.page);
            fetchLogs();
        }
    });

    // --- Initial Load ---
    // Apply stored values to the UI elements
    hostFilter.value = localStorage.getItem('agent_logs_host_filter') || '';
    searchFilter.value = localStorage.getItem('agent_logs_search_filter') || '';
    limitSelector.value = localStorage.getItem('agent_logs_limit') || '50';
    autoRefreshSwitch.checked = localStorage.getItem('agent_logs_auto_refresh') === 'true';

    // Fetch data with the restored state
    fetchLogs();
    // Start the interval if it was previously enabled
    handleAutoRefresh();
};
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>