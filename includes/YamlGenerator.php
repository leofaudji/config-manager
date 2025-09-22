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
    public function generate(int $group_id_filter = 0, bool $ignore_host_filter = false, int $host_id_override = 0): string
    {
        // Get the active Traefik host ID from settings.
        // A value of 0 or null means generate a global config.
        $active_traefik_host_id = (int)get_setting('active_traefik_host_id', 1);

        // If an override is provided from the deploy script, use it.
        if ($host_id_override > 0) {
            $active_traefik_host_id = $host_id_override;
        }

        // 1. Get routers based on filters
        $routers = $this->getRouters($active_traefik_host_id, $group_id_filter, $ignore_host_filter);

        // 2. Collect required service and middleware names from the filtered routers
        $required_service_names = [];
        $required_middleware_names = [];
        foreach ($routers as $router_data) {
            if (!empty($router_data['service'])) {
                $required_service_names[] = $router_data['service'];
            }
            if (!empty($router_data['middlewares'])) {
                foreach ($router_data['middlewares'] as $mw_name_with_provider) {
                    $required_middleware_names[] = str_replace('@file', '', $mw_name_with_provider);
                }
            }
        }
        $required_service_names = array_unique($required_service_names);
        $required_middleware_names = array_unique($required_middleware_names);

        // 3. Get the definitions for only the required services and middlewares.
        $services = $this->getServicesByName($required_service_names);
        $middlewares = $this->getMiddlewaresByName($required_middleware_names);

        $config = [
            'http' => [
                'routers' => $routers,
                'services' => $services,
                'middlewares' => $middlewares,
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

    private function getRouters(int $traefik_host_id = 1, int $group_id_filter = 0, bool $ignore_host_filter = false): array
    {
        $routers = [];

        $where_conditions = [];
        $params = [];
        $types = '';

        if (!$ignore_host_filter) {
            $where_conditions[] = "(g.traefik_host_id = ? OR g.traefik_host_id IS NULL OR g.traefik_host_id = 0)";
            $params[] = $traefik_host_id;
            $types .= 'i';
        }

        if ($group_id_filter > 0) {
            $where_conditions[] = "r.group_id = ?";
            $params[] = $group_id_filter;
            $types .= 'i';
        }

        $where_clause = !empty($where_conditions) ? "WHERE " . implode(' AND ', $where_conditions) : "";

        $sql = "SELECT r.*, GROUP_CONCAT(m.name ORDER BY rm.priority) as middleware_names
                    FROM routers r
                    LEFT JOIN router_middleware rm ON r.id = rm.router_id
                    LEFT JOIN middlewares m ON rm.middleware_id = m.id
                    LEFT JOIN `groups` g ON r.group_id = g.id "
                    . $where_clause .
                    "
                    GROUP BY r.id
                    ORDER BY r.name ASC";
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("Failed to prepare statement for getRouters: " . $this->conn->error);
        }
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();

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

    private function getServicesByName(array $service_names): array
    {
        if (empty($service_names)) {
            return [];
        }

        $services_map = [];
        
        $in_clause_svc = implode(',', array_fill(0, count($service_names), '?'));
        $types_svc = str_repeat('s', count($service_names));

        $sql = "SELECT id, name, pass_host_header, load_balancer_method FROM services WHERE name IN ($in_clause_svc) ORDER BY name ASC";
        $stmt_services = $this->conn->prepare($sql);
        if (!$stmt_services) {
            throw new Exception("Failed to prepare statement for getServices: " . $this->conn->error);
        }
        $stmt_services->bind_param($types_svc, ...$service_names);
        $stmt_services->execute();
        $services_result = $stmt_services->get_result();

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

    private function getMiddlewaresByName(array $middleware_names): array
    {
        if (empty($middleware_names)) {
            return [];
        }

        $middlewares = [];
        $in_clause_mw = implode(',', array_fill(0, count($middleware_names), '?'));
        $types_mw = str_repeat('s', count($middleware_names));

        $sql = "SELECT name, type, config_json FROM middlewares WHERE name IN ($in_clause_mw) ORDER BY name ASC";
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("Failed to prepare statement for getMiddlewares: " . $this->conn->error);
        }
        $stmt->bind_param($types_mw, ...$middleware_names);
        $stmt->execute();
        $result = $stmt->get_result();
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