<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$conn = Database::getInstance()->getConnection();

// Get all hosts for the dropdown
$hosts_result = $conn->query("SELECT id, name, default_volume_path FROM docker_hosts ORDER BY name ASC");

// Get the global default from settings to use as a fallback
$default_git_compose_path_from_settings = get_setting('default_git_compose_path');

// Get launcher defaults for ports from ENV
$launcher_default_host_port = Config::get('LAUNCHER_DEFAULT_HOST_PORT', '80');
$launcher_default_container_port = Config::get('LAUNCHER_DEFAULT_CONTAINER_PORT', '80');

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-rocket-launch-fill"></i> App Launcher</h1>
</div>

<div class="card">
    <div class="card-body">
        <p class="card-text">Deploy a new application from a Git repository or an existing Docker image. This wizard will guide you through the configuration and deploy a Docker Compose file as a stack on the selected host.</p>
        <form id="main-form" action="<?= base_url('/api/app-launcher/deploy') ?>" method="POST">
            
            <div class="accordion" id="appLauncherAccordion">
                <!-- Step 1: Host Selection -->
                <div class="accordion-item">
                    <h2 class="accordion-header" id="headingOne">
                        <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#collapseOne" aria-expanded="true" aria-controls="collapseOne">
                            <strong>Step 1: Select Target Host</strong>
                        </button>
                    </h2>
                    <div id="collapseOne" class="accordion-collapse collapse show" aria-labelledby="headingOne" data-bs-parent="#appLauncherAccordion">
                        <div class="accordion-body">
                            <select class="form-select" id="host_id" name="host_id" required>
                                <option value="" disabled selected>-- Choose a Docker Host --</option>
                                <?php while ($host = $hosts_result->fetch_assoc()): ?>
                                    <option value="<?= $host['id'] ?>" data-volume-path="<?= htmlspecialchars($host['default_volume_path'] ?? '/opt/stacks') ?>"><?= htmlspecialchars($host['name']) ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Step 2: Deployment Source -->
                <div class="accordion-item">
                    <h2 class="accordion-header" id="headingTwo">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseTwo" aria-expanded="false" aria-controls="collapseTwo" disabled>
                            <strong>Step 2: Define Deployment Source</strong>
                        </button>
                    </h2>
                    <div id="collapseTwo" class="accordion-collapse collapse" aria-labelledby="headingTwo" data-bs-parent="#appLauncherAccordion">
                        <div class="accordion-body">
                            <div class="btn-group w-100" role="group" aria-label="Deployment source selection">
                                <input type="radio" class="btn-check" name="source_type" id="source_type_git" value="git" autocomplete="off" checked>
                                <label class="btn btn-outline-primary" for="source_type_git"><i class="bi bi-github me-2"></i>From Git Repository</label>

                                <input type="radio" class="btn-check" name="source_type" id="source_type_local_image" value="image" autocomplete="off">
                                <label class="btn btn-outline-primary" for="source_type_local_image"><i class="bi bi-hdd-stack-fill me-2"></i>From Existing Image on Host</label>

                                <input type="radio" class="btn-check" name="source_type" id="source_type_hub_image" value="hub" autocomplete="off">
                                <label class="btn btn-outline-primary" for="source_type_hub_image"><i class="bi bi-box-seam me-2"></i>From Docker Hub</label>
                            </div>
                            <hr>
                            <!-- Git Repository -->
                            <div id="git-source-section">
                                <div class="mb-3">
                                    <label for="git_url" class="form-label">Repository URL (SSH or HTTPS) <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <input type="text" class="form-control" id="git_url" name="git_url" placeholder="e.g., git@github.com:user/repo.git or https://github.com/user/repo.git" required>
                                        <button class="btn btn-outline-secondary" type="button" id="test-git-connection-btn">Test Connection</button>
                                    </div>
                                    <small class="form-text text-muted">For private HTTPS repos, credentials must be managed on the server where this app is running (e.g., using a credential helper).</small>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="git_branch" class="form-label">Branch</label>
                                        <input type="text" class="form-control" id="git_branch" name="git_branch" value="main">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="compose_path" class="form-label">Compose File Path (optional)</label>
                                        <div class="input-group">
                                            <input type="text" class="form-control" id="compose_path" name="compose_path" value="<?= htmlspecialchars($default_git_compose_path_from_settings ?? '') ?>" placeholder="<?= htmlspecialchars($default_git_compose_path_from_settings ?: 'docker-compose.yml') ?>">
                                            <button class="btn btn-outline-secondary" type="button" id="test-compose-path-btn">Test Path</button>
                                        </div>
                                    </div>
                                </div>
                                <div class="form-check form-switch mb-3">
                                    <input class="form-check-input" type="checkbox" role="switch" id="build_from_dockerfile" name="build_from_dockerfile" value="1">
                                    <label class="form-check-label" for="build_from_dockerfile">Buat image dari Dockerfile</label>
                                    <small class="form-text text-muted d-block">Jika dicentang, sistem akan menjalankan `docker-compose build` di host remote sebelum deploy. File `docker-compose.yml` di repository Anda harus berisi direktif `build`.</small>
                                </div>
                            </div>
                            <!-- Local Image Selection -->
                            <div id="local-image-source-section" style="display: none;">
                                <div class="mb-3">
                                    <label for="image_name_select" class="form-label">Select Image <span class="text-danger">*</span></label>
                                    <select class="form-select" id="image_name_select" name="image_name_local" disabled>
                                        <option>-- Select a host first --</option>
                                    </select>
                                    <small class="form-text text-muted">Select an image that already exists on the target host.</small>
                                </div>
                            </div>
                            <!-- Docker Hub Image Selection -->
                            <div id="hub-image-source-section" style="display: none;">
                                <h6>1. Search Docker Hub (Optional)</h6>
                                <div class="input-group mb-3">
                                    <input type="text" class="form-control" id="docker-hub-search-input" placeholder="e.g., nginx, portainer/portainer-ce">
                                    <button class="btn btn-outline-secondary" type="button" id="docker-hub-search-btn">Search</button>
                                </div>
                                <div id="docker-hub-search-results" class="list-group mb-3" style="max-height: 200px; overflow-y: auto;">
                                    <!-- Search results will be populated here -->
                                </div>
                                <nav id="docker-hub-pagination" class="d-flex justify-content-center" aria-label="Docker Hub search pagination"></nav>
                                
                                <hr>
                                <h6>2. Specify Image Name & Tag <span class="text-danger">*</span></h6>
                                <div class="mb-3">
                                    <input type="text" class="form-control" id="image_name_hub" name="image_name_hub" placeholder="e.g., ubuntu:latest, my-registry/my-app:1.2">
                                    <small class="form-text text-muted">Enter the full image name. Use the search above to find public images.</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 3: Application & Resource Configuration -->
                <div class="accordion-item">
                    <h2 class="accordion-header" id="headingThree">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseThree" aria-expanded="false" aria-controls="collapseThree" disabled>
                            <strong>Step 3: Configure Application & Resources</strong>
                        </button>
                    </h2>
                    <div id="collapseThree" class="accordion-collapse collapse" aria-labelledby="headingThree" data-bs-parent="#appLauncherAccordion">
                        <div class="accordion-body">
                            <div class="mb-3">
                                <label for="stack_name" class="form-label">Stack Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="stack_name" name="stack_name" required pattern="[a-zA-Z0-9][a-zA-Z0-9_.-]*">
                                <div class="invalid-feedback">Stack name must start with a letter or number and can only contain letters, numbers, underscores, periods, or hyphens.</div>
                                <small class="form-text text-muted">A unique name for this application on the host.</small>
                            </div>
                            <hr>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label for="deploy_replicas" class="form-label">Replicas</label>
                                    <input type="number" class="form-control" id="deploy_replicas" name="deploy_replicas" value="1" min="1">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="deploy_cpu_slider" class="form-label">CPU Limit: <strong id="cpu-limit-display">1</strong> vCPUs</label>
                                    <input type="range" class="form-range" id="deploy_cpu_slider" min="1" max="8" step="1" value="1">
                                    <input type="hidden" name="deploy_cpu" id="deploy_cpu" value="1">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="deploy_memory_slider" class="form-label">Memory Limit: <strong id="memory-limit-display">1024</strong> MB</label>
                                    <input type="range" class="form-range" id="deploy_memory_slider" min="1024" max="8192" step="1024" value="1024">
                                    <input type="hidden" name="deploy_memory" id="deploy_memory" value="1024M">
                                </div>
                            </div>
                            <div class="mb-3">
                                <label for="network_name" class="form-label">Attach to Network</label>
                                <div class="input-group">
                                    <select class="form-select" id="network_name" name="network_name" disabled>
                                        <option>-- Select a host first --</option>
                                    </select>
                                    <button class="btn btn-outline-secondary" type="button" id="refresh-networks-btn" title="Refresh network list" disabled><i class="bi bi-arrow-clockwise"></i></button>
                                </div>
                            </div>
                            <div class="mb-3" id="container-ip-group" style="display: none;">
                                <label for="container_ip" class="form-label">Container IP Address (Optional)</label>
                                <input type="text" class="form-control" name="container_ip" id="container_ip" placeholder="e.g., 172.20.0.10">
                                <div class="invalid-feedback">An IP Address is required when a network is selected.</div>
                                <small class="form-text text-muted">Assign a static IP to the container within the selected network. Use with caution.</small>
                            </div>
                            <hr>
                            <label class="form-label"><strong>Port Mapping (Optional)</strong></label>
                            <div class="row">
                                <div class="col-md-6">
                                    <label for="host_port" class="form-label">Host Port</label>
                                    <input type="number" class="form-control" name="host_port" id="host_port" placeholder="e.g., 8080 (random if empty)" value="<?= htmlspecialchars($launcher_default_host_port) ?>">
                                </div>
                                <div class="col-md-6">
                                    <label for="container_port" class="form-label">Container Port</label>
                                    <input type="number" class="form-control" name="container_port" id="container_port" placeholder="e.g., 80" value="<?= htmlspecialchars($launcher_default_container_port) ?>">
                                </div>
                            </div>
                            <div class="invalid-feedback" id="port-validation-feedback">If you specify a Host Port, you must also specify a Container Port.</div>
                            <small class="form-text text-muted">Expose a port. If you fill one, you must fill the other.</small>
                            <hr>
                            <h6 class="mt-3">Volume Mappings (Optional)</h6>
                            <div id="volumes-container">
                                <!-- Volume mapping rows will be added here -->
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-secondary mt-2" id="add-volume-btn"><i class="bi bi-plus-circle"></i> Add Volume Mapping</button>
                            <small class="form-text text-muted d-block mt-1">A persistent volume will be created on the host for each mapping. Container Path is required for each entry.</small>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-4">
                <a href="<?= base_url('/') ?>" class="btn btn-secondary">Cancel</a>
                <button type="button" class="btn btn-outline-info" data-bs-toggle="modal" data-bs-target="#deploymentInfoModal"><i class="bi bi-info-circle"></i> Cara Kerja</button>
                <button type="button" class="btn btn-info" id="view-compose-yaml-btn" disabled>View Generated YAML</button>
                <button type="submit" class="btn btn-primary" id="launch-app-btn" disabled>Launch Application</button>
            </div>
        </form>
    </div>
