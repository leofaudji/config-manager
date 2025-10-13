<?php

class DockerClient
{
    private string $apiUrl;
    private bool $tlsEnabled;
    private ?string $caCertPath;
    private ?string $clientCertPath;
    private ?string $clientKeyPath;
    private array $host;

    /**
     * @param array $host An associative array of host details from the database.
     */
    public function __construct(array $host)
    {
        if (empty($host['docker_api_url'])) {
            throw new InvalidArgumentException("Docker API URL is required.");
        }

        $this->host = $host;
        $this->apiUrl = ($host['tls_enabled'] ? 'https://' : 'http://') . str_replace('tcp://', '', $host['docker_api_url']);
        $this->tlsEnabled = (bool)$host['tls_enabled'];

        if ($this->tlsEnabled) {
            if (empty($host['ca_cert_path']) || empty($host['client_cert_path']) || empty($host['client_key_path'])) {
                throw new InvalidArgumentException("All TLS certificate paths are required when TLS is enabled.");
            }
            if (!file_exists($host['ca_cert_path']) || !file_exists($host['client_cert_path']) || !file_exists($host['client_key_path'])) {
                throw new RuntimeException("One or more TLS certificate files not found on the application server.");
            }
            $this->caCertPath = $host['ca_cert_path'];
            $this->clientCertPath = $host['client_cert_path'];
            $this->clientKeyPath = $host['client_key_path'];
        }
    }

    /**
     * Inspects a single container to get its full details.
     * @param string $containerId The ID of the container.
     * @return array The container details.
     * @throws Exception
     */
    public function inspectContainer(string $containerId): array
    {
        return $this->request("/containers/{$containerId}/json");
    }

    /**
     * Lists all containers.
     * @return array The list of containers.
     * @throws Exception
     */
    public function listContainers(): array
    {
        return $this->request('/containers/json?all=1');
    }

    /**
     * Starts a container.
     * @param string $containerId The ID of the container.
     * @return bool True on success.
     * @throws Exception
     */
    public function startContainer(string $containerId): bool
    {
        return $this->request("/containers/{$containerId}/start", 'POST');
    }

    /**
     * Stops a container.
     * @param string $containerId The ID of the container.
     * @return bool True on success.
     * @throws Exception
     */
    public function stopContainer(string $containerId): bool
    {
        return $this->request("/containers/{$containerId}/stop", 'POST');
    }

    /**
     * Restarts a container.
     * @param string $containerId The ID of the container.
     * @return bool True on success.
     * @throws Exception
     */
    public function restartContainer(string $containerId): bool
    {
        return $this->request("/containers/{$containerId}/restart", 'POST');
    }

    /**
     * Removes a container.
     * @param string $containerIdOrName The ID or name of the container.
     * @param bool $force If true, the container will be stopped before removal.
     * @return bool True on success.
     * @throws Exception
     */
    public function removeContainer(string $containerIdOrName, bool $force = false): bool
    {
        $params = $force ? '?force=true' : '';
        $this->request("/containers/{$containerIdOrName}{$params}", 'DELETE');
        return true; // Exception is thrown on failure
    }

    /**
     * Prunes unused containers.
     * @return array The response from the API, including containers deleted and space reclaimed.
     * @throws Exception
     */
    public function pruneContainers(): array
    {
        // The prune endpoint doesn't require a body
        return $this->request('/containers/prune', 'POST');
    }

    /**
     * Lists all networks.
     * @return array The list of networks.
     * @throws Exception
     */
    public function listNetworks(): array
    {
        return $this->request('/networks');
    }

    /**
     * Creates a new network.
     * @param array $config The network configuration.
     * @return array The response from the API.
     * @throws Exception
     */
    public function createNetwork(array $config): array
    {
        return $this->request('/networks/create', 'POST', $config);
    }

