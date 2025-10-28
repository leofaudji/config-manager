<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/header.php';

$conn = Database::getInstance()->getConnection();
$hosts_result = $conn->query("SELECT id, name FROM docker_hosts ORDER BY name ASC");
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-clipboard-data"></i> Service Level Agreement (SLA) Report</h1>
    <div class="btn-toolbar mb-2 mb-md-0">
        <div class="btn-group" role="group">
            <button type="button" class="btn btn-sm btn-outline-secondary active" id="view-mode-standard" title="Standard Report View"><i class="bi bi-card-list"></i> Standard</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="view-mode-heatmap" title="Heatmap View"><i class="bi bi-calendar-heart"></i> Heatmap</button>
        </div>
    </div>
</div>

<div id="standard-report-view">
    <div class="card">
        <div class="card-header">
            Report Generator
        </div>
        <div class="card-body">
            <form id="sla-report-form">
                <div class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label for="host_id" class="form-label">Host</label>
                        <select class="form-select" id="host_id" name="host_id" required>
                            <option value="" selected disabled>-- Select a Host --</option>
                            <option value="all">-- All Hosts (Global Summary) --</option>
                            <option disabled>-----------------</option>
                            <?php mysqli_data_seek($hosts_result, 0); ?>
                            <?php while ($host = $hosts_result->fetch_assoc()): ?>
                                <option value="<?= $host['id'] ?>"><?= htmlspecialchars($host['name']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="container_id" class="form-label">Container</label>
                        <select class="form-select" id="container_id" name="container_id" disabled>
                            <option value="">-- Select a host first --</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="date_range" class="form-label">Date Range</label>
                        <input type="text" class="form-control" id="date_range" name="date_range" required>
                    </div>
                    <div class="col-md-2 d-flex align-items-end pb-1">
                         <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="show_only_downtime" name="show_only_downtime" value="1">
                            <label class="form-check-label" for="show_only_downtime">Only with downtime</label>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="btn-group w-100">
                            <button type="submit" class="btn btn-primary" id="generate-report-btn">Generate</button>
                            <button type="button" class="btn btn-outline-secondary" id="reset-filters-btn" title="Reset Filters"><i class="bi bi-x-lg"></i></button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Output for Single Container Report -->
    <div id="single-container-output" class="mt-4" style="display: none;">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3 id="report-title"></h3>
            <div class="btn-group">
                <button type="button" class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-download"></i> Export
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="#" id="export-csv-btn"><i class="bi bi-file-earmark-spreadsheet me-2"></i>Export Details as CSV</a></li>
                    <li><a class="dropdown-item" href="#" id="export-pdf-btn"><i class="bi bi-file-earmark-pdf me-2"></i>Export Details as PDF</a></li>
                </ul>
            </div>
        </div>

        <div class="row">
            <div class="col-md-4">
                <div class="card text-white bg-success mb-3">
                    <div class="card-body text-center">
                        <h5 class="card-title">SLA Percentage</h5>
                        <p class="card-text display-4" id="sla-percentage">-%</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-white bg-warning mb-3">
                    <div class="card-body text-center">
                        <h5 class="card-title">Total Downtime</h5>
                        <p class="card-text display-4" id="total-downtime">-</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-white bg-info mb-3">
                    <div class="card-body text-center">
                        <h5 class="card-title">Downtime Incidents</h5>
                        <p class="card-text display-4" id="downtime-incidents">-</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                Downtime Details
            </div>
            <div class="card-body">
                <table class="table table-striped table-hover" id="downtime-table">
                    <thead>
                        <tr>
                            <th>Start Time</th>
                            <th>End Time</th>
                            <th>Duration</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Data will be populated by JavaScript -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Output for Host Summary Report -->
    <div id="host-summary-output" class="mt-4" style="display: none;">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3 id="summary-report-title"></h3>
            <div class="btn-group">
                <button type="button" class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-download"></i> Export
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="#" id="export-summary-csv-btn"><i class="bi bi-file-earmark-spreadsheet me-2"></i>Export Summary as CSV</a></li>
                    <li><a class="dropdown-item" href="#" id="export-summary-pdf-btn"><i class="bi bi-file-earmark-pdf me-2"></i>Export Summary as PDF</a></li>
                </ul>
            </div>
        </div>

        <!-- Overall Host SLA Cards -->
        <div class="row" id="overall-host-sla-cards">
            <div class="col-md-6">
                <div class="card text-white bg-success mb-3">
                    <div class="card-body text-center">
                        <h5 class="card-title">Overall Host SLA</h5>
                        <p class="card-text display-4" id="overall-host-sla-percentage">-%</p>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card text-white bg-warning mb-3">
                    <div class="card-body text-center">
                        <h5 class="card-title">Total Aggregated Downtime</h5>
                        <p class="card-text display-4" id="overall-host-total-downtime">-</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                SLA Details per Container
            </div>
            <div class="card-body">
                <table class="table table-striped table-hover" id="host-sla-table">
                    <thead>
                        <tr>
                            <th>Container Name</th>
                            <th>SLA</th>
                            <th>Total Downtime</th>
                            <th>Incidents</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Data will be populated by JavaScript -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Output for Global Summary Report -->
    <div id="global-summary-output" class="mt-4" style="display: none;">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3 id="global-summary-report-title"></h3>
            <div class="btn-group">
                <button type="button" class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-download"></i> Export
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="#" id="export-global-summary-csv-btn"><i class="bi bi-file-earmark-spreadsheet me-2"></i>Export Summary as CSV</a></li>
                    <li><a class="dropdown-item" href="#" id="export-global-summary-pdf-btn"><i class="bi bi-file-earmark-pdf me-2"></i>Export Summary as PDF</a></li>
                </ul>
            </div>
        </div>

        <!-- NEW: Details of SLA Violations -->
        <div class="card mt-4" id="violation-details-card" style="display: none;">
            <div class="card-header bg-warning">
                <h5 class="mb-0"><i class="bi bi-exclamation-triangle-fill"></i> SLA Violation Details</h5>
            </div>
            <div class="card-body">
                <table class="table table-striped table-hover table-sm" id="violation-details-table">
                    <thead>
                        <tr>
                            <th>Host</th>
                            <th>Service / Container</th>
                            <th>SLA</th>
                            <th>Total Downtime</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>

        <div class="card mt-4">
            <div class="card-header">
                SLA Summary per Host
            </div>
            <div class="card-body">
                <table class="table table-striped table-hover" id="global-sla-table">
                    <thead>
                        <tr>
                            <th>Host Name</th>
                            <th>Overall SLA</th>
                            <th>OS</th>
                            <th>CPUs</th>
                            <th>Memory</th>
                            <th>Total Aggregated Downtime</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div id="heatmap-view" style="display: none;">
    <div class="card">
        <div class="card-header">
            Heatmap Generator
        </div>
        <div class="card-body">
            <form id="heatmap-form">
                <div class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label for="heatmap_host_id" class="form-label">Host</label>
                        <select class="form-select" id="heatmap_host_id" name="host_id" required>
                            <option value="" selected disabled>-- Select a Host --</option>
                            <?php mysqli_data_seek($hosts_result, 0); ?>
                            <?php while ($host = $hosts_result->fetch_assoc()): ?>
                                <option value="<?= $host['id'] ?>"><?= htmlspecialchars($host['name']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="heatmap_container_id" class="form-label">Container / Service</label>
                        <select class="form-select" id="heatmap_container_id" name="container_id" required disabled>
                            <option value="">-- Select a host first --</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="month_year" class="form-label">Month</label>
                        <input type="month" class="form-control" id="month_year" name="month_year" value="<?= date('Y-m') ?>" required>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-primary w-100" id="generate-heatmap-btn">Generate Heatmap</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div id="heatmap-output" class="mt-4" style="display: none;">
        <div class="card">
            <div class="card-header">
                <h5 id="heatmap-title" class="mb-0"></h5>
            </div>
            <div class="card-body">
                <div id="calendar-heatmap-container" class="mb-3"></div>
                <div class="d-flex justify-content-end align-items-center">
                    <small class="me-3">Legend:</small>
                    <span class="badge me-1" style="background-color: #d6e685; color: #333;">100%</span>
                    <span class="badge me-1" style="background-color: #8cc665; color: #fff;">99.9%+</span>
                    <span class="badge me-1" style="background-color: #44a340; color: #fff;">99%+</span>
                    <span class="badge me-1" style="background-color: #1e6823; color: #fff;">&lt;99%</span>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    #calendar-heatmap-container { display: grid; grid-template-columns: repeat(7, 1fr); gap: 5px; }
    .calendar-day { border: 1px solid #e1e4e8; border-radius: 3px; aspect-ratio: 1 / 1; padding: 5px; font-size: 0.8rem; }
    .calendar-day .day-number { font-weight: bold; }
    .calendar-day .sla-value { font-size: 0.9rem; display: block; text-align: center; margin-top: 10px; }
    .dark-mode .calendar-day { border-color: #30363d; }
</style>

<script>
window.pageInit = function() {
    // Karena skrip sekarang dimuat secara global di header, kita bisa langsung inisialisasi.
    
    const hostSelect = document.getElementById('host_id');
    const containerSelect = document.getElementById('container_id');
    const form = document.getElementById('sla-report-form');
    const reportOutput = document.getElementById('report-output');
    const singleContainerOutput = document.getElementById('single-container-output');
    const hostSummaryOutput = document.getElementById('host-summary-output');
    const globalSummaryOutput = document.getElementById('global-summary-output');
    let reportData = {}; // Holds data for export functions
    const resetFiltersBtn = document.getElementById('reset-filters-btn');
    const viewModeStandardBtn = document.getElementById('view-mode-standard');
    const viewModeHeatmapBtn = document.getElementById('view-mode-heatmap');
    const standardReportView = document.getElementById('standard-report-view');
    const heatmapView = document.getElementById('heatmap-view');

    function switchViewMode(mode) {
        if (mode === 'heatmap') {
            standardReportView.style.display = 'none';
            heatmapView.style.display = 'block';
            viewModeStandardBtn.classList.remove('active');
            viewModeHeatmapBtn.classList.add('active');
        } else { // standard
            standardReportView.style.display = 'block';
            heatmapView.style.display = 'none';
            viewModeStandardBtn.classList.add('active');
            viewModeHeatmapBtn.classList.remove('active');
        }
        localStorage.setItem('sla_report_view_mode', mode);
    }

    // Initialize Date Range Picker
    $('input[name="date_range"]').daterangepicker({
        opens: 'left',
        startDate: moment().startOf('month'),
        endDate: moment().endOf('month'),
        ranges: {
           'Today': [moment(), moment()],
           'Yesterday': [moment().subtract(1, 'days'), moment().subtract(1, 'days')],
           'Last 7 Days': [moment().subtract(6, 'days'), moment()],
           'Last 30 Days': [moment().subtract(29, 'days'), moment()],
           'This Month': [moment().startOf('month'), moment().endOf('month')],
           'Last Month': [moment().subtract(1, 'month').startOf('month'), moment().subtract(1, 'month').endOf('month')]
        }
    });

    function saveFilters() {
        localStorage.setItem('sla_report_host_id', hostSelect.value);
        localStorage.setItem('sla_report_container_id', containerSelect.value);
        localStorage.setItem('sla_report_show_only_downtime', document.getElementById('show_only_downtime').checked);
        localStorage.setItem('sla_report_date_range', $('input[name="date_range"]').val());
    }

    function loadFilters() {
        const savedHostId = localStorage.getItem('sla_report_host_id');
        const savedContainerId = localStorage.getItem('sla_report_container_id');
        const savedDateRange = localStorage.getItem('sla_report_date_range');
        const savedShowOnlyDowntime = localStorage.getItem('sla_report_show_only_downtime');

        if (savedDateRange) {
            const dates = savedDateRange.split(' - ');
            $('input[name="date_range"]').data('daterangepicker').setStartDate(dates[0]);
            $('input[name="date_range"]').data('daterangepicker').setEndDate(dates[1]);
        }

        if (savedShowOnlyDowntime === 'true') {
            document.getElementById('show_only_downtime').checked = true;
        }

        if (savedHostId) {
            hostSelect.value = savedHostId;
            // Trigger change event to load containers, and then select the saved container
            hostSelect.dispatchEvent(new CustomEvent('change', { detail: { savedContainerId: savedContainerId } }));
        }
    }

    function resetFilters() {
        if (!confirm('Are you sure you want to reset the filters?')) return;

        localStorage.removeItem('sla_report_host_id');
        localStorage.removeItem('sla_report_container_id');
        localStorage.removeItem('sla_report_show_only_downtime');
        localStorage.removeItem('sla_report_date_range');

        form.reset();
        hostSelect.value = '';
        containerSelect.innerHTML = '<option value="">-- Select a host first --</option>';
        containerSelect.disabled = true;
        singleContainerOutput.style.display = 'none';
        hostSummaryOutput.style.display = 'none';
        globalSummaryOutput.style.display = 'none';

        // Reset date picker to default (This Month)
        $('input[name="date_range"]').data('daterangepicker').setStartDate(moment().startOf('month'));
        $('input[name="date_range"]').data('daterangepicker').setEndDate(moment().endOf('month'));
    }

    hostSelect.addEventListener('change', function(event) {
        const hostId = this.value;
        containerSelect.disabled = true;
        containerSelect.innerHTML = '<option>Loading containers...</option>';
        if (!hostId) {
            containerSelect.innerHTML = '<option value="">-- Select a host first --</option>';
            return;
        }
        if (hostId === 'all') {
            containerSelect.innerHTML = '<option value="all" selected>-- Global Summary --</option>';
            return;
        }

        fetch(`<?= base_url('/api/containers/list?host_id=') ?>${hostId}`)
            .then(response => response.json())
            .then(result => {
                // FIX: Revert to checking for the object structure {status: 'success', data: [...]}.
                if (result.status === 'success' && Array.isArray(result.data)) { // NOSONAR
                    let optionsHtml = '<option value="all" selected>-- All Containers (Summary) --</option>';
                    optionsHtml += '<option disabled>-----------------</option>';
                    result.data.forEach(item => {
                        // Add a prefix to distinguish services from containers in the UI
                        const prefix = item.Type === 'service' ? '[S] ' : '[C] ';
                        optionsHtml += `<option value="${item.Id}" data-type="${item.Type}">
                                            ${prefix}${escapeHtml(item.Name)}
                                        </option>`;
                    });
                    containerSelect.innerHTML = optionsHtml;

                    // If a saved container ID was passed, select it
                    const savedContainerId = event.detail?.savedContainerId;
                    if (savedContainerId && containerSelect.querySelector(`option[value="${savedContainerId}"]`)) {
                        containerSelect.value = savedContainerId;
                    }

                    containerSelect.disabled = false;
                } else {
                    throw new Error(result.message || 'Invalid data format received from server.');
                }
            })
            .catch(error => {
                containerSelect.innerHTML = `<option>Error: ${error.message}</option>`;
                showToast('Failed to load containers.', false);
            });
    });

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(form);
        const params = new URLSearchParams(formData);
        const btn = document.getElementById('generate-report-btn');
        const originalBtnText = btn.innerHTML;

        saveFilters(); // Save filters on generate

        btn.disabled = true;
        btn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Generating...`;

        fetch(`<?= base_url('/api/sla-report') ?>?${params.toString()}`)
            .then(response => response.json())
            .then(data => {
                if (data.status !== 'success') throw new Error(data.message);
                
                // Hide both outputs first
                singleContainerOutput.style.display = 'none';
                hostSummaryOutput.style.display = 'none';
                globalSummaryOutput.style.display = 'none';

                if (data.data.report_type === 'single_container') {
                    displaySingleContainerReport(data.data);
                } else if (data.data.report_type === 'host_summary') {
                    displayHostSummaryReport(data.data);
                } else if (data.data.report_type === 'global_summary') {
                    displayGlobalSummaryReport(data.data);
                }
            })
            .catch(error => showToast(`Error generating report: ${error.message}`, false))
            .finally(() => {
                btn.disabled = false;
                btn.innerHTML = originalBtnText;
            });
    });

    resetFiltersBtn.addEventListener('click', resetFilters);

        // Fungsi untuk menampilkan data laporan
        function displaySingleContainerReport(data) {
            reportData = data; // Store for export
            const { summary, downtime_details, container_name } = reportData;

            document.getElementById('report-title').textContent = `SLA Report for: ${container_name}`;
            document.getElementById('sla-percentage').textContent = `${summary.sla_percentage}%`;
            document.getElementById('total-downtime').textContent = summary.total_downtime_human;
            document.getElementById('downtime-incidents').textContent = summary.downtime_incidents;

            const slaCard = document.getElementById('sla-percentage').closest('.card');
            const minimumSla = parseFloat(<?= json_encode(get_setting('minimum_sla_percentage', '99.9')) ?>);
            slaCard.classList.remove('bg-success', 'bg-warning', 'bg-danger');
            if (summary.sla_percentage_raw >= minimumSla) {
                slaCard.classList.add('bg-success');
            } else if (summary.sla_percentage_raw >= 99.0) { // Keep a 'warning' threshold
                slaCard.classList.add('bg-warning');
            } else {
                slaCard.classList.add('bg-danger');
            }

            const tableBody = document.querySelector('#downtime-table tbody');
            tableBody.innerHTML = '';
            if (downtime_details.length > 0) {
                downtime_details.forEach(incident => {
                    const incidentBtn = incident.incident_id
                        ? `<a href="${basePath}/incidents/${incident.incident_id}" class="btn btn-sm btn-outline-primary" title="View Incident #${incident.incident_id}"><i class="bi bi-shield-fill-exclamation"></i> View Incident</a>`
                        : '';

                    const maintenance_info = incident.maintenance_overlap_seconds > 0
                        ? ` <span class="badge bg-secondary" title="This downtime occurred during a scheduled maintenance window and does not affect SLA calculation."><i class="bi bi-tools"></i> Maintenance</span>`
                        : '';

                    const row = `<tr>
                        <td>${incident.start_time || 'N/A'}</td>
                        <td>${incident.end_time || 'Ongoing'}</td>
                        <td>
                            ${incident.duration_human || '-'} ${maintenance_info}
                        </td>
                        <td><span class="badge text-bg-danger">${incident.status}</span></td>
                        <td class="text-end">${incidentBtn}</td>
                    </tr>`;
                    tableBody.innerHTML += row;
                });
            } else {
                tableBody.innerHTML = '<tr><td colspan="4" class="text-center">No downtime incidents recorded in this period.</td></tr>';
            }

            singleContainerOutput.style.display = 'block';
        }

        function displayHostSummaryReport(data) {
            reportData = data; // Store for export

            // Populate overall host SLA cards
            document.getElementById('overall-host-sla-percentage').textContent = `${data.overall_host_sla}%`;
            document.getElementById('overall-host-total-downtime').textContent = data.overall_total_downtime_human;

            const overallSlaCard = document.getElementById('overall-host-sla-percentage').closest('.card');
            const minimumSla = parseFloat(<?= json_encode(get_setting('minimum_sla_percentage', '99.9')) ?>);
            overallSlaCard.classList.remove('bg-success', 'bg-warning', 'bg-danger');
            if (data.overall_host_sla_raw >= minimumSla) {
                overallSlaCard.classList.add('bg-success');
            } else if (data.overall_host_sla_raw >= 99.0) {
                overallSlaCard.classList.add('bg-warning');
            } else {
                overallSlaCard.classList.add('bg-danger');
            }

            document.getElementById('summary-report-title').textContent = `SLA Summary for: ${data.host_name}`;
            const tableBody = document.querySelector('#host-sla-table tbody');
            tableBody.innerHTML = '';

            if (data.container_slas.length > 0) {
                data.container_slas.forEach(container => {
                    const minimumSla = parseFloat(<?= json_encode(get_setting('minimum_sla_percentage', '99.9')) ?>);
                    let slaBadgeClass = 'bg-success';
                    if (container.sla_percentage_raw < minimumSla) slaBadgeClass = 'bg-danger';
                    else if (container.sla_percentage_raw < 99.9) slaBadgeClass = 'bg-warning'; // Keep a 'warning' threshold

                    const row = `<tr class="clickable-row" data-container-id="${container.container_id}" title="Click to view detailed PDF report for ${container.container_name}">
                        <td>${container.container_name}</td>
                        <td><span class="badge ${slaBadgeClass}">${container.sla_percentage}%</span></td>
                        <td>${container.total_downtime_human}</td>
                        <td>${container.downtime_incidents}</td>
                    </tr>`;
                    tableBody.innerHTML += row;
                });
            } else {
                tableBody.innerHTML = '<tr><td colspan="4" class="text-center">No container health history found for this host in the selected period.</td></tr>';
            }
            hostSummaryOutput.style.display = 'block';
        }

        function displayGlobalSummaryReport(data) {
            reportData = data; // Store for export

            document.getElementById('global-summary-report-title').textContent = `Global SLA Summary`;
            const tableBody = document.querySelector('#global-sla-table tbody');
            const violationDetailsCard = document.getElementById('violation-details-card');
            tableBody.innerHTML = '';

            if (data.host_slas.length > 0) {
                data.host_slas.forEach(host => {
                    const minimumSla = parseFloat(<?= json_encode(get_setting('minimum_sla_percentage', '99.9')) ?>);
                    let slaBadgeClass = 'bg-success';
                    if (host.overall_host_sla_raw < minimumSla) slaBadgeClass = 'bg-danger';
                    else if (host.overall_host_sla_raw < 99.9) slaBadgeClass = 'bg-warning';

                    const row = `<tr class="clickable-row" data-host-id="${host.host_id}" title="Click to view detailed summary for ${host.host_name}">
                        <td>${host.host_name}</td>
                        <td><span class="badge ${slaBadgeClass}">${host.overall_host_sla}%</span></td>
                        <td><small>${host.host_specs.os}</small></td>
                        <td><small>${host.host_specs.cpus}</small></td>
                        <td><small>${host.host_specs.memory}</small></td>
                        <td>${host.overall_total_downtime_human}</td>
                    </tr>`;
                    tableBody.innerHTML += row;
                });
            } else {
                tableBody.innerHTML = '<tr><td colspan="3" class="text-center">No hosts with health history found in the selected period.</td></tr>';
            }
            globalSummaryOutput.style.display = 'block';

            // --- NEW: Populate Violation Details Table ---
            const violationTableBody = document.querySelector('#violation-details-table tbody');
            violationTableBody.innerHTML = '';
            if (data.violation_details && data.violation_details.length > 0) {
                data.violation_details.forEach(item => {
                    const row = `
                        <tr class="clickable-row" data-host-id="${item.host_id}" data-container-id="${item.container_id}" title="Click to export detailed PDF report">
                            <td>${escapeHtml(item.host_name)}</td>
                            <td><strong>${escapeHtml(item.container_name)}</strong></td>
                            <td><span class="badge bg-danger">${item.sla_percentage}%</span></td>
                            <td>${item.total_downtime_human}</td>
                        </tr>
                    `;
                    violationTableBody.innerHTML += row;
                });
                violationDetailsCard.style.display = 'block';
            } else {
                // Hide the card if there are no violations, even if the global summary is shown
                violationDetailsCard.style.display = 'none';
            }
        }



        // Helper function to prevent XSS
        function escapeHtml(unsafe) {
            if (typeof unsafe !== 'string') return '';
            return unsafe.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
        }

        // Load saved filters on page initialization
        loadFilters();

        // --- View Mode Logic ---
        viewModeStandardBtn.addEventListener('click', () => switchViewMode('standard'));
        viewModeHeatmapBtn.addEventListener('click', () => switchViewMode('heatmap'));
        const savedViewMode = localStorage.getItem('sla_report_view_mode') || 'standard';
        switchViewMode(savedViewMode);

        // Fungsi Ekspor
        function handleExport(format) {
            event.preventDefault();
            if (!form.checkValidity()) {
                showToast('Please generate a report first.', false);
                return;
            }
            const formData = new FormData(form);
            formData.append('report_type', 'sla_report');

            const endpoint = (format === 'pdf') ? '<?= base_url('/api/pdf') ?>' : '<?= base_url('/api/csv') ?>';

            fetch(endpoint, { method: 'POST', body: formData })
                .then(res => {
                    if (!res.ok) {
                        // If the server returns an error, try to read the text to show a better message
                        return res.text().then(text => { throw new Error(text || 'Network response was not ok.') });
                    }
                    if (format === 'pdf') {
                        return res.blob();
                    } else { // csv
                        const header = res.headers.get('Content-Disposition');
                        const parts = header.split(';');
                        const filename = parts[1].split('=')[1].replace(/"/g, '');
                        return res.blob().then(blob => ({ blob, filename }));
                    }
                })
                .then(result => {
                    if (format === 'pdf') {
                        const url = window.URL.createObjectURL(result);
                        window.open(url, '_blank');
                    } else { // csv
                        const { blob, filename } = result;
                        const url = window.URL.createObjectURL(blob);
                        const a = document.createElement('a');
                        a.style.display = 'none';
                        a.href = url;
                        a.download = filename;
                        document.body.appendChild(a);
                        a.click();
                        window.URL.revokeObjectURL(url);
                        a.remove();
                    }
                })
                .catch(err => showToast(`Error exporting ${format.toUpperCase()}: ` + err.message, false));
        }

        document.getElementById('export-csv-btn').addEventListener('click', () => handleExport('csv'));
        document.getElementById('export-pdf-btn').addEventListener('click', () => handleExport('pdf'));
        document.getElementById('export-summary-csv-btn').addEventListener('click', () => handleExport('csv'));
        document.getElementById('export-summary-pdf-btn').addEventListener('click', () => handleExport('pdf'));
        document.getElementById('export-global-summary-csv-btn').addEventListener('click', () => handleExport('csv'));
        document.getElementById('export-global-summary-pdf-btn').addEventListener('click', () => handleExport('pdf'));

        // Event listener for clicking rows in the host summary table
        const hostSlaTableBody = document.querySelector('#host-sla-table tbody');
        hostSlaTableBody.addEventListener('click', function(event) {
            const row = event.target.closest('tr');
            if (!row || !row.dataset.containerId) return;

            const containerId = row.dataset.containerId;
            const hostId = hostSelect.value;
            const dateRange = $('input[name="date_range"]').val();

            // Show loading indicator on the row
            const originalContent = row.innerHTML;
            row.innerHTML = `<td colspan="4" class="text-center"><span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Generating PDF...</td>`;

            const formData = new FormData();
            formData.append('report_type', 'sla_report');
            formData.append('host_id', hostId);
            formData.append('container_id', containerId);
            formData.append('date_range', dateRange);

            fetch('<?= base_url('/api/pdf') ?>', { method: 'POST', body: formData })
                .then(res => {
                    if (!res.ok) throw new Error('Failed to generate PDF.');
                    return res.blob();
                })
                .then(blob => {
                    const url = window.URL.createObjectURL(blob);
                    window.open(url, '_blank');
                })
                .catch(err => showToast('Error exporting PDF: ' + err.message, false))
                .finally(() => row.innerHTML = originalContent); // Restore row content
        });

        // Event listener for clicking rows in the GLOBAL summary table
        const globalSlaTableBody = document.querySelector('#global-sla-table tbody');
        globalSlaTableBody.addEventListener('click', function(event) {
            const row = event.target.closest('tr');
            if (!row || !row.dataset.hostId) return;
 
            const hostId = row.dataset.hostId;
            const dateRange = $('input[name="date_range"]').val();
 
            // Show loading indicator on the row
            const originalContent = row.innerHTML;
            row.innerHTML = `<td colspan="6" class="text-center"><span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Generating PDF...</td>`;
 
            const formData = new FormData();
            formData.append('report_type', 'sla_report');
            formData.append('host_id', hostId);
            formData.append('container_id', 'all'); // For summary report
            formData.append('date_range', dateRange);
 
            fetch('<?= base_url('/api/pdf') ?>', { method: 'POST', body: formData })
                .then(res => {
                    if (!res.ok) throw new Error('Failed to generate PDF.');
                    return res.blob();
                })
                .then(blob => {
                    window.open(window.URL.createObjectURL(blob), '_blank');
                })
                .catch(err => showToast('Error exporting PDF: ' + err.message, false))
                .finally(() => row.innerHTML = originalContent); // Restore row content
        });

        // --- NEW: Event listener for clicking rows in the VIOLATION details table ---
        const violationTableBody = document.querySelector('#violation-details-table tbody');
        violationTableBody.addEventListener('click', function(event) {
            const row = event.target.closest('tr');
            if (!row || !row.dataset.hostId || !row.dataset.containerId) return;

            const hostId = row.dataset.hostId;
            const containerId = row.dataset.containerId;
            const dateRange = $('input[name="date_range"]').val();

            // Show loading indicator on the row
            const originalContent = row.innerHTML;
            row.innerHTML = `<td colspan="4" class="text-center"><span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Generating PDF...</td>`;

            const formData = new FormData();
            formData.append('report_type', 'sla_report');
            formData.append('host_id', hostId);
            formData.append('container_id', containerId);
            formData.append('date_range', dateRange);

            fetch('<?= base_url('/api/pdf') ?>', { method: 'POST', body: formData })
                .then(res => {
                    if (!res.ok) throw new Error('Failed to generate PDF.');
                    return res.blob();
                })
                .then(blob => window.open(window.URL.createObjectURL(blob), '_blank'))
                .catch(err => showToast('Error exporting PDF: ' + err.message, false))
                .finally(() => row.innerHTML = originalContent);
        });

    // --- NEW: Handle pre-filtering from URL ---
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('filter') === 'violations') {
        const downtimeCheckbox = document.getElementById('show_only_downtime');

        hostSelect.value = 'all';
        downtimeCheckbox.checked = true;

        // Use a small timeout to ensure other scripts have initialized before submitting
        setTimeout(() => form.dispatchEvent(new Event('submit')), 50);
    }

    // --- Heatmap Logic (migrated from sla_heatmap.php) ---
    const heatmapHostSelect = document.getElementById('heatmap_host_id');
    const heatmapContainerSelect = document.getElementById('heatmap_container_id');
    const heatmapForm = document.getElementById('heatmap-form');
    const heatmapOutput = document.getElementById('heatmap-output');
    const heatmapContainer = document.getElementById('calendar-heatmap-container');
    const heatmapTitle = document.getElementById('heatmap-title');

    heatmapHostSelect.addEventListener('change', function() {
        const hostId = this.value;
        heatmapContainerSelect.disabled = true;
        heatmapContainerSelect.innerHTML = '<option>Loading...</option>';
        if (!hostId) {
            heatmapContainerSelect.innerHTML = '<option value="">-- Select a host first --</option>';
            return;
        }

        fetch(`<?= base_url('/api/containers/list?host_id=') ?>${hostId}`)
            .then(response => response.json())
            .then(result => {
                if (result.status === 'success' && Array.isArray(result.data)) {
                    let optionsHtml = '<option value="" disabled selected>-- Select an item --</option>';
                    result.data.forEach(item => {
                        const prefix = item.Type === 'service' ? '[S] ' : '[C] ';
                        optionsHtml += `<option value="${item.Id}" data-name="${item.Name}">${prefix}${item.Name}</option>`;
                    });
                    heatmapContainerSelect.innerHTML = optionsHtml;
                    heatmapContainerSelect.disabled = false;
                } else {
                    throw new Error(result.message || 'Invalid data format.');
                }
            })
            .catch(error => {
                heatmapContainerSelect.innerHTML = `<option>Error: ${error.message}</option>`;
                showToast('Failed to load containers.', false);
            });
    });

    heatmapForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const btn = document.getElementById('generate-heatmap-btn');
        const originalBtnText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Generating...`;

        const formData = new FormData(heatmapForm);
        const params = new URLSearchParams(formData);

        fetch(`<?= base_url('/api/sla-heatmap') ?>?${params.toString()}`)
            .then(response => response.json())
            .then(data => {
                if (data.status !== 'success') throw new Error(data.message);
                
                const selectedContainer = heatmapContainerSelect.options[heatmapContainerSelect.selectedIndex];
                const containerName = selectedContainer.dataset.name;
                const [year, month] = formData.get('month_year').split('-');
                
                heatmapTitle.textContent = `SLA Heatmap for: ${containerName}`;
                renderHeatmap(parseInt(year), parseInt(month), data.data);
                heatmapOutput.style.display = 'block';
            })
            .catch(error => showToast(`Error generating heatmap: ${error.message}`, false))
            .finally(() => {
                btn.disabled = false;
                btn.innerHTML = originalBtnText;
            });
    });

    function getSlaColor(sla) {
        if (sla === null) return '#ebedf0'; // No data
        if (sla < 99) return '#1e6823';
        if (sla < 99.9) return '#44a340';
        return '#8cc665';
    }

    function renderHeatmap(year, month, slaData) {
        heatmapContainer.innerHTML = '';
        const date = new Date(year, month - 1, 1);
        const firstDay = date.getDay();
        const daysInMonth = new Date(year, month, 0).getDate();

        const weekdays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        weekdays.forEach(day => {
            const dayEl = document.createElement('div');
            dayEl.className = 'text-center fw-bold small text-muted';
            dayEl.textContent = day;
            heatmapContainer.appendChild(dayEl);
        });

        for (let i = 0; i < firstDay; i++) {
            heatmapContainer.appendChild(document.createElement('div'));
        }

        for (let day = 1; day <= daysInMonth; day++) {
            const dayString = `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
            const sla = slaData[dayString];
            const dayEl = document.createElement('div');
            dayEl.className = 'calendar-day';
            
            let slaText = 'N/A';
            let color = '#ebedf0';
            let textColor = 'inherit';

            if (sla !== undefined && sla !== null) {
                color = getSlaColor(sla);
                slaText = `${sla.toFixed(2)}%`;
                textColor = (sla >= 99.9) ? '#000' : '#fff';
            }

            dayEl.style.backgroundColor = color;
            dayEl.style.color = textColor;
            dayEl.setAttribute('data-bs-toggle', 'tooltip');
            dayEl.setAttribute('title', `SLA on ${dayString}: ${slaText}`);

            dayEl.innerHTML = `<div class="day-number">${day}</div><div class="sla-value">${slaText}</div>`;
            heatmapContainer.appendChild(dayEl);
        }

        const tooltipTriggerList = heatmapContainer.querySelectorAll('[data-bs-toggle="tooltip"]');
        [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl));
    }

};


</script>

<?php
$conn->close();
require_once __DIR__ . '/../includes/footer.php';
?>