</div>

<!-- Deployment Log Modal -->
<div class="modal fade" id="deploymentLogModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="deploymentLogModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="deploymentLogModalLabel">Deployment in Progress...</h5>
      </div>
      <div class="modal-body bg-dark text-light font-monospace">
        <pre id="deployment-log-content" class="mb-0" style="white-space: pre-wrap; word-break: break-all;"></pre>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" id="deployment-log-close-btn" data-bs-dismiss="modal" disabled>Close</button>
      </div>
    </div>
  </div>
</div>

<!-- Deployment Info Modal -->
<div class="modal fade" id="deploymentInfoModal" tabindex="-1" aria-labelledby="deploymentInfoModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="deploymentInfoModalLabel"><i class="bi bi-diagram-3"></i> Alur Deployment App Launcher</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p>App Launcher menggunakan command-line tool <code>docker-compose</code> di server aplikasi ini untuk mengelola host Docker Standalone (non-Swarm) dari jarak jauh. Alur kerja bervariasi tergantung pada sumber deployment yang Anda pilih.</p>
        
        <hr>

        <h5><span class="badge bg-info">Alur 1</span> Deployment dari Git Repository</h5>
        <ol>
            <li><strong>Clone Repository:</strong> Aplikasi ini meng-clone repository Git Anda ke direktori sementara di server ini.</li>
            <li><strong>(Opsional) Build Image:</strong> Jika "Buat image dari Dockerfile" dicentang, aplikasi akan menjalankan `docker-compose build` yang menargetkan host remote. Ini akan membuat image Docker langsung di host tujuan menggunakan `Dockerfile` dari repository Anda.</li>
            <li><strong>Modifikasi Compose:</strong> File <code>docker-compose.yml</code> dari repository dibaca, lalu dimodifikasi di memori dengan pengaturan dari Step 3 (network, port, volume, dll).</li>
            <li><strong>Membuat Direktori Proyek:</strong> Direktori permanen untuk stack Anda (misalnya, <code>/opt/stacks/my-app</code>) dibuat di <strong>server aplikasi ini</strong>, sesuai path di "General Settings".</li>
            <li><strong>Salin & Simpan:</strong> Seluruh isi repository dari direktori sementara disalin ke direktori proyek permanen. File <code>docker-compose.yml</code> yang sudah dimodifikasi kemudian disimpan di sana, menimpa file aslinya.</li>
            <li><strong>Eksekusi Jarak Jauh:</strong> Aplikasi menjalankan perintah <code>docker-compose up -d</code> (didahului oleh `build` jika dipilih). Perintah ini dikonfigurasi dengan variabel lingkungan (<code>DOCKER_HOST</code>, <code>DOCKER_TLS_VERIFY</code>, dll.) untuk menargetkan API Docker dari host standalone jarak jauh.</li>
            <li><strong>Cleanup:</strong> Direktori sementara hasil clone repository akan dihapus.</li>
        </ol>

        <hr>

        <h5><span class="badge bg-success">Alur 2</span> Deployment dari Existing Docker Image</h5>
        <ol>
            <li><strong>Membuat Compose dari Awal:</strong> Aplikasi ini membuat konten file <code>docker-compose.yml</code> baru sepenuhnya berdasarkan input dari Step 3 (nama stack, image, network, port, dll).</li>
            <li><strong>Membuat Direktori Proyek:</strong> Sebuah direktori permanen untuk stack Anda (misalnya, <code>/opt/stacks/my-app</code>) dibuat di <strong>server aplikasi ini</strong>.</li>
            <li><strong>Menyimpan File Compose:</strong> File <code>docker-compose.yml</code> yang baru dibuat disimpan ke dalam direktori proyek tersebut.</li>
            <li><strong>Eksekusi Jarak Jauh:</strong> Aplikasi menjalankan perintah <code>docker-compose -p {stack_name} up -d</code> yang menargetkan API Docker dari host standalone jarak jauh.</li>
        </ol>

        <hr>

        <h5><span class="badge bg-info text-dark">Alur 3</span> Deployment dari Docker Hub</h5>
        <ol>
            <li><strong>Membuat Compose dari Awal:</strong> Aplikasi ini membuat konten file <code>docker-compose.yml</code> baru sepenuhnya berdasarkan input dari Step 3 (nama stack, image dari Docker Hub, network, port, dll).</li>
            <li><strong>Membuat Direktori Proyek:</strong> Sebuah direktori permanen untuk stack Anda (misalnya, <code>/opt/stacks/my-app</code>) dibuat di <strong>server aplikasi ini</strong>.</li>
            <li><strong>Menyimpan File Compose:</strong> File <code>docker-compose.yml</code> yang baru dibuat disimpan ke dalam direktori proyek tersebut.</li>
            <li><strong>Eksekusi Jarak Jauh:</strong> Aplikasi menjalankan perintah <code>docker-compose -p {stack_name} up -d</code>. Host remote kemudian akan secara otomatis menarik (pull) image dari Docker Hub dan membuat kontainer.</li>
        </ol>

        <div class="alert alert-warning">
            <strong>Poin Kunci untuk Semua Alur:</strong> Direktori proyek yang berisi file <code>docker-compose.yml</code> <strong>harus tetap ada di server aplikasi ini</strong>. Menghapus direktori ini akan membuat aplikasi tidak mungkin mengelola (misalnya, memperbarui atau menghentikan) stack di kemudian hari.
        </div>

      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
      </div>
    </div>
  </div>