    /**
     * Ensures a specific network exists on the Docker host, creating it if necessary.
     * @param string $networkName The name of the network to ensure.
     * @return void
     * @throws Exception
     */
    public function ensureNetworkExists(string $networkName): void
    {
        try {
            // Try to inspect the network. If it fails with a 404, it doesn't exist.
            $this->request("/networks/{$networkName}");
        } catch (RuntimeException $e) {
            if (strpos($e->getMessage(), '404') !== false) {
                // Network not found, so create it.
                $this->createNetwork(['Name' => $networkName, 'Driver' => 'bridge']);
            } else {
                throw $e; // Re-throw other errors
            }
        }
    }


    /**
     * Removes a network.
     * @param string $networkIdOrName The ID or name of the network.
     * @return bool True on success.
     * @throws Exception
     */
    public function removeNetwork(string $networkIdOrName): bool
    {
        return $this->request("/networks/{$networkIdOrName}", 'DELETE');
    }

    /**
     * Prunes unused networks.
     * @return array The response from the API, including networks deleted.
     * @throws Exception
     */
    public function pruneNetworks(): array
    {
        // The prune endpoint doesn't require a body
        return $this->request('/networks/prune', 'POST');
    }

    /**
     * Removes an image.
     * @param string $imageIdOrName The ID or name of the image.
     * @return bool True on success.
     * @throws Exception
     */
    public function removeImage(string $imageIdOrName): bool
    {
        $this->request("/images/{$imageIdOrName}", 'DELETE');
        // If the request did not throw an exception, it was successful.
        return true;
    }

    /**
     * Prunes unused images.
     * @return array The response from the API, including space reclaimed.
     * @throws Exception
     */
    public function pruneImages(): array
    {
        // The filter `dangling=false` prunes all unused images, not just dangling ones.
        // The filter is JSON `{"dangling":["false"]}` which needs to be URL encoded.
        return $this->request('/images/prune?filters=%7B%22dangling%22%3A%5B%22false%22%5D%7D', 'POST');
    }

    /**
     * Lists all images.
     * @return array The list of images.
     * @throws Exception
     */
    public function listImages(): array
    {
        return $this->request('/images/json');
    }

    /**
     * Pulls an image from a registry.
     * @param string $imageName The name of the image to pull (e.g., nginx:latest).
     * @return string The output from the pull command.
     * @throws Exception
     */
    public function pullImage(string $imageName): string {
        // If the image name doesn't contain a registry (no '.' or ':port' in the first part),
        // and no custom registry is configured for the host, explicitly prefix it with the Docker Hub registry.
        // This forces the daemon to ignore local mirrors that might be misconfigured.
        if (strpos($imageName, '/') === false && empty($this->host['registry_url'])) {
            $imageName = 'docker.io/library/' . $imageName;
        }

        $path = "/images/create?fromImage=" . urlencode($imageName);
        $headers = [];

        // IMPORTANT: Only add the auth header if BOTH username and password are provided and not empty.
        if (!empty($this->host['registry_username']) && !empty($this->host['registry_password'])) {
            $auth_details = [
                'username' => $this->host['registry_username'],
                'password' => $this->host['registry_password'],
                'serveraddress' => $this->host['registry_url'] ?: 'https://index.docker.io/v1/' // Default to Docker Hub
            ];
            $auth_header_value = base64_encode(json_encode($auth_details));
            $headers[] = 'X-Registry-Auth: ' . $auth_header_value;
        }

        // This is a streaming request, so we handle it differently.
        // We're using the rawRequest method but with POST and headers.
        $response_stream = $this->request($path, 'POST', null, 'application/json', $headers, 300);

        // Process the streaming response to make it readable
        $lines = explode("\n", trim($response_stream));
        $output = [];
        foreach ($lines as $line) {
            $data = json_decode($line, true);
            $output[] = $data['status'] . (isset($data['progress']) ? ' ' . $data['progress'] : '');
        }
        return implode("\n", $output);
    }

    /**
     * Inspects an image to get its details.
     * @param string $imageName The name or ID of the image.
     * @return array The image details.
     * @throws Exception
     */
    public function inspectImage(string $imageName): array
    {
        return $this->request("/images/{$imageName}/json");
    }

    /**
     * Lists all volumes.
     * @return array The list of volumes.
     * @throws Exception
     */
    public function listVolumes(): array
    {
        return $this->request('/volumes');
    }

