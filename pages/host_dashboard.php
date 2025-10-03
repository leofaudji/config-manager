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
$active_page = 'dashboard';
require_once __DIR__ . '/../includes/host_nav.php';
?>

<!-- Summary Widgets -->
<div class="row mb-4">
    <div class="col-lg-4 col-md-6 mb-3">
        <a href="<?= base_url('/hosts/' . $id . '/containers') ?>" class="text-decoration-none">
            <div class="card text-white bg-primary h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="card-title mb-0" id="total-containers-widget">...</h3>
                            <p class="card-text mb-0">Total Containers</p>
                        </div>
                        <i class="bi bi-box-seam fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-lg-4 col-md-6 mb-3">
        <a href="<?= base_url('/hosts/' . $id . '/containers') ?>" class="text-decoration-none">
            <div class="card text-white bg-success h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="card-title mb-0" id="running-containers-widget">...</h3>
                            <p class="card-text mb-0">Running</p>
                        </div>
                        <i class="bi bi-play-circle fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-lg-4 col-md-6 mb-3">
        <a href="<?= base_url('/hosts/' . $id . '/containers') ?>" class="text-decoration-none">
            <div class="card text-white bg-danger h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="card-title mb-0" id="stopped-containers-widget">...</h3>
                            <p class="card-text mb-0">Stopped</p>
                        </div>
                        <i class="bi bi-stop-circle fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-lg-4 col-md-6 mb-3">
        <a href="<?= base_url('/hosts/' . $id . '/stacks') ?>" class="text-decoration-none">
            <div class="card text-white bg-info h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="card-title mb-0" id="total-stacks-widget">...</h3>
                            <p class="card-text mb-0">Application Stacks</p>
                        </div>
                        <i class="bi bi-stack fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-lg-4 col-md-6 mb-3">
        <a href="<?= base_url('/hosts/' . $id . '/networks') ?>" class="text-decoration-none">
            <div class="card text-white bg-secondary h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="card-title mb-0" id="total-networks-widget">...</h3>
                            <p class="card-text mb-0">Networks</p>
                        </div>
                        <i class="bi bi-diagram-3 fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-lg-4 col-md-6 mb-3">
        <a href="<?= base_url('/hosts/' . $id . '/images') ?>" class="text-decoration-none">
            <div class="card text-white bg-dark h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="card-title mb-0" id="total-images-widget">...</h3>
                            <p class="card-text mb-0">Images</p>
                        </div>
                        <i class="bi bi-box-seam fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
        </a>
    </div>
</div>

<!-- Stats Chart -->
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Resource Usage (Last 24 Hours)</h5>
            </div>
            <div class="card-body" style="height: 40vh;">
                <canvas id="resourceUsageChart"></canvas>
            </div>
        </div>
    </div>
</div>

<script>
(function() { // IIFE to ensure script runs on AJAX load
    const hostId = <?= $id ?>;

    // Fetch all dashboard data (widgets and chart) in a single request
    fetch(`${basePath}/api/hosts/${hostId}/stats`)
        .then(response => response.json())
        .then(result => {
            if (result.status === 'success') {
                const data = result.data;

                // 1. Populate Widgets
                document.getElementById('total-containers-widget').textContent = data.total_containers;
                document.getElementById('running-containers-widget').textContent = data.running_containers;
                document.getElementById('stopped-containers-widget').textContent = data.stopped_containers;
                document.getElementById('total-stacks-widget').textContent = data.total_stacks;
                document.getElementById('total-networks-widget').textContent = data.total_networks;
                document.getElementById('total-images-widget').textContent = data.total_images;

                // 2. Populate Chart
                const ctx = document.getElementById('resourceUsageChart').getContext('2d');
                const chartData = data.chart_data;

                if (!chartData || chartData.labels.length === 0) {
                    ctx.font = "16px Arial";
                    ctx.fillText("No historical data available for the last 24 hours.", 10, 50);
                    return;
                }
                new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: chartData.labels,
                        datasets: [
                            {
                                label: 'Host CPU Usage (%)',
                                data: chartData.host_cpu_usage,
                                borderColor: 'rgb(255, 159, 64)',
                                backgroundColor: 'rgba(255, 159, 64, 0.2)',
                                fill: true,
                                tension: 0.4,
                                pointRadius: 2,
                                pointHoverRadius: 5,
                                yAxisID: 'y' // Associate with the main Y-axis (0-100%)
                            },
                            {
                                label: 'Container CPU Usage (%)',
                                data: chartData.container_cpu_usage,
                                borderColor: 'rgb(75, 192, 192)',
                                backgroundColor: 'rgba(75, 192, 192, 0.2)',
                                fill: true,
                                tension: 0.4,
                                pointRadius: 2,
                                pointHoverRadius: 5,
                                yAxisID: 'y1' // Associate with the secondary Y-axis
                            }, 
                            {
                                label: 'Memory Usage (%)',
                                data: chartData.memory_usage,
                                borderColor: 'rgb(255, 99, 132)',
                                backgroundColor: 'rgba(255, 99, 132, 0.2)',
                                fill: true,
                                tension: 0.4,
                                pointRadius: 2,
                                pointHoverRadius: 5,
                                yAxisID: 'y' // Associate with the main Y-axis (0-100%)
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                type: 'linear',
                                display: true,
                                position: 'left',
                                beginAtZero: true,
                                max: 100, // Set a fixed max for percentage
                                ticks: {
                                    // Include a '%' sign in the ticks
                                    callback: function(value) {
                                        return value + '%'
                                    }
                                }
                            },
                            y1: {
                                type: 'linear',
                                display: true,
                                position: 'right',
                                grid: {
                                    drawOnChartArea: false, // only want the grid lines for one axis to show up
                                }
                            }
                        },
                        interaction: {
                            mode: 'index',
                            intersect: false,
                        }
                    },
                });
            } else {
                throw new Error(result.message);
            }
        })
        .catch(error => {
            console.error('Error fetching host dashboard data:', error);
            // Show error on chart
            const ctx = document.getElementById('resourceUsageChart').getContext('2d');
            ctx.font = "16px Arial";
            ctx.fillText("An error occurred while loading dashboard data.", 10, 50);
            // Show error on widgets
            ['total-containers-widget', 'running-containers-widget', 'stopped-containers-widget', 'total-stacks-widget', 'total-networks-widget', 'total-images-widget'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.textContent = 'Error';
            });
        });
})();
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>