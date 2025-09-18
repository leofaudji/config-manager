<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$conn = Database::getInstance()->getConnection();

if (!isset($_GET['id'])) {
    header("Location: " . base_url('/hosts?status=error&message=Host ID not provided.'));
    exit;
}

$id = (int)$_GET['id'];
$stmt = $conn->prepare("SELECT * FROM docker_hosts WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
if (!($host = $result->fetch_assoc())) {
    header("Location: " . base_url('/hosts?status=error&message=Host not found.'));
    exit;
}
$stmt->close();
$conn->close();

require_once __DIR__ . '/../includes/header.php';
$active_page = 'images';
require_once __DIR__ . '/../includes/host_nav.php';
?>

<div class="card mt-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-box-seam"></i> Image Management</h5>
        <div class="d-flex align-items-center" id="image-actions-container">
            <div id="bulk-actions-container" class="dropdown me-2" style="display: none;">
                <button class="btn btn-secondary btn-sm dropdown-toggle" type="button" id="bulk-actions-btn" data-bs-toggle="dropdown" aria-expanded="false">
                    Bulk Actions
                </button>
                <ul class="dropdown-menu" aria-labelledby="bulk-actions-btn">
                    <li><a class="dropdown-item text-danger bulk-action-trigger" href="#" data-action="delete">Delete Selected</a></li>
                </ul>
            </div>
            <form class="search-form me-2" data-type="images" id="image-search-form" onsubmit="return false;">
                <div class="input-group input-group-sm">
                    <input type="text" name="search_images" class="form-control" placeholder="Search by tag or ID...">
                    <button class="btn btn-outline-secondary" type="submit" title="Search"><i class="bi bi-search"></i></button>
                    <button class="btn btn-outline-secondary reset-search-btn" type="button" title="Reset"><i class="bi bi-x-lg"></i></button>
                </div>
            </form>
            <button id="prune-images-btn" class="btn btn-sm btn-outline-warning me-2">
                <i class="bi bi-trash3"></i> Prune Unused
            </button>
            <button id="refresh-images-btn" class="btn btn-sm btn-outline-primary me-2"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
            <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#pullImageModal">
                <i class="bi bi-download"></i> Pull New Image
            </button>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive table-responsive-sticky">
            <table class="table table-striped table-sm table-hover">
                <thead>
                    <tr>
                        <th><input class="form-check-input" type="checkbox" id="select-all-images" title="Select all images"></th>
                        <th class="sortable asc" data-sort="RepoTags">Tag</th>
                        <th>Used By</th>
                        <th class="sortable" data-sort="Id">ID</th>
                        <th class="sortable" data-sort="Size">Size</th>
                        <th class="sortable" data-sortCreated">Created</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody id="images-container">
                    <!-- Image data will be loaded here by AJAX -->
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer d-flex justify-content-between align-items-center">
        <div class="text-muted small" id="images-info"></div>
        <div class="d-flex align-items-center">
            <nav id="images-pagination"></nav>
            <div class="ms-3">
                <select name="limit_images" class="form-select form-select-sm" id="images-limit-selector" style="width: auto;">
                    <option value="10">10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="-1">All</option>
                </select>
            </div>
        </div>
    </div>
</div>

<!-- Pull Image Modal -->
<div class="modal fade" id="pullImageModal" tabindex="-1" aria-labelledby="pullImageModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="pullImageModalLabel">Pull New Image</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form id="pull-image-form">
            <h6>Search Docker Hub</h6>
            <div class="input-group mb-3">
                <input type="text" class="form-control" id="docker-hub-search-input" placeholder="e.g., nginx">
                <button class="btn btn-outline-secondary" type="button" id="docker-hub-search-btn">Search</button>
            </div>
            <div id="docker-hub-search-results" class="list-group" style="max-height: 200px; overflow-y: auto;">
                <!-- Search results will be populated here -->
            </div>
            <nav id="docker-hub-pagination" class="d-flex justify-content-center mt-2" aria-label="Docker Hub search pagination"></nav>
            <hr>
            <h6>Pull Manually</h6>
            <div class="mb-3">
                <label for="image-name" class="form-label">Image Name & Tag <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="image-name" name="image_name" placeholder="e.g., ubuntu:latest, nginx:1.21-alpine" required>
                <small class="form-text text-muted">Specify the full image name, including the tag. For private registries, ensure credentials are set on the host configuration.</small>
            </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="confirm-pull-btn">Pull Image</button>
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

<script>
document.addEventListener('DOMContentLoaded', function() {
    const hostId = <?= $id ?>;
    const imagesContainer = document.getElementById('images-container');
    const pruneBtn = document.getElementById('prune-images-btn');
    const refreshImagesBtn = document.getElementById('refresh-images-btn');
    const pullImageModal = document.getElementById('pullImageModal');
    const confirmPullBtn = document.getElementById('confirm-pull-btn');
    const pullImageForm = document.getElementById('pull-image-form');
    const dockerHubSearchInput = document.getElementById('docker-hub-search-input');
    const searchBtn = document.getElementById('docker-hub-search-btn');
    const searchResultsContainer = document.getElementById('docker-hub-search-results');
    const imageNameInput = document.getElementById('image-name');
    const viewTagsModalEl = document.getElementById('viewTagsModal');
    const viewTagsModal = new bootstrap.Modal(viewTagsModalEl);
    const tagsListContainer = document.getElementById('tags-list-container');
    const tagFilterInput = document.getElementById('tag-filter-input');
    const viewTagsModalLabel = document.getElementById('viewTagsModalLabel');
    const bulkActionsContainer = document.getElementById('bulk-actions-container');
    const selectAllCheckbox = document.getElementById('select-all-images');
    const searchForm = document.getElementById('image-search-form');
    const imageSearchInput = searchForm.querySelector('input[name="search_images"]');
    const resetBtn = searchForm.querySelector('.reset-search-btn');
    const paginationContainer = document.getElementById('images-pagination');
    const infoContainer = document.getElementById('images-info');
    const limitSelector = document.getElementById('images-limit-selector');
    const tableHeader = document.querySelector('#images-container').closest('table').querySelector('thead');

    let currentPage = 1;
    let currentLimit = 10;
    let currentSort = 'RepoTags';
    let currentOrder = 'asc';

    function reloadCurrentView() {
        loadImages(parseInt(currentPage), parseInt(currentLimit));
    }

    function loadImages(page = 1, limit = 10) {
        currentPage = parseInt(page) || 1;
        currentLimit = parseInt(limit) || 10;
        const originalBtnContent = refreshImagesBtn.innerHTML;
        refreshImagesBtn.disabled = true;
        refreshImagesBtn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Refreshing...`;
        imagesContainer.innerHTML = '<tr><td colspan="7" class="text-center"><div class="spinner-border spinner-border-sm" role="status"><span class="visually-hidden">Loading...</span></div></td></tr>';

        const searchTerm = imageSearchInput.value.trim();
        fetch(`${basePath}/api/hosts/${hostId}/images?details=true&search=${encodeURIComponent(searchTerm)}&page=${page}&limit=${limit}&sort=${currentSort}&order=${currentOrder}`)
            .then(response => response.json())
            .then(result => {
                if (result.status === 'error') throw new Error(result.message);
                
                let html = '';
                if (result.data && result.data.length > 0) {
                    result.data.forEach(img => {
                        const isDangling = !img.RepoTags || (img.RepoTags.length === 1 && img.RepoTags[0] === '<none>:<none>');
                        const tagsHtml = isDangling ? '<i class="text-muted">&lt;none&gt;</i>' : img.RepoTags.join(',<br>');
                        const id = img.Id.replace('sha256:', '').substring(0, 12);
                        const size = formatBytes(img.Size);
                        const created = new Date(img.Created * 1000).toLocaleString();
                        const isUnused = img.Containers <= 0; // An image is unused if container count is 0 or -1 (for dangling)
                        const usedByStacks = img.UsedByStacks || [];
                        
                        const unusedBadge = isUnused ? ' <span class="badge bg-secondary">Unused</span>' : '';

                        let usedByHtml = '';
                        if (usedByStacks.length > 0) {
                            usedByHtml = usedByStacks.map(stackName => 
                                `<a href="${basePath}/hosts/${hostId}/stacks" class="badge bg-primary text-decoration-none me-1" data-bs-toggle="tooltip" title="Used by '${stackName}' stack">${stackName}</a>`
                            ).join('');
                        } else if (!isUnused) {
                            usedByHtml = '<span class="badge bg-secondary" data-bs-toggle="tooltip" title="Used by a standalone container not managed by this application.">Standalone</span>';
                        }

                        html += `<tr><td><input class="form-check-input image-checkbox" type="checkbox" value="${img.Id}" data-name="${img.RepoTags ? img.RepoTags[0] : id}"></td><td>${tagsHtml}${unusedBadge}</td><td>${usedByHtml}</td><td><code>${id}</code></td><td>${size}</td><td>${created}</td><td class="text-end"><button class="btn btn-sm btn-outline-danger delete-image-btn" data-image-id="${img.Id}" data-image-name="${img.RepoTags ? img.RepoTags[0] : id}" title="Remove Image"><i class="bi bi-trash"></i></button></td></tr>`;
                    });
                } else {
                    html = '<tr><td colspan="7" class="text-center">No images found on this host.</td></tr>';
                }
                imagesContainer.innerHTML = html;
                infoContainer.innerHTML = result.info;

                // Build pagination
                let paginationHtml = '';
                if (result.total_pages > 1) {
                    paginationHtml += '<ul class="pagination pagination-sm mb-0">';
                    paginationHtml += `<li class="page-item ${result.current_page <= 1 ? 'disabled' : ''}"><a class="page-link" href="#" data-page="${result.current_page - 1}">«</a></li>`;
                    for (let i = 1; i <= result.total_pages; i++) {
                        paginationHtml += `<li class="page-item ${result.current_page == i ? 'active' : ''}"><a class="page-link" href="#" data-page="${i}">${i}</a></li>`;
                    }
                    paginationHtml += `<li class="page-item ${result.current_page >= result.total_pages ? 'disabled' : ''}"><a class="page-link" href="#" data-page="${parseInt(result.current_page) + 1}">»</a></li>`;
                    paginationHtml += '</ul>';
                }
                paginationContainer.innerHTML = paginationHtml;

                // Save state
                localStorage.setItem(`host_${hostId}_images_page`, result.current_page);
                localStorage.setItem(`host_${hostId}_images_limit`, result.limit);

                // Update sort indicators in header
                tableHeader.querySelectorAll('th.sortable').forEach(th => {
                    th.classList.remove('asc', 'desc');
                    if (th.dataset.sort === currentSort) {
                        th.classList.add(currentOrder);
                    }
                });

                // Re-initialize tooltips for the new content
                const tooltipTriggerList = imagesContainer.querySelectorAll('[data-bs-toggle="tooltip"]');
                [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl));

            })
            .catch(error => imagesContainer.innerHTML = `<tr><td colspan="7" class="text-center text-danger">Failed to load images: ${error.message}</td></tr>`)
            .finally(() => {
                refreshImagesBtn.disabled = false;
                refreshImagesBtn.innerHTML = originalBtnContent;
            });
    }

    tableHeader.addEventListener('click', function(e) {
        const th = e.target.closest('th.sortable');
        if (!th) return;

        const sortField = th.dataset.sort;
        if (currentSort === sortField) {
            currentOrder = currentOrder === 'asc' ? 'desc' : 'asc';
        } else {
            currentSort = sortField;
            currentOrder = 'asc';
        }
        localStorage.setItem(`host_${hostId}_images_sort`, currentSort);
        localStorage.setItem(`host_${hostId}_images_order`, currentOrder);
        loadImages(1, limitSelector.value);
    });

    refreshImagesBtn.addEventListener('click', reloadCurrentView);

    function updateBulkActionsVisibility() {
        const checkedBoxes = imagesContainer.querySelectorAll('.image-checkbox:checked');
        if (checkedBoxes.length > 0) {
            bulkActionsContainer.style.display = 'block';
        } else {
            bulkActionsContainer.style.display = 'none';
        }
    }

    imagesContainer.addEventListener('change', (e) => {
        if (e.target.matches('.image-checkbox')) {
            updateBulkActionsVisibility();
        }
    });

    selectAllCheckbox.addEventListener('change', function() {
        const isChecked = this.checked;
        imagesContainer.querySelectorAll('.image-checkbox:not(:disabled)').forEach(checkbox => {
            checkbox.checked = isChecked;
        });
        updateBulkActionsVisibility();
    });

    bulkActionsContainer.addEventListener('click', function(e) {
        const trigger = e.target.closest('.bulk-action-trigger');
        if (!trigger) return;

        e.preventDefault();
        const action = trigger.dataset.action;
        const checkedBoxes = Array.from(imagesContainer.querySelectorAll('.image-checkbox:checked'));
        const imageIds = checkedBoxes.map(cb => cb.value);

        if (imageIds.length === 0) {
            showToast('No images selected.', false);
            return;
        }

        if (!confirm(`Are you sure you want to ${action} ${imageIds.length} selected image(s)? This action cannot be undone.`)) {
            return;
        }

        let completed = 0;
        const total = imageIds.length;
        showToast(`Performing bulk action '${action}' on ${total} images...`, true);

        imageIds.forEach(imageId => {
            const formData = new FormData();
            formData.append('action', 'delete_image');
            formData.append('image_id', imageId);

            fetch(`${basePath}/api/hosts/${hostId}/images`, { method: 'POST', body: formData })
                .catch(error => console.error(`Error during bulk delete for image ${imageId}:`, error))
                .finally(() => {
                    completed++;
                    if (completed === total) {
                        showToast(`Bulk delete completed.`, true);
                        setTimeout(reloadCurrentView, 2000);
                    }
                });
        });
    });

    searchForm.addEventListener('submit', function(e) {
        e.preventDefault();
        loadImages();
    });

    resetBtn.addEventListener('click', function() {
        if (imageSearchInput.value !== '') {
            imageSearchInput.value = '';
            loadImages();
        }
    });

    imageSearchInput.addEventListener('input', debounce(() => {
        loadImages();
    }, 400));

    paginationContainer.addEventListener('click', function(e) {
        const pageLink = e.target.closest('.page-link');
        if (pageLink) {
            e.preventDefault();
            loadImages(parseInt(pageLink.dataset.page), limitSelector.value);
        }
    });

    limitSelector.addEventListener('change', function() {
        loadImages(1, this.value);
    });

    if (confirmPullBtn) {
        confirmPullBtn.addEventListener('click', function() {
            const imageName = imageNameInput.value.trim();

            if (!imageName) {
                showToast('Please enter an image name.', false);
                return;
            }

            const originalBtnContent = this.innerHTML;
            this.disabled = true;
            this.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Pulling...`;

            const formData = new FormData();
            formData.append('action', 'pull_image');
            formData.append('image_name', imageName);

            const url = `${basePath}/api/hosts/${hostId}/images`;

            fetch(url, { method: 'POST', body: formData })
                .then(response => response.json().then(data => ({ ok: response.ok, data })))
                .then(({ ok, data }) => {
                    showToast(data.message, ok);
                    if (ok) {
                        bootstrap.Modal.getInstance(pullImageModal).hide();
                        reloadCurrentView();
                    }
                })
                .catch(error => showToast(error.message || 'An unknown error occurred while pulling the image.', false))
                .finally(() => {
                    this.disabled = false;
                    this.innerHTML = originalBtnContent;
                });
        });
    }

    if (pullImageModal) {
        pullImageModal.addEventListener('hidden.bs.modal', () => {
            pullImageForm.reset();
            searchResultsContainer.innerHTML = '';
            document.getElementById('docker-hub-pagination').innerHTML = '';
        });
    }

    function performSearch(page = 1) {
        const query = dockerHubSearchInput.value.trim();
        const paginationContainer = document.getElementById('docker-hub-pagination');

        if (!query) return;

        const originalBtnContent = searchBtn.innerHTML;
        searchBtn.disabled = true;
        searchBtn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>`;
        searchResultsContainer.innerHTML = '<div class="list-group-item text-center">Searching...</div>';

        if (parseInt(page) === 1) {
            paginationContainer.innerHTML = '';
        }

        fetch(`${basePath}/api/dockerhub/search?q=${encodeURIComponent(query)}&page=${page}`)
            .then(response => response.json().then(data => ({ ok: response.ok, data })))
            .then(({ ok, data }) => {
                if (!ok) throw new Error(data.message || 'Search failed');

                let html = '';
                if (data.data && data.data.length > 0) {
                    data.data.forEach(repo => {
                        const officialBadge = repo.is_official ? '<span class="badge bg-success ms-2">Official</span>' : '';
                        html += `<div class="list-group-item list-group-item-action search-result-item">
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
                let paginationHtml = '';
                const paginationData = data.pagination;
                if (paginationData && paginationData.total_pages > 1) {
                    const currentPage = parseInt(paginationData.current_page);
                    const totalPages = parseInt(paginationData.total_pages);

                    paginationHtml += '<ul class="pagination pagination-sm mb-0">';
                    // Previous button
                    paginationHtml += `<li class="page-item ${currentPage <= 1 ? 'disabled' : ''}"><a class="page-link" href="#" data-page="${currentPage - 1}">«</a></li>`;

                    // Page numbers logic
                    const maxPagesToShow = 5;
                    let startPage, endPage;
                    if (totalPages <= maxPagesToShow) {
                        startPage = 1;
                        endPage = totalPages;
                    } else {
                        const maxPagesBeforeCurrent = Math.floor(maxPagesToShow / 2);
                        const maxPagesAfterCurrent = Math.ceil(maxPagesToShow / 2) - 1;
                        if (currentPage <= maxPagesBeforeCurrent) {
                            startPage = 1;
                            endPage = maxPagesToShow;
                        } else if (currentPage + maxPagesAfterCurrent >= totalPages) {
                            startPage = totalPages - maxPagesToShow + 1;
                            endPage = totalPages;
                        } else {
                            startPage = currentPage - maxPagesBeforeCurrent;
                            endPage = currentPage + maxPagesAfterCurrent;
                        }
                    }

                    if (startPage > 1) {
                        paginationHtml += `<li class="page-item"><a class="page-link" href="#" data-page="1">1</a></li>`;
                        if (startPage > 2) {
                            paginationHtml += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
                        }
                    }

                    for (let i = startPage; i <= endPage; i++) {
                        paginationHtml += `<li class="page-item ${currentPage === i ? 'active' : ''}"><a class="page-link" href="#" data-page="${i}">${i}</a></li>`;
                    }

                    if (endPage < totalPages) {
                        if (endPage < totalPages - 1) {
                            paginationHtml += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
                        }
                        paginationHtml += `<li class="page-item"><a class="page-link" href="#" data-page="${totalPages}">${totalPages}</a></li>`;
                    }

                    // Next button
                    paginationHtml += `<li class="page-item ${currentPage >= totalPages ? 'disabled' : ''}"><a class="page-link" href="#" data-page="${currentPage + 1}">»</a></li>`;
                    paginationHtml += '</ul>';
                }
                paginationContainer.innerHTML = paginationHtml;

            })
            .catch(error => {
                searchResultsContainer.innerHTML = `<div class="list-group-item text-center text-danger">${error.message}</div>`;
            })
            .finally(() => {
                searchBtn.disabled = false;
                searchBtn.innerHTML = 'Search';
            });
    }

    searchBtn.addEventListener('click', () => performSearch(1));
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
            imageNameInput.value = imageName + ':latest'; // Default to latest tag
            imageNameInput.focus();
        }
    });

    tagsListContainer.addEventListener('click', function(e) {
        const tagItem = e.target.closest('.tag-select-item');
        if (tagItem) {
            e.preventDefault();
            const imageName = viewTagsModalLabel.textContent.replace('Tags for: ', '');
            const selectedTag = tagItem.dataset.tag;
            imageNameInput.value = `${imageName}:${selectedTag}`;
            viewTagsModal.hide();
        }
    });

    tagFilterInput.addEventListener('input', debounce(function() {
        const filterText = this.value.toLowerCase();
        tagsListContainer.querySelectorAll('.tag-select-item').forEach(tag => {
            tag.style.display = tag.textContent.toLowerCase().includes(filterText) ? '' : 'none';
        });
    }, 200));

    document.getElementById('docker-hub-pagination').addEventListener('click', function(e) {
        const pageLink = e.target.closest('.page-link');
        if (pageLink) {
            e.preventDefault();
            const page = pageLink.dataset.page;
            performSearch(page);
        }
    });

    pruneBtn.addEventListener('click', function() {
        if (!confirm('Are you sure you want to remove all unused images? This includes images without tags and images that are not used by any container. This action cannot be undone.')) {
            return;
        }

        const originalBtnContent = this.innerHTML;
        this.disabled = true;
        this.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Pruning...`;

        const url = `${basePath}/api/hosts/${hostId}/images/prune`;

        fetch(url, { method: 'POST' })
            .then(response => response.json().then(data => ({ ok: response.ok, data })))
            .then(({ ok, data }) => {
                showToast(data.message, ok);
                if (ok) {
                    reloadCurrentView();
                }
            })
            .catch(error => showToast(error.message || 'An unknown error occurred during prune.', false))
            .finally(() => {
                this.disabled = false;
                this.innerHTML = originalBtnContent;
            });
    });

    imagesContainer.addEventListener('click', function(e) {
        const deleteBtn = e.target.closest('.delete-image-btn');
        if (!deleteBtn) return;

        const imageId = deleteBtn.dataset.imageId;
        const imageName = deleteBtn.dataset.imageName;

        if (!confirm(`Are you sure you want to delete the image "${imageName}"? This action cannot be undone.`)) {
            return;
        }

        const originalIcon = deleteBtn.innerHTML;
        deleteBtn.disabled = true;
        deleteBtn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>`;

        const formData = new FormData();
        formData.append('action', 'delete_image');
        formData.append('image_id', imageId);

        const url = `${basePath}/api/hosts/${hostId}/images`;

        fetch(url, { method: 'POST', body: formData })
            .then(response => response.json().then(data => ({ ok: response.ok, data })))
            .then(({ ok, data }) => {
                showToast(data.message, ok);
                if (ok) loadImages();
            })
            .catch(error => showToast(error.message || 'An unknown error occurred while deleting the image.', false))
            .finally(() => {
                deleteBtn.disabled = false;
                deleteBtn.innerHTML = originalIcon;
            });
    });

    function initialize() {
        const initialPage = parseInt(localStorage.getItem(`host_${hostId}_images_page`)) || 1;
        const initialLimit = parseInt(localStorage.getItem(`host_${hostId}_images_limit`)) || 10;
        currentSort = localStorage.getItem(`host_${hostId}_images_sort`) || 'RepoTags';
        currentOrder = localStorage.getItem(`host_${hostId}_images_order`) || 'asc';
        
        limitSelector.value = initialLimit;

        loadImages(initialPage, initialLimit);
    }
    initialize();
});
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>