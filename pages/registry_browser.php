<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$host_id = $_GET['host_id'] ?? null;
if (!$host_id) {
    header("Location: " . base_url('/hosts?status=error&message=Host ID not specified.'));
    exit;
}

$conn = Database::getInstance()->getConnection();
$stmt = $conn->prepare("SELECT * FROM docker_hosts WHERE id = ?");
$stmt->bind_param("i", $host_id);
$stmt->execute();
$result = $stmt->get_result();
if (!($host = $result->fetch_assoc()) || empty($host['registry_url'])) {
    header("Location: " . base_url('/hosts?status=error&message=Host or registry configuration not found.'));
    exit;
}
$stmt->close();
$conn->close();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <div>
        <h1 class="h2"><i class="bi bi-box-seam"></i> Registry Browser</h1>
        <p class="text-muted">Browsing registry at <code><?= htmlspecialchars($host['registry_url']) ?></code> for host: <strong><?= htmlspecialchars($host['name']) ?></strong></p>
    </div>
    <div class="btn-toolbar mb-2 mb-md-0">
        <a href="<?= base_url('/hosts') ?>" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back to All Hosts
        </a>
    </div>
</div>

<div class="row">
    <div class="col-md-4">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Repositories</h5>
            </div>
            <div id="repo-list-container" class="list-group list-group-flush" style="max-height: 70vh; overflow-y: auto;">
                <!-- Repositories will be loaded here -->
            </div>
        </div>
    </div>
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0" id="tags-list-title">Image Tags</h5>
            </div>
            <div class="card-body">
                <div id="tags-list-container" class="list-group" style="max-height: 70vh; overflow-y: auto;">
                    <div class="list-group-item text-center text-muted">Select a repository to view its tags.</div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
window.pageInit = function() {
    const hostId = <?= json_encode($host_id) ?>;
    const repoListContainer = document.getElementById('repo-list-container');
    const tagsListContainer = document.getElementById('tags-list-container');
    const tagsListTitle = document.getElementById('tags-list-title');

    function loadRepositories() {
        repoListContainer.innerHTML = '<div class="list-group-item text-center"><div class="spinner-border spinner-border-sm"></div></div>';
        fetch(`<?= base_url('/api/registry/repositories?host_id=') ?>${hostId}`)
            .then(response => response.json())
            .then(result => {
                if (result.status !== 'success') throw new Error(result.message);
                let html = '';
                if (result.data.length > 0) {
                    result.data.forEach(repo => {
                        html += `<a href="#" class="list-group-item list-group-item-action repo-item" data-repo="${repo}">${repo}</a>`;
                    });
                } else {
                    html = '<div class="list-group-item text-center text-muted">No repositories found.</div>';
                }
                repoListContainer.innerHTML = html;
            })
            .catch(error => {
                repoListContainer.innerHTML = `<div class="list-group-item text-danger">Error: ${error.message}</div>`;
            });
    }

    function loadTags(repoName) {
        tagsListTitle.textContent = `Tags for: ${repoName}`;
        tagsListContainer.innerHTML = '<div class="list-group-item text-center"><div class="spinner-border spinner-border-sm"></div></div>';

        fetch(`<?= base_url('/api/registry/tags?host_id=') ?>${hostId}&repo=${encodeURIComponent(repoName)}`)
            .then(response => response.json())
            .then(result => {
                if (result.status !== 'success') throw new Error(result.message);
                let html = '';
                if (result.data.length > 0) {
                    result.data.forEach(tag => {
                        html += `<div class="list-group-item d-flex justify-content-between align-items-center">
                                    <span class="font-monospace">${tag}</span>
                                    <button class="btn btn-sm btn-outline-primary copy-image-name-btn" data-image-name="${repoName}:${tag}" title="Copy full image name">
                                        <i class="bi bi-clipboard"></i> Copy
                                    </button>
                                 </div>`;
                    });
                } else {
                    html = '<div class="list-group-item text-center text-muted">No tags found for this repository.</div>';
                }
                tagsListContainer.innerHTML = html;
            })
            .catch(error => {
                tagsListContainer.innerHTML = `<div class="list-group-item text-danger">Error: ${error.message}</div>`;
            });
    }

    repoListContainer.addEventListener('click', function(e) {
        const repoItem = e.target.closest('.repo-item');
        if (repoItem) {
            e.preventDefault();
            document.querySelectorAll('.repo-item.active').forEach(el => el.classList.remove('active'));
            repoItem.classList.add('active');
            loadTags(repoItem.dataset.repo);
        }
    });

    tagsListContainer.addEventListener('click', function(e) {
        const copyBtn = e.target.closest('.copy-image-name-btn');
        if (copyBtn) {
            navigator.clipboard.writeText(copyBtn.dataset.imageName).then(() => {
                showToast(`Copied '${copyBtn.dataset.imageName}' to clipboard!`, true);
            });
        }
    });

    loadRepositories();
};
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>