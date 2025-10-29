<?php
// Router sudah menangani otentikasi dan otorisasi.
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-bar-chart-line-fill"></i> Global Deployment Statistics</h1>
</div>

<div class="card">
    <div class="card-header">
        All Host Deployments per Day (Last 30 Days)
    </div>
    <div class="card-body" style="height: 65vh;">
        <canvas id="routerStatsChart"></canvas>
    </div>
    <div class="card-footer text-muted small">
        This chart shows the number of stack deployments (created, updated, deleted) across all hosts.
    </div>
</div>

<script>
window.pageInit = function() {
    const ctx = document.getElementById('routerStatsChart').getContext('2d');

    fetch('<?= base_url('/api/stats') ?>')
        .then(response => response.json())
        .then(data => {
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: data.labels,
                    datasets: data.datasets
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        title: {
                            display: true,
                            text: 'Deployment Activity Over Time'
                        },
                    },
                    scales: {
                        x: {
                            stacked: true,
                        },
                        y: {
                            stacked: true,
                            beginAtZero: true
                        }
                    }
                }
            });
        })
        .catch(error => {
            console.error('Error fetching statistics data:', error);
            ctx.font = "16px Arial";
            ctx.fillText("Failed to load chart data.", 10, 50);
        });
};
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>