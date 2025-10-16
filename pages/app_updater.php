<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$conn = Database::getInstance()->getConnection();

// --- Get IDs from URL ---
$host_id = $_GET['id'] ?? null;
$stack_db_id = $_GET['stack_db_id'] ?? null;

if (!$host_id || !$stack_db_id) {
    header("Location: " . base_url('/hosts?status=error&message=Invalid update URL.'));
    exit;
}

// --- Fetch Host Details ---
$stmt_host = $conn->prepare("SELECT id, name, default_volume_path FROM docker_hosts WHERE id = ?");
$stmt_host->bind_param("i", $host_id);
$stmt_host->execute();
$host_result = $stmt_host->get_result();
if (!($host = $host_result->fetch_assoc())) {
    header("Location: " . base_url('/hosts?status=error&message=Host not found.'));
    exit;
}
$stmt_host->close();

// --- Fetch Stack Deployment Details ---
$stmt_stack = $conn->prepare("SELECT * FROM application_stacks WHERE id = ? AND host_id = ?");
$stmt_stack->bind_param("ii", $stack_db_id, $host_id);
$stmt_stack->execute();
$stack_result = $stmt_stack->get_result();
if (!($stack_data = $stack_result->fetch_assoc())) {
    header("Location: " . base_url('/hosts/' . $host_id . '/stacks?status=error&message=Stack not found in database.'));
    exit;
}
$stmt_stack->close();

$details = json_decode($stack_data['deployment_details'], true);
if (json_last_error() !== JSON_ERROR_NONE) {
    // Fallback to empty array if JSON is invalid
    $details = [];
}

