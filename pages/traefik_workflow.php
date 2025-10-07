<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-diagram-3"></i> Alur Kerja Manajemen Traefik</h1>
</div>

<div class="card">
    <div class="card-body">
        <p class="card-text">Diagram ini menjelaskan bagaimana Config Manager mengelola file konfigurasi dinamis Traefik (<code>dynamic.yml</code>). Proses ini memastikan bahwa setiap perubahan dicatat dan dapat dilacak.</p>
        <div class="text-center">
            <pre class="mermaid">
sequenceDiagram
    participant User as Pengguna
    participant WebUI as Antarmuka Web
    participant Server as Server Config Manager
    participant Traefik as Traefik Proxy

    User->>WebUI: 1. Melakukan perubahan (CRUD) pada Router/Service
    WebUI->>Server: 2. Simpan perubahan ke Database

    User->>WebUI: 3. Klik "Generate & Deploy"
    WebUI->>Server: 4. Kirim permintaan Deploy

    Server->>Server: 5. Buat konten YAML dari data di Database
    Server->>Server: 6. Simpan YAML ke Riwayat (History)
    Server->>Traefik: 7. Tulis konten YAML ke file `dynamic.yml`

    Traefik->>Traefik: 8. Deteksi perubahan & muat ulang konfigurasi secara otomatis
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