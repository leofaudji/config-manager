<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-heart-pulse"></i> Service Health Status</h1>
    <div class="btn-toolbar mb-2 mb-md-0">
        <div class="form-check form-switch me-3" data-bs-toggle="tooltip" title="Play a sound when a new item becomes unhealthy">
            <input class="form-check-input" type="checkbox" role="switch" id="audio-alert-switch">
            <label class="form-check-label" for="audio-alert-switch"><i class="bi bi-volume-up"></i></label>
        </div>
        <div class="form-check form-switch me-3" data-bs-toggle="tooltip" title="Automatically refresh health status every 15 seconds">
            <input class="form-check-input" type="checkbox" role="switch" id="auto-refresh-switch">
            <label class="form-check-label" for="auto-refresh-switch">Auto Refresh</label>
        </div>
        <button class="btn btn-sm btn-outline-primary" id="refresh-health-btn">
            <i class="bi bi-arrow-clockwise"></i> Refresh Now
        </button>
        <span id="last-updated-timestamp" class="text-muted small align-self-center ms-3"></span>
    </div>
</div>

<!-- Host Summary Cards -->
<div id="host-summary-container" class="row mb-4">
    <!-- Summary cards will be loaded here by JavaScript -->
    <div class="col-12 text-center">
        <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">Loading host summaries...</span>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Monitored Items</h5>
        <div class="d-flex align-items-center">
            <form class="search-form me-2" data-type="health_status" id="health-status-search-form" onsubmit="return false;">
                <div class="input-group input-group-sm">
                    <input type="text" name="search_health_status" class="form-control" placeholder="Search by name...">
                    <button class="btn btn-outline-secondary" type="submit" title="Search"><i class="bi bi-search"></i></button>
                    <button class="btn btn-outline-secondary reset-search-btn" type="button" title="Reset"><i class="bi bi-x-lg"></i></button>
                </div>
            </form>
            <select id="status-filter" class="form-select form-select-sm" style="width: auto;">
                <option value="">All Statuses</option>
                <option value="healthy">Healthy</option>
                <option value="unhealthy">Unhealthy</option>
                <option value="unknown">Unknown</option>
            </select>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-sm">
                <thead>
                    <tr>
                        <th class="sortable" data-sort="status">Status</th>
                        <th class="sortable asc" data-sort="name">Name</th>
                        <th class="sortable" data-sort="group_name">Host / Group</th>
                        <th class="sortable" data-sort="health_check_type">Type</th>
                        <th>Last Log</th>
                        <th class="sortable" data-sort="last_checked_at">Last Checked</th>
                        <th>Info</th>
                    </tr>
                </thead>
                <tbody id="health-status-container">
                    <!-- Data will be loaded here by AJAX -->
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer d-flex justify-content-between align-items-center">
        <div class="text-muted small" id="health-status-info"></div>
        <div class="d-flex align-items-center">
            <nav id="health-status-pagination"></nav>
            <div class="ms-3">
                <select name="limit_health_status" class="form-select form-select-sm" id="health-status-limit-selector" style="width: auto;">
                    <option value="15">15</option>
                    <option value="30">30</option>
                    <option value="50">50</option>
                    <option value="-1">All</option>
                </select>
            </div>
        </div>
    </div>
</div>

<!-- Health Check Logic Info Modal -->
<div class="modal fade" id="healthCheckInfoModal" tabindex="-1" aria-labelledby="healthCheckInfoModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="healthCheckInfoModalLabel">Health Check Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p><strong>Last Check Flow:</strong></p>
        <ul id="health-check-log-content" class="list-group">
            <!-- Log steps will be rendered here -->
        </ul>
        <hr>
        <p class="mb-2"><strong>General Check Flow:</strong></p>
        <ol>
            <li><strong class="text-primary">Docker Healthcheck:</strong> Uses the container's built-in `HEALTHCHECK` if available. (Most reliable)</li>
            <li><strong class="text-info">Published Port Check:</strong> Tries to connect to any port published to the host (e.g., `8080:80`).</li>
            <li><strong class="text-secondary">Internal Port Check:</strong> Tries to connect to common ports (80, 443, etc.) on the container's internal IP.</li>
        </ol>
      </div>
    </div>
  </div>
</div>

