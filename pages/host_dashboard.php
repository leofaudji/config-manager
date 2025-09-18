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
document.addEventListener('DOMContentLoaded', function() {
    const hostId = <?= $id ?>;

    // Fetch and render the chart
    const ctx = document.getElementById('resourceUsageChart').getContext('2d');
    fetch(`${basePath}/api/hosts/${hostId}/chart-data`)
        .then(response => response.json())
        .then(result => {
            if (result.status === 'success') {
                if (result.data.labels.length === 0) {
                    ctx.font = "16px Arial";
                    ctx.fillText("No historical data available for the last 24 hours.", 10, 50);
                    return;
                }
                new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: result.data.labels,
                        datasets: [{
                            label: 'CPU Usage (%)',
                            data: result.data.cpu_usage,
                            borderColor: 'rgb(75, 192, 192)',
                            tension: 0.1
                        }, {
                            label: 'Memory Usage (%)',
                            data: result.data.memory_usage,
                            borderColor: 'rgb(255, 99, 132)',
                            tension: 0.1
                        }]
                    }
                });
            } else {
                throw new Error(result.message);
            }
        })
        .catch(error => {
            console.error('Error fetching chart data:', error);
            ctx.font = "16px Arial";
            ctx.fillText("An error occurred while loading chart data.", 10, 50);
        });

    // Fetch and render the summary widgets
    fetch(`${basePath}/api/hosts/${hostId}/stats`)
        .then(response => response.json())
        .then(result => {
            if (result.status === 'success') {
                const data = result.data;
                document.getElementById('total-containers-widget').textContent = data.total_containers;
                document.getElementById('running-containers-widget').textContent = data.running_containers;
                document.getElementById('stopped-containers-widget').textContent = data.stopped_containers;
                document.getElementById('total-stacks-widget').textContent = data.total_stacks;
                document.getElementById('total-networks-widget').textContent = data.total_networks;
                document.getElementById('total-images-widget').textContent = data.total_images;
            }
        })
        .catch(error => console.error('Error fetching host dashboard stats:', error));
});
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>