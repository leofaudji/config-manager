<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$conn = Database::getInstance()->getConnection();

if (!isset($_GET['id'])) {
    header("Location: " . base_url('/hosts?status=error&message=Host ID not provided.'));
    exit;
}

$id = (int)$_GET['id'];
$stmt = $conn->prepare("SELECT * FROM docker_hosts WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
if (!($host = $result->fetch_assoc())) {
    header("Location: " . base_url('/hosts?status=error&message=Host not found.'));
    exit;
}
$stmt->close();
$conn->close();

require_once __DIR__ . '/../includes/header.php';
$active_page = 'containers';
require_once __DIR__ . '/../includes/host_nav.php';
?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-box-seam"></i> Containers</h5>
        <div class="d-flex align-items-center">
            <div id="bulk-actions-container" class="dropdown me-2" style="display: none;">
                <button class="btn btn-secondary btn-sm dropdown-toggle" type="button" id="bulk-actions-btn" data-bs-toggle="dropdown" aria-expanded="false">
                    Bulk Actions
                </button>
                <ul class="dropdown-menu" aria-labelledby="bulk-actions-btn">
                    <li><a class="dropdown-item bulk-action-trigger" href="#" data-action="start">Start Selected</a></li>
                    <li><a class="dropdown-item bulk-action-trigger" href="#" data-action="stop">Stop Selected</a></li>
                    <li><a class="dropdown-item bulk-action-trigger" href="#" data-action="restart">Restart Selected</a></li>
                </ul>
            </div>
            <form class="search-form me-2" data-type="containers" id="container-search-form" onsubmit="return false;">
                <div class="input-group input-group-sm">
                    <input type="text" name="search_containers" class="form-control" placeholder="Search by name or image...">
                    <button class="btn btn-outline-secondary" type="submit" title="Search"><i class="bi bi-search"></i></button>
                    <button class="btn btn-outline-secondary reset-search-btn" type="button" title="Reset"><i class="bi bi-x-lg"></i></button>
                </div>
            </form> 
            <div class="btn-group btn-group-sm me-2" role="group" id="container-filter-group">
                <button type="button" class="btn btn-outline-secondary active" data-filter="all">All</button>
                <button type="button" class="btn btn-outline-secondary" data-filter="running">Running</button>
                <button type="button" class="btn btn-outline-secondary" data-filter="stopped">Stopped</button>
            </div>
            <button id="check-all-updates-btn" class="btn btn-sm btn-outline-info me-2" title="Check all visible containers for image updates"><i class="bi bi-cloud-arrow-down-fill"></i> Check All</button>
            <button id="prune-containers-btn" class="btn btn-sm btn-outline-warning me-2" title="Remove all stopped containers"><i class="bi bi-trash3"></i> Prune</button>
            <button id="refresh-containers-btn" class="btn btn-sm btn-outline-primary" title="Refresh List"><i class="bi bi-arrow-clockwise"></i></button>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive table-responsive-sticky">
            <table class="table table-striped table-sm table-hover">
                <thead>
                    <tr>
                        <th><input class="form-check-input" type="checkbox" id="select-all-containers" title="Select all containers"></th>
                        <th class="sortable asc" data-sort="Name">Name</th>
                        <th class="sortable" data-sort="Image">Image</th>
                        <th class="sortable" data-sort="State">State</th>
                        <th data-sort="Status">Status</th>
                        <th>IP Address</th>
                        <th>Networks</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody id="containers-container">
                    <!-- Container data will be loaded here by AJAX -->
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer d-flex justify-content-between align-items-center">
        <div class="text-muted small" id="containers-info"></div>
        <div class="d-flex align-items-center">
            <nav id="containers-pagination"></nav>
            <div class="ms-3">
                <select name="limit_containers" class="form-select form-select-sm" id="containers-limit-selector" style="width: auto;">
                    <option value="10">10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="-1">All</option>
                </select>
            </div>
        </div>
    </div>
</div>

<!-- Live Stats Modal -->
<div class="modal fade" id="liveStatsModal" tabindex="-1" aria-labelledby="liveStatsModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="liveStatsModalLabel">Live Stats</h5>
        <div class="ms-auto d-flex align-items-center">
            <label for="stats-refresh-rate" class="form-label me-2 mb-0 small">Refresh Rate:</label>
            <select id="stats-refresh-rate" class="form-select form-select-sm" style="width: auto;">
                <option value="5000" selected>5 Seconds</option>
                <option value="30000">30 Seconds</option>
                <option value="60000">60 Seconds</option>
            </select>
        </div>
        <button type="button" class="btn-close ms-2" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="row">
            <div class="col-md-6">
                <canvas id="cpuChart"></canvas>
            </div>
            <div class="col-md-6">
                <canvas id="memoryChart"></canvas>
            </div>
        </div>
        <div id="stats-error-message" class="alert alert-danger mt-3 d-none"></div>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const hostId = <?= $id ?>;
    const containerBody = document.getElementById('containers-container');
    const refreshBtn = document.getElementById('refresh-containers-btn');
    const filterGroup = document.getElementById('container-filter-group');
    const paginationContainer = document.getElementById('containers-pagination');
    const infoContainer = document.getElementById('containers-info');
    const limitSelector = document.getElementById('containers-limit-selector');
    const searchForm = document.getElementById('container-search-form');
    const searchInput = searchForm.querySelector('input[name="search_containers"]');
    const resetBtn = searchForm.querySelector('.reset-search-btn');
    const pruneBtn = document.getElementById('prune-containers-btn');
    const bulkActionsContainer = document.getElementById('bulk-actions-container');
    const checkAllUpdatesBtn = document.getElementById('check-all-updates-btn');
    const selectAllCheckbox = document.getElementById('select-all-containers');
    const tableHeader = document.querySelector('#containers-container').closest('table').querySelector('thead');

    let currentFilter = localStorage.getItem(`host_${hostId}_containers_filter`) || 'all';
    let currentPage = 1;
    let currentLimit = 10;
    let currentSort = 'Name';
    let currentOrder = 'asc';

    function reloadCurrentView() {
        loadContainers(parseInt(currentPage), parseInt(currentLimit));
    }

    function loadContainers(page = 1, limit = 10) {
        currentPage = parseInt(page) || 1;
        currentLimit = parseInt(limit) || 10;
        const originalBtnContent = refreshBtn.innerHTML;
        refreshBtn.disabled = true;
        refreshBtn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>`;
        containerBody.innerHTML = '<tr><td colspan="8" class="text-center"><div class="spinner-border spinner-border-sm" role="status"><span class="visually-hidden">Loading...</span></div></td></tr>';

        const searchTerm = searchInput.value.trim();
        const fetchUrl = `${basePath}/api/hosts/${hostId}/containers?page=${page}&limit=${limit}&filter=${currentFilter}&search=${encodeURIComponent(searchTerm)}&sort=${currentSort}&order=${currentOrder}`;

        fetch(fetchUrl)
            .then(response => response.json())
            .then(result => {
                if (result.status === 'error') throw new Error(result.message);
                
                containerBody.innerHTML = result.html;
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

                // Save state
                localStorage.setItem(`host_${hostId}_containers_page`, result.current_page);
                localStorage.setItem(`host_${hostId}_containers_limit`, result.limit);
                localStorage.setItem(`host_${hostId}_containers_filter`, currentFilter);

                // Update sort indicators in header
                tableHeader.querySelectorAll('th.sortable').forEach(th => {
                    th.classList.remove('asc', 'desc');
                    if (th.dataset.sort === currentSort) {
                        th.classList.add(currentOrder);
                    }
                });
            }).catch(error => containerBody.innerHTML = `<tr><td colspan="8" class="text-center text-danger">Failed to load containers: ${error.message}</td></tr>`)
            .finally(() => {
                refreshBtn.disabled = false;
                refreshBtn.innerHTML = originalBtnContent;
            });
    }

    // --- Event Listeners ---

    refreshBtn.addEventListener('click', reloadCurrentView);

    if (pruneBtn) {
        pruneBtn.addEventListener('click', function() {
            if (!confirm('Are you sure you want to remove all stopped containers? This action cannot be undone.')) {
                return;
            }

            const originalBtnContent = this.innerHTML;
            this.disabled = true;
            this.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Pruning...`;

            const url = `${basePath}/api/hosts/${hostId}/containers/prune`;

            fetch(url, { method: 'POST' })
                .then(response => response.json().then(data => ({ ok: response.ok, data })))
                .then(({ ok, data }) => {
                    showToast(data.message, ok);
                    if (ok) {
                        // Refresh the view after a short delay to allow Docker to update the container's state.
                        setTimeout(reloadCurrentView, 2000);
                    }
                })
                .catch(error => {
                    showToast(error.message || 'An unknown error occurred during prune.', false);
                })
                .finally(() => {
                    this.disabled = false;
                    this.innerHTML = originalBtnContent;
                });
        });
    }

    function updateBulkActionsVisibility() {
        const checkedBoxes = containerBody.querySelectorAll('.container-checkbox:checked');
        if (checkedBoxes.length > 0) {
            bulkActionsContainer.style.display = 'block';
        } else {
            bulkActionsContainer.style.display = 'none';
        }
    }

    containerBody.addEventListener('change', (e) => {
        if (e.target.matches('.container-checkbox')) {
            updateBulkActionsVisibility();
        }
    });

    selectAllCheckbox.addEventListener('change', function() {
        const isChecked = this.checked;
        containerBody.querySelectorAll('.container-checkbox').forEach(checkbox => {
            checkbox.checked = isChecked;
        });
        updateBulkActionsVisibility();
    });

    bulkActionsContainer.addEventListener('click', function(e) {
        const trigger = e.target.closest('.bulk-action-trigger');
        if (!trigger) return;

        e.preventDefault();
        const action = trigger.dataset.action;
        const checkedBoxes = Array.from(containerBody.querySelectorAll('.container-checkbox:checked'));
        const containerIds = checkedBoxes.map(cb => cb.value);

        if (containerIds.length === 0) {
            showToast('No containers selected.', false);
            return;
        }

        if (!confirm(`Are you sure you want to ${action} ${containerIds.length} selected container(s)?`)) {
            return;
        }

        let completed = 0;
        const total = containerIds.length;
        showToast(`Performing bulk action '${action}' on ${total} containers...`, true);

        containerIds.forEach(containerId => {
            const url = `${basePath}/api/hosts/${hostId}/containers/${containerId}/${action}`;
            fetch(url, { method: 'POST' })
                .catch(error => {
                    console.error(`Error during bulk action for container ${containerId}:`, error);
                })
                .finally(() => {
                    completed++;
                    if (completed === total) {
                        showToast(`Bulk action '${action}' completed.`, true);
                        setTimeout(reloadCurrentView, 2000);
                    }
                });
        });
    });

    function checkContainerUpdate(button) {
        const containerId = button.dataset.containerId;
        const stackId = button.dataset.stackId;

        // If the button is already in "update available" state, redirect to the update page.
        if (button.classList.contains('update-available')) {
            if (stackId) {
                window.location.href = `${basePath}/hosts/${hostId}/stacks/${stackId}/update`;
            } else {
                showToast('This container is not part of a managed stack and cannot be updated automatically.', false);
            }
            return;
        }

        button.disabled = true;
        button.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>`;

        const url = `${basePath}/api/hosts/${hostId}/containers/${containerId}/check-update`;

        return fetch(url, { method: 'POST' }) // Return the promise
            .then(response => response.json().then(data => ({ ok: response.ok, data })))
            .then(({ ok, data }) => {
                if (ok) {
                    if (data.update_available) {
                        button.innerHTML = `<i class="bi bi-arrow-up-circle-fill"></i>`;
                        button.classList.remove('btn-outline-secondary', 'btn-outline-success');
                        button.classList.add('btn-warning', 'update-available');
                        if (stackId) {
                            button.title = `Update available for ${data.image_tag}. Click to update stack.`;
                        } else {
                            button.title = `Update available for ${data.image_tag}, but automatic update is not possible (not a managed stack).`;
                            button.disabled = true; // Disable if not manageable
                        }
                    } else {
                        button.innerHTML = `<i class="bi bi-check-circle-fill"></i>`;
                        button.classList.remove('btn-outline-secondary', 'btn-warning');
                        button.classList.add('btn-outline-success');
                        button.title = `Image ${data.image_tag} is up to date. Click to re-check.`;
                    }
                } else {
                    throw new Error(data.message || 'Check failed.');
                }
            })
            .catch(error => {
                button.innerHTML = `<i class="bi bi-x-circle-fill text-danger"></i>`;
                button.title = `Error: ${error.message}. Click to retry.`;
            })
            .finally(() => {
                // Re-enable the button unless it's an un-managed stack with an update
                if (!button.classList.contains('update-available') || stackId) {
                    button.disabled = false;
                }
            });
    }

    if (checkAllUpdatesBtn) {
        checkAllUpdatesBtn.addEventListener('click', function() {
            const individualCheckButtons = containerBody.querySelectorAll('.update-check-btn');
            if (individualCheckButtons.length === 0) {
                showToast('No containers to check.', true);
                return;
            }

            const originalBtnContent = this.innerHTML;
            this.disabled = true;
            this.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Checking...`;

            const checkPromises = [];
            individualCheckButtons.forEach(btn => {
                checkPromises.push(checkContainerUpdate(btn));
            });

            Promise.allSettled(checkPromises).then(() => {
                showToast(`Update check finished for ${individualCheckButtons.length} containers.`, true);
                this.disabled = false;
                this.innerHTML = originalBtnContent;
            });
        });
    }

    containerBody.addEventListener('click', function(e) {
        const updateBtn = e.target.closest('.update-check-btn');
        if (updateBtn) {
            e.preventDefault();
            checkContainerUpdate(updateBtn);
        }
    });

    tableHeader.addEventListener('click', function(e) {
        const th = e.target.closest('th.sortable');
        if (!th) return;

        const sortField = th.dataset.sort;
        if (currentSort === sortField) {
            currentOrder = currentOrder === 'asc' ? 'desc' : 'asc';
        } else {
            currentSort = sortField;
            currentOrder = 'asc';
        }
        localStorage.setItem(`host_${hostId}_containers_sort`, currentSort);
        localStorage.setItem(`host_${hostId}_containers_order`, currentOrder);
        loadContainers(1, limitSelector.value);
    });

    searchForm.addEventListener('submit', function(e) {
        e.preventDefault();
        loadContainers(1, limitSelector.value);
    });

    resetBtn.addEventListener('click', function() {
        if (searchInput.value !== '') {
            searchInput.value = '';
            loadContainers(1, limitSelector.value);
        }
    });

    // Debounce is available from main.js
    searchInput.addEventListener('input', debounce(() => {
        loadContainers(1, limitSelector.value);
    }, 400));

    filterGroup.addEventListener('click', function(e) {
        if (e.target.tagName === 'BUTTON') {
            currentFilter = e.target.dataset.filter;
            filterGroup.querySelector('.active').classList.remove('active');
            e.target.classList.add('active');
            loadContainers(1, limitSelector.value);
        }
    });

    paginationContainer.addEventListener('click', function(e) {
        const pageLink = e.target.closest('.page-link');
        if (pageLink) {
            e.preventDefault();
            loadContainers(parseInt(pageLink.dataset.page), limitSelector.value);
        }
    });

    limitSelector.addEventListener('change', function() {
        loadContainers(1, this.value);
    });

    containerBody.addEventListener('click', function(e) {
        const actionBtn = e.target.closest('.container-action-btn');
        if (!actionBtn) return;

        e.preventDefault();
        const containerId = actionBtn.dataset.containerId;
        const action = actionBtn.dataset.action;

        if (!confirm(`Are you sure you want to ${action} this container?`)) {
            return;
        }

        const originalIcon = actionBtn.innerHTML;
        actionBtn.disabled = true;
        actionBtn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>`;

        const url = `${basePath}/api/hosts/${hostId}/containers/${containerId}/${action}`;

        fetch(url, { method: 'POST' })
            .then(response => response.json().then(data => ({ ok: response.ok, data })))
            .then(({ ok, data }) => {
                showToast(data.message, ok);
                if (ok) {
                    // Refresh the view after a short delay to allow Docker to update the container's state.
                    setTimeout(reloadCurrentView, 2000);
                } else {
                    actionBtn.disabled = false;
                    actionBtn.innerHTML = originalIcon;
                }
            })
            .catch(error => {
                showToast(error.message || 'An unknown network error occurred.', false);
                actionBtn.disabled = false;
                actionBtn.innerHTML = originalIcon;
            });
    });

    const viewLogsModal = document.getElementById('viewLogsModal');
    if (viewLogsModal) {
        const logContentContainer = document.getElementById('log-content-container');
        const logModalLabel = document.getElementById('viewLogsModalLabel');

        viewLogsModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            const containerId = button.dataset.containerId;
            const containerName = button.dataset.containerName;

            logModalLabel.textContent = `Logs for: ${containerName}`;
            logContentContainer.textContent = 'Loading logs...';

            const url = `${basePath}/api/hosts/${hostId}/containers/${containerId}/logs`;

            fetch(url)
                .then(response => response.json().then(data => ({ ok: response.ok, data })))
                .then(({ ok, data }) => {
                    if (ok) {
                        logContentContainer.textContent = data.logs || 'No logs found or logs are empty.';
                    } else {
                        throw new Error(data.message || 'Failed to fetch logs.');
                    }
                })
                .catch(error => {
                    logContentContainer.textContent = `Error: ${error.message}`;
                });
        });
    }

    // --- Live Stats Modal Logic ---
    const liveStatsModalEl = document.getElementById('liveStatsModal');
    if (liveStatsModalEl) {
        let eventSource = null;
        let cpuChart = null;
        let memoryChart = null;
        let currentRefreshRate = 5000; // Default to 5 seconds
        let lastUpdateTime = 0;
        const refreshRateSelector = document.getElementById('stats-refresh-rate');

        const createChart = (ctx, label) => {
            return new Chart(ctx, {
                type: 'line',
                data: {
                    labels: [],
                    datasets: [{
                        label: label,
                        data: [],
                        borderColor: 'rgb(75, 192, 192)',
                        backgroundColor: 'rgba(75, 192, 192, 0.2)',
                        borderWidth: 1,
                        fill: true,
                        tension: 0.4
                    }]
                },
                options: {
                    scales: { y: { beginAtZero: true } },
                    animation: { duration: 200 }
                }
            });
        };

        const addDataToChart = (chart, label, data) => {
            chart.data.labels.push(label);
            chart.data.datasets[0].data.push(data);
            // Limit the number of data points to keep the chart clean
            if (chart.data.labels.length > 30) {
                chart.data.labels.shift();
                chart.data.datasets[0].data.shift();
            }
            chart.update();
        };

        liveStatsModalEl.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            const containerId = button.dataset.containerId;
            const containerName = button.dataset.containerName;

            // Reset and set up refresh rate logic
            lastUpdateTime = 0;
            refreshRateSelector.value = 5000; // Reset to default
            currentRefreshRate = parseInt(refreshRateSelector.value, 10);
            refreshRateSelector.onchange = () => { currentRefreshRate = parseInt(refreshRateSelector.value, 10); };

            document.getElementById('liveStatsModalLabel').textContent = `Live Stats for: ${containerName}`;
            document.getElementById('stats-error-message').classList.add('d-none');

            // Initialize charts
            if (cpuChart) cpuChart.destroy();
            if (memoryChart) memoryChart.destroy();
            cpuChart = createChart(document.getElementById('cpuChart').getContext('2d'), 'CPU Usage (%)');
            memoryChart = createChart(document.getElementById('memoryChart').getContext('2d'), 'Memory Usage (MB)');

            // Start streaming
            eventSource = new EventSource(`${basePath}/api/hosts/${hostId}/containers/${containerId}/stats`);

            eventSource.onmessage = function(e) {
                const now = Date.now();
                // Throttle the chart update based on the selected refresh rate
                if (now - lastUpdateTime < currentRefreshRate) {
                    return;
                }
                lastUpdateTime = now;

                const stats = JSON.parse(e.data);
                if (stats.error) {
                    document.getElementById('stats-error-message').textContent = stats.error;
                    document.getElementById('stats-error-message').classList.remove('d-none');
                    eventSource.close();
                    return;
                }
                addDataToChart(cpuChart, stats.timestamp, stats.cpu_percent);
                addDataToChart(memoryChart, stats.timestamp, (stats.memory_usage / 1024 / 1024).toFixed(2));
            };

            eventSource.onerror = function() {
                document.getElementById('stats-error-message').textContent = 'Connection to stats stream lost. Please close and reopen.';
                document.getElementById('stats-error-message').classList.remove('d-none');
                eventSource.close();
            };
        });

        liveStatsModalEl.addEventListener('hidden.bs.modal', function() {
            if (eventSource) {
                eventSource.close();
                eventSource = null;
            }
        });
    }

    // --- Initial Load ---
    function initialize() {
        const initialPage = parseInt(localStorage.getItem(`host_${hostId}_containers_page`)) || 1;
        const initialLimit = parseInt(localStorage.getItem(`host_${hostId}_containers_limit`)) || 10;
        currentSort = localStorage.getItem(`host_${hostId}_containers_sort`) || 'Name';
        currentOrder = localStorage.getItem(`host_${hostId}_containers_order`) || 'asc';
        
        filterGroup.querySelector('.active')?.classList.remove('active');
        filterGroup.querySelector(`button[data-filter="${currentFilter}"]`)?.classList.add('active');
        limitSelector.value = initialLimit;

        loadContainers(initialPage, initialLimit);
    }

    initialize();
});
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>