    /**
     * Creates a new volume.
     * @param array $config The volume configuration.
     * @return array The response from the API.
     * @throws Exception
     */
    public function createVolume(array $config): array
    {
        return $this->request('/volumes/create', 'POST', $config);
    }

    /**
     * Inspects a single volume to get its details.
     * @param string $volumeName The name of the volume.
     * @return array The volume details.
     * @throws Exception
     */
    public function inspectVolume(string $volumeName): array
    {
        return $this->request("/volumes/{$volumeName}");
    }

    /**
     * Prunes unused volumes.
     * @return array The response from the API, including space reclaimed.
     * @throws Exception
     */
    public function pruneVolumes(): array
    {
        // The prune endpoint doesn't require a body
        return $this->request('/volumes/prune', 'POST');
    }

    /**
     * Removes a volume.
     * @param string $volumeName The name of the volume.
     * @return bool True on success.
     * @throws Exception
     */
    public function removeVolume(string $volumeName): bool
    {
        return $this->request("/volumes/{$volumeName}", 'DELETE');
    }

    /**
     * Lists all stacks (Swarm).
     * @return array The list of stacks.
     * @throws Exception
     */
    public function listStacks(): array
    {
        return $this->request('/stacks');
    }

    /**
     * Lists all services (Swarm).
     * @param array $filters An array of filters to apply (e.g., ['label' => ['com.docker.stack.namespace=mystack']]).
     * @return array The list of services.
     * @throws Exception
     */
    public function listServices(array $filters = []): array
    {
        $path = '/services';
        if (!empty($filters)) {
            $path .= '?filters=' . urlencode(json_encode($filters));
        }
        return $this->request($path);
    }


    /**
     * Lists all tasks (Swarm).
     * @return array The list of tasks.
     * @throws Exception
     */
    public function listTasks(array $filters = []): array
    {
        $path = '/tasks';
        if (!empty($filters)) {
            // The 'filters' parameter needs to be a JSON-encoded string.
            $path .= '?filters=' . urlencode(json_encode($filters));
        }
        return $this->request($path);
    }

    /**
     * Lists all nodes in the Swarm.
     * @return array The list of nodes.
     * @throws Exception
     */
    public function listNodes(): array
    {
        return $this->request('/nodes');
    }


    /**
     * Creates a new stack (Swarm).
     * @param string $name The name of the stack.
     * @param string $composeContent The content of the docker-compose file.
     * @return array The response from the API.
     * @throws Exception
     */
    public function createStack(string $name, string $composeContent): array
    {
        $data = [
            'Name' => $name,
            'StackFileContent' => $composeContent
        ];
        return $this->request('/stacks', 'POST', $data);
    }

    /**
     * Removes a stack (Swarm).
     * @param string $stackId The ID of the stack.
     * @return bool True on success.
     * @throws Exception
     */
    public function removeStack(string $stackId): bool
    {
        return $this->request("/stacks/{$stackId}", 'DELETE');
    }

    /**
     * Updates a stack (Swarm).
     * @param string $stackId The ID of the stack.
     * @param string $composeContent The new content of the docker-compose file.
     * @param int $version The current version of the stack object.
     * @return bool True on success.
     * @throws Exception
     */
    public function updateStack(string $stackId, string $composeContent, int $version): bool
    {
        // The Docker API for stack update expects the raw compose content directly in the body.
        $this->request("/stacks/{$stackId}/update?version={$version}", 'POST', $composeContent, 'application/x-yaml');
        // If the request did not throw an exception, it was successful.
        return true;
    }

    /**
     * Inspects a stack to get its version for updates.
     * @param string $stackId The ID of the stack.
     * @return array The stack details.
     * @throws Exception
     */
    public function inspectStack(string $stackId): array
    {
        return $this->request("/stacks/{$stackId}");
    }

