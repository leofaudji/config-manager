<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-diagram-3"></i> Alur Kerja Arsitektur Aplikasi</h1>
</div>

<div class="card">
    <div class="card-body">
        <p class="card-text">Diagram ini memberikan gambaran tingkat tinggi tentang bagaimana komponen utama Config Manager berinteraksi satu sama lain dan dengan infrastruktur yang dikelolanya.</p>
        <div class="text-center">
            <pre class="mermaid">
graph TD
    subgraph "Pengguna"
        A["<i class='bi bi-person-fill'></i> Pengguna (Admin/Viewer)"]
    end

    subgraph "Infrastruktur Terkelola"
        C["<i class='bi bi-bezier2'></i> Traefik Proxy"]
        subgraph Docker Hosts
            D["<i class='bi bi-docker'></i> Docker Daemon"]
            E["<i class='bi bi-heart-pulse-fill'></i> Health Agent"]
            F["<i class='bi bi-stack'></i> Deployed Apps (Stacks)"]
        end
    end

    subgraph "Aplikasi Utama"
        B["<i class='bi bi-display-fill'></i> Config Manager<br>(PHP + MySQL)"]
    end

    A -- "Mengelola via Web UI" --> B
    B -- "Menghasilkan `dynamic.yml`" --> C
    B -- "Mengelola via Docker API" --> D
    B -- "Men-deploy & Mengelola" --> F
    B -- "Men-deploy & Mengonfigurasi" --> E
    E -- "Melaporkan Status Kesehatan" --> B
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