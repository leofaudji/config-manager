<?php

class AppLauncherHelper
{
    /**
     * Modifies the compose data array with settings from the form.
     *
     * @param array &$compose_data The compose data array (passed by reference).
     * @param array $params An array of parameters from the form (replicas, cpu, memory, etc.).
     * @param array $host The host details from the database.
     * @param bool $is_swarm_manager Whether the host is a swarm manager.
     */
    public static function applyFormSettings(array &$compose_data, array $params, array $host, bool $is_swarm_manager): void
    {
        // Extract params for easier access
        $replicas = $params['replicas'] ?? null;
        $cpu = $params['cpu'] ?? null;
        $memory = $params['memory'] ?? null;
        $network = $params['network'] ?? null;
        $volume_paths = $params['volume_paths'] ?? [];
        $host_port = $params['host_port'] ?? null;
        $container_port = $params['container_port'] ?? null;
        $container_ip = $params['container_ip'] ?? null;
        $stack_name = $params['stack_name'] ?? '';

        if (!isset($compose_data['services']) || !is_array($compose_data['services'])) {
            return;
        }

        $is_first_service = true;
        $num_services = count($compose_data['services']);
        foreach (array_keys($compose_data['services']) as $service_key) {
            // Apply universal settings to all services
            if ($is_swarm_manager) {
                // Ensure deploy section exists
                if (!isset($compose_data['services'][$service_key]['deploy'])) {
                    $compose_data['services'][$service_key]['deploy'] = [];
                }

                // Set/replace resource limits from form
                $compose_data['services'][$service_key]['deploy']['resources']['limits']['cpus'] = (string)$cpu;
                $compose_data['services'][$service_key]['deploy']['resources']['limits']['memory'] = $memory;

                // Set/replace restart policy
                $compose_data['services'][$service_key]['deploy']['restart_policy'] = [
                    'condition' => 'any'
                ];
            } else { // Standalone host
                // Set/replace resource limits from form
                $compose_data['services'][$service_key]['cpus'] = (float)$cpu;
                $compose_data['services'][$service_key]['mem_limit'] = $memory;

                // Set/replace restart policy
                $compose_data['services'][$service_key]['restart'] = 'unless-stopped';
            }

            // For standalone hosts, explicitly set the container name.
            // This avoids the default "{project}_{service}_1" naming convention.
            // This is not recommended for Swarm as it conflicts with scaling.
            if (!$is_swarm_manager) {
                // If there's only one service, name the container after the stack. Otherwise, name it after the service key.
                if ($num_services === 1) {
                    $compose_data['services'][$service_key]['container_name'] = $stack_name;
                } else {
                    $compose_data['services'][$service_key]['container_name'] = $service_key;
                }
            }

            // Also set the hostname. This is generally safe.
            // For single service apps, it matches the stack name. For multi-service, it matches the service key to avoid conflicts.
            if ($num_services === 1) {
                $compose_data['services'][$service_key]['hostname'] = $stack_name;
            } else {
                $compose_data['services'][$service_key]['hostname'] = $service_key;
            }

            // Apply network attachment to all services
            if ($network) {
                $network_key = preg_replace('/[^\w.-]+/', '_', $network);

                if (!isset($compose_data['services'][$service_key]['networks'])) {
                    $compose_data['services'][$service_key]['networks'] = [];
                }
                $current_networks = $compose_data['services'][$service_key]['networks'];

                $is_already_defined = false;
                if (is_array($current_networks)) {
                    if (in_array($network_key, $current_networks, true) || array_key_exists($network_key, $current_networks)) {
                        $is_already_defined = true;
                    }
                }

                if (!$is_already_defined) {
                    // For the first service, if an IP is provided, use the complex object format.
                    if ($is_first_service && $container_ip) {
                        $compose_data['services'][$service_key]['networks'][$network_key] = ['ipv4_address' => $container_ip];
                    } else {
                        // For subsequent services or if no IP is given, use the simple string format.
                        $compose_data['services'][$service_key]['networks'][] = $network_key;
                    }
                }
            }

            // Apply singular settings only to the FIRST service
            if ($is_first_service) {
                if ($is_swarm_manager && $replicas) {
                    if (!isset($compose_data['services'][$service_key]['deploy'])) $compose_data['services'][$service_key]['deploy'] = [];
                    $compose_data['services'][$service_key]['deploy']['replicas'] = $replicas;
                }

            // --- Volume Mapping ---
            if (!empty($volume_paths) && is_array($volume_paths)) {
                foreach ($volume_paths as $volume_map) {
                    $container_path = $volume_map['container'] ?? null;
                    $host_path = $volume_map['host'] ?? null;

                    if ($container_path && $host_path) {
                        if (!str_starts_with($container_path, '/')) {
                            $container_path = '/' . $container_path;
                        }
                        $suffix = preg_replace('/[^\w.-]+/', '_', trim($container_path, '/'));
                        $volume_name = preg_replace('/[^a-zA-Z0-9_.-]/', '', $stack_name) . '_' . ($suffix ?: 'data');

                        if (!isset($compose_data['services'][$service_key]['volumes'])) $compose_data['services'][$service_key]['volumes'] = [];
                        $compose_data['services'][$service_key]['volumes'][] = $volume_name . ':' . $container_path;

                        if (!isset($compose_data['volumes'])) $compose_data['volumes'] = [];
                        $compose_data['volumes'][$volume_name] = [
                            'driver' => 'local',
                            'driver_opts' => [
                                'type' => 'none',
                                'o' => 'bind',
                                'device' => $host_path,
                            ],
                        ];
                    }
                    }
                }
                // Handle port mapping. If a container port is provided in the form, it overrides any existing ports.
                if ($container_port) {
                    // Overwrite any existing ports for the first service.
                    $compose_data['services'][$service_key]['ports'] = [];

                    if ($host_port) {
                        // If host port is specified, create a full mapping.
                        $port_mapping = $host_port . ':' . $container_port;
                    } else {
                        // If only container port is specified, just expose it (maps to a random host port).
                        $port_mapping = (string)$container_port;
                    }
                    $compose_data['services'][$service_key]['ports'][] = $port_mapping;
                }
                $is_first_service = false;
            }
        }

        // Add top-level network definition
        if ($network) {
            $network_key = preg_replace('/[^\w.-]+/', '_', $network);
            if (!isset($compose_data['networks'][$network_key])) {
            if (!isset($compose_data['networks'])) $compose_data['networks'] = [];
                $compose_data['networks'][$network_key] = [
                'name' => $network,
                'external' => true
            ];
            }
        }
    }
}