    /**
     * Updates the number of replicas for a specific service.
     * @param string $serviceId The ID of the service to update.
     * @param int $version The current version index of the service object.
     * @param int $newReplicas The new number of replicas.
     * @return bool True on success.
     * @throws Exception
     */
    public function updateServiceReplicas(string $serviceId, int $version, int $newReplicas): bool
    {
        // 1. Get the current full specification of the service.
        $serviceSpec = $this->request("/services/{$serviceId}")['Spec'];

        // 2. Modify the number of replicas in the specification.
        // This ensures we only change what's necessary.
        $serviceSpec['Mode']['Replicated']['Replicas'] = $newReplicas;

        // 3. Send the update request. The Docker API requires the current version
        //    to prevent race conditions. The new spec is sent as the JSON body.
        $this->request("/services/{$serviceId}/update?version={$version}", 'POST', $serviceSpec);

        // If the request did not throw an exception, it was successful.
        return true;
    }

    /**
     * Updates a service with a full new specification.
     * @param string $serviceId The ID of the service to update.
     * @param int $version The current version index of the service object.
     * @param array $serviceSpec The new full service specification.
     * @return bool True on success.
     * @throws Exception
     */
    public function updateServiceSpec(string $serviceId, int $version, array $serviceSpec): bool
    {
        // Send the update request. The Docker API requires the current version
        // to prevent race conditions. The new spec is sent as the JSON body.
        $this->request("/services/{$serviceId}/update?version={$version}", 'POST', $serviceSpec);

        // If the request did not throw an exception, it was successful.
        return true;
    }

    /**
     * Forces a service to update, which effectively restarts its tasks.
     * @param string $serviceId The ID of the service to update.
     * @param int $version The current version index of the service object.
     * @return bool True on success.
     * @throws Exception
     */
    public function forceServiceUpdate(string $serviceId, int $version): bool
    {
        // The `--force` flag in the CLI is equivalent to sending an empty POST request.
        // The API will re-evaluate and re-create tasks.
        $this->request("/services/{$serviceId}/update?version={$version}", 'POST', []);

        return true;
    }

    /**
     * Gets system-wide information from the Docker daemon.
     * @return array The Docker system info.
     * @throws Exception
     */
    public function getInfo(): array
    {
        return $this->request('/info');
    }

    /**
     * Gets a one-time snapshot of a container's stats.
     * @param string $containerId The ID of the container.
     * @return array The container stats.
     * @throws Exception
     */
    public function getContainerStats(string $containerId): array
    {
        return $this->request("/containers/{$containerId}/stats?stream=false");
    }

    /**
     * Gets logs from a container.
     * @param string $containerId The ID of the container.
     * @param int $tail The number of lines to show from the end of the logs.
     * @return string The container logs.
     * @throws Exception
     */
    public function getContainerLogs(string $containerId, int $tail = 200): string
    {
        $path = "/containers/{$containerId}/logs?stdout=true&stderr=true&timestamps=true&tail={$tail}";
        
        // This is a raw request, not expecting JSON
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->apiUrl . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        if ($this->tlsEnabled) {
            curl_setopt($ch, CURLOPT_SSLCERT, $this->clientCertPath);
            curl_setopt($ch, CURLOPT_SSLKEY, $this->clientKeyPath);
            curl_setopt($ch, CURLOPT_CAINFO, $this->caCertPath);
        }

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($curl_error) throw new RuntimeException("cURL Error: " . $curl_error);
        if ($http_code >= 400) throw new RuntimeException("Docker API Error (HTTP {$http_code}): " . $response);

        // Clean non-printable characters from the raw log stream header, but keep line breaks.
        return preg_replace('/[^\x20-\x7E\n\r\t]/', '', $response);
    }

    /**
     * Makes a raw request to the Docker API, returning the raw response body.
     * Useful for endpoints like logs that don't return JSON.
     * @param string $path The API endpoint path.
     * @return string The raw response body.
     * @throws RuntimeException
     */
    public function rawRequest(string $path): string
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->apiUrl . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        if ($this->tlsEnabled) {
            curl_setopt($ch, CURLOPT_SSLCERT, $this->clientCertPath);
            curl_setopt($ch, CURLOPT_SSLKEY, $this->clientKeyPath);
            curl_setopt($ch, CURLOPT_CAINFO, $this->caCertPath);
        }

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($curl_error) throw new RuntimeException("cURL Error: " . $curl_error);
        if ($http_code >= 400) throw new RuntimeException("Docker API Error (HTTP {$http_code}): " . $response);

