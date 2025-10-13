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
        $placement_constraint = $params['deploy_placement_constraint'] ?? null;
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
                $compose_data['services'][$service_key]['deploy'] = $compose_data['services'][$service_key]['deploy'] ?? [];

                // Set/replace resource limits from form
                if ($cpu || $memory) {
                    $compose_data['services'][$service_key]['deploy']['resources']['limits'] = [];
                    if ($cpu) {
                        // Explicitly cast to string to satisfy Docker Swarm's requirement (e.g., "1.5" not 1.5)
                        $compose_data['services'][$service_key]['deploy']['resources']['limits']['cpus'] = (string)$cpu;
                    }
                    if ($memory) $compose_data['services'][$service_key]['deploy']['resources']['limits']['memory'] = $memory;
                }

                // Set/replace restart policy
                $compose_data['services'][$service_key]['deploy']['restart_policy'] = [
                    'condition' => 'any'
                ];

                // Apply placement constraint if specified
                if ($placement_constraint) {
                    $compose_data['services'][$service_key]['deploy']['placement']['constraints'] = [$placement_constraint];
                }
            } else { // Standalone host
                // For standalone, resource limits are at the top level of the service
                if ($cpu) {
                    $compose_data['services'][$service_key]['cpus'] = (float)$cpu;
                }
                if ($memory) {
                    $compose_data['services'][$service_key]['mem_limit'] = $memory;
                }

                // Set restart policy only if it's not already defined in the compose file.
                if (!isset($compose_data['services'][$service_key]['restart'])) {
                    $compose_data['services'][$service_key]['restart'] = 'unless-stopped';
                }
            }

            // For standalone hosts, explicitly set the container name.
            // This avoids the default "{project}_{service}_1" naming convention.
            // This is not recommended for Swarm as it conflicts with scaling.
            if (!$is_swarm_manager) {
                // Always set the container name based on the stack and service key to ensure uniqueness and clarity.
                $compose_data['services'][$service_key]['container_name'] = $stack_name . '_' . $service_key;
            }

            // Also set the hostname. This is generally safe.
            // For single service apps, it matches the stack name. For multi-service, it matches the service key to avoid conflicts.
            if ($num_services === 1) {
                $compose_data['services'][$service_key]['hostname'] = $stack_name;
            } else {
                $compose_data['services'][$service_key]['hostname'] = $service_key;
            }

            // Ensure that if a hostname is set, the service is attached to at least one network.
            // If no specific network is chosen, attach it to the default network to support the alias.
            if (isset($compose_data['services'][$service_key]['hostname'])) {
                // Only create a default network if NO other network is specified by the user or in the compose file.
                // Also, do not create a default network if the user intended to use 'ingress' or the default 'bridge'.
                if (empty($network) && !isset($compose_data['services'][$service_key]['networks'])) {
                    if (!isset($compose_data['networks']['default'])) {
                        // Define a default network that allows inter-container communication (including ping)
                        $compose_data['networks']['default'] = [
                            'driver_opts' => [
                                'com.docker.network.bridge.enable_icc' => 'true ', // Add space to force string type in YAML
                                'com.docker.network.bridge.enable_ip_masquerade' => 'true ', // Add space
                            ]
                        ];
                    }
                    // Explicitly attach the service to the default network to support the hostname alias.
                    $compose_data['services'][$service_key]['networks'] = ['default'];
                }
            }
            // Apply network attachment to all services
            // Only apply network settings if a specific, non-default network is chosen.
            if ($network && $network !== 'bridge' && $network !== 'ingress') {
                $network_key = preg_replace('/[^\w.-]+/', '_', $network);

                if (!isset($compose_data['services'][$service_key]['networks'])) {
                    $compose_data['services'][$service_key]['networks'] = [];
                }
                $current_networks =& $compose_data['services'][$service_key]['networks'];

                // Ensure we are working with a map (associative array) for consistency.
                // If the original compose file used a list format, convert it.
                if (is_array($current_networks) && array_is_list($current_networks)) {
                    $map = [];
                    foreach ($current_networks as $net_name) {
                        $map[$net_name] = null;
                    }
                    $current_networks = $map;
                }

                // Now we can safely assume it's a map. Check if the network is already attached.
                if (!array_key_exists($network_key, $current_networks)) {
                    // For the first service, if an IP is provided, use the complex object format.
                    if ($is_first_service && !empty($container_ip)) {
                        $current_networks[$network_key] = ['ipv4_address' => $container_ip];
                    } else {
                        // For subsequent services or if no IP is given, use the map format with a null value.
                        $current_networks[$network_key] = null;
                    }
                }
                unset($current_networks); // Unset reference
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
                            // Generate volume name based on container path only. Docker-compose will prefix it with the project name.
                            $volume_name = preg_replace('/[^\w.-]+/', '_', trim($container_path, '/')) ?: 'data';

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

                $is_first_service = false;
            }
        }

        // --- Port & Expose Mapping (Applied to all services for simplicity) ---
        // This is applied after the loop to ensure it's not tied to is_first_service
        if ($container_port) {
            foreach (array_keys($compose_data['services']) as $service_key) {
                // Overwrite any existing ports for the service.
                $compose_data['services'][$service_key]['ports'] = [];

                if ($host_port) {
                    // If host port is specified, create a full mapping.
                    $port_mapping = $host_port . ':' . $container_port;
                } else {
                    // If only container port is specified, just expose it (maps to a random host port).
                    $port_mapping = (string)$container_port;
                }
                $compose_data['services'][$service_key]['ports'][] = $port_mapping;
                // Also add to expose for better health agent discovery
                if (!isset($compose_data['services'][$service_key]['expose'])) {
                    $compose_data['services'][$service_key]['expose'] = [];
                }
                if (!in_array((string)$container_port, $compose_data['services'][$service_key]['expose'])) {
                    $compose_data['services'][$service_key]['expose'][] = (string)$container_port;
                }
            }
        }

        // Add top-level network definition
        // Only add the top-level network if it's a specific, non-default network.
        if ($network && $network !== 'bridge' && $network !== 'ingress') {
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