// --- Logic to pre-fill editor content ---
$compose_content_for_editor = '';
if ($stack_data['source_type'] === 'editor') {
    // If the original source was the editor, use the content stored in the DB
    $compose_content_for_editor = $details['compose_content_editor'] ?? '';
} else {
    // For other types (git, image, hub), try to read the generated docker-compose.yml from the filesystem
    $base_compose_path = get_setting('default_compose_path', '');
    if (!empty($base_compose_path)) {
        $safe_host_name = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $host['name']);
        $stack_name = $stack_data['stack_name'];
        $compose_filename = $stack_data['compose_file_path']; // e.g., 'docker-compose.yml'

        $full_compose_path = rtrim($base_compose_path, '/') . '/' . $safe_host_name . '/' . $stack_name . '/' . $compose_filename;

        if (file_exists($full_compose_path) && is_readable($full_compose_path)) {
            $compose_content_for_editor = file_get_contents($full_compose_path);
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-arrow-repeat"></i> Update Application: <?= htmlspecialchars($details['stack_name'] ?? 'N/A') ?></h1>
</div>

<div class="card">
    <div class="card-body">
        <p class="card-text">Update the configuration for an existing application. The application will be redeployed with the new settings.</p>
        <form id="main-form" action="<?= base_url('/api/app-launcher/deploy') ?>" method="POST">
            <input type="hidden" name="update_stack" value="true">
            
            <div class="accordion" id="appLauncherAccordion">
                <!-- Step 1: Host Selection -->
                <div class="accordion-item">
                    <h2 class="accordion-header" id="headingOne">
                        <button class="accordion-button" type="button">
                            <strong>Step 1: Target Host (Locked)</strong>
                        </button>
                    </h2>
                    <div id="collapseOne" class="accordion-collapse collapse show" aria-labelledby="headingOne" data-bs-parent="#appLauncherAccordion">
                        <div class="accordion-body">
                            <input type="text" class="form-control" value="<?= htmlspecialchars($host['name']) ?>" readonly>
                            <input type="hidden" id="host_id" name="host_id" value="<?= htmlspecialchars($host_id) ?>">
                        </div>
                    </div>
                </div>

                <!-- Step 2: Deployment Source -->
                <div class="accordion-item">
                    <h2 class="accordion-header" id="headingTwo">
                        <button class="accordion-button" type="button">
                            <strong>Step 2: Define Deployment Source</strong>
                        </button>
                    </h2>
                    <div id="collapseTwo" class="accordion-collapse collapse show" aria-labelledby="headingTwo" data-bs-parent="#appLauncherAccordion">
                        <div class="accordion-body">
                            <div class="btn-group w-100" role="group" aria-label="Deployment source selection">
                                <input type="radio" class="btn-check" name="source_type" id="source_type_git" value="git" autocomplete="off" <?= ($details['source_type'] ?? 'git') === 'git' ? 'checked' : '' ?>>
                                <label class="btn btn-outline-primary" for="source_type_git"><i class="bi bi-github me-2"></i>From Git Repository</label>

                                <input type="radio" class="btn-check" name="source_type" id="source_type_local_image" value="image" autocomplete="off" <?= ($details['source_type'] ?? '') === 'image' ? 'checked' : '' ?>>
                                <label class="btn btn-outline-primary" for="source_type_local_image"><i class="bi bi-hdd-stack-fill me-2"></i>From Existing Image on Host</label>

                                <input type="radio" class="btn-check" name="source_type" id="source_type_hub_image" value="hub" autocomplete="off" <?= ($details['source_type'] ?? '') === 'hub' ? 'checked' : '' ?>>
                                <label class="btn btn-outline-primary" for="source_type_hub_image"><i class="bi bi-box-seam me-2"></i>From Docker Hub</label>

                                <input type="radio" class="btn-check" name="source_type" id="source_type_editor" value="editor" autocomplete="off" <?= ($details['source_type'] ?? '') === 'editor' ? 'checked' : '' ?>>
                                <label class="btn btn-outline-primary" for="source_type_editor"><i class="bi bi-code-square me-2"></i>From Editor</label>
                            </div>
                            <hr>
                            <div class="mb-3">
                                <label for="stack_name" class="form-label">Stack Name</label>
                                <input type="text" class="form-control" id="stack_name" name="stack_name" value="<?= htmlspecialchars($details['stack_name'] ?? '') ?>" readonly>
                                <div class="invalid-feedback">Stack name must start with a letter or number and can only contain letters, numbers, underscores, periods, or hyphens.</div>
                            </div>
                            <!-- Git Repository -->
                            <div id="git-source-section">
                                <div class="mb-3">
                                    <label for="git_url" class="form-label">Repository URL (SSH or HTTPS) <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <input type="text" class="form-control" id="git_url" name="git_url" placeholder="e.g., git@github.com:user/repo.git" value="<?= htmlspecialchars($details['git_url'] ?? '') ?>" required>
                                        <button class="btn btn-outline-secondary" type="button" id="test-git-connection-btn">Test Connection</button>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="git_branch" class="form-label">Branch</label>
                                        <input type="text" class="form-control" id="git_branch" name="git_branch" value="<?= htmlspecialchars($details['git_branch'] ?? 'main') ?>">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="compose_path" class="form-label">Compose File Path (optional)</label>
                                        <div class="input-group">
                                            <input type="text" class="form-control" id="compose_path" name="compose_path" placeholder="e.g., deploy/docker-compose.yml" value="<?= htmlspecialchars($details['compose_path'] ?? '') ?>">
                                            <button class="btn btn-outline-secondary" type="button" id="test-compose-path-btn">Test Path</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <!-- Local Image Selection -->
                            <div id="local-image-source-section" style="display: none;">
                                <div class="mb-3">
                                    <label for="image_name_select" class="form-label">Select Image <span class="text-danger">*</span></label>
                                    <select class="form-select" id="image_name_select" name="image_name_local" disabled>
                                        <option>Loading images...</option>
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
                                    <input type="text" class="form-control" id="image_name_hub" name="image_name_hub" placeholder="e.g., ubuntu:latest" value="<?= htmlspecialchars($details['image_name_hub'] ?? '') ?>">
                                    <small class="form-text text-muted">Enter the full image name. Use the search above to find public images.</small>
                                </div>
                            </div>
                            <!-- Editor -->
                            <div id="editor-source-section" style="display: none;">
                                <div class="mb-3">
                                    <label class="form-label">Docker Compose Content <span class="text-danger">*</span></label>
                                    <textarea id="compose_content_editor" name="compose_content_editor"><?= htmlspecialchars($compose_content_for_editor) ?></textarea>
                                    <small class="form-text text-muted">Paste or write your `docker-compose.yml` content here. Syntax highlighting is supported.</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 3: Application & Resource Configuration -->
                <div class="accordion-item">
                    <h2 class="accordion-header" id="headingThree">
                        <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#collapseThree" aria-expanded="true" aria-controls="collapseThree">
                            <strong>Step 3: Configure Application & Resources</strong>
                        </button>
                    </h2>
                    <div id="collapseThree" class="accordion-collapse collapse show" aria-labelledby="headingThree" data-bs-parent="#appLauncherAccordion">
                        <div class="accordion-body">
                            <div id="step3-extra-config">
                            <hr>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label for="deploy_replicas" class="form-label">Replicas</label>
                                    <input type="number" class="form-control" id="deploy_replicas" name="deploy_replicas" value="<?= htmlspecialchars($details['deploy_replicas'] ?? '1') ?>" min="1">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <?php
                                        $cpu_val = !empty($details['deploy_cpu']) ? $details['deploy_cpu'] : '1';
                                    ?>
                                    <label for="deploy_cpu_slider" class="form-label">CPU Limit: <strong id="cpu-limit-display"><?= $cpu_val ?></strong> vCPUs</label>
                                    <input type="range" class="form-range" id="deploy_cpu_slider" min="0.25" max="8" step="0.25" value="<?= (float)$cpu_val ?>">
                                    <input type="hidden" name="deploy_cpu" id="deploy_cpu" value="<?= $cpu_val ?>">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <?php
                                        $mem_val_str = !empty($details['deploy_memory']) ? $details['deploy_memory'] : '1024M';
                                        $mem_val_int = (int)filter_var($mem_val_str, FILTER_SANITIZE_NUMBER_INT);
                                    ?>
                                    <label for="deploy_memory_slider" class="form-label">Memory Limit: <strong id="memory-limit-display"><?= $mem_val_int ?></strong> MB</label>
                                    <input type="range" class="form-range" id="deploy_memory_slider" min="256" max="8192" step="256" value="<?= $mem_val_int ?>">
                                    <input type="hidden" name="deploy_memory" id="deploy_memory" value="<?= $mem_val_str ?>">
                                </div>
                                <div class="col-md-4 mb-3" id="deploy-placement-group" style="display: none;">
                                    <label for="deploy_placement_constraint" class="form-label">Placement Constraint</label>
                                    <select class="form-select" id="deploy_placement_constraint" name="deploy_placement_constraint">
                                        <option value="" <?= ($details['deploy_placement_constraint'] ?? '') === '' ? 'selected' : '' ?>>Any Node (Manager or Worker)</option>
                                        <option value="node.role==worker" <?= ($details['deploy_placement_constraint'] ?? '') === 'node.role==worker' ? 'selected' : '' ?>>Only Worker Nodes</option>
                                        <option value="node.role==manager" <?= ($details['deploy_placement_constraint'] ?? '') === 'node.role==manager' ? 'selected' : '' ?>>Only Manager Nodes</option>
                                    </select>
                                    <small class="form-text text-muted">Where to deploy the service within the Swarm cluster.</small>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label for="network_name" class="form-label">Attach to Network</label>
                                <div class="input-group">
                                    <select class="form-select" id="network_name" name="network_name" disabled>
                                        <option>Loading networks...</option>
                                    </select>
                                    <button class="btn btn-outline-secondary" type="button" id="refresh-networks-btn" title="Refresh network list"><i class="bi bi-arrow-clockwise"></i></button>
                                </div>
                            </div>
                            <div class="mb-3" id="container-ip-group" style="display: <?= !empty($details['network_name']) ? 'block' : 'none' ?>;">
                                <label for="container_ip" class="form-label">Container IP Address (Optional)</label>
                                <input type="text" class="form-control" name="container_ip" id="container_ip" placeholder="e.g., 172.20.0.10" value="<?= htmlspecialchars($details['container_ip'] ?? '') ?>">
                                <small class="form-text text-muted">Assign a static IP to the container within the selected network. Use with caution.</small>
                            </div>
                            <hr>
                            <label class="form-label"><strong>Port Mapping (Optional)</strong></label>
                            <div class="row">
                                <div class="col-md-6">
                                    <label for="host_port" class="form-label">Host Port</label>
                                    <input type="number" class="form-control" name="host_port" id="host_port" placeholder="e.g., 8080" value="<?= htmlspecialchars($details['host_port'] ?? '') ?>">
                                </div>
                                <div class="col-md-6">
                                    <label for="container_port" class="form-label">Container Port</label>
                                    <input type="number" class="form-control" name="container_port" id="container_port" placeholder="e.g., 80" value="<?= htmlspecialchars($details['container_port'] ?? '') ?>">
                                </div>
                            </div>
                            <div class="invalid-feedback" id="port-validation-feedback">If you specify a Host Port, you must also specify a Container Port.</div>
                            <small class="form-text text-muted">Expose a port. If you fill one, you must fill the other.</small>
                            <hr>
                            <h6 class="mt-3">Volume Mappings (Optional)</h6>
                            <div id="volumes-container">
                                <?php
                                $volume_paths = $details['volume_paths'] ?? [];
                                foreach ($volume_paths as $index => $volume_map):
                                    $container_path = htmlspecialchars($volume_map['container'] ?? '');
                                    $host_path = htmlspecialchars($volume_map['host'] ?? '');
                                ?>
                                <div class="input-group mb-2 volume-mapping-row"><input type="text" class="form-control host-volume-path-display" placeholder="Host Path (auto-generated)" readonly="" value="<?= $host_path ?>"><span class="input-group-text">:</span><input type="text" class="form-control container-volume-path" name="volume_paths[<?= $index ?>][container]" placeholder="Container Path (e.g., /data)" required="" value="<?= $container_path ?>"><input type="hidden" class="host-volume-path-hidden" name="volume_paths[<?= $index ?>][host]" value="<?= $host_path ?>"><button class="btn btn-outline-danger remove-item-btn" type="button" title="Remove mapping"><i class="bi bi-trash"></i></button></div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-secondary mt-2" id="add-volume-btn"><i class="bi bi-plus-circle"></i> Add Volume Mapping</button>
                            <small class="form-text text-muted d-block mt-1">A persistent volume will be created on the host for each mapping. Container Path is required for each entry.</small>
                            </div>

                            <hr>
                            <h5 class="mt-4">Autoscaling Settings</h5>
                            <p class="text-muted small">Atur autoscaling untuk stack ini berdasarkan utilisasi CPU dari host tempatnya berjalan. Fitur ini hanya berlaku untuk Docker Swarm.</p>

                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" role="switch" id="autoscaling_enabled" name="autoscaling_enabled" value="1" <?= ($stack_data['autoscaling_enabled'] ?? 0) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="autoscaling_enabled">Enable Autoscaling</label>
                            </div>

                            <div id="autoscaling-fields">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="autoscaling_min_replicas" class="form-label">Minimum Replicas</label>
                                        <input type="number" class="form-control" id="autoscaling_min_replicas" name="autoscaling_min_replicas" value="<?= htmlspecialchars($stack_data['autoscaling_min_replicas'] ?? 1) ?>" min="1">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="autoscaling_max_replicas" class="form-label">Maximum Replicas</label>
                                        <input type="number" class="form-control" id="autoscaling_max_replicas" name="autoscaling_max_replicas" value="<?= htmlspecialchars($stack_data['autoscaling_max_replicas'] ?? 1) ?>" min="1">
                                    </div>
                                </div>

                                <div class="row">
                                    <?php
                                        $cpu_up_val = $stack_data['autoscaling_cpu_threshold_up'] ?? 80;
                                        $cpu_down_val = $stack_data['autoscaling_cpu_threshold_down'] ?? 20;
                                    ?>
                                    <div class="col-md-6 mb-3">
                                        <label for="autoscaling_cpu_up_slider" class="form-label">CPU Scale-Up Threshold: <strong id="cpu-threshold-up-display"><?= $cpu_up_val ?></strong>%</label>
                                        <input type="range" class="form-range" id="autoscaling_cpu_up_slider" min="1" max="100" value="<?= $cpu_up_val ?>">
                                        <input type="hidden" name="autoscaling_cpu_threshold_up" id="autoscaling_cpu_threshold_up" value="<?= $cpu_up_val ?>">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="autoscaling_cpu_down_slider" class="form-label">CPU Scale-Down Threshold: <strong id="cpu-threshold-down-display"><?= $cpu_down_val ?></strong>%</label>
                                        <input type="range" class="form-range" id="autoscaling_cpu_down_slider" min="1" max="100" value="<?= $cpu_down_val ?>">
                                        <input type="hidden" name="autoscaling_cpu_threshold_down" id="autoscaling_cpu_threshold_down" value="<?= $cpu_down_val ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-4">
                <a href="<?= base_url('/hosts/' . $host_id . '/stacks') ?>" class="btn btn-secondary">Cancel</a>
                <button type="button" class="btn btn-info" id="view-compose-yaml-btn">View Generated YAML</button>
                <button type="submit" class="btn btn-primary" id="launch-app-btn">Update Application</button>
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
window.pageInit = function() {
    const mainForm = document.getElementById('main-form');
    const hostId = <?= $host_id ?>;
    const deploymentDetails = <?= json_encode($details) ?>;

    const hostSelect = document.getElementById('host_id');
    const networkSelect = document.getElementById('network_name');
    const stackNameInput = document.getElementById('stack_name');
    const cpuSlider = document.getElementById('deploy_cpu_slider');
    const cpuDisplay = document.getElementById('cpu-limit-display');
    const cpuInput = document.getElementById('deploy_cpu');
    const memorySlider = document.getElementById('deploy_memory_slider');
    const memoryDisplay = document.getElementById('memory-limit-display');
    const deployPlacementGroup = document.getElementById('deploy-placement-group');
    const memoryInput = document.getElementById('deploy_memory');
    const sourceTypeGitRadio = document.getElementById('source_type_git');
    const sourceTypeLocalImageRadio = document.getElementById('source_type_local_image');
    const sourceTypeHubImageRadio = document.getElementById('source_type_hub_image');
    const sourceTypeEditorRadio = document.getElementById('source_type_editor');
    const gitSourceSection = document.getElementById('git-source-section');
    const localImageSourceSection = document.getElementById('local-image-source-section');
    const hubImageSourceSection = document.getElementById('hub-image-source-section');
    const editorSourceSection = document.getElementById('editor-source-section');
    const gitUrlInput = document.getElementById('git_url');
    const imageNameSelect = document.getElementById('image_name_select');
    const imageNameHubInput = document.getElementById('image_name_hub');
    const composeContentEditor = document.getElementById('compose_content_editor');
    const editorHighlightCode = document.getElementById('editor-highlight-code');
    const composePathInput = document.getElementById('compose_path');
    const hostPortInput = document.getElementById('host_port');
    const containerPortInput = document.getElementById('container_port');
    const launchBtn = document.getElementById('launch-app-btn');
    const previewBtn = document.getElementById('view-compose-yaml-btn');
    const containerIpGroup = document.getElementById('container-ip-group');
    const containerIpInput = document.getElementById('container_ip');
    const dockerHubSearchInput = document.getElementById('docker-hub-search-input');
    const dockerHubSearchBtn = document.getElementById('docker-hub-search-btn');
    const searchResultsContainer = document.getElementById('docker-hub-search-results');
    const dockerHubPaginationContainer = document.getElementById('docker-hub-pagination');
    const viewTagsModalEl = document.getElementById('viewTagsModal');
    const viewTagsModal = new bootstrap.Modal(viewTagsModalEl);
    const tagsListContainer = document.getElementById('tags-list-container');
    const tagFilterInput = document.getElementById('tag-filter-input');
    const viewTagsModalLabel = document.getElementById('viewTagsModalLabel');
    const step3Button = document.querySelector('button[aria-controls="collapseThree"]');
    const step3Collapse = document.getElementById('collapseThree');
    const step3ExtraConfig = document.getElementById('step3-extra-config');
    let availableNetworks = [];
    let allContainers = [];
    let isSwarmManager = false;
    const logModalEl = document.getElementById('deploymentLogModal');
    const logModal = new bootstrap.Modal(logModalEl);
    const logContent = document.getElementById('deployment-log-content');
    const logCloseBtn = document.getElementById('deployment-log-close-btn');
    const addVolumeBtn = document.getElementById('add-volume-btn');
    const volumesContainer = document.getElementById('volumes-container');
    const refreshNetworksBtn = document.getElementById('refresh-networks-btn');
    const previewModalEl = document.getElementById('previewConfigModal');
    const previewModal = new bootstrap.Modal(previewModalEl);
    const previewModalLabel = document.getElementById('previewConfigModalLabel');
    const previewCodeContainer = document.getElementById('preview-yaml-content-container');
    const deployFromPreviewBtn = document.getElementById('deploy-from-preview-btn');
    const autoscalingSwitch = document.getElementById('autoscaling_enabled');
    const cpuThresholdUpSlider = document.getElementById('autoscaling_cpu_up_slider');
    const cpuThresholdUpDisplay = document.getElementById('cpu-threshold-up-display');
    const cpuThresholdUpInput = document.getElementById('autoscaling_cpu_threshold_up');
    const cpuThresholdDownSlider = document.getElementById('autoscaling_cpu_down_slider');
    const cpuThresholdDownDisplay = document.getElementById('cpu-threshold-down-display');
    const cpuThresholdDownInput = document.getElementById('autoscaling_cpu_threshold_down');

    function ipToLong(ip) {
        if (!ip) return 0;
        return ip.split('.').reduce((acc, octet) => (acc << 8) + parseInt(octet, 10), 0) >>> 0;
    }

    function longToIp(long) {
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
                    result.data.sort((a, b) => a.Name.localeCompare(b.Name));
                    availableNetworks = result.data;
                    let optionsHtml = '<option value="">-- Do not attach to a specific network --</option>';
                    // Add a specific option for the default bridge network behavior
                    optionsHtml += '<option value="bridge">-- Use Stack Default (Bridge) --</option>';
                    availableNetworks.forEach(net => {
                        if (net.Name === 'bridge' || net.Name === 'host' || net.Name === 'none') return; // Don't list default docker networks
                        optionsHtml += `<option value="${net.Name}">${net.Name}</option>`;
                    });
                    networkSelect.innerHTML = optionsHtml;
                    if (deploymentDetails.network_name) {
                        networkSelect.value = deploymentDetails.network_name;
                    }
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
        gitSourceSection.style.display = 'none';
        localImageSourceSection.style.display = 'none';
        hubImageSourceSection.style.display = 'none';
        editorSourceSection.style.display = 'none';

        gitUrlInput.required = false;
        imageNameSelect.required = false;
        imageNameHubInput.required = false;
        composeContentEditor.required = false;

        // Logic for Step 3 visibility
        step3Button.disabled = false;
        if (sourceTypeEditorRadio.checked) {
            step3ExtraConfig.style.display = 'none';
        } else {
            step3ExtraConfig.style.display = 'block';
        }

        if (sourceTypeLocalImageRadio.checked) {
            localImageSourceSection.style.display = 'block';
            imageNameSelect.required = true;
        } else if (sourceTypeHubImageRadio.checked) {
            hubImageSourceSection.style.display = 'block';
            imageNameHubInput.required = true;
        } else if (sourceTypeEditorRadio.checked) {
            editorSourceSection.style.display = 'block';
            composeContentEditor.required = true;
        } else { // Git is the default
            gitSourceSection.style.display = 'block';
            gitUrlInput.required = true;
        }
        checkFormValidity();
    }

    sourceTypeGitRadio.addEventListener('change', toggleSourceSections);
    sourceTypeLocalImageRadio.addEventListener('change', toggleSourceSections);
    sourceTypeHubImageRadio.addEventListener('change', toggleSourceSections);
    sourceTypeEditorRadio.addEventListener('change', toggleSourceSections);
    sourceTypeEditorRadio.addEventListener('change', toggleSourceSections);

    const editor = CodeMirror.fromTextArea(document.getElementById('compose_content_editor'), {
        lineNumbers: true,
        mode: 'yaml',
        theme: document.body.classList.contains('dark-mode') ? 'monokai' : 'default',
        lineWrapping: true,
        indentUnit: 2,
        tabSize: 2
    });
    editor.setSize(null, '350px');
    mainForm.addEventListener('submit', () => editor.save());

    function updateHostVolumePath() {
        const baseVolumePath = '<?= htmlspecialchars($host['default_volume_path'] ?? '/opt/stacks') ?>';
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
        const stackName = stackNameInput.value.trim();
        const stackNameValid = /^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/.test(stackName);

        let sourceValid = false;
        if (sourceTypeGitRadio.checked) {
            sourceValid = gitUrlInput.value.trim() !== '';
        } else if (sourceTypeLocalImageRadio.checked) {
            sourceValid = imageNameSelect.value.trim() !== '';
        } else if (sourceTypeHubImageRadio.checked) {
            sourceValid = imageNameHubInput.value.trim() !== '';
        } else if (sourceTypeEditorRadio.checked) {
            sourceValid = composeContentEditor.value.trim() !== '';
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

        // If source is editor, validation is simpler
        if (sourceTypeEditorRadio.checked) {
            const isEditorValid = !!(hostId && stackName && stackNameValid && sourceValid && volumesValid);
            launchBtn.disabled = !isEditorValid;
            previewBtn.disabled = !isEditorValid;
            return;
        }

        // --- Existing validation for other source types ---
        const hostPort = hostPortInput.value.trim();
        const containerPort = containerPortInput.value.trim();
        const portsValid = !hostPort || !!containerPort;

        let networkIpValid = true;
        if (networkSelect.value) {
            if (containerIpInput.value.trim() === '') {
                networkIpValid = false;
                containerIpInput.classList.add('is-invalid');
            } else {
                containerIpInput.classList.remove('is-invalid');
            }
        } else {
            containerIpInput.classList.remove('is-invalid');
        }

        const isFormValid = hostId && stackName && stackNameValid && sourceValid && portsValid && volumesValid && networkIpValid;

        launchBtn.disabled = !isFormValid;
        previewBtn.disabled = !isFormValid;

        if (!portsValid) {
            document.getElementById('port-validation-feedback').style.display = 'block';
        } else {
            document.getElementById('port-validation-feedback').style.display = 'none';
        }
    }

    function initializePage() {
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

        fetch(`${basePath}/api/hosts/${hostId}/images`)
            .then(response => response.json())
            .then(result => {
                if (result.status === 'success' && result.data) {
                    let optionsHtml = '<option value="" disabled selected>-- Select an image --</option>';
                    // FIX: The API returns an array of objects {name, source}, not strings.
                    result.data.forEach(imgObj => {
                        const sourceLabel = imgObj.source === 'registry' ? ' (registry)' : ' (local)';
                        const displayName = imgObj.name + sourceLabel;
                        optionsHtml += `<option value="${imgObj.name}">${displayName}</option>`;
                    });
                    imageNameSelect.innerHTML = optionsHtml;
                    imageNameSelect.disabled = false;
                    if (deploymentDetails.image_name_local) {
                        imageNameSelect.value = deploymentDetails.image_name_local;
                    }
                } else {
                    throw new Error(result.message || 'Failed to load images.');
                }
            });

        fetch(`${basePath}/api/hosts/${hostId}/stats`)
            .then(response => response.json())
            .then(result => {
                if (result.status === 'success' && result.data && result.data.cpus && result.data.memory) {
                    isSwarmManager = result.data.is_swarm_manager || false;
                    const hostCpus = result.data.cpus;
                    const hostMemoryMb = Math.floor(result.data.memory / (1024 * 1024));

                    cpuSlider.max = hostCpus;
                    cpuSlider.dispatchEvent(new Event('input'));

                    const maxMemoryStepped = Math.floor(hostMemoryMb / 1024) * 1024;
                    memorySlider.max = maxMemoryStepped > 0 ? maxMemoryStepped : 1024;
                    memorySlider.dispatchEvent(new Event('input'));
                }
            })
            .catch(error => console.warn("Could not fetch host stats, using default slider limits.", error));

        toggleSourceSections();
        updateHostVolumePath();
        checkFormValidity();
    }

    // Event Listeners
    stackNameInput.addEventListener('input', updateHostVolumePath);

    networkSelect.addEventListener('change', function() {
        const selectedNetworkName = this.value;
        if (selectedNetworkName) {
            containerIpGroup.style.display = 'block';
        } else {
            containerIpGroup.style.display = 'none';
            containerIpInput.value = '';
            return;
        }

        const selectedNetwork = availableNetworks.find(net => net.Name === selectedNetworkName);
        if (selectedNetwork && selectedNetwork.IPAM && selectedNetwork.IPAM.Config && selectedNetwork.IPAM.Config[0] && selectedNetwork.IPAM.Config[0].Subnet) {
            const subnetCIDR = selectedNetwork.IPAM.Config[0].Subnet;
            const gateway = selectedNetwork.IPAM.Config[0].Gateway;

            const usedIps = [];
            if (gateway) usedIps.push(gateway);
            allContainers.forEach(container => {
                if (container.NetworkSettings && container.NetworkSettings.Networks && container.NetworkSettings.Networks[selectedNetworkName]) {
                    const ip = container.NetworkSettings.Networks[selectedNetworkName].IPAddress;
                    if (ip) usedIps.push(ip);
                }
            });

            if (usedIps.length > 0) {
                const usedIpsLong = usedIps.map(ipToLong);
                const maxIpLong = Math.max(...usedIpsLong);
                const nextIpLong = maxIpLong + 1;

                const [subnetIp, mask] = subnetCIDR.split('/');
                const subnetLong = ipToLong(subnetIp);
                const maskBits = parseInt(mask, 10);
                const broadcastLong = (subnetLong & (-1 << (32 - maskBits))) | ~(-1 << (32 - maskBits));
                
                if (nextIpLong < broadcastLong) {
                    const nextIp = longToIp(nextIpLong);
                    containerIpInput.value = nextIp;
                    showToast(`Suggested next available IP: ${nextIp}`, true);
                }
            }
        }
    });

    refreshNetworksBtn.addEventListener('click', function() {
        if (hostId) {
            loadNetworks(hostId);
        }
    });

    // Live validation listeners
    [stackNameInput, gitUrlInput, imageNameSelect, imageNameHubInput, hostPortInput, containerPortInput, volumesContainer, networkSelect, containerIpInput, composeContentEditor].forEach(input => {
        input.addEventListener('input', checkFormValidity);
    });

    // --- Resource Sliders ---
    if (cpuSlider && cpuDisplay && cpuInput) {
        cpuSlider.addEventListener('input', function() {
            const value = this.value;
            cpuDisplay.textContent = parseFloat(value).toFixed(2);
            cpuInput.value = value;
        });
    }
    if (memorySlider && memoryDisplay && memoryInput) {
        memorySlider.addEventListener('input', function() {
            memoryDisplay.textContent = this.value;
            memoryInput.value = `${this.value}M`;
        });
    }

    // --- Autoscaling Sliders ---
    if (cpuThresholdUpSlider) {
        cpuThresholdUpSlider.addEventListener('input', function() {
            cpuThresholdUpDisplay.textContent = this.value;
            cpuThresholdUpInput.value = this.value;
        });
    }
    if (cpuThresholdDownSlider) {
        cpuThresholdDownSlider.addEventListener('input', function() {
            cpuThresholdDownDisplay.textContent = this.value;
            cpuThresholdDownInput.value = this.value;
        });
    }

    // --- Autoscaling Fields Toggle ---
    if (autoscalingSwitch) {
        const autoscalingFields = document.getElementById('autoscaling-fields');
        const toggleAutoscalingFields = () => {
            const isEnabled = autoscalingSwitch.checked;
            autoscalingFields.querySelectorAll('input, select').forEach(el => {
                el.disabled = !isEnabled;
            });
            autoscalingFields.style.opacity = isEnabled ? '1' : '0.5';
        };

        autoscalingSwitch.addEventListener('change', toggleAutoscalingFields);
        toggleAutoscalingFields(); // Initial state
    }

    // --- YAML Preview Logic ---
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
            return 'server-side'; 
        }

        // Add container_name if not a swarm manager
        if (!isSwarmManager) {
            service.container_name = stackName;
        }

        // --- Resources ---
        const replicas = document.getElementById('deploy_replicas').value;
        const cpu = cpuInput.value;
        const memory = memoryInput.value;
        const placementConstraint = document.getElementById('deploy_placement_constraint').value;

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
            if (placementConstraint) {
                service.deploy.placement = {
                    constraints: [placementConstraint]
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
                    driver: 'local', driver_opts: { type: 'none', o: 'bind', device: hostPath }
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
        if (compose.networks && Object.keys(compose.networks).length === 0) {
            delete compose.networks;
        }

        return compose;
    }

    previewBtn.addEventListener('click', function() {
        const originalBtnContent = this.innerHTML;
        const mainForm = document.getElementById('main-form');
        const formData = new FormData(mainForm);

        this.disabled = true;
        this.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Generating...`;

        let previewPromise;

        if (sourceTypeLocalImageRadio.checked || sourceTypeHubImageRadio.checked) {
            const composeObject = buildComposeObject();
            if (!composeObject) {
                showToast('Please specify an Image to generate a preview.', false);
                this.disabled = false;
                this.innerHTML = originalBtnContent;
                return;
            }
            const yamlString = jsyaml.dump(composeObject, { indent: 2 });
            previewPromise = Promise.resolve(yamlString);

        } else { // Git or Editor source
            if (sourceTypeGitRadio.checked && !formData.get('git_url')) {
                showToast('Please enter a Git URL to generate a preview.', false);
                this.disabled = false;
                this.innerHTML = originalBtnContent;
                return;
            }
            if (sourceTypeEditorRadio.checked && !formData.get('compose_content_editor')) {
                showToast('Please enter compose content to generate a preview.', false);
                this.disabled = false;
                this.innerHTML = originalBtnContent;
                return;
            }
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

    // --- Volume Mapping UI Logic ---
    let volumeIndex = <?= count($volume_paths) ?>;
    addVolumeBtn.addEventListener('click', function() {
        const template = document.getElementById('volume-mapping-template').content.cloneNode(true);
        template.querySelector('.container-volume-path').name = `volume_paths[${volumeIndex}][container]`;
        template.querySelector('.host-volume-path-hidden').name = `volume_paths[${volumeIndex}][host]`;
        volumesContainer.appendChild(template);
        updateHostVolumePath();
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

    // --- Docker Hub Search Logic ---
    function performSearch(page = 1) {
        const query = dockerHubSearchInput.value.trim();
        if (!query) return;

        const originalBtnContent = dockerHubSearchBtn.innerHTML;
        dockerHubSearchBtn.disabled = true;
        dockerHubSearchBtn.innerHTML = `<span class="spinner-border spinner-border-sm"></span>`;
        searchResultsContainer.innerHTML = '<div class="list-group-item text-center">Searching...</div>';
        if (parseInt(page) === 1) dockerHubPaginationContainer.innerHTML = '';

        fetch(`${basePath}/api/dockerhub/search?q=${encodeURIComponent(query)}&page=${page}`)
            .then(response => response.json().then(data => ({ ok: response.ok, data })))
            .then(({ ok, data }) => {
                if (!ok) throw new Error(data.message || 'Search failed');
                let html = '';
                if (data.data && data.data.length > 0) {
                    data.data.forEach(repo => {
                        const officialBadge = repo.is_official ? '<span class="badge bg-success ms-2">Official</span>' : '';
                        html += `<div class="list-group-item list-group-item-action search-result-item" style="cursor: pointer;"><div class="d-flex w-100 justify-content-between align-items-center"><h6 class="mb-1">${repo.name}${officialBadge}</h6><div><small class="me-2"><i class="bi bi-star-fill text-warning"></i> ${repo.stars}</small><button type="button" class="btn btn-sm btn-outline-info view-tags-btn" data-image-name="${repo.name}" title="View available tags"><i class="bi bi-tags-fill"></i> Tags</button></div></div><p class="mb-1 small text-muted">${repo.description || 'No description.'}</p></div>`;
                    });
                } else {
                    html = '<div class="list-group-item text-center">No results found.</div>';
                }
                searchResultsContainer.innerHTML = html;
                if (data.pagination && data.pagination.total_pages > 1) {
                    dockerHubPaginationContainer.innerHTML = buildPagination(data.pagination.current_page, data.pagination.total_pages);
                }
            })
            .catch(error => searchResultsContainer.innerHTML = `<div class="list-group-item text-center text-danger">${error.message}</div>`)
            .finally(() => {
                dockerHubSearchBtn.disabled = false;
                dockerHubSearchBtn.innerHTML = 'Search';
            });
    }

    dockerHubSearchBtn.addEventListener('click', () => performSearch(1));
    dockerHubSearchInput.addEventListener('keypress', e => { if (e.key === 'Enter') { e.preventDefault(); performSearch(1); } });

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
                .catch(error => tagsListContainer.innerHTML = `<div class="list-group-item text-danger">${error.message}</div>`);
        } else if (item) {
            e.preventDefault();
            const imageName = item.querySelector('.view-tags-btn').dataset.imageName;
            imageNameHubInput.value = imageName + ':latest';
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
            performSearch(pageLink.dataset.page);
        }
    });

    function buildPagination(currentPage, totalPages) {
        let paginationHtml = '<ul class="pagination pagination-sm mb-0">';
        currentPage = parseInt(currentPage);
        totalPages = parseInt(totalPages);
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
        paginationHtml += `<li class="page-item ${currentPage <= 1 ? 'disabled' : ''}"><a class="page-link" href="#" data-page="${currentPage - 1}">«</a></li>`;
        if (startPage > 1) { paginationHtml += `<li class="page-item"><a class="page-link" href="#" data-page="1">1</a></li>`; if (startPage > 2) { paginationHtml += `<li class="page-item disabled"><span class="page-link">...</span></li>`; } }
        for (let i = startPage; i <= endPage; i++) { paginationHtml += `<li class="page-item ${currentPage === i ? 'active' : ''}"><a class="page-link" href="#" data-page="${i}">${i}</a></li>`; }
        if (endPage < totalPages) { if (endPage < totalPages - 1) { paginationHtml += `<li class="page-item disabled"><span class="page-link">...</span></li>`; } paginationHtml += `<li class="page-item"><a class="page-link" href="#" data-page="${totalPages}">${totalPages}</a></li>`; }
        paginationHtml += `<li class="page-item ${currentPage >= totalPages ? 'disabled' : ''}"><a class="page-link" href="#" data-page="${currentPage + 1}">»</a></li>`;
        paginationHtml += '</ul>';
        return paginationHtml;
    }

    // --- Deployment Log Streaming ---
    mainForm.addEventListener('submit', async function(e) {
        e.preventDefault();
        e.stopImmediatePropagation();

        if (launchBtn.disabled) {
            showToast('Please fill all required fields before launching.', false);
            return;
        }

        logContent.textContent = '';
        logCloseBtn.disabled = true;
        document.getElementById('deploymentLogModalLabel').textContent = 'Update in Progress...';
        logModal.show();
        
        const originalBtnContent = launchBtn.innerHTML;
        launchBtn.disabled = true;
        launchBtn.innerHTML = `<span class="spinner-border spinner-border-sm"></span> Updating...`;

        try {
            const formData = new FormData(mainForm);
            const response = await fetch(mainForm.action, {
                method: 'POST',
                body: formData
            });

            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            let finalStatus = 'failed';

            while (true) {
                const { value, done } = await reader.read();
                if (done) break;
                const chunk = decoder.decode(value, { stream: true });
                if (chunk.includes('_DEPLOYMENT_COMPLETE_')) finalStatus = 'success';
                const cleanChunk = chunk.replace(/_DEPLOYMENT_(COMPLETE|FAILED)_/, '');
                logContent.textContent += cleanChunk;
                logContent.parentElement.scrollTop = logContent.parentElement.scrollHeight;
            }

            if (finalStatus === 'success') {
                showToast('Update completed successfully!', true);
            } else {
                showToast('Update failed. Check logs for details.', false);
            }
        } catch (error) {
            logContent.textContent += `\n\n--- SCRIPT ERROR ---\n${error.message}`;
            showToast('A critical error occurred during update.', false);
        } finally {
            launchBtn.disabled = false;
            launchBtn.innerHTML = originalBtnContent;
            logCloseBtn.disabled = false;
            document.getElementById('deploymentLogModalLabel').textContent = 'Update Finished';
        }
    });

    // --- Initial Load ---
    initializePage();
};
</script>

<?php 
$conn->close(); // Close the connection at the very end.
require_once __DIR__ . '/../includes/footer.php'; 
?>