        // Clean non-printable characters from the raw log stream header, but keep line breaks.
        // The Docker log stream is multiplexed and includes an 8-byte header on each line.
        // We need to parse this header to correctly extract the log content.
        $result = '';
        $offset = 0;
        $response_len = strlen($response);

        while ($offset < $response_len) {
            // The header is 8 bytes: 1 byte for stream type, 3 reserved, 4 for size.
            if ($offset + 8 > $response_len) {
                break; // Not enough data for a full header
            }
            
            // Unpack the 4-byte size from the header (bytes 5-8). It's a big-endian unsigned long.
            $size_data = substr($response, $offset + 4, 4);
            $size = unpack('N', $size_data)[1];

            // The actual log message follows the header.
            $log_line = substr($response, $offset + 8, $size);
            $result .= $log_line;

            // Move the offset to the beginning of the next header.
            $offset += 8 + $size;
        }
        return $result;
    }

    /**
     * Makes a request to the Docker API.
     * @param string $path The API endpoint path.
     * @param string $method The HTTP method (GET, POST, etc.).
     * @param mixed|null $data The data to send with POST requests (can be an array for JSON or a string for raw content).
     * @param string $contentType The Content-Type header for the request.
     * @param array $extraHeaders Additional headers to send.
     * @param int $timeout The cURL timeout in seconds.
     * @return mixed The response from the API.
     * @throws Exception
     */
    private function request(string $path, string $method = 'GET', $data = null, string $contentType = 'application/json', array $extraHeaders = [], int $timeout = 15)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->apiUrl . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data !== null) {
                $body = ($contentType === 'application/json') ? json_encode($data) : $data;
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
                $headers = array_merge(['Content-Type: ' . $contentType], $extraHeaders);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            } else {
                // Docker API often uses empty POST bodies for actions
                curl_setopt($ch, CURLOPT_POSTFIELDS, '');
                if (!empty($extraHeaders)) {
                    curl_setopt($ch, CURLOPT_HTTPHEADER, $extraHeaders);
                }
            }
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        if ($this->tlsEnabled) {
            curl_setopt($ch, CURLOPT_SSLCERT, $this->clientCertPath);
            curl_setopt($ch, CURLOPT_SSLKEY, $this->clientKeyPath);
            curl_setopt($ch, CURLOPT_CAINFO, $this->caCertPath);
        }

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($curl_error) {
            throw new RuntimeException("cURL Error: " . $curl_error);
        }

        // Actions like start/stop/restart return 204 No Content on success.
        // A 304 Not Modified also indicates success (e.g., container is already started/stopped).
        if ($http_code === 204 || $http_code === 304) {
            return true;
        }

        if ($http_code >= 400) {
            $errorBody = json_decode($response, true);
            $errorMessage = $errorBody['message'] ?? $response;
            throw new RuntimeException("Docker API Error (HTTP {$http_code}): " . $errorMessage);
        }

        // For streaming responses (like pull), the body is not JSON. Return it raw.
        $decoded = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }
        return $response; // Return raw string if not valid JSON
    }

    /**
     * Executes a command in a running container.
     * @param string $containerId The ID of the container.
     * @param string $command The command to execute.
     * @param bool $useTempContainer If true, runs the command in a new temporary container with docker socket mounted.
     * @param bool $pullImage If true, attempts to pull the image for the temp container first.
     * @return string The output of the command.
     * @throws Exception
     */
    public function exec(string $containerId, string $command, bool $useTempContainer = false, bool $pullImage = false): string
    {
        $env_vars = "DOCKER_HOST=" . escapeshellarg($this->host['docker_api_url']);
        $cert_dir = null;

        if ($this->tlsEnabled) {
            $cert_dir = rtrim(sys_get_temp_dir(), '/') . '/docker_certs_' . uniqid();
            if (!mkdir($cert_dir, 0700, true)) {
                throw new RuntimeException("Could not create temporary cert directory.");
            }
            
            copy($this->caCertPath, $cert_dir . '/ca.pem');
            copy($this->clientCertPath, $cert_dir . '/cert.pem');
            copy($this->clientKeyPath, $cert_dir . '/key.pem');

            $env_vars .= " DOCKER_TLS_VERIFY=1 DOCKER_CERT_PATH=" . escapeshellarg($cert_dir);
        }

        if ($useTempContainer) {
            if ($pullImage) {
                // Attempt to pull the image first to ensure it's available.
                $this->pullImage($containerId);
            }
            // Run a temporary container with the docker socket mounted to execute a host-level docker command.
            // --- NEW: Smarter network attachment for TCP checks ---
            // If the command is a netcat check, we need to attach to the target container's network.
            $network_attach_arg = '';
            if (str_starts_with($command, 'nc -z')) {
                // Extract the target IP from the nc command
                preg_match('/nc -z -w \d+ (\S+)/', $command, $matches);
                $target_ip = $matches[1] ?? null;
                if ($target_ip) {
                    $target_network_name = $this->findNetworkByContainerIp($target_ip);
                    if ($target_network_name) $network_attach_arg = ' --network=' . escapeshellarg($target_network_name);
                }
            }
            $full_command = 'env ' . $env_vars . ' docker run --rm' . $network_attach_arg . ' ' . escapeshellarg($containerId) . ' sh -c ' . escapeshellarg($command) . ' 2>&1';
        } else {
            // Standard exec in an existing container.
            $full_command = 'env ' . $env_vars . ' docker exec ' . escapeshellarg($containerId) . ' sh -c ' . escapeshellarg($command) . ' 2>&1';
        }

        exec($full_command, $output, $return_var);

        // Cleanup temporary cert directory
        if ($cert_dir && is_dir($cert_dir)) {
            shell_exec("rm -rf " . escapeshellarg($cert_dir));
        }

        $output_string = implode("\n", $output);

        if ($return_var !== 0) throw new RuntimeException("Command failed with exit code {$return_var}: " . $output_string);

        return $output_string;
    }

    /**
     * Creates and starts a lightweight helper container for reading host stats.
     * This container needs access to the host's PID namespace.
     * @param string $containerName The name for the helper container.
     * @param string $helperImage The Docker image to use (e.g., 'alpine:latest').
     * @return void
     * @throws Exception
     */
    public function createAndStartCpuReaderContainer(string $containerName, string $helperImage = 'alpine:latest'): void
    {
        $config = [
            'Image' => $helperImage,
            'Cmd' => ['sleep', 'infinity'],
            'HostConfig' => [
                'PidMode' => 'host', // IMPORTANT: Allows the container to see host processes (like `top`)
                'RestartPolicy' => ['Name' => 'unless-stopped']
            ]
        ];

        // Create the container with a specific name
        $this->createAndStartContainer($containerName, $config);
    }

    /**
     * Creates and starts a health agent container on the host.
     * This container reports health statuses back to the main application.
     * @param string $containerName The name for the agent container.
     * @param string $agentImage The Docker image to use for the agent.
     * @return string The ID of the created container.
     * @throws Exception
     */
    public function createAndStartHealthAgentContainer(string $containerName, string $image, array $env, array $command = null, ?string $networkToAttach = null)
    {
        $config = [
            'Image' => $image,
            'Env' => $env,
            'HostConfig' => [
                'Binds' => [
                    '/var/run/docker.sock:/var/run/docker.sock'
                ],
                // Add host.docker.internal to allow the agent to check published ports on the host
                'ExtraHosts' => [
                    'host.docker.internal:host-gateway'
                ],
                'RestartPolicy' => ['Name' => 'always']
            ] 
        ];

        // Jika command diberikan, tambahkan ke konfigurasi
        if ($command !== null) {
            $config['Cmd'] = $command;
        }

        $containerId = $this->createAndStartContainer($containerName, $config, $networkToAttach);

        return $containerId;
    }

    /**
     * Private helper to create and start a container with a unified networking configuration.
     * @param string $containerName The name for the container.
     * @param array $config The base configuration for the container.
     * @param string|null $networkToAttach The specific network to attach to.
     * @return string The ID of the created container.
     * @throws Exception
     */
    private function createAndStartContainer(string $containerName, array $config, ?string $networkToAttach = null): string
    {
        // Docker API expects the EndpointsConfig to be an object, not an array.
        // We ensure this structure is always correct.
        $endpointsConfig = new stdClass();
        if ($networkToAttach) {
            // If a specific network is provided, set it.
            $endpointsConfig->{$networkToAttach} = new stdClass();
        }
        // If no network is provided, $endpointsConfig remains an empty object {}.
        // Docker will then correctly attach the container to the default 'bridge' network.

        $config['NetworkingConfig'] = [
            'EndpointsConfig' => $endpointsConfig
        ];

        $response = $this->request('/containers/create?name=' . $containerName, 'POST', $config);
        if (!isset($response['Id'])) {
            throw new Exception('Failed to create container: ' . ($response['message'] ?? 'Unknown error'));
        }
        $containerId = $response['Id'];
        $this->startContainer($containerId);
        return $containerId;
    }

    /**
     * Copies a local file into a container.
     *
     * @param string $containerId The ID of the container.
     * @param string $localPath The absolute path to the local file.
     * @param string $containerPath The absolute path inside the container.
     * @return void
     * @throws Exception
     */
    public function copyToContainer(string $containerId, string $localPath, string $containerPath): void
    {
        // Docker API requires the file to be sent as a tar archive.
        $tar_path = sys_get_temp_dir() . '/' . uniqid('cm_agent_') . '.tar';
        try {
            $phar = new PharData($tar_path);
            $phar->addFile($localPath, basename($containerPath)); // Store with the final basename
        } catch (Exception $e) {
            throw new Exception("Failed to create tar archive for agent script: " . $e->getMessage());
        }

        $tar_content = file_get_contents($tar_path);
        $containerDir = dirname($containerPath);

        $this->request(
            "/containers/{$containerId}/archive?path=" . urlencode($containerDir),
            'PUT',
            $tar_content,
            'application/x-tar'
        );

        unlink($tar_path);
    }

    /**
     * Executes a command in a running container in the background (detached).
     *
     * @param string $containerId The ID of the container.
     * @param array $command The command and its arguments as an array.
     * @return void
     * @throws RuntimeException
     */
    public function execInContainer(string $containerId, array $command, bool $wait = false): void
    {
        // 1. Create the exec instance
        $create_config = [
            'AttachStdout' => true,
            'AttachStderr' => true,
            'Cmd' => $command,
            'Detach' => !$wait, // Run in background unless we need to wait
            'Tty' => false,
        ];
        $exec_create_response = $this->request("/containers/{$containerId}/exec", 'POST', $create_config);
        $exec_id = $exec_create_response['Id'] ?? null;
        if (!$exec_id) throw new Exception("Failed to create exec instance.");

        // 2. Start the exec instance and optionally wait for it
        $start_response = $this->request("/exec/{$exec_id}/start", 'POST', ['Detach' => !$wait, 'Tty' => false]);

        if ($wait) {
            // If we are waiting, the response is the raw output stream.
            // We need to inspect the result to see if it was successful.
            $inspect_response = $this->request("/exec/{$exec_id}/json");
            $exit_code = $inspect_response['ExitCode'] ?? -1;

            if ($exit_code !== 0) {
                // The raw output is in $start_response. Clean it up for the error message.
                $output = preg_replace('/[^\x20-\x7E\n\r\t]/', '', $start_response);
                throw new RuntimeException("Exec command failed with exit code {$exit_code}. Output: " . $output);
            }
        }
    }

    /**
     * Updates the resource limits of a running container (Vertical Scaling).
     * @param string $containerId The ID of the container to update.
     * @param float $newCpuLimit The new CPU limit (e.g., 1.5 for 1.5 cores).
     * @return bool True on success.
     * @throws Exception
     */
    public function updateContainerResources(string $containerId, float $newCpuLimit): bool
    {
        // The modern way to update CPU limits is via NanoCpus.
        // 1 vCPU = 1,000,000,000 nanoseconds (1e9).
        $nanoCpus = (int)($newCpuLimit * 1e9);

        $updateConfig = [
            'NanoCpus' => $nanoCpus
        ];
        $this->request("/containers/{$containerId}/update", 'POST', $updateConfig);
        // If the request did not throw an exception, it was successful.
        return true;
    }

    /**
     * Recreates a container managed by a docker-compose stack on a standalone host.
     * This mimics the behavior of `docker-compose up -d --force-recreate <service_name>`.
     *
     * @param string $stackName The name of the stack (compose project).
     * @param string $serviceName The name of the service within the compose file.
     * @return string The output from the docker-compose command.
     * @throws Exception
     */
    public function recreateContainerFromStack(string $stackName, string $serviceName): string
    {
        $base_compose_path = get_setting('default_compose_path');
        if (empty($base_compose_path)) {
            throw new Exception("Cannot recreate container. 'Default Standalone Compose Path' is not configured in settings.");
        }

        // Construct the path to the deployment directory on the application server
        $safe_host_name = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $this->host['name']);
        $deployment_dir = rtrim($base_compose_path, '/') . '/' . $safe_host_name . '/' . $stackName;

        if (!is_dir($deployment_dir)) {
            throw new Exception("Deployment directory '{$deployment_dir}' not found. Cannot manage this stack.");
        }

        // Assume the compose file is named 'docker-compose.yml' for simplicity, as this is our convention.
        $compose_file_path = $deployment_dir . '/docker-compose.yml';
        if (!file_exists($compose_file_path)) {
            throw new Exception("Compose file not found at '{$compose_file_path}'.");
        }

        // Prepare environment variables for the remote docker-compose command
        $env_vars = "DOCKER_HOST=" . escapeshellarg($this->host['docker_api_url']) . " COMPOSE_NONINTERACTIVE=1";
        if ($this->tlsEnabled) {
            $cert_path_dir = $deployment_dir . '/certs';
            if (!is_dir($cert_path_dir)) throw new Exception("Certs directory not found in deployment folder for TLS connection.");
            $env_vars .= " DOCKER_TLS_VERIFY=1 DOCKER_CERT_PATH=" . escapeshellarg($cert_path_dir);
        }

        // Build the full command
        $cd_command = "cd " . escapeshellarg($deployment_dir);
        $compose_command = "docker compose -p " . escapeshellarg($stackName) . " up -d --force-recreate " . escapeshellarg($serviceName);
        $full_command = 'env ' . $env_vars . ' sh -c ' . escapeshellarg($cd_command . ' && ' . $compose_command) . ' 2>&1';

        exec($full_command, $output, $return_var);

        if ($return_var !== 0) {
            throw new Exception("Failed to recreate container for service '{$serviceName}'. Output: " . implode("\n", $output));
        }

        return implode("\n", $output);
    }

    /**
     * Finds the name of a Docker network that a container with a specific IP is connected to.
     *
     * @param string $containerIp The internal IP address of the target container.
     * @return string|null The name of the network, or null if not found.
     * @throws Exception
     */
    private function findNetworkByContainerIp(string $containerIp): ?string
    {
        $containers = $this->listContainers();
        foreach ($containers as $container) {
            if (isset($container['NetworkSettings']['Networks']) && is_array($container['NetworkSettings']['Networks'])) {
                foreach ($container['NetworkSettings']['Networks'] as $networkName => $networkDetails) {
                    if (isset($networkDetails['IPAddress']) && $networkDetails['IPAddress'] === $containerIp) {
                        // We found the container and its network.
                        // We should not use default networks like 'bridge', 'host', or 'none' for connection.
                        if (!in_array($networkName, ['bridge', 'host', 'none'])) {
                            return $networkName;
                        }
                    }
                }
            }
        }
        return null; // Return null if no matching network is found
    }
}