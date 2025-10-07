<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-diagram-3"></i> Alur Kerja Deployment Health Agent</h1>
</div>

<div class="card">
    <div class="card-body">
        <p class="card-text">Diagram ini menjelaskan alur kerja lengkap untuk deployment <code>health-agent</code>, mulai dari interaksi pengguna di antarmuka web hingga agen yang berjalan mandiri di host target dan melaporkan status kembali ke server.</p>
        <div class="text-center">
            <pre class="mermaid">
sequenceDiagram
    participant User as Pengguna
    participant WebUI as Antarmuka Web
    participant Server as Server Config Manager
    participant DockerHost as Host Docker Target
    participant Agent as Kontainer Health Agent

    alt Deployment (Satu Kali)
        User->>WebUI: 1. Klik "Deploy"
        WebUI->>Server: 2. Kirim permintaan deploy
        Server->>DockerHost: 3. Hapus agen lama & tarik image baru
        Server->>DockerHost: 4. Buat & jalankan kontainer agen baru
        Note over Server,DockerHost: Menginjeksikan skrip & perintah cron
        DockerHost-->>Agent: 5. Kontainer agen mulai berjalan
    end

    loop Setiap 1 Menit
        Agent->>Agent: 6. Cronjob terpicu
        Agent->>DockerHost: 7. Periksa kesehatan semua kontainer lokal
        Agent->>Server: 8. Kirim laporan kesehatan (Push)
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