<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-fire"></i> Resource Hotspots</h1>
    <div class="btn-toolbar mb-2 mb-md-0">
        <div class="form-check form-switch me-3">
            <input class="form-check-input" type="checkbox" role="switch" id="auto-refresh-hotspots" checked>
            <label class="form-check-label" for="auto-refresh-hotspots">Auto-Refresh (15s)</label>
        </div>
        <button class="btn btn-sm btn-outline-primary" id="refresh-hotspots-btn">
            <i class="bi bi-arrow-clockwise"></i> Refresh Now
        </button>
    </div>
</div>

<div class="row">
    <!-- Top CPU Consumers -->
    <div class="col-lg-6 mb-4">
        <div class="card h-100">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-cpu-fill"></i> Top CPU Consumers</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover table-sm">
                        <thead>
                            <tr>
                                <th>Container</th>
                                <th>Host</th>
                                <th class="text-end">CPU Usage</th>
                            </tr>
                        </thead>
                        <tbody id="top-cpu-container">
                            <!-- Data will be loaded here -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Top Memory Consumers -->
    <div class="col-lg-6 mb-4">
        <div class="card h-100">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-memory"></i> Top Memory Consumers</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover table-sm">
                        <thead>
                            <tr>
                                <th>Container</th>
                                <th>Host</th>
                                <th class="text-end">Memory Usage</th>
                            </tr>
                        </thead>
                        <tbody id="top-memory-container">
                            <!-- Data will be loaded here -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
window.pageInit = function() {
    const topCpuContainer = document.getElementById('top-cpu-container');
    const topMemoryContainer = document.getElementById('top-memory-container');
    const refreshBtn = document.getElementById('refresh-hotspots-btn');
    const autoRefreshSwitch = document.getElementById('auto-refresh-hotspots');

    function loadHotspots(isAutoRefresh = false) {
        const originalBtnContent = refreshBtn.innerHTML;
        refreshBtn.disabled = true;
        refreshBtn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Refreshing...`;

        // Only show loading spinner on manual refresh
        if (!isAutoRefresh) {
            topCpuContainer.innerHTML = '<tr><td colspan="3" class="text-center"><div class="spinner-border spinner-border-sm"></div></td></tr>';
            topMemoryContainer.innerHTML = '<tr><td colspan="3" class="text-center"><div class="spinner-border spinner-border-sm"></div></td></tr>';
        }

        fetch(`<?= base_url('/api/monitoring/hotspots') ?>`)
            .then(response => response.json())
            .then(result => {
                if (result.status !== 'success') throw new Error(result.message);

                const renderTable = (container, data, valueKey, formatter) => {
                    let html = '';
                    if (data.length > 0) {
                        data.forEach(item => {
                            html += `<tr><td>${item.container_name}</td><td><span class="badge bg-dark">${item.host_name}</span></td><td class="text-end fw-bold">${formatter(item[valueKey])}</td></tr>`;
                        });
                    } else {
                        html = '<tr><td colspan="3" class="text-center text-muted">No data available.</td></tr>';
                    }
                    container.innerHTML = html;
                };

                renderTable(topCpuContainer, result.data.top_cpu, 'cpu_usage', val => `${val.toFixed(2)}%`);
                renderTable(topMemoryContainer, result.data.top_memory, 'memory_usage', val => formatBytes(val));
            })
            .catch(error => {
                const errorHtml = `<tr><td colspan="3" class="text-center text-danger">Error: ${error.message}</td></tr>`;
                topCpuContainer.innerHTML = errorHtml;
                topMemoryContainer.innerHTML = errorHtml;
            })
            .finally(() => {
                refreshBtn.disabled = false;
                refreshBtn.innerHTML = originalBtnContent;
            });
    }

    refreshBtn.addEventListener('click', loadHotspots);
    autoRefreshSwitch.addEventListener('change', function() {
        if (this.checked) {
            if (window.currentPageInterval) clearInterval(window.currentPageInterval);
            window.currentPageInterval = setInterval(() => loadHotspots(true), 15000);
        } else {
            if (window.currentPageInterval) clearInterval(window.currentPageInterval);
        }
    });

    loadHotspots();
    autoRefreshSwitch.dispatchEvent(new Event('change')); // Start auto-refresh by default
};
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>