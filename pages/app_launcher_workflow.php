<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-diagram-3"></i> Alur Kerja App Launcher</h1>
</div>

<div class="card">
    <div class="card-body">
        <p class="card-text">Diagram ini menjelaskan bagaimana App Launcher men-deploy sebuah aplikasi baru sebagai stack Docker Compose di host Standalone atau Swarm.</p>
        <div class="text-center">
            <pre class="mermaid">
graph TD
    subgraph "Antarmuka Web"
        A["<i class='bi bi-person-fill'></i> Pengguna mengisi form<br>App Launcher"]
    end

    subgraph "Server Config Manager"
        B{Pilih Sumber}
        C1["Clone repo & modifikasi compose"]
        C2["Buat compose baru dari input"]
        C3["Gunakan konten dari editor"]
        D["Simpan file compose ke<br>direktori proyek lokal"]
        E["Jalankan `docker-compose up -d`<br>menargetkan API host remote"]
    end

    subgraph "Host Docker Target"
        F["<i class='bi bi-docker'></i> Docker Daemon"]
        G["<i class='bi bi-box-seam'></i> Kontainer Berjalan"]
    end

    style A fill:#E6E6FA,stroke:#333,stroke-width:2px
    style F fill:#FFFFE0,stroke:#333,stroke-width:2px
    style G fill:#D3D3D3,stroke:#333,stroke-width:2px

    A --> B
    B -- "Git" --> C1 --> D
    B -- "Image / Hub" --> C2 --> D
    B -- "Editor" --> C3 --> D
    D --> E --> F --> G
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