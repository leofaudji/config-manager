<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-diagram-3"></i> Alur Kerja Deployment CPU Reader</h1>
</div>

<div class="card">
    <div class="card-body">
        <p class="card-text">Diagram ini menjelaskan alur kerja deployment untuk <code>host-cpu-reader</code>. Agen ini adalah kontainer minimalis yang berjalan dengan akses ke proses host (`PidMode: host`), memungkinkan server pusat untuk mengeksekusi perintah seperti `top` di dalamnya untuk mengukur penggunaan CPU host secara keseluruhan.</p>
        <div class="text-center">
            <pre class="mermaid">
sequenceDiagram
    participant User as Pengguna
    participant WebUI as Antarmuka Web
    participant Server as Server Config Manager
    participant DockerHost as Host Docker Target
    participant CPUReader as Kontainer CPU Reader

    alt Deployment (Satu Kali)
        User->>WebUI: 1. Klik "Deploy"
        WebUI->>Server: 2. Kirim permintaan deploy
        Server->>DockerHost: 3. Buat & jalankan kontainer 'host-cpu-reader'
        Note over Server,DockerHost: Kontainer ini pasif (hanya `sleep`)<br>dengan `PidMode: host`
        DockerHost-->>CPUReader: 4. Kontainer mulai berjalan & idle
    end

    loop Setiap 5 Menit
        Server->>Server: 5. Cronjob 'collect_stats.php' berjalan
        Server->>CPUReader: 6. Kirim perintah 'exec' via Docker API<br>(Contoh: `top -bn1`)
        CPUReader-->>Server: 7. Kembalikan hasil (output) dari perintah
        Server->>Server: 8. Simpan statistik CPU ke Database
    end
            </pre>
        </div>
    </div>
</div>

<script type="module">
    import mermaid from 'https://cdn.jsdelivr.net/npm/mermaid@10/dist/mermaid.esm.min.mjs';
    mermaid.initialize({ startOnLoad: true });
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>