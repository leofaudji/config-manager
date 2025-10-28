<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$conn = Database::getInstance()->getConnection();
$hosts_result = $conn->query("SELECT id, name FROM docker_hosts ORDER BY name ASC");
$hosts = $hosts_result->fetch_all(MYSQLI_ASSOC);
$conn->close();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-shield-lock-fill"></i> Security Events</h1>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Falco Alerts Log</h5>
        <form id="filter-form" class="d-flex align-items-center">
            <input type="text" class="form-control form-control-sm me-2" id="search-input" placeholder="Search rule or output...">
            <select class="form-select form-select-sm me-2" id="host-filter">
                <option value="">All Hosts</option>
                <?php foreach ($hosts as $host): ?>
                    <option value="<?= $host['id'] ?>"><?= htmlspecialchars($host['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <select class="form-select form-select-sm me-2" id="priority-filter">
                <option value="">All Priorities</option>
                <option>Emergency</option><option>Alert</option><option>Critical</option>
                <option>Error</option><option>Warning</option><option>Notice</option>
                <option>Informational</option><option>Debug</option>
            </select>
            <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-funnel-fill"></i></button>
        </form>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-sm">
                <thead>
                    <tr>
                        <th>Timestamp</th>
                        <th>Priority</th>
                        <th>Rule</th>
                        <th>Output</th>
                        <th>Host</th>
                        <th>Container</th>
                    </tr>
                </thead>
                <tbody id="events-container">
                    <!-- Data will be loaded here by AJAX -->
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer d-flex justify-content-between align-items-center">
        <div class="text-muted small" id="events-info"></div>
        <div class="d-flex align-items-center">
            <nav id="events-pagination"></nav>
            <div class="ms-3">
                <select id="limit-selector" class="form-select form-select-sm" style="width: auto;">
                    <option value="25">25</option><option value="50">50</option><option value="100">100</option>
                </select>
            </div>
        </div>
    </div>
</div>

<script>
window.pageInit = function() {
    const container = document.getElementById('events-container');
    const paginationContainer = document.getElementById('events-pagination');
    const infoContainer = document.getElementById('events-info');
    const filterForm = document.getElementById('filter-form');
    const searchInput = document.getElementById('search-input');
    const hostFilter = document.getElementById('host-filter');
    const priorityFilter = document.getElementById('priority-filter');
    const limitSelector = document.getElementById('limit-selector');

    function loadEvents(page = 1) {
        container.innerHTML = '<tr><td colspan="6" class="text-center"><div class="spinner-border spinner-border-sm"></div></td></tr>';
        const searchTerm = searchInput.value.trim();
        const hostId = hostFilter.value;
        const priority = priorityFilter.value;
        const limit = limitSelector.value;
        const url = `<?= base_url('/api/security/events') ?>?page=${page}&limit=${limit}&search=${encodeURIComponent(searchTerm)}&host_id=${hostId}&priority=${priority}`;

        fetch(url)
            .then(response => response.json())
            .then(result => {
                if (result.status !== 'success') throw new Error(result.message);

                let html = '';
                if (result.data && result.data.length > 0) {
                    const priorityColors = {
                        'Emergency': 'dark', 'Alert': 'danger', 'Critical': 'danger',
                        'Error': 'danger', 'Warning': 'warning', 'Notice': 'info',
                        'Informational': 'primary', 'Debug': 'secondary'
                    };
                    result.data.forEach(event => {
                        const color = priorityColors[event.priority] || 'secondary';
                        html += `
                            <tr>
                                <td><small>${new Date(event.event_time).toLocaleString()}</small></td>
                                <td><span class="badge bg-${color}">${event.priority}</span></td>
                                <td><strong>${event.rule}</strong></td>
                                <td>${event.output}</td>
                                <td>${event.host_name || 'N/A'}</td>
                                <td>${event.container_name || 'N/A'}</td>
                            </tr>
                        `;
                    });
                } else {
                    html = '<tr><td colspan="6" class="text-center text-muted">No security events found.</td></tr>';
                }
                container.innerHTML = html;
                infoContainer.innerHTML = result.info;
                renderPagination(paginationContainer, result.total_pages, result.current_page, loadEvents);
            })
            .catch(error => {
                container.innerHTML = `<tr><td colspan="6" class="text-center text-danger">Error: ${error.message}</td></tr>`;
            });
    }

    filterForm.addEventListener('submit', (e) => {
        e.preventDefault();
        loadEvents(1);
    });

    limitSelector.addEventListener('change', () => loadEvents(1));

    loadEvents(1);
};
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>