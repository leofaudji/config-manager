<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-diagram-3"></i> Alur Kerja Manajemen Kontainer</h1>
</div>

<div class="card">
    <div class="card-body">
        <p class="card-text">Diagram ini menjelaskan bagaimana Config Manager berinteraksi dengan API Docker di host remote untuk melakukan tindakan pada kontainer, seperti start, stop, dan restart.</p>
        <div class="text-center">
            <pre class="mermaid">
sequenceDiagram
    participant User as Pengguna
    participant WebUI as Antarmuka Web
    participant Server as Server Config Manager
    participant DockerHost as Host Docker Target

    User->>WebUI: 1. Klik tombol aksi (misal: Start) pada kontainer
    WebUI->>Server: 2. Kirim permintaan aksi

    Server->>DockerHost: 3. Ambil kredensial & kirim perintah ke API Docker
    Note over Server,DockerHost: Contoh: POST /containers/{id}/start

    DockerHost-->>Server: 4. Docker daemon mengeksekusi perintah & mengirim respons
    Server-->>WebUI: 5. Teruskan hasil (sukses/gagal)
    WebUI-->>User: 6. Tampilkan notifikasi & perbarui UI
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