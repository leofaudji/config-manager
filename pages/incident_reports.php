<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/header.php';

// Fetch users for the assignee filter
$conn = Database::getInstance()->getConnection();
$users_result = $conn->query("SELECT id, username FROM users ORDER BY username ASC");
$users = $users_result->fetch_all(MYSQLI_ASSOC);
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-shield-fill-exclamation"></i> Incident Reports</h1>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Incident History</h5>
        <div class="d-flex align-items-center">
            <div class="btn-group me-2">
                <button type="button" class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-download"></i> Export
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="#" id="export-pdf-btn"><i class="bi bi-file-earmark-pdf me-2"></i>Export as PDF</a></li>
                </ul>
            </div>
            <form class="d-flex" id="incident-filter-form" onsubmit="return false;">
                <input type="text" class="form-control form-control-sm me-2" id="search-input" placeholder="Search by name...">
                <select id="status-filter" class="form-select form-select-sm me-2" style="width: auto;">
                    <option value="">All Statuses</option>
                    <option value="Open">Open</option>
                    <option value="Investigating">Investigating</option>
                    <option value="On Hold">On Hold</option>
                    <option value="Resolved">Resolved</option>
                    <option value="Closed">Closed</option>
                </select>
                <select id="severity-filter" class="form-select form-select-sm me-2" style="width: auto;">
                    <option value="">All Severities</option>
                    <option value="Critical">Critical</option>
                    <option value="High">High</option>
                    <option value="Medium">Medium</option>
                    <option value="Low">Low</option>
                </select>
                <select id="assignee-filter" class="form-select form-select-sm me-2" style="width: auto;">
                    <option value="">All Assignees</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?= $user['id'] ?>"><?= htmlspecialchars($user['username']) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" class="form-control form-control-sm" id="date-range-filter" name="date_range">
            </form>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-sm">
                <thead>
                    <tr>
                        <th>Status</th>
                        <th>Severity</th>
                        <th>Assignee</th>
                        <th>Target</th>
                        <th>Type</th>
                        <th>Start Time</th>
                        <th>End Time</th>
                        <th>Duration</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody id="incidents-container">
                    <!-- Data will be loaded here by AJAX -->
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer d-flex justify-content-between align-items-center">
        <div class="text-muted small" id="incidents-info"></div>
        <div class="d-flex align-items-center">
            <nav id="incidents-pagination"></nav>
            <div class="ms-3">
                <select class="form-select form-select-sm" id="incidents-limit-selector" style="width: auto;" title="Items per page">
                    <option value="20">20</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                    <option value="-1">All</option>
                </select>
            </div>
        </div>
    </div>
</div>