</div>

<!-- View Tags Modal -->
<div class="modal fade" id="viewTagsModal" tabindex="-1" aria-labelledby="viewTagsModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="viewTagsModalLabel">Available Tags</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <input type="text" class="form-control mb-3" id="tag-filter-input" placeholder="Filter tags...">
        <div id="tags-list-container" class="list-group" style="max-height: 400px; overflow-y: auto;">
            <!-- Tags will be loaded here -->
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- Template for Volume Mapping -->
<template id="volume-mapping-template">
    <div class="input-group mb-2 volume-mapping-row">
        <input type="text" class="form-control host-volume-path-display" placeholder="Host Path (auto-generated)" readonly>
        <span class="input-group-text">:</span>
        <input type="text" class="form-control container-volume-path" name="volume_paths[][container]" placeholder="Container Path (e.g., /data)" required>
        <input type="hidden" class="host-volume-path-hidden" name="volume_paths[][host]">
        <button class="btn btn-outline-danger remove-item-btn" type="button" title="Remove mapping"><i class="bi bi-trash"></i></button>
    </div>
</template>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const mainForm = document.getElementById('main-form');
    const hostSelect = document.getElementById('host_id');
    const networkSelect = document.getElementById('network_name');
    const stackNameInput = document.getElementById('stack_name');
    const hostVolumePathDisplay = document.getElementById('host_volume_path_display');
    const cpuSlider = document.getElementById('deploy_cpu_slider');
    const cpuDisplay = document.getElementById('cpu-limit-display');
    const cpuInput = document.getElementById('deploy_cpu');
    const memorySlider = document.getElementById('deploy_memory_slider');
    const memoryDisplay = document.getElementById('memory-limit-display');
    const memoryInput = document.getElementById('deploy_memory');
    const sourceTypeGitRadio = document.getElementById('source_type_git');
    const sourceTypeLocalImageRadio = document.getElementById('source_type_local_image');
    const sourceTypeHubImageRadio = document.getElementById('source_type_hub_image');
    const gitSourceSection = document.getElementById('git-source-section');
    const localImageSourceSection = document.getElementById('local-image-source-section');
    const hubImageSourceSection = document.getElementById('hub-image-source-section');
    const gitUrlInput = document.getElementById('git_url');
    const imageNameSelect = document.getElementById('image_name_select');
    const imageNameHubInput = document.getElementById('image_name_hub');
    const composePathInput = document.getElementById('compose_path');
    const hostPortInput = document.getElementById('host_port');
    const containerPortInput = document.getElementById('container_port');
    const launchBtn = document.getElementById('launch-app-btn');
    const previewBtn = document.getElementById('view-compose-yaml-btn');
    const containerIpGroup = document.getElementById('container-ip-group');
    const containerIpInput = document.getElementById('container_ip');
    let availableNetworks = [];
    const dockerHubSearchInput = document.getElementById('docker-hub-search-input');
    const dockerHubSearchBtn = document.getElementById('docker-hub-search-btn');
    const searchResultsContainer = document.getElementById('docker-hub-search-results');
    const dockerHubPaginationContainer = document.getElementById('docker-hub-pagination');
    const viewTagsModalEl = document.getElementById('viewTagsModal');
    const viewTagsModal = new bootstrap.Modal(viewTagsModalEl);
    const tagsListContainer = document.getElementById('tags-list-container');
    const tagFilterInput = document.getElementById('tag-filter-input');
    const viewTagsModalLabel = document.getElementById('viewTagsModalLabel');
    let allContainers = [];
    let isSwarmManager = false;
    const logModalEl = document.getElementById('deploymentLogModal');
    const logModal = new bootstrap.Modal(logModalEl);
    const logContent = document.getElementById('deployment-log-content');
    const logCloseBtn = document.getElementById('deployment-log-close-btn');
    const addVolumeBtn = document.getElementById('add-volume-btn');
    const volumesContainer = document.getElementById('volumes-container');
    const refreshNetworksBtn = document.getElementById('refresh-networks-btn');

    function ipToLong(ip) {
        if (!ip) return 0;
        // Use reduce for a concise conversion
        return ip.split('.').reduce((acc, octet) => (acc << 8) + parseInt(octet, 10), 0) >>> 0;
    }

    function longToIp(long) {
        // Use bitwise shifts to extract octets
        return [(long >>> 24), (long >>> 16) & 255, (long >>> 8) & 255, long & 255].join('.');
    }

    function loadNetworks(hostId) {
        if (!hostId) return;

        networkSelect.disabled = true;
        refreshNetworksBtn.disabled = true;
        networkSelect.innerHTML = '<option>Loading networks...</option>';
        const originalBtnIcon = refreshNetworksBtn.innerHTML;
        refreshNetworksBtn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>`;

        fetch(`${basePath}/api/hosts/${hostId}/networks?limit=-1`)
            .then(response => response.json())
            .then(result => {
                if (result.status === 'success' && result.data) {
                    // Sort networks by name alphabetically before processing
                    result.data.sort((a, b) => a.Name.localeCompare(b.Name));
                    
                    availableNetworks = result.data; // Store full network objects
                    let optionsHtml = '<option value="">-- Do not attach to a specific network --</option>';
                    availableNetworks.forEach(net => {
                        optionsHtml += `<option value="${net.Name}">${net.Name}</option>`;
                    });
                    networkSelect.innerHTML = optionsHtml;
                } else {
                    availableNetworks = [];
                    throw new Error(result.message || 'Failed to load networks.');
                }
            })
            .catch(error => {
                console.error("Failed to fetch host networks:", error);
                networkSelect.innerHTML = '<option>-- Error loading networks --</option>';
                showToast("Could not load networks for the selected host.", false);
            })
            .finally(() => {
                networkSelect.disabled = false;
                refreshNetworksBtn.disabled = false;
                refreshNetworksBtn.innerHTML = originalBtnIcon;
            });
    }
    
    function toggleSourceSections() {
        // Hide all sections first
        gitSourceSection.style.display = 'none';
        localImageSourceSection.style.display = 'none';
        hubImageSourceSection.style.display = 'none';

        // Make all inputs not required first
        gitUrlInput.required = false;
        imageNameSelect.required = false;
        imageNameHubInput.required = false;

        if (sourceTypeLocalImageRadio.checked) {
            localImageSourceSection.style.display = 'block';
            imageNameSelect.required = true;
        } else if (sourceTypeHubImageRadio.checked) {
            hubImageSourceSection.style.display = 'block';
            imageNameHubInput.required = true;
        } else { // Git is checked by default
            gitSourceSection.style.display = 'block';
            gitUrlInput.required = true;
        }
        checkFormValidity();
    }

    sourceTypeGitRadio.addEventListener('change', toggleSourceSections);
    sourceTypeLocalImageRadio.addEventListener('change', toggleSourceSections);
    sourceTypeHubImageRadio.addEventListener('change', toggleSourceSections);

    function updateHostVolumePath() {
        const selectedHostOption = hostSelect.options[hostSelect.selectedIndex];
        if (!selectedHostOption || !selectedHostOption.value) {
            document.querySelectorAll('.volume-mapping-row').forEach(row => {
                row.querySelector('.host-volume-path-display').value = 'Select a host to see the path';
                row.querySelector('.host-volume-path-hidden').value = '';
            });
            return;
        }
        
        const baseVolumePath = selectedHostOption.dataset.volumePath || '/opt/stacks';
        const stackName = stackNameInput.value.trim() || '<stack-name>';
        const cleanBasePath = baseVolumePath.endsWith('/') ? baseVolumePath.slice(0, -1) : baseVolumePath;

        document.querySelectorAll('.volume-mapping-row').forEach(row => {
            const containerPathInput = row.querySelector('.container-volume-path');
            const hostPathDisplay = row.querySelector('.host-volume-path-display');
            const hostPathHidden = row.querySelector('.host-volume-path-hidden');
            const containerPath = containerPathInput.value.trim();
            const subDir = containerPath.replace(/^\/+|\/+$/g, '').replace(/[^a-zA-Z0-9_.-]/g, '_');
            const fullHostPath = (subDir && stackName !== '<stack-name>') ? `${cleanBasePath}/${stackName}/${subDir}` : `${cleanBasePath}/${stackName}/${subDir || '<volume-name>'}`;
            hostPathDisplay.value = fullHostPath;
            hostPathHidden.value = (subDir && stackName !== '<stack-name>') ? fullHostPath : '';
        });
    }

    function checkFormValidity() {
        const hostId = hostSelect.value;
        const stackName = stackNameInput.value.trim();
        const stackNameValid = /^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/.test(stackName);

        let sourceValid = false;
        if (sourceTypeGitRadio.checked) {
            sourceValid = gitUrlInput.value.trim() !== '';
        } else if (sourceTypeLocalImageRadio.checked) {
            sourceValid = imageNameSelect.value.trim() !== '';
        } else if (sourceTypeHubImageRadio.checked) {
            sourceValid = imageNameHubInput.value.trim() !== '';
        }

        const hostPort = hostPortInput.value.trim();
        const containerPort = containerPortInput.value.trim();
        const portsValid = !hostPort || !!containerPort;
        
        let networkIpValid = true;
        if (networkSelect.value) { // If a network is selected
            if (containerIpInput.value.trim() === '') {
                networkIpValid = false;
                containerIpInput.classList.add('is-invalid');
            } else {
                containerIpInput.classList.remove('is-invalid');
            }
        } else {
            containerIpInput.classList.remove('is-invalid');
        }

        let volumesValid = true;
        const volumeRows = document.querySelectorAll('.volume-mapping-row');
        if (volumeRows.length > 0) {
            volumeRows.forEach(row => {
                const containerPathInput = row.querySelector('.container-volume-path');
                if (containerPathInput.value.trim() === '') {
                    volumesValid = false;
                    containerPathInput.classList.add('is-invalid');
                } else {
                    containerPathInput.classList.remove('is-invalid');
                }
            });
        }

        const isFormValid = hostId && stackName && stackNameValid && sourceValid && portsValid && volumesValid && networkIpValid;

        launchBtn.disabled = !isFormValid;
        previewBtn.disabled = !isFormValid;

        // Provide visual feedback
        if (stackName && !stackNameValid) {
            stackNameInput.classList.add('is-invalid');
        } else {
            stackNameInput.classList.remove('is-invalid');
        }

        if (!portsValid) {
            document.getElementById('port-validation-feedback').style.display = 'block';
        } else {
            document.getElementById('port-validation-feedback').style.display = 'none';
        }
    }

    hostSelect.addEventListener('change', function() {
        const hostId = this.value;
        const globalDefaultGitComposePath = '<?= htmlspecialchars($default_git_compose_path_from_settings ?? '') ?>';
        updateHostVolumePath(); // Update volume path when host changes

        if (!hostId) {
            networkSelect.innerHTML = '<option>-- Select a host first --</option>';
            networkSelect.disabled = true;
            refreshNetworksBtn.disabled = true;
            imageNameSelect.innerHTML = '<option>-- Select a host first --</option>';
            imageNameSelect.disabled = true;
            allContainers = []; // Reset containers
            composePathInput.value = globalDefaultGitComposePath;

            // Reset sliders to default values
            cpuSlider.max = 8;
            cpuSlider.value = 1;
            cpuSlider.dispatchEvent(new Event('input'));

            memorySlider.min = 1024;
            memorySlider.max = 8192;
            memorySlider.value = 1024;
            memorySlider.dispatchEvent(new Event('input'));

            // Disable and collapse other accordion items
            document.querySelectorAll('#appLauncherAccordion .accordion-button:not([aria-controls="collapseOne"])').forEach(btn => btn.disabled = true);
            bootstrap.Collapse.getOrCreateInstance(document.getElementById('collapseTwo')).hide();
            bootstrap.Collapse.getOrCreateInstance(document.getElementById('collapseThree')).hide();
            checkFormValidity();
            return;
        }

        // Enable and open the next step
        const step2Button = document.querySelector('button[aria-controls="collapseTwo"]');
        const step3Button = document.querySelector('button[aria-controls="collapseThree"]');
        step2Button.disabled = false;
        step3Button.disabled = false;
        refreshNetworksBtn.disabled = false;
        bootstrap.Collapse.getOrCreateInstance(document.getElementById('collapseTwo')).show();

        // Clear previous host's data and show loading states
        allContainers = [];
        imageNameSelect.disabled = true;
        imageNameSelect.innerHTML = '<option>Loading images...</option>';

        // Fetch all containers for IP suggestion
        fetch(`${basePath}/api/hosts/${hostId}/containers?raw=true`)
            .then(response => response.json())
            .then(result => {
                if (result.status === 'success') {
                    allContainers = result.data;
                } else {
                    allContainers = [];
                    console.warn('Could not fetch containers for IP suggestion.');
                }
            });

        loadNetworks(hostId);

        // Fetch host images
        fetch(`${basePath}/api/hosts/${hostId}/images`)
            .then(response => response.json())
            .then(result => {
                if (result.status === 'success' && result.data) {
                    let optionsHtml = '<option value="" disabled selected>-- Select an image --</option>';
                    result.data.forEach(img => {
                        // Assuming result.data is an array of strings (image tags)
                        optionsHtml += `<option value="${img}">${img}</option>`;
                    });
                    imageNameSelect.innerHTML = optionsHtml;
                    imageNameSelect.disabled = false;
                } else {
                    throw new Error(result.message || 'Failed to load images.');
                }
            })
            .catch(error => {
                console.error("Failed to fetch host images:", error);
                imageNameSelect.innerHTML = '<option>-- Error loading images --</option>';
                showToast("Could not load images for the selected host.", false);
            });

        // Fetch host stats to update resource sliders
        fetch(`${basePath}/api/hosts/${hostId}/stats`)
            .then(response => response.json())
            .then(result => {
                if (result.status === 'success' && result.data && result.data.cpus && result.data.memory) {
                    isSwarmManager = result.data.is_swarm_manager || false;
                    const hostCpus = result.data.cpus;
                    const hostMemoryMb = Math.floor(result.data.memory / (1024 * 1024));

                    // Update CPU slider
                    cpuSlider.max = hostCpus;
                    if (parseInt(cpuSlider.value) > hostCpus) {
                        cpuSlider.value = hostCpus;
                    }
                    cpuSlider.dispatchEvent(new Event('input'));

                    // Update Memory slider
                    const maxMemoryStepped = Math.floor(hostMemoryMb / 1024) * 1024;
                    memorySlider.max = maxMemoryStepped > 0 ? maxMemoryStepped : 1024;
                    if (parseInt(memorySlider.value) > maxMemoryStepped) {
                        memorySlider.value = maxMemoryStepped;
                    }
                    memorySlider.dispatchEvent(new Event('input'));

                } else {
                    isSwarmManager = false;
                    throw new Error(result.message || 'Host stats not available.');
                }
            })
            .catch(error => {
                console.warn("Could not fetch host stats, using default slider limits.", error);
                showToast("Could not load host resource limits, using defaults.", false);
            });
    });

    stackNameInput.addEventListener('input', function() {
        // Force stack name to lowercase to match docker-compose project name behavior
        this.value = this.value.toLowerCase();
    });

    stackNameInput.addEventListener('input', updateHostVolumePath);

    networkSelect.addEventListener('change', function() {
        const selectedNetworkName = this.value;
        containerIpInput.value = ''; // Reset IP field on any change

        // Show/hide the static IP input based on network selection
        if (selectedNetworkName) {
            containerIpGroup.style.display = 'block';
        } else {
            containerIpGroup.style.display = 'none';
            return; // Exit if no network is selected
        }

        // --- IP Suggestion Logic ---
        const selectedNetwork = availableNetworks.find(net => net.Name === selectedNetworkName);
        if (selectedNetwork && selectedNetwork.IPAM && selectedNetwork.IPAM.Config && selectedNetwork.IPAM.Config[0] && selectedNetwork.IPAM.Config[0].Subnet) {
            const subnetCIDR = selectedNetwork.IPAM.Config[0].Subnet;
            const gateway = selectedNetwork.IPAM.Config[0].Gateway;

            // Collect all used IPs in this network
            const usedIps = [];
            if (gateway) {
                usedIps.push(gateway);
            }
            allContainers.forEach(container => {
                if (container.NetworkSettings && container.NetworkSettings.Networks && container.NetworkSettings.Networks[selectedNetworkName]) {
                    const ip = container.NetworkSettings.Networks[selectedNetworkName].IPAddress;
                    if (ip) {
                        usedIps.push(ip);
                    }
                }
            });

            if (usedIps.length > 0) {
                const usedIpsLong = usedIps.map(ipToLong);
                const maxIpLong = Math.max(...usedIpsLong);
                const nextIpLong = maxIpLong + 1;

                // Check if the next IP is within the subnet range
                const [subnetIp, mask] = subnetCIDR.split('/');
                const subnetLong = ipToLong(subnetIp);
                const maskBits = parseInt(mask, 10);
                const broadcastLong = (subnetLong & (-1 << (32 - maskBits))) | ~(-1 << (32 - maskBits));
                
                if (nextIpLong < broadcastLong) { // Ensure not broadcast address
                    const nextIp = longToIp(nextIpLong);
                    containerIpInput.value = nextIp;
                    showToast(`Suggested next available IP: ${nextIp}`, true);
                }
            }
        }
        // --- End of IP Suggestion Logic ---
    });

    // Add listeners to all relevant inputs for live validation
    [stackNameInput, gitUrlInput, imageNameSelect, imageNameHubInput, hostPortInput, containerPortInput].forEach(input => {
        input.addEventListener('input', checkFormValidity);
    });

    updateHostVolumePath();
    checkFormValidity(); // Initial check

    // --- Resource Sliders ---
    if (cpuSlider && cpuDisplay && cpuInput) {
        cpuSlider.addEventListener('input', function() {
            const value = this.value;
            cpuDisplay.textContent = value; // It's an integer now
            cpuInput.value = value;
        });
    }
    if (memorySlider && memoryDisplay && memoryInput) {
        memorySlider.addEventListener('input', function() {
            memoryDisplay.textContent = this.value;
            memoryInput.value = `${this.value}M`;
        });
    }

    // --- YAML Preview ---
    const viewYamlBtn = document.getElementById('view-compose-yaml-btn');
    const previewModalEl = document.getElementById('previewConfigModal');
    const previewModal = new bootstrap.Modal(previewModalEl);
    const previewModalLabel = document.getElementById('previewConfigModalLabel');
    const previewCodeContainer = document.getElementById('preview-yaml-content-container');
    const deployFromPreviewBtn = document.getElementById('deploy-from-preview-btn');

    function buildComposeObject() {
        const stackName = stackNameInput.value.trim();
        if (!stackName) return null;

        const compose = { version: '3.8', services: {}, networks: {} };
        const service = {};

        // --- Source ---
        if (sourceTypeLocalImageRadio.checked) {
            const imageName = imageNameSelect.value;
            if (!imageName) return null;
            service.image = imageName;
        } else if (sourceTypeHubImageRadio.checked) {
            const imageName = imageNameHubInput.value;
            if (!imageName) return null;
            service.image = imageName;
        } else {
            // Preview for Git source is not supported as it requires fetching the file.
            return 'git'; 
        }

        // Add container_name if not a swarm manager
        if (!isSwarmManager) {
            service.container_name = stackName;
        }

        // Also set the hostname to the stack name
        service.hostname = stackName;

        // --- Resources ---
        const replicas = document.getElementById('deploy_replicas').value;
        const cpu = cpuInput.value;
        const memory = memoryInput.value;

        if (isSwarmManager) {
            service.deploy = {
                replicas: parseInt(replicas),
                resources: {
                    limits: {
                        cpus: cpu,
                        memory: memory
                    }
                },
                restart_policy: {
                    condition: 'any'
                }
            };
        } else { // Standalone
            service.cpus = parseFloat(cpu);
            service.mem_limit = memory;
            service.restart = 'unless-stopped';
        }

        // --- Network ---
        const networkName = networkSelect.value;
        const containerIp = containerIpInput.value.trim();
        if (networkName) {
            const networkKey = networkName.replace(/[^\w.-]+/g, '_');

            if (containerIp) {
                service.networks = {
                    [networkKey]: {
                        'ipv4_address': containerIp
                    }
                };
            } else {
                service.networks = [networkKey];
            }
            compose.networks[networkKey] = { name: networkName, external: true };
        }

        // --- Volume ---
        document.querySelectorAll('.volume-mapping-row').forEach(row => {
            const hostPath = row.querySelector('.host-volume-path-hidden').value.trim();
            const containerPath = row.querySelector('.container-volume-path').value.trim();

            if (containerPath && hostPath) {
                const suffix = containerPath.replace(/^\/+|\/+$/g, '').replace(/[^a-zA-Z0-9_.-]/g, '_');
                const volumeName = `${stackName}_${suffix || 'data'}`;

                if (!service.volumes) service.volumes = [];
                service.volumes.push(`${volumeName}:${containerPath}`);

                if (!compose.volumes) compose.volumes = {};
                compose.volumes[volumeName] = {
                    driver: 'local',
                    driver_opts: { type: 'none', o: 'bind', device: hostPath }
                };
            }
        });

        // --- Ports ---
        const hostPort = document.getElementById('host_port').value.trim();
        const containerPort = document.getElementById('container_port').value.trim();
        if (containerPort) {
            let portMapping = '';
            if (hostPort) {
                portMapping = `${hostPort}:${containerPort}`;
            } else {
                portMapping = containerPort;
            }
            service.ports = [portMapping];
        }

        compose.services[stackName] = service;
        if (Object.keys(compose.networks).length === 0) {
            delete compose.networks;
        }

        return compose;
    }

    viewYamlBtn.addEventListener('click', function() {
        const originalBtnContent = this.innerHTML;
        const mainForm = document.getElementById('main-form');
        const formData = new FormData(mainForm);

        // Basic validation
        if (!formData.get('host_id') || !formData.get('stack_name')) {
            showToast('Please select a Host and provide a Stack Name to generate a preview.', false);
            return;
        }

        this.disabled = true;
        this.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Generating...`;

        let previewPromise;

        if (sourceTypeLocalImageRadio.checked || sourceTypeHubImageRadio.checked) {
            // Client-side generation for 'image' source
            const composeObject = buildComposeObject();
            if (!composeObject) {
                showToast('Please specify an Image to generate a preview.', false);
                this.disabled = false;
                this.innerHTML = originalBtnContent;
                return;
            }
            const yamlString = jsyaml.dump(composeObject, { indent: 2 });
            previewPromise = Promise.resolve(yamlString);

        } else { // Git source is the only other option
            if (!formData.get('git_url')) {
                showToast('Please enter a Git URL to generate a preview.', false);
                this.disabled = false;
                this.innerHTML = originalBtnContent;
                return;
            }
            // Server-side generation for 'git' source
            previewPromise = fetch(`${basePath}/api/app-launcher/preview`, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json().then(data => {
                if (!response.ok) throw new Error(data.message || 'Failed to generate preview from server.');
                return data.yaml;
            }));
        }

        previewPromise
            .then(yamlString => {
                previewModalLabel.textContent = 'Preview: Generated docker-compose.yml';
                previewCodeContainer.textContent = yamlString;
                Prism.highlightElement(previewCodeContainer);
                deployFromPreviewBtn.style.display = 'none';
                previewModal.show();
            })
            .catch(error => {
                showToast(error.message, false);
            })
            .finally(() => {
                this.disabled = false;
                this.innerHTML = originalBtnContent;
            });
    });

    // Reset the deploy button visibility when the modal is hidden
    previewModalEl.addEventListener('hidden.bs.modal', function() {
        deployFromPreviewBtn.style.display = 'block';
    });

    // --- Test Compose Path ---
    const testComposePathBtn = document.getElementById('test-compose-path-btn');
    if (testComposePathBtn) {
        testComposePathBtn.addEventListener('click', function() {
            const gitUrl = gitUrlInput.value.trim();
            const gitBranch = document.getElementById('git_branch').value.trim();
            const composePath = composePathInput.value.trim();

            if (!gitUrl || !composePath) {
                showToast('Please provide a Git URL and a Compose File Path to test.', false);
                return;
            }

            const originalBtnContent = this.innerHTML;
            this.disabled = true;
            this.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Testing...`;

            const formData = new FormData();
            formData.append('git_url', gitUrl);
            formData.append('git_branch', gitBranch);
            formData.append('compose_path', composePath);

            fetch(`${basePath}/api/git/test-compose-path`, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json().then(data => ({ ok: response.ok, data })))
            .then(({ ok, data }) => {
                showToast(data.message, ok);
            })
            .catch(error => showToast('An unknown error occurred while testing the path.', false))
            .finally(() => {
                this.disabled = false;
                this.innerHTML = originalBtnContent;
            });
        });
    }

    // --- Docker Hub Search Logic ---
    function performSearch(page = 1) {
        const query = dockerHubSearchInput.value.trim();

        if (!query) return;

        const originalBtnContent = dockerHubSearchBtn.innerHTML;
        dockerHubSearchBtn.disabled = true;
        dockerHubSearchBtn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>`;
        searchResultsContainer.innerHTML = '<div class="list-group-item text-center">Searching...</div>';

        if (parseInt(page) === 1) {
            dockerHubPaginationContainer.innerHTML = '';
        }

        fetch(`${basePath}/api/dockerhub/search?q=${encodeURIComponent(query)}&page=${page}`)
            .then(response => response.json().then(data => ({ ok: response.ok, data })))
            .then(({ ok, data }) => {
                if (!ok) throw new Error(data.message || 'Search failed');

                let html = '';
                if (data.data && data.data.length > 0) {
                    data.data.forEach(repo => {
                        const officialBadge = repo.is_official ? '<span class="badge bg-success ms-2">Official</span>' : '';
                        html += `<div class="list-group-item list-group-item-action search-result-item" style="cursor: pointer;">
                                    <div class="d-flex w-100 justify-content-between align-items-center">
                                        <h6 class="mb-1">${repo.name}${officialBadge}</h6>
                                        <div>
                                            <small class="me-2"><i class="bi bi-star-fill text-warning"></i> ${repo.stars}</small>
                                            <button type="button" class="btn btn-sm btn-outline-info view-tags-btn" data-image-name="${repo.name}" title="View available tags"><i class="bi bi-tags-fill"></i> Tags</button>
                                        </div>
                                    </div>
                                    <p class="mb-1 small text-muted">${repo.description || 'No description.'}</p>
                                 </div>`;
                    });
                } else {
                    html = '<div class="list-group-item text-center">No results found.</div>';
                }
                searchResultsContainer.innerHTML = html;

                // Render pagination
                const paginationData = data.pagination;
                if (paginationData && paginationData.total_pages > 1) {
                    dockerHubPaginationContainer.innerHTML = buildPagination(paginationData.current_page, paginationData.total_pages);
                }

            })
            .catch(error => {
                searchResultsContainer.innerHTML = `<div class="list-group-item text-center text-danger">${error.message}</div>`;
            })
            .finally(() => {
                dockerHubSearchBtn.disabled = false;
                dockerHubSearchBtn.innerHTML = 'Search';
            });
    }

    dockerHubSearchBtn.addEventListener('click', () => performSearch(1));
    dockerHubSearchInput.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            performSearch(1);
        }
    });

    searchResultsContainer.addEventListener('click', function(e) {
        const viewTagsBtn = e.target.closest('.view-tags-btn');
        const item = e.target.closest('.search-result-item');

        if (viewTagsBtn) {
            e.preventDefault();
            e.stopPropagation();

            const imageName = viewTagsBtn.dataset.imageName;
            viewTagsModalLabel.textContent = `Tags for: ${imageName}`;
            tagsListContainer.innerHTML = '<div class="list-group-item text-center"><div class="spinner-border spinner-border-sm"></div> Loading tags...</div>';
            tagFilterInput.value = '';
            viewTagsModal.show();

            fetch(`${basePath}/api/dockerhub/tags?image=${encodeURIComponent(imageName)}`)
                .then(response => response.json().then(data => ({ ok: response.ok, data })))
                .then(({ ok, data }) => {
                    if (!ok) throw new Error(data.message);
                    
                    let tagsHtml = '';
                    if (data.data && data.data.length > 0) {
                        data.data.forEach(tag => {
                            tagsHtml += `<a href="#" class="list-group-item list-group-item-action tag-select-item" data-tag="${tag}">${tag}</a>`;
                        });
                    } else {
                        tagsHtml = '<div class="list-group-item">No tags found.</div>';
                    }
                    tagsListContainer.innerHTML = tagsHtml;
                })
                .catch(error => {
                    tagsListContainer.innerHTML = `<div class="list-group-item text-danger">${error.message}</div>`;
                });
        } else if (item) {
            e.preventDefault();
            const imageName = item.querySelector('.view-tags-btn').dataset.imageName;
            imageNameHubInput.value = imageName + ':latest'; // Default to latest tag
            imageNameHubInput.focus();
            checkFormValidity();
        }
    });

    tagsListContainer.addEventListener('click', function(e) {
        const tagItem = e.target.closest('.tag-select-item');
        if (tagItem) {
            e.preventDefault();
            const imageName = viewTagsModalLabel.textContent.replace('Tags for: ', '');
            const selectedTag = tagItem.dataset.tag;
            imageNameHubInput.value = `${imageName}:${selectedTag}`;
            viewTagsModal.hide();
            checkFormValidity();
        }
    });

    tagFilterInput.addEventListener('input', debounce(function() {
        const filterText = this.value.toLowerCase();
        tagsListContainer.querySelectorAll('.tag-select-item').forEach(tag => {
            tag.style.display = tag.textContent.toLowerCase().includes(filterText) ? '' : 'none';
        });
    }, 200));

    dockerHubPaginationContainer.addEventListener('click', function(e) {
        const pageLink = e.target.closest('.page-link');
        if (pageLink) {
            e.preventDefault();
            const page = pageLink.dataset.page;
            performSearch(page);
        }
    });

    function buildPagination(currentPage, totalPages) {
        let paginationHtml = '<ul class="pagination pagination-sm mb-0">';
        currentPage = parseInt(currentPage);
        totalPages = parseInt(totalPages);

        // Previous button
        paginationHtml += `<li class="page-item ${currentPage <= 1 ? 'disabled' : ''}"><a class="page-link" href="#" data-page="${currentPage - 1}">«</a></li>`;

        // Page numbers logic
        const maxPagesToShow = 5;
        let startPage, endPage;
        if (totalPages <= maxPagesToShow) {
            startPage = 1; endPage = totalPages;
        } else {
            const maxPagesBeforeCurrent = Math.floor(maxPagesToShow / 2);
            const maxPagesAfterCurrent = Math.ceil(maxPagesToShow / 2) - 1;
            if (currentPage <= maxPagesBeforeCurrent) { startPage = 1; endPage = maxPagesToShow; } 
            else if (currentPage + maxPagesAfterCurrent >= totalPages) { startPage = totalPages - maxPagesToShow + 1; endPage = totalPages; } 
            else { startPage = currentPage - maxPagesBeforeCurrent; endPage = currentPage + maxPagesAfterCurrent; }
        }

        if (startPage > 1) { paginationHtml += `<li class="page-item"><a class="page-link" href="#" data-page="1">1</a></li>`; if (startPage > 2) { paginationHtml += `<li class="page-item disabled"><span class="page-link">...</span></li>`; } }
        for (let i = startPage; i <= endPage; i++) { paginationHtml += `<li class="page-item ${currentPage === i ? 'active' : ''}"><a class="page-link" href="#" data-page="${i}">${i}</a></li>`; }
        if (endPage < totalPages) { if (endPage < totalPages - 1) { paginationHtml += `<li class="page-item disabled"><span class="page-link">...</span></li>`; } paginationHtml += `<li class="page-item"><a class="page-link" href="#" data-page="${totalPages}">${totalPages}</a></li>`; }

        // Next button
        paginationHtml += `<li class="page-item ${currentPage >= totalPages ? 'disabled' : ''}"><a class="page-link" href="#" data-page="${currentPage + 1}">»</a></li>`;
        paginationHtml += '</ul>';
        return paginationHtml;
    }

    // --- Volume Mapping UI Logic ---
    let volumeIndex = 0;
    addVolumeBtn.addEventListener('click', function() {
        const template = document.getElementById('volume-mapping-template').content.cloneNode(true);

        // Find inputs and update their names with a unique index to ensure correct grouping in PHP
        template.querySelector('.container-volume-path').name = `volume_paths[${volumeIndex}][container]`;
        template.querySelector('.host-volume-path-hidden').name = `volume_paths[${volumeIndex}][host]`;

        volumesContainer.appendChild(template);
        updateHostVolumePath(); // Update path for the new row
        checkFormValidity();
        volumeIndex++;
    });

    volumesContainer.addEventListener('click', function(e) {
        const removeBtn = e.target.closest('.remove-item-btn');
        if (removeBtn) {
            removeBtn.closest('.volume-mapping-row').remove();
            checkFormValidity();
        }
    });

    refreshNetworksBtn.addEventListener('click', function() {
        const hostId = hostSelect.value;
        if (hostId) {
            loadNetworks(hostId);
        }
    });

    // --- Deployment Log Streaming ---
    mainForm.addEventListener('submit', async function(e) {
        e.preventDefault();
        e.stopImmediatePropagation(); // Mencegah handler lain (generik) berjalan

        if (launchBtn.disabled) {
            showToast('Please fill all required fields before launching.', false);
            return;
        }

        // Show modal and reset state
        logContent.textContent = '';
        logCloseBtn.disabled = true;
        document.getElementById('deploymentLogModalLabel').textContent = 'Deployment in Progress...';
        logModal.show();
        
        const originalBtnContent = launchBtn.innerHTML;
        launchBtn.disabled = true;
        launchBtn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Deploying...`;

        try {
            const formData = new FormData(mainForm);
            const response = await fetch(mainForm.action, {
                method: 'POST',
                body: formData
            });

            // The backend now streams text/plain, so we can't check response.ok in the same way
            // We will check for a success marker in the stream itself.
            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            let finalStatus = 'failed';

            while (true) {
                const { value, done } = await reader.read();
                if (done) break;
                
                const chunk = decoder.decode(value, { stream: true });
                
                if (chunk.includes('_DEPLOYMENT_COMPLETE_')) {
                    finalStatus = 'success';
                }
                
                const cleanChunk = chunk.replace(/_DEPLOYMENT_(COMPLETE|FAILED)_/, '');
                
                logContent.textContent += cleanChunk;
                logContent.parentElement.scrollTop = logContent.parentElement.scrollHeight; // Auto-scroll
            }

            if (finalStatus === 'success') {
                showToast('Deployment completed successfully!', true);
            } else {
                showToast('Deployment failed. Check logs for details.', false);
            }
        } catch (error) {
            logContent.textContent += `\n\n--- SCRIPT ERROR ---\n${error.message}`;
            showToast('A critical error occurred during deployment.', false);
        } finally {
            launchBtn.disabled = false;
            launchBtn.innerHTML = originalBtnContent;
            logCloseBtn.disabled = false;
            document.getElementById('deploymentLogModalLabel').textContent = 'Deployment Finished';
        }
    });
});
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>