<script>
window.pageInit = function() {
    const escapeHtml = (unsafe) => {
        if (typeof unsafe !== 'string') return '';
        return unsafe
             .replace(/&/g, "&amp;")
             .replace(/</g, "&lt;")
             .replace(/>/g, "&gt;")
             .replace(/"/g, "&quot;")
             .replace(/'/g, "&#039;");
    };

    const container = document.getElementById('health-status-container');
    const summaryContainer = document.getElementById('host-summary-container');
    const searchForm = document.getElementById('health-status-search-form');
    const searchInput = searchForm.querySelector('input[name="search_health_status"]');
    const resetBtn = searchForm.querySelector('.reset-search-btn');
    const paginationContainer = document.getElementById('health-status-pagination');
    const infoContainer = document.getElementById('health-status-info');
    const limitSelector = document.getElementById('health-status-limit-selector');
    const audioAlertSwitch = document.getElementById('audio-alert-switch');
    const lastUpdatedTimestamp = document.getElementById('last-updated-timestamp');
    const refreshBtn = document.getElementById('refresh-health-btn');
    const autoRefreshSwitch = document.getElementById('auto-refresh-switch');
    const statusFilter = document.getElementById('status-filter');
    const tableHeader = document.querySelector('#health-status-container').closest('table').querySelector('thead');

    function timeAgo(dateString) {
        if (!dateString) return 'Never';
        const date = new Date(dateString);
        const now = new Date();
        const seconds = Math.round((now - date) / 1000);

        if (seconds < 5) return 'just now';
        if (seconds < 60) return `${seconds}s ago`;

        const minutes = Math.round(seconds / 60);
        if (minutes < 60) return `${minutes}m ago`;

        const hours = Math.round(minutes / 60);
        if (hours < 24) return `${hours}h ago`;

        return date.toLocaleString(); // Fallback for older dates
    }

    let lastUnhealthySet = new Set();
    const alertSound = new Audio('https://cdn.jsdelivr.net/gh/scottschiller/SoundManager2@master/demo/audio/alert-01.mp3');
    let currentPage = 1;
    let currentLimit = 15;
    let currentSort = 'status';
    let autoRefreshInterval = null;
    let currentOrder = 'asc';
    let currentGroupFilter = '';

    function loadHealthStatus(page = 1, limit = 15) {
        currentPage = parseInt(page) || 1;
        currentLimit = parseInt(limit) || 15;
        container.innerHTML = '<tr><td colspan="7" class="text-center"><div class="spinner-border spinner-border-sm" role="status"><span class="visually-hidden">Loading...</span></div></td></tr>';
        
        // Show loading state on manual refresh
        const originalBtnContent = refreshBtn.innerHTML;
        refreshBtn.disabled = true;
        refreshBtn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>`;
        
        const searchTerm = searchInput.value.trim();
        const statusFilterValue = statusFilter.value;
        
        let url = `${basePath}/api/health-status?search=${encodeURIComponent(searchTerm)}&page=${page}&limit=${limit}&sort=${currentSort}&order=${currentOrder}&status_filter=${statusFilterValue}`;
        if (currentGroupFilter) {
            url += `&group_filter=${encodeURIComponent(currentGroupFilter)}`;
        }

        fetch(url)
            .then(response => response.json())
            .then(result => {
                if (result.status === 'error') throw new Error(result.message);

                // --- NEW: Audio Alert Logic ---
                const currentUnhealthySet = new Set(
                    result.data
                        .filter(item => item.status === 'unhealthy')
                        .map(item => item.name)
                );

                const newUnhealthyItems = [...currentUnhealthySet].filter(item => !lastUnhealthySet.has(item));

                if (newUnhealthyItems.length > 0 && audioAlertSwitch.checked) {
                    alertSound.play().catch(e => console.warn("Audio play failed:", e));
                }
                lastUnhealthySet = currentUnhealthySet;
                
                // Render Summary Cards
                let summaryHtml = '';
                if (result.summary && result.summary.length > 0) {
                    result.summary.forEach(host => {
                        const total = (host.counts.healthy || 0) + (host.counts.unhealthy || 0) + (host.counts.unknown || 0);
                        const isUnhealthy = (host.counts.unhealthy || 0) > 0;
                        const isFiltered = currentGroupFilter === host.host_name;
                        const cardClass = isFiltered ? 'border-primary border-2' : (isUnhealthy ? 'border-danger' : '');
                        summaryHtml += `
                            <div class="col-lg-3 col-md-4 mb-3">
                                <div class="card h-100 summary-card ${cardClass}" style="cursor: pointer;" data-host-name="${host.host_name}">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <h6 class="card-title mb-0">${host.host_name}</h6>
                                            <span class="badge bg-dark rounded-pill">${total}</span>
                                        </div>
                                        <div class="d-flex justify-content-around mt-2 text-center">
                                            <div><span class="fs-5 fw-bold text-success">${host.counts.healthy || 0}</span><br><small class="text-muted">Healthy</small></div>
                                            <div><span class="fs-5 fw-bold ${isUnhealthy ? 'text-danger' : 'text-secondary'}">${host.counts.unhealthy || 0}</span><br><small class="text-muted">Unhealthy</small></div>
                                            <div><span class="fs-5 fw-bold text-secondary">${host.counts.unknown || 0}</span><br><small class="text-muted">Unknown</small></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        `;
                    });
                } else {
                    summaryHtml = '<div class="col-12"><div class="alert alert-info">No host health summaries available.</div></div>';
                }
                summaryContainer.innerHTML = summaryHtml;

                // Render Table Data
                let html = '';
                if (result.data && result.data.length > 0) {
                    result.data.forEach(item => {
                        let statusBadge = 'secondary';
                        if (item.status === 'healthy') statusBadge = 'success';
                        else if (item.status === 'unhealthy') statusBadge = 'danger';

                        let lastLogMessage = item.last_log || 'N/A';
                        try {
                            const logSteps = JSON.parse(item.last_log);
                            if (Array.isArray(logSteps) && logSteps.length > 0) {
                                lastLogMessage = logSteps[logSteps.length - 1].message;
                            }
                        } catch (e) {
                            // It's not JSON, so we just use the raw string.
                        }

                        html += `<tr>
                                    <td><span class="badge bg-${statusBadge}">${item.status || 'unknown'}</span></td>
                                    <td>${item.name}</td>
                                    <td><span class="badge bg-dark">${item.group_name || 'N/A'}</span></td>
                                    <td><span class="badge bg-info">${item.health_check_type}</span></td>
                                    <td><small class="text-muted">${escapeHtml(lastLogMessage)}</small></td>
                                    <td data-timestamp="${item.last_checked_at}">${item.last_checked_at ? timeAgo(item.last_checked_at) : 'Never'}</td>
                                    <td class="text-center">
                                        <button type="button" class="btn btn-sm btn-outline-secondary view-check-flow-btn" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#healthCheckInfoModal" 
                                                data-item-name="${escapeHtml(item.name)}"
                                                data-log-message="${escapeHtml(item.last_log || 'No log available for this check.')}"
                                                title="View Last Check Log">
                                            <i class="bi bi-info-circle"></i>
                                        </button>
                                    </td>
                                 </tr>`;
                    });
                } else {
                    html = '<tr><td colspan="7" class="text-center">No monitored items found.</td></tr>';
                }
                container.innerHTML = html;
                infoContainer.innerHTML = result.info;

                // Build pagination
                let paginationHtml = '';
                if (result.total_pages > 1) {
                    paginationHtml += '<ul class="pagination pagination-sm mb-0">';
                    paginationHtml += `<li class="page-item ${result.current_page <= 1 ? 'disabled' : ''}"><a class="page-link" href="#" data-page="${result.current_page - 1}">«</a></li>`;
                    for (let i = 1; i <= result.total_pages; i++) {
                        paginationHtml += `<li class="page-item ${result.current_page == i ? 'active' : ''}"><a class="page-link" href="#" data-page="${i}">${i}</a></li>`;
                    }
                    paginationHtml += `<li class="page-item ${result.current_page >= result.total_pages ? 'disabled' : ''}"><a class="page-link" href="#" data-page="${parseInt(result.current_page) + 1}">»</a></li>`;
                    paginationHtml += '</ul>';
                }
                paginationContainer.innerHTML = paginationHtml;

                // Update sort indicators
                tableHeader.querySelectorAll('th.sortable').forEach(th => {
                    th.classList.remove('asc', 'desc');
                    if (th.dataset.sort === currentSort) th.classList.add(currentOrder);
                });
            })
            .then(() => {
                if (lastUpdatedTimestamp) {
                    lastUpdatedTimestamp.textContent = `Last updated: ${new Date().toLocaleTimeString()}`;
                }
            })
            .catch(error => container.innerHTML = `<tr><td colspan="7" class="text-center text-danger">Failed to load health status: ${error.message}</td></tr>`)
            .finally(() => {
                refreshBtn.disabled = false;
                refreshBtn.innerHTML = originalBtnContent;
            });
    }

    // Event Listeners
    paginationContainer.addEventListener('click', e => {
        const pageLink = e.target.closest('.page-link');
        if (pageLink) { e.preventDefault(); loadHealthStatus(parseInt(pageLink.dataset.page), limitSelector.value); }
    });

    tableHeader.addEventListener('click', e => {
        const th = e.target.closest('th.sortable');
        if (!th) return;
        const sortField = th.dataset.sort;
        if (currentSort === sortField) {
            currentOrder = currentOrder === 'asc' ? 'desc' : 'asc';
        } else {
            currentSort = sortField;
            currentOrder = 'asc';
        }
        loadHealthStatus(1, limitSelector.value);
    });

    limitSelector.addEventListener('change', () => loadHealthStatus(1, limitSelector.value));
    statusFilter.addEventListener('change', () => loadHealthStatus(1, limitSelector.value));
    searchForm.addEventListener('submit', e => { e.preventDefault(); loadHealthStatus(); });
    resetBtn.addEventListener('click', () => { if (searchInput.value !== '') { searchInput.value = ''; loadHealthStatus(); } });
    searchInput.addEventListener('input', debounce(() => loadHealthStatus(), 400));

    summaryContainer.addEventListener('click', e => {
        const card = e.target.closest('.summary-card');
        if (!card) return;
        const hostName = card.dataset.hostName;
        if (currentGroupFilter === hostName) {
            currentGroupFilter = ''; // Toggle off
        } else {
            currentGroupFilter = hostName;
        }
        loadHealthStatus(1, limitSelector.value);
    });

    audioAlertSwitch.addEventListener('change', function() {
        localStorage.setItem('health_status_audio_alert', this.checked);
        // --- Unlock audio on first user interaction ---
        if (this.checked && !audioUnlocked) {
            alertSound.volume = 0;
            alertSound.play().catch(()=>{});
            alertSound.pause();
            alertSound.volume = 1;
            audioUnlocked = true;
        }
        showToast(`Audio alerts ${this.checked ? 'enabled' : 'disabled'}.`, true);
    });

    // --- Modal Handler for Dynamic Content ---
    const healthCheckModal = document.getElementById('healthCheckInfoModal');
    if (healthCheckModal) {
        healthCheckModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const itemName = button.dataset.itemName;
            const logMessage = button.dataset.logMessage;

            const modalTitle = healthCheckModal.querySelector('.modal-title');
            const modalLogContent = healthCheckModal.querySelector('#health-check-log-content');

            modalTitle.textContent = `Health Check Details for: ${itemName}`;
            
            try {
                const logSteps = JSON.parse(logMessage);
                let logHtml = '';
                if (Array.isArray(logSteps)) {
                    logSteps.forEach(step => {
                        let icon = '<i class="bi bi-question-circle text-secondary"></i>';
                        let textClass = 'text-muted';
                        if (step.status === 'success') {
                            icon = '<i class="bi bi-check-circle-fill text-success"></i>';
                            textClass = 'text-success';
                        } else if (step.status === 'fail') {
                            icon = '<i class="bi bi-x-circle-fill text-danger"></i>';
                            textClass = 'text-danger';
                        }
                        logHtml += `<li class="list-group-item d-flex align-items-start"><span class="me-3">${icon}</span><div><strong>${step.step}:</strong> <span class="${textClass}">${step.message}</span></div></li>`;
                    });
                }
                modalLogContent.innerHTML = logHtml || '<li class="list-group-item">No structured log available.</li>';
            } catch (e) {
                // Fallback for old, non-JSON logs
                modalLogContent.innerHTML = `<li class="list-group-item">${logMessage}</li>`;
            }
        });
    }

    refreshBtn.addEventListener('click', () => loadHealthStatus(currentPage, currentLimit));

    autoRefreshSwitch.addEventListener('change', function() {
        localStorage.setItem('health_status_auto_refresh', this.checked);
        if (this.checked) {
            if (autoRefreshInterval) clearInterval(autoRefreshInterval);
            autoRefreshInterval = setInterval(() => loadHealthStatus(currentPage, currentLimit), 15000); // 15 seconds
            // --- Unlock audio on user interaction ---
            if (!audioUnlocked) {
                alertSound.volume = 0;
                alertSound.play().catch(()=>{});
                alertSound.pause();
                alertSound.volume = 1;
                audioUnlocked = true;
            }
            showToast('Auto-refresh enabled (15s).', true);
        } else {
            if (autoRefreshInterval) {
                clearInterval(autoRefreshInterval);
                autoRefreshInterval = null;
                showToast('Auto-refresh disabled.', true);
            }
        }
    });

    // Initial Load
    const savedAutoRefresh = localStorage.getItem('health_status_auto_refresh') === 'true';
    const savedAudioAlert = localStorage.getItem('health_status_audio_alert') === 'true';
    audioAlertSwitch.checked = savedAudioAlert;
    autoRefreshSwitch.checked = savedAutoRefresh;
    loadHealthStatus();
    if (savedAutoRefresh) {
        autoRefreshSwitch.dispatchEvent(new Event('change'));
    }

    // Periodically update all relative timestamps on the page
    setInterval(() => {
        document.querySelectorAll('[data-timestamp]').forEach(el => {
            el.textContent = timeAgo(el.dataset.timestamp);
        });
    }, 10000); // Update every 10 seconds
};
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>