<script>
window.pageInit = function() {
    const container = document.getElementById('incidents-container');
    const paginationContainer = document.getElementById('incidents-pagination');
    const infoContainer = document.getElementById('incidents-info');
    const searchInput = document.getElementById('search-input');
    const statusFilter = document.getElementById('status-filter');
    const severityFilter = document.getElementById('severity-filter');
    const assigneeFilter = document.getElementById('assignee-filter');
    const dateRangeFilter = $('#date-range-filter');
    const limitSelector = document.getElementById('incidents-limit-selector');
    let currentPage = 1;

    // Initialize Date Range Picker
    dateRangeFilter.daterangepicker({
        opens: 'left',
        autoUpdateInput: false, // Don't auto-update the input value
        locale: {
            cancelLabel: 'Clear'
        },
        ranges: {
           'Today': [moment(), moment()],
           'Yesterday': [moment().subtract(1, 'days'), moment().subtract(1, 'days')],
           'Last 7 Days': [moment().subtract(6, 'days'), moment()],
           'Last 30 Days': [moment().subtract(29, 'days'), moment()],
           'This Month': [moment().startOf('month'), moment().endOf('month')],
        }
    });

    function saveState() {
        localStorage.setItem('incidents_page', currentPage);
        localStorage.setItem('incidents_search', searchInput.value);
        localStorage.setItem('incidents_status', statusFilter.value);
        localStorage.setItem('incidents_date_range', dateRangeFilter.val());
        localStorage.setItem('incidents_severity', severityFilter.value);
        localStorage.setItem('incidents_assignee', assigneeFilter.value);
        localStorage.setItem('incidents_limit', limitSelector.value);
    }

    function loadState() {
        currentPage = parseInt(localStorage.getItem('incidents_page')) || 1;
        searchInput.value = localStorage.getItem('incidents_search') || '';
        statusFilter.value = localStorage.getItem('incidents_status') || '';
        severityFilter.value = localStorage.getItem('incidents_severity') || '';
        assigneeFilter.value = localStorage.getItem('incidents_assignee') || '';
        const savedDateRange = localStorage.getItem('incidents_date_range') || '';
        limitSelector.value = localStorage.getItem('incidents_limit') || '20';
        if (savedDateRange) {
            const dates = savedDateRange.split(' - ');
            dateRangeFilter.data('daterangepicker').setStartDate(dates[0]);
            dateRangeFilter.data('daterangepicker').setEndDate(dates[1]);
            dateRangeFilter.val(savedDateRange);
        }
    }

    function loadIncidents(page = 1) {
        currentPage = page;
        saveState();
        container.innerHTML = '<tr><td colspan="7" class="text-center"><div class="spinner-border spinner-border-sm" role="status"></div></td></tr>';

        const searchTerm = searchInput.value.trim();
        const status = statusFilter.value;
        const severity = severityFilter.value;
        const assignee = assigneeFilter.value;
        const dateRange = dateRangeFilter.val();
        const url = new URL('<?= base_url('/api/incidents') ?>', window.location.origin);
        const limit = limitSelector.value;
        url.searchParams.append('page', page);
        url.searchParams.append('limit', limit);
        if (searchTerm) url.searchParams.append('search', searchTerm);
        if (status) url.searchParams.append('status', status);
        if (severity) url.searchParams.append('severity', severity);
        if (assignee) url.searchParams.append('assignee', assignee);
        if (dateRange) url.searchParams.append('date_range', dateRange);

        fetch(url)
            .then(response => response.json())
            .then(result => {
                if (result.status !== 'success') throw new Error(result.message);

                let html = '';
                if (result.data && result.data.length > 0) {
                    result.data.forEach(incident => {
                        let statusBadge = 'secondary';
                        if (incident.status === 'Open') statusBadge = 'danger';
                        if (incident.status === 'Investigating') statusBadge = 'warning';
                        if (incident.status === 'On Hold') statusBadge = 'secondary';
                        if (incident.status === 'Resolved') statusBadge = 'primary';
                        if (incident.status === 'Closed') statusBadge = 'success';

                        let severityBadge = 'secondary';
                        if (incident.severity === 'Critical') severityBadge = 'danger';
                        if (incident.severity === 'High') severityBadge = 'warning';
                        if (incident.severity === 'Medium') severityBadge = 'info';
                        if (incident.severity === 'Low') severityBadge = 'light text-dark';

                        html += `
                            <tr>
                                <td><span class="badge bg-${statusBadge}">${incident.status}</span></td>
                                <td><span class="badge bg-${severityBadge}">${incident.severity}</span></td>
                                <td><span class="badge rounded-pill text-bg-dark">${incident.assignee_username || 'Unassigned'}</span></td>
                                <td><strong>${incident.target_name}</strong></td>
                                <td><span class="badge bg-dark">${incident.incident_type}</span></td>
                                <td>${incident.start_time}</td>
                                <td>${incident.end_time || 'Ongoing'}</td>
                                <td>${incident.duration_human || '-'}</td>
                                <td class="text-end">
                                    <div class="btn-group">
                                        <a href="#" class="btn btn-sm btn-outline-danger export-single-pdf-btn" data-incident-id="${incident.id}" title="Export to PDF"><i class="bi bi-file-earmark-pdf"></i></a>
                                        <a href="<?= base_url('/incidents/') ?>${incident.id}" class="btn btn-sm btn-outline-primary" title="View/Edit Details">View/Edit</a>
                                    </div>
                                </td>
                            </tr>
                        `;
                    });
                } else {
                    html = '<tr><td colspan="9" class="text-center">No incidents found.</td></tr>';
                }
                container.innerHTML = html;
                infoContainer.textContent = result.info;
                
                // --- Pagination Rendering Logic ---
                let paginationHtml = '';
                if (result.total_pages > 1) {
                    paginationHtml += '<ul class="pagination pagination-sm mb-0">';
                    // Previous button
                    const prevPage = result.current_page > 1 ? result.current_page - 1 : 1;
                    paginationHtml += `<li class="page-item ${result.current_page <= 1 ? 'disabled' : ''}"><a class="page-link" href="#" data-page="${prevPage}">«</a></li>`;

                    // Page numbers
                    for (let i = 1; i <= result.total_pages; i++) {
                        paginationHtml += `<li class="page-item ${result.current_page == i ? 'active' : ''}"><a class="page-link" href="#" data-page="${i}">${i}</a></li>`;
                    }

                    // Next button
                    const nextPage = result.current_page < result.total_pages ? parseInt(result.current_page) + 1 : result.total_pages;
                    paginationHtml += `<li class="page-item ${result.current_page >= result.total_pages ? 'disabled' : ''}"><a class="page-link" href="#" data-page="${nextPage}">»</a></li>`;

                    paginationHtml += '</ul>';
                }
                paginationContainer.innerHTML = paginationHtml;

            })
            .catch(error => container.innerHTML = `<tr><td colspan="9" class="text-center text-danger">Failed to load incidents: ${error.message}</td></tr>`);
    }

    // Event listeners
    searchInput.addEventListener('input', debounce(() => loadIncidents(1), 400));
    statusFilter.addEventListener('change', () => loadIncidents(1));
    severityFilter.addEventListener('change', () => loadIncidents(1));
    assigneeFilter.addEventListener('change', () => loadIncidents(1));
    limitSelector.addEventListener('change', () => loadIncidents(1));

    // --- NEW: Single Incident PDF Export Logic ---
    container.addEventListener('click', function(e) {
        const exportBtn = e.target.closest('.export-single-pdf-btn');
        if (!exportBtn) return;

        e.preventDefault();
        const incidentId = exportBtn.dataset.incidentId;
        const originalContent = exportBtn.innerHTML;
        exportBtn.disabled = true;
        exportBtn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>`;

        const formData = new FormData();
        formData.append('report_type', 'single_incident_report');
        formData.append('incident_id', incidentId);

        fetch('<?= base_url('/api/pdf') ?>', { method: 'POST', body: formData })
            .then(res => {
                if (!res.ok) {
                    return res.text().then(text => { throw new Error(text || 'Failed to generate PDF.') });
                }
                return res.blob();
            })
            .then(blob => {
                const url = window.URL.createObjectURL(blob);
                window.open(url, '_blank');
            })
            .catch(err => showToast('Error exporting PDF: ' + err.message, false))
            .finally(() => {
                exportBtn.disabled = false;
                exportBtn.innerHTML = originalContent;
            });
    });

    // --- FIX: Add event listeners for the date range picker ---
    dateRangeFilter.on('apply.daterangepicker', function(ev, picker) {
        // Set the input value and trigger the load
        $(this).val(picker.startDate.format('YYYY-MM-DD') + ' - ' + picker.endDate.format('YYYY-MM-DD'));
        loadIncidents(1);
    });
    dateRangeFilter.on('cancel.daterangepicker', function(ev, picker) {
        // Clear the input value and trigger the load
        $(this).val('');
        loadIncidents(1);
    });
    // --- Export Logic ---
    document.getElementById('export-pdf-btn').addEventListener('click', function(e) {
        e.preventDefault();
        const btn = this;
        const originalContent = btn.innerHTML;
        btn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Generating...`;
        btn.disabled = true;

        const formData = new FormData();
        formData.append('report_type', 'incident_report');
        formData.append('search', searchInput.value.trim());
        formData.append('status', statusFilter.value);
        formData.append('date_range', dateRangeFilter.val());

        fetch('<?= base_url('/api/pdf') ?>', { method: 'POST', body: formData })
            .then(res => {
                if (!res.ok) {
                    return res.text().then(text => { throw new Error(text || 'Failed to generate PDF.') });
                }
                return res.blob();
            })
            .then(blob => {
                const url = window.URL.createObjectURL(blob);
                window.open(url, '_blank');
            })
            .catch(err => showToast('Error exporting PDF: ' + err.message, false))
            .finally(() => { btn.innerHTML = originalContent; btn.disabled = false; });
    });

    // --- Pagination Click Listener ---
    paginationContainer.addEventListener('click', function(e) {
        const pageLink = e.target.closest('.page-link');
        if (pageLink && !pageLink.parentElement.classList.contains('disabled')) { 
            e.preventDefault(); 
            loadIncidents(parseInt(pageLink.dataset.page)); 
        }
    });

    // Initial load
    loadState();
    loadIncidents(currentPage);
};
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>