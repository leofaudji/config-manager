<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$service_id = $_GET['service_id'] ?? null;
if (!$service_id) {
    die("Service ID is required.");
}

$conn = Database::getInstance()->getConnection();

// Ambil detail service dan router
$stmt_service = $conn->prepare("SELECT s.name as service_name, r.id as router_id, r.rule as router_rule FROM services s JOIN routers r ON s.name = r.service_name WHERE s.id = ? LIMIT 1");
$stmt_service->bind_param("i", $service_id);
$stmt_service->execute();
$service_info = $stmt_service->get_result()->fetch_assoc();
$stmt_service->close();

if (!$service_info) {
    die("Service or associated router not found.");
}

// Ambil daftar server untuk service ini
$stmt_servers = $conn->prepare("SELECT url FROM servers WHERE service_id = ? ORDER BY url ASC");
$stmt_servers->bind_param("i", $service_id);
$stmt_servers->execute();
$servers_result = $stmt_servers->get_result();
$servers = $servers_result->fetch_all(MYSQLI_ASSOC);
$stmt_servers->close();

// Ambil daftar middleware untuk router ini
$stmt_middlewares = $conn->prepare("
    SELECT m.name 
    FROM middlewares m
    JOIN router_middleware rm ON m.id = rm.middleware_id
    WHERE rm.router_id = ?
    ORDER BY rm.priority ASC
");
$stmt_middlewares->bind_param("i", $service_info['router_id']);
$stmt_middlewares->execute();
$middlewares_result = $stmt_middlewares->get_result();
$middlewares = $middlewares_result->fetch_all(MYSQLI_ASSOC);
$stmt_middlewares->close();
$conn->close();

function generateTrafficFlowDiagram($routerRule, $serviceName, $servers, $middlewares) {
    $routerRule = htmlspecialchars($routerRule, ENT_QUOTES, 'UTF-8');
    $serviceName = htmlspecialchars($serviceName, ENT_QUOTES, 'UTF-8');
    $middleware_list = !empty($middlewares) ? implode(', ', array_column($middlewares, 'name')) : 'None';

    if (count($servers) > 1) {
        // Load Balancing
        $serverNodes = '';
        $serverLinks = '';
        foreach ($servers as $index => $server) {
            $serverId = 'S_' . ($index + 1);
            $sanitizedUrl = htmlspecialchars($server['url'], ENT_QUOTES, 'UTF-8');
            $serverNodes .= "    {$serverId}[\"<i class='bi bi-hdd'></i> {$sanitizedUrl}\"]:::server\n";
            $serverLinks .= "    LB -- \"Load Balance\" --> {$serverId}\n";
        }
        return <<<MERMAID
graph TD
    subgraph "Pengguna"
        User["<i class='bi bi-person-fill'></i> User"]
    end
    subgraph "Infrastruktur Traefik"
        Traefik["<i class='bi bi-sign-turn-right-fill'></i> Traefik Proxy<br/><small>Rule: {$routerRule}</small>"]
        Middlewares["<i class='bi bi-sliders'></i> Middlewares<br/><small>{$middleware_list}</small>"]
        LB(("<i class='bi bi-distribute-horizontal'></i><br>Load Balancer"))
    end
    subgraph "Host(s) Docker"
{$serverNodes}
    end

    classDef server fill:#D3D3D3,stroke:#333,stroke-width:2px,color:#000

    User -- "HTTP Request" --> Traefik
    Traefik -- "Apply Middlewares" --> Middlewares
    Middlewares -- "Route to '{$serviceName}'" --> LB
{$serverLinks}
MERMAID;
    } else {
        // Single Server
        $serverUrl = count($servers) > 0 ? htmlspecialchars($servers[0]['url'], ENT_QUOTES, 'UTF-8') : 'No Server Configured';
        return <<<MERMAID
sequenceDiagram
    participant User as Pengguna
    participant Traefik as Traefik Proxy
    participant Middlewares as Middlewares
    participant Container as Kontainer<br>{$serverUrl}

    User->>Traefik: 1. Request (Host: {$routerRule})
    Traefik->>Traefik: 2. Match Rule -> service '{$serviceName}'
    Traefik->>Middlewares: 3. Apply Middlewares<br>({$middleware_list})
    Middlewares->>Container: 4. Forward Request
    Container-->>Middlewares: 5. Response
    Middlewares-->>Traefik: 6. Response
    Traefik-->>User: 7. Response
MERMAID;
    }
}

$diagram_code = generateTrafficFlowDiagram($service_info['router_rule'], $service_info['service_name'], $servers, $middlewares);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-diagram-3"></i> Alur Lalu Lintas untuk Service: <?= htmlspecialchars($service_info['service_name']) ?></h1>
</div>

<div class="card">
    <div class="card-body">
        <div class="text-center">
            <pre class="mermaid">
                <?= $diagram_code ?>
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