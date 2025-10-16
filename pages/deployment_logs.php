<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-body-text"></i> Deployment Logs</h1>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Recent Deployment Logs</h5>
        <form class="d-flex" id="log-filter-form" onsubmit="return false;">
            <input type="text" class="form-control form-control-sm me-2" id="search-input" placeholder="Search by host or stack name...">
        </form>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-sm">
                <thead>
                    <tr>
                        <th>Host</th>
                        <th>Stack</th>
                        <th>Last Modified</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody id="logs-container">
                    <!-- Data will be loaded here by AJAX -->
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Log Viewer Modal -->
<div class="modal fade" id="logViewerModal" tabindex="-1" aria-labelledby="logViewerModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="logViewerModalLabel">Deployment Log</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body bg-dark text-light font-monospace">
        <pre id="log-content" class="mb-0" style="white-space: pre-wrap; word-break: break-all;"></pre>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script>
window.pageInit = function() {
    const container = document.getElementById('logs-container');
    const searchInput = document.getElementById('search-input');
    const logViewerModal = new bootstrap.Modal(document.getElementById('logViewerModal'));
    const logContentEl = document.getElementById('log-content');
    const logViewerModalLabel = document.getElementById('logViewerModalLabel');
    let allLogs = [];

    function renderLogs(logsToRender) {
        let html = '';
        if (logsToRender.length > 0) {
            logsToRender.forEach(log => {
                html += `
                    <tr>
                        <td>${log.host}</td>
                        <td>${log.stack}</td>
                        <td>${log.last_modified}</td>
                        <td class="text-end">
                            <button class="btn btn-sm btn-outline-primary view-log-btn" data-log-file="${log.file_path}" data-log-name="${log.host}/${log.stack}">
                                <i class="bi bi-eye"></i> View Log
                            </button>
                        </td>
                    </tr>
                `;
            });
        } else {
            html = '<tr><td colspan="4" class="text-center">No deployment logs found.</td></tr>';
        }
        container.innerHTML = html;
    }

    function loadLogs() {
        container.innerHTML = '<tr><td colspan="4" class="text-center"><div class="spinner-border spinner-border-sm"></div></td></tr>';
        fetch('<?= base_url('/api/deployment-logs') ?>')
            .then(response => response.json())
            .then(result => {
                if (result.status !== 'success') throw new Error(result.message);
                allLogs = result.data;
                renderLogs(allLogs);
            })
            .catch(error => {
                container.innerHTML = `<tr><td colspan="4" class="text-center text-danger">Error: ${error.message}</td></tr>`;
            });
    }

    searchInput.addEventListener('input', debounce(() => {
        const searchTerm = searchInput.value.toLowerCase();
        const filteredLogs = allLogs.filter(log => 
            log.host.toLowerCase().includes(searchTerm) || 
            log.stack.toLowerCase().includes(searchTerm)
        );
        renderLogs(filteredLogs);
    }, 300));

    container.addEventListener('click', function(e) {
        const viewBtn = e.target.closest('.view-log-btn');
        if (viewBtn) {
            const logFile = viewBtn.dataset.logFile;
            const logName = viewBtn.dataset.logName;
            logViewerModalLabel.textContent = `Log for: ${logName}`;
            logContentEl.textContent = 'Loading log content...';
            logViewerModal.show();

            fetch(`<?= base_url('/api/deployment-logs') ?>?file=${encodeURIComponent(logFile)}`)
                .then(response => response.json())
                .then(result => {
                    if (result.status !== 'success') throw new Error(result.message);
                    logContentEl.textContent = result.content;
                })
                .catch(error => {
                    logContentEl.textContent = `Error loading log: ${error.message}`;
                });
        }
    });

    loadLogs();
};
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>