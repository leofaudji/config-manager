<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-diagram-3"></i> Alur Lalu Lintas Aplikasi</h1>
</div>

<div class="card">
    <div class="card-body">
        <p class="card-text">Diagram ini menjelaskan alur lalu lintas sebuah permintaan HTTP dari pengguna hingga mencapai kontainer aplikasi yang dituju, melalui Traefik sebagai reverse proxy.</p>
        <div class="text-center">
            <pre class="mermaid">
sequenceDiagram
    participant User as Pengguna
    participant DNS
    box "Infrastruktur Traefik"
        participant Traefik as Traefik Proxy
    end
    box "Host Docker Target"
        participant Docker as Docker Engine
        participant Container as Kontainer Aplikasi
    end


    User->>DNS: 1. Request: myapp.example.com
    DNS-->>User: 2. Response: IP Address Traefik

    User->>Traefik: 3. HTTP Request (Host: myapp.example.com)

    Traefik->>Traefik: 4. Baca konfigurasi dinamis<br>untuk mencocokkan 'Host Rule'
    Note right of Traefik: Menemukan: myapp.example.com -> service-myapp

    Traefik->>Traefik: 5. Temukan alamat IP & Port<br>dari 'service-myapp'
    Note right of Traefik: Menemukan: 192.168.1.100:8080

    Traefik->>Docker: 6. Teruskan (forward) request ke<br>Host Docker Target (192.168.1.100:8080)

    Docker->>Container: 7. Docker Engine (via port mapping)<br>mengarahkan request ke kontainer aplikasi

    Container->>Container: 8. Aplikasi memproses request

    Container-->>Docker: 9. HTTP Response
    Docker-->>Traefik: 10. HTTP Response
    Traefik-->>User: 11. HTTP Response
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