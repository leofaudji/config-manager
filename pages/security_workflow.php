<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-shield-lock-fill"></i> Alur Kerja Keamanan Falco</h1>
</div>

<div class="card">
    <div class="card-body">
        <p class="card-text">Diagram ini menjelaskan bagaimana Config Manager berintegrasi dengan Falco dan Falcosidekick untuk mendeteksi, meneruskan, dan menampilkan peristiwa keamanan secara real-time.</p>
        <div class="text-center">
            <pre class="mermaid">
sequenceDiagram
    participant User as Pengguna
    participant WebUI as Antarmuka Web
    participant DockerHost as Host Docker Target
    participant Falco as Falco Sensor
    participant Falcosidekick as Falcosidekick
    participant Server as Server Config Manager
    participant DB as Database

    User->>WebUI: 1. Deploy Falco & Falcosidekick
    WebUI->>DockerHost: 2. Buat & jalankan kontainer
    DockerHost->>Falco: 3. Falco memonitor aktivitas sistem
    Note over Falco: Aktivitas mencurigakan terdeteksi! (misal: shell di kontainer)
    Falco->>Falcosidekick: 4. Kirim alert via gRPC
    Falcosidekick->>Server: 5. Teruskan alert via Webhook (ke /api/security/ingest)
    Server->>DB: 6. Simpan event ke tabel `security_events`
    Server->>User: 7. Kirim notifikasi (jika prioritas tinggi)
    User->>WebUI: 8. Lihat detail event di halaman "Security Events"
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