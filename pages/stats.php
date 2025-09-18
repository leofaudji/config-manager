<?php
// Router sudah menangani otentikasi dan otorisasi.
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Router Statistics</h1>
</div>

<div class="card">
    <div class="card-header">
        Jumlah Router per Domain
    </div>
    <div class="card-body" style="height: 65vh;">
        <canvas id="routerStatsChart"></canvas>
    </div>
    <div class="card-footer text-muted small">
        Statistik ini dibuat secara dinamis dengan menghitung domain dasar dari setiap `rule` router yang ada.
    </div>
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>