<?php

require_once 'Spyc.php';

/**
 * Class YamlGenerator
 * Handles fetching configuration data from the database and converting it to a YAML string.
 */
class YamlGenerator
{
    private $conn;

    /**
     * YamlGenerator constructor.
     */
    public function __construct()
    {
        // Get the database connection from the singleton
        $this->conn = Database::getInstance()->getConnection();
    }

    /**
     * Generates the final YAML configuration string.
     * @return string The formatted YAML string.
     */
    public function generate(): string
    {
        $config = [
            'http' => [
                'routers' => $this->getRouters(),
                'services' => $this->getServices(),
                'middlewares' => $this->getMiddlewares(),
            ],
            'serversTransports' => $this->getTransports(),
        ];

        // Filter out empty top-level keys for a cleaner output
        foreach ($config as $key => &$value) {
            if (empty($value)) {
                unset($config[$key]);
            }
        }

        return Spyc::YAMLDump($config, 2, 0);
    }

    private function getRouters(): array
    {
        $routers = [];
        $sql = "SELECT r.*, GROUP_CONCAT(m.name ORDER BY rm.priority) as middleware_names
                FROM routers r
                LEFT JOIN router_middleware rm ON r.id = rm.router_id
                LEFT JOIN middlewares m ON rm.middleware_id = m.id
                GROUP BY r.id
                ORDER BY r.name ASC";
        $result = $this->conn->query($sql);

        while ($router = $result->fetch_assoc()) {
            $routerData = [
                'rule' => $router['rule'],
                'entryPoints' => explode(',', $router['entry_points']),
                'service' => $router['service_name']
            ];

            // Add middlewares if they exist
            if (!empty($router['middleware_names'])) {
                // Append @file provider suffix. This could be made more dynamic in the future.
                $middleware_list = array_map(fn($name) => $name . '@file', explode(',', $router['middleware_names']));
                $routerData['middlewares'] = $middleware_list;
            }

            // Add TLS section if enabled and cert_resolver is set
            if (!empty($router['tls']) && !empty($router['cert_resolver'])) {
                $routerData['tls'] = [
                    'certResolver' => $router['cert_resolver'],
                ];
            }
            $routers[$router['name']] = $routerData;
        }
        return $routers;
    }

    private function getServices(): array
    {
        $services_map = [];
        $services_result = $this->conn->query("SELECT id, name, pass_host_header, load_balancer_method FROM services ORDER BY name ASC");
        if ($services_result && $services_result->num_rows > 0) {
            $all_services = $services_result->fetch_all(MYSQLI_ASSOC);
            $service_ids = array_column($all_services, 'id');

            if (empty($service_ids)) {
                return []; // Tidak ada service, jadi tidak perlu query server.
            }

            // Fetch all servers in one query to avoid N+1 problem
            $servers_by_service_id = [];
            $in_clause = implode(',', array_fill(0, count($service_ids), '?'));
            $types = str_repeat('i', count($service_ids));

            $stmt = $this->conn->prepare("SELECT service_id, url FROM servers WHERE service_id IN ($in_clause)");
            $stmt->bind_param($types, ...$service_ids);
            $stmt->execute();
            $servers_result = $stmt->get_result();

            // REFACTOR: Group all server URLs by their service_id first.
            // This makes the logic clearer and ensures all servers for a service are handled together.
            while ($server = $servers_result->fetch_assoc()) {
                $servers_by_service_id[$server['service_id']][] = ['url' => $server['url']];
            }
            $stmt->close();

            // Build the final services map for YAML conversion.
            foreach ($all_services as $service) {
                // Get the pre-grouped list of servers for the current service.
                $server_list = $servers_by_service_id[$service['id']] ?? [];

                $loadBalancerData = [
                    'passHostHeader' => (bool)$service['pass_host_header'],
                    'servers' => $server_list,
                ];

                // Add method only if it's not the default
                if (isset($service['load_balancer_method']) && $service['load_balancer_method'] !== 'roundRobin') {
                    $loadBalancerData['method'] = $service['load_balancer_method'];
                }

                $services_map[$service['name']] = ['loadBalancer' => $loadBalancerData];
            }
        }
        return $services_map;
    }

    private function getMiddlewares(): array
    {
        $middlewares = [];
        $result = $this->conn->query("SELECT name, type, config_json FROM middlewares ORDER BY name ASC");
        while ($mw = $result->fetch_assoc()) {
            $config = json_decode($mw['config_json'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $middlewares[$mw['name']] = [
                    $mw['type'] => $config
                ];
            }
        }
        return $middlewares;
    }

    private function getTransports(): array
    {
        $transports = [];
        $result = $this->conn->query("SELECT * FROM transports ORDER BY name ASC");
        while ($transport = $result->fetch_assoc()) {
            $transports[$transport['name']] = [
                'insecureSkipVerify' => (bool)$transport['insecure_skip_verify'],
            ];
        }
        return $transports;
    }
}