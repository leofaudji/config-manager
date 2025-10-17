<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/header.php';

$conn = Database::getInstance()->getConnection();
$hosts_result = $conn->query("SELECT id, name FROM docker_hosts ORDER BY name ASC");
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-clipboard-data"></i> Service Level Agreement (SLA) Report</h1>
</div>

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
                        <?php while ($host = $hosts_result->fetch_assoc()): ?>
                            <option value="<?= $host['id'] ?>"><?= htmlspecialchars($host['name']) ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label for="container_id" class="form-label">Container</label>
                    <select class="form-select" id="container_id" name="container_id" disabled>
                        <option value="">-- Select a host first --</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="date_range" class="form-label">Date Range</label>
                    <input type="text" class="form-control" id="date_range" name="date_range" required>
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

<script>
window.pageInit = function() {
    // Karena skrip sekarang dimuat secara global di header, kita bisa langsung inisialisasi.
    
    const hostSelect = document.getElementById('host_id');
    const containerSelect = document.getElementById('container_id');
    const form = document.getElementById('sla-report-form');
    const reportOutput = document.getElementById('report-output');
    const singleContainerOutput = document.getElementById('single-container-output');
    const hostSummaryOutput = document.getElementById('host-summary-output');
    let reportData = {}; // Holds data for export functions
    const resetFiltersBtn = document.getElementById('reset-filters-btn');

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
        localStorage.setItem('sla_report_date_range', $('input[name="date_range"]').val());
    }

    function loadFilters() {
        const savedHostId = localStorage.getItem('sla_report_host_id');
        const savedContainerId = localStorage.getItem('sla_report_container_id');
        const savedDateRange = localStorage.getItem('sla_report_date_range');

        if (savedDateRange) {
            const dates = savedDateRange.split(' - ');
            $('input[name="date_range"]').data('daterangepicker').setStartDate(dates[0]);
            $('input[name="date_range"]').data('daterangepicker').setEndDate(dates[1]);
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
        localStorage.removeItem('sla_report_date_range');

        form.reset();
        hostSelect.value = '';
        containerSelect.innerHTML = '<option value="">-- Select a host first --</option>';
        containerSelect.disabled = true;
        singleContainerOutput.style.display = 'none';
        hostSummaryOutput.style.display = 'none';

        // Reset date picker to default (This Month)
        $('input[name="date_range"]').data('daterangepicker').setStartDate(moment().startOf('month'));
        $('input[name="date_range"]').data('daterangepicker').setEndDate(moment().endOf('month'));
    }

    hostSelect.addEventListener('change', function(event) {
        const hostId = this.value;
        containerSelect.disabled = true;
        containerSelect.innerHTML = '<option>Loading containers...</option>';
        if (!hostId) return;

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

                if (data.data.report_type === 'single_container') {
                    displaySingleContainerReport(data.data);
                } else if (data.data.report_type === 'host_summary') {
                    displayHostSummaryReport(data.data);
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
                    const row = `<tr>
                        <td>${incident.start_time || 'N/A'}</td>
                        <td>${incident.end_time || 'Ongoing'}</td>
                        <td>${incident.duration_human || '-'}</td>
                        <td><span class="badge text-bg-danger">${incident.status}</span></td>
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

                    const row = `<tr>
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

        // Helper function to prevent XSS
        function escapeHtml(unsafe) {
            if (typeof unsafe !== 'string') return '';
            return unsafe.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
        }

        // Load saved filters on page initialization
        loadFilters();


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
};
</script>

<?php
$conn->close();
require_once __DIR__ . '/../includes/footer.php';
?>