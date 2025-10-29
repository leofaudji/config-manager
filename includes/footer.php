<?php
if (!(isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')) {
// --- Start of non-AJAX wrapper ---
?>
    </main> <!-- end main-content -->

    <footer class="footer-fixed">
        <div class="d-flex justify-content-between align-items-center">
            <a href="<?= base_url('/changelog') ?>" class="text-muted small text-decoration-none" title="View Changelog">
                v<?= APP_VERSION ?>
            </a>
            <span class="text-muted">&copy; <?= date('Y') ?> 
                <a href="https://assistindo.id" target="_blank" rel="noopener noreferrer" class="text-muted">PT. Assist Software Indonesia Pratama</a>. All rights reserved.
            </span>
            <a href="<?= base_url('/faq') ?>" class="text-muted small">Help & FAQ</a>
        </div>
    </footer>

</div> <!-- end content-wrapper -->

<!-- Traefik Host Modal (for Add/Edit) -->
<div class="modal fade" id="traefikHostModal" tabindex="-1" aria-labelledby="traefikHostModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="traefikHostModalLabel">Add New Traefik Host</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form id="traefik-host-form">
            <input type="hidden" name="id" id="traefik-host-id">
            <div class="mb-3">
                <label for="traefik-host-name" class="form-label">Host Name</label>
                <input type="text" class="form-control" id="traefik-host-name" name="name" required>
            </div>
            <div class="mb-3">
                <label for="traefik-host-description" class="form-label">Description</label>
                <textarea class="form-control" id="traefik-host-description" name="description" rows="3"></textarea>
            </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="save-traefik-host-btn">Save Host</button>
      </div>
    </div>
  </div>
</div>

<!-- Stack Change Detail Modal -->
<div class="modal fade" id="stackChangeDetailModal" tabindex="-1" aria-labelledby="stackChangeDetailModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="stackChangeDetailModalLabel">Stack Change Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <dl class="row">
          <dt class="col-sm-3">Stack Name</dt>
          <dd class="col-sm-9" id="detail-stack-name"></dd>
          <dt class="col-sm-3">Change Type</dt>
          <dd class="col-sm-9" id="detail-change-type"></dd>
          <dt class="col-sm-3">Timestamp</dt>
          <dd class="col-sm-9" id="detail-created-at"></dd>
          <dt class="col-sm-3">Changed By</dt>
          <dd class="col-sm-9" id="detail-changed-by"></dd>
          <dt class="col-sm-3">Details</dt>
          <dd class="col-sm-9"><pre><code id="detail-details" class="font-monospace"></code></pre></dd>
        </dl>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- Toast Container -->
<div class="toast-container position-fixed bottom-0 end-0 p-3" id="toast-container" style="z-index: 1100">
    <!-- Toasts will be appended here by JavaScript -->
</div>

<!-- View History Modal -->
<div class="modal fade" id="viewHistoryModal" tabindex="-1" aria-labelledby="viewHistoryModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="viewHistoryModalLabel">View YAML Configuration</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <pre><code id="yaml-content-container" class="language-yaml">Loading...</code></pre>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- Container Logs Modal -->
<div class="modal fade" id="viewLogsModal" tabindex="-1" aria-labelledby="viewLogsModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="viewLogsModalLabel">Container Logs</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body bg-dark text-light font-monospace">
        <pre><code id="log-content-container">Loading logs...</code></pre>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- Network Modal -->
<div class="modal fade" id="networkModal" tabindex="-1" aria-labelledby="networkModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="networkModalLabel">Add New Network</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form id="network-form">
            <input type="hidden" name="action" value="create">
            <div class="mb-3">
                <label for="network-name" class="form-label">Network Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="network-name" name="name" required>
            </div>
            <div class="mb-3">
                <label for="network-driver" class="form-label">Driver</label>
                <select class="form-select" id="network-driver" name="driver">
                    <option value="bridge" selected>bridge</option>
                    <option value="overlay">overlay</option>
                    <option value="macvlan">macvlan</option>
                    <option value="host">host</option>
                    <option value="none">none</option>
                </select>
            </div>
            <div id="network-macvlan-container" style="display: none;">
                <hr>
                <h6>MACVLAN Options</h6>
                <div class="mb-3">
                    <label for="macvlan-parent-input" class="form-label">Parent network card</label>
                    <input type="text" class="form-control" id="macvlan-parent-input" name="macvlan_parent" placeholder="e.g., eth0" list="macvlan-parent-list">
                    <datalist id="macvlan-parent-list"></datalist>
                    <small class="form-text text-muted d-block">The name of the host interface to use for macvlan.</small>
                </div>
            </div>
            <div id="network-ipam-container" style="display: none;">
                <hr>
                <h6>IPAM Configuration</h6>
                <div class="mb-3">
                    <label for="ipam-subnet" class="form-label">Subnet</label>
                    <input type="text" class="form-control" id="ipam-subnet" name="ipam_subnet" placeholder="e.g., 172.25.0.0/16">
                </div>
                <div class="mb-3">
                    <label for="ipam-gateway" class="form-label">Gateway</label>
                    <input type="text" class="form-control" id="ipam-gateway" name="ipam_gateway" placeholder="e.g., 172.25.0.1">
                </div>
                <div class="mb-3">
                    <label for="ipam-ip_range" class="form-label">IP Range (Optional)</label>
                    <input type="text" class="form-control" id="ipam-ip_range" name="ipam_ip_range" placeholder="e.g., 172.25.5.0/24">
                </div>
            </div>
            <div class="mb-3 form-check form-switch" id="network-attachable-container" style="display: none;">
                <input class="form-check-input" type="checkbox" role="switch" id="network-attachable" name="attachable" value="1">
                <label class="form-check-label" for="network-attachable">Attachable</label>
                <small class="form-text text-muted d-block">Allows standalone containers to connect.</small>
            </div>
            <hr>
            <h6>Labels</h6>
            <div id="network-labels-container">
                <!-- Labels will be added here dynamically -->
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="add-network-label-btn">Add Label</button>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="save-network-btn">Create Network</button>
      </div>
    </div>
  </div>
</div>

<!-- Join Swarm Modal -->
<div class="modal fade" id="joinSwarmModal" tabindex="-1" aria-labelledby="joinSwarmModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="joinSwarmModalLabel">Join Swarm Cluster</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p>Select a manager node to join this host to its Swarm cluster as a worker.</p>
        <form id="join-swarm-form">
            <input type="hidden" name="target_host_id" id="join-swarm-target-host-id">
            <div class="mb-3">
                <label for="manager-host-select" class="form-label">Select Manager Host</label>
                <select class="form-select" id="manager-host-select" name="manager_host_id" required></select>
            </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="confirm-join-swarm-btn">Join Cluster</button>
      </div>
    </div>
  </div>
</div>

<!-- Cron Log Modal -->
<div class="modal fade" id="cronLogModal" tabindex="-1" aria-labelledby="cronLogModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="cronLogModalLabel">Cron Job Log</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body bg-dark text-light font-monospace">
        <pre id="cron-log-content">Loading log...</pre>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- Preview Config Modal -->
<div class="modal fade" id="previewConfigModal" tabindex="-1" aria-labelledby="previewConfigModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="previewConfigModalLabel">Preview Current Configuration</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="linter-results-container" class="mb-3"></div>
        <pre><code id="preview-yaml-content-container" class="language-yaml">Loading...</code></pre>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
        <button type="button" id="deploy-from-preview-btn" class="btn btn-success"><i class="bi bi-rocket-takeoff"></i> Deploy Konfigurasi Ini</button>
      </div>
    </div>
  </div>
</div>

<!-- Sync to Git Modal -->
<div class="modal fade" id="syncGitModal" tabindex="-1" aria-labelledby="syncGitModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="syncGitModalLabel">Pending Git Changes</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p>The following changes will be committed and pushed to the repository.</p>
        <div id="git-diff-output">
            <!-- Diff2HTML will render here -->
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-success" id="confirm-git-sync-btn"><i class="bi bi-git"></i> Confirm and Sync Changes</button>
      </div>
    </div>
  </div>
</div>

<!-- Global Search Modal -->
<div class="modal" id="globalSearchModal" tabindex="-1" aria-labelledby="globalSearchModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-scrollable modal-xl">
    <div class="modal-content">
      <div class="progress" id="global-search-progress" style="height: 3px; display: none;">
          <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 100%"></div>
      </div>
      <div class="modal-header border-0">
        <div class="input-group global-search-input-group">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="text" class="form-control" id="global-search-input" placeholder="Search for hosts, stacks, containers, images, volumes..." autocomplete="off">
            <button type="button" class="btn-close" id="global-search-clear-btn" style="display: none;" aria-label="Clear search"></button>
        </div>
      </div>
      <div class="modal-body pt-0">
        <div id="global-search-results-container">
            <div class="text-center text-muted p-4">
                <p class="mb-1">Start typing to search.</p>
                <p class="small">You can search for hosts, stacks, containers, images, volumes, networks, routers, services, and pages.</p>
            </div>
        </div>
      </div>
      <div class="modal-footer justify-content-start small text-muted border-0">
        <span><kbd>↑</kbd> <kbd>↓</kbd> to navigate</span>
        <span class="ms-3"><kbd>Enter</kbd> to select</span>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-core.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/plugins/autoloader/prism-autoloader.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/diff@5.1.0/dist/diff.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/js-yaml@4.1.0/dist/js-yaml.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/diff2html@3.4.47/bundles/js/diff2html-ui.min.js"></script>
<!-- CodeMirror JS -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.15/codemirror.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.15/mode/yaml/yaml.min.js"></script>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="<?= base_url('assets/js/main.js') ?>"></script>

<script>
    // --- Efek Bayangan pada Header saat Scroll ---
    (function() {
        const topNavbar = document.querySelector('.top-navbar');
        if (!topNavbar) return;

        const handleScroll = () => {
            if (window.scrollY > 10) {
                topNavbar.classList.add('scrolled');
            } else {
                topNavbar.classList.remove('scrolled');
            }
        };

        // Terapkan pada saat load dan saat scroll
        handleScroll();
        document.addEventListener('scroll', handleScroll, { passive: true });
    })();

    // --- Logika untuk Tombol Monitoring Alert ---
    (function() {
        const toggleBtn = document.getElementById('monitoring-alert-toggle-btn');
        const container = document.querySelector('.monitoring-alerts-container');

        if (toggleBtn && container) {
            toggleBtn.addEventListener('click', function() {
                container.classList.toggle('active');
            });
        }
    })();

    // --- Global Search (Ctrl+K) Logic ---
    (function() {
        const searchModalEl = document.getElementById('globalSearchModal');
        if (!searchModalEl) return;

        const searchModal = new bootstrap.Modal(searchModalEl);
        const searchBtn = document.getElementById('global-search-btn');
        const searchInput = document.getElementById('global-search-input');
        const resultsContainer = document.getElementById('global-search-results-container');
        const clearBtn = document.getElementById('global-search-clear-btn'); // Tombol clear baru
        const progressBar = document.getElementById('global-search-progress');
        let clearOnHide = true; // Flag to control clearing on hide
        let focusedPane = 'results'; // 'results' or 'categories'
        let searchTimeout;
        
        // --- IDE: Recent Searches Logic ---
        const RECENT_SEARCHES_KEY = 'global_recent_searches';
        const MAX_RECENT_SEARCHES = 5;

        function getRecentSearches() {
            return JSON.parse(localStorage.getItem(RECENT_SEARCHES_KEY) || '[]');
        }

        function addRecentSearch(query) {
            if (!query) return;
            let searches = getRecentSearches();
            searches = searches.filter(s => s.toLowerCase() !== query.toLowerCase());
            searches.unshift(query);
            searches = searches.slice(0, MAX_RECENT_SEARCHES);
            localStorage.setItem(RECENT_SEARCHES_KEY, JSON.stringify(searches));
        }

        // Keyboard shortcut to open modal
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                e.preventDefault();
                searchModal.show();
            }
        });

        // Focus input when modal is shown
        searchModalEl.addEventListener('shown.bs.modal', function() {
            searchInput.focus();
            if (!searchInput.value) renderRecentSearches(); // Show recent searches if input is empty
            if (searchBtn) searchBtn.classList.add('active');
        });

        // Clear input when modal is hidden
        searchModalEl.addEventListener('hidden.bs.modal', function() {
            if (searchBtn) searchBtn.classList.remove('active');
            // if (clearOnHide) { // Selalu bersihkan saat ditutup
                searchInput.value = '';
                if (clearBtn) clearBtn.style.display = 'none';
                resultsContainer.innerHTML = `
                    <div class="text-center text-muted p-4">
                        <p class="mb-1">Start typing to search.</p>
                        <p class="small">You can search for hosts, stacks, incidents, users, templates, containers, images, volumes, networks, routers, services, groups, middlewares, and pages.</p>
                    </div>`;
                // clearOnHide = false; // Reset the flag for the next time
            // }
        });

        function renderRecentSearches() {
            const searches = getRecentSearches();
            if (searches.length === 0) {
                resultsContainer.innerHTML = `
                    <div class="text-center text-muted p-4">
                        <p class="mb-1">Start typing to search.</p>
                        <p class="small">You can search for hosts, stacks, incidents, users, templates, containers, images, volumes, networks, routers, services, groups, middlewares, and pages.</p>
                    </div>`;
                return;
            }

            let html = '<ul class="list-group list-group-flush">';
            html += `<li class="list-group-item list-group-item-light small text-muted d-flex justify-content-between align-items-center">
                        <span>Recent Searches</span>
                        <button class="btn btn-sm btn-link text-muted p-0" id="clear-recent-searches-btn" title="Clear recent searches">Clear</button>
                     </li>`;
            searches.forEach(query => {
                html += `<a href="#" class="list-group-item list-group-item-action d-flex align-items-center recent-search-item" data-query="${query}">
                            <i class="bi bi-clock-history me-3 text-muted"></i>
                            <span>${query}</span>
                         </a>`;
            });
            html += '</ul>';
            resultsContainer.innerHTML = html;
        }

        const resetModalFooter = () => {
            const modalFooter = searchModalEl.querySelector('.modal-footer');
            if (modalFooter) {
                modalFooter.innerHTML = `<span><kbd>↑</kbd> <kbd>↓</kbd> to navigate</span><span class="ms-3"><kbd>Enter</kbd> to select</span>`;
            }
        };

        const performSearch = () => {
            const query = searchInput.value.trim();
            if (progressBar) progressBar.style.display = 'block'; // Tampilkan progress bar
            if (clearBtn) clearBtn.style.display = query ? 'block' : 'none';
            // if (clearBtn) clearBtn.style.display = query ? 'block' : 'none'; // Dihapus karena sudah ada di tempat lain

            // --- IDE: Command Palette Logic ---
            let command = null;
            let argument = '';
            let searchUrl = `${basePath}/api/search?q=${encodeURIComponent(query)}`;

            const commandPatterns = {
                restart: /^restart\s+(.+)/i,
                logs: /^logs(?:\s+for)?\s+(.+)/i,
                deploy: /^deploy\s+(.+)/i,
                edit: /^edit\s+(.+)/i,
            };

            for (const cmd in commandPatterns) {
                const match = query.match(commandPatterns[cmd]);
                if (match) {
                    command = cmd;
                    argument = match[1].trim();
                    // Modify the search URL to send command info to the backend
                    searchUrl = `${basePath}/api/search?command=${cmd}&arg=${encodeURIComponent(argument)}`;
                    break;
                }
            }

            if (query.length < 2 && !command) { // Allow commands with short args
                resultsContainer.innerHTML = '<div class="text-center text-muted p-4">Please enter at least 2 characters.</div>';
                if (progressBar) progressBar.style.display = 'none'; // Sembunyikan progress bar
                resetModalFooter();
                return;
            }

            const startTime = performance.now();

            // --- IDE: Redesign Search Results ---
            fetch(searchUrl)
                .then(response => response.json())
                .then(result => {
                    const endTime = performance.now();
                    addRecentSearch(query); // Add to recent searches on successful fetch

                    const duration = ((endTime - startTime) / 1000).toFixed(2);

                    if (result.status !== 'success') throw new Error(result.message);

                    const resultCount = result.data.length;
                    const modalFooter = searchModalEl.querySelector('.modal-footer');
                    if (modalFooter) {
                        modalFooter.innerHTML = `<span><kbd>↑</kbd> <kbd>↓</kbd> to navigate</span><span class="ms-3"><kbd>Enter</kbd> to select</span><span class="ms-auto text-muted small">${resultCount} results in ${duration}s</span>`;
                    }

                    if (result.data.length === 0) {
                        resultsContainer.innerHTML = '<div class="text-center text-muted p-4">No results found.</div>';
                        if (progressBar) progressBar.style.display = 'none'; // Sembunyikan progress bar
                        return;
                    }

                    // --- NEW: Two-column layout logic ---
                    const groupedResults = result.data.reduce((acc, item) => {
                        (acc[item.category] = acc[item.category] || []).push(item);
                        return acc;
                    }, {});

                    const categories = Object.keys(groupedResults);

                    // --- IDE: Definisikan ikon untuk setiap kategori ---
                    const categoryIcons = {
                        'Hosts': 'bi-hdd-network-fill',
                        'Stacks': 'bi-stack',
                        'Containers': 'bi-box-seam',
                        'Images': 'bi-hdd-stack',
                        'Volumes': 'bi-database',
                        'Networks': 'bi-diagram-3',
                        'Routers': 'bi-bezier2',
                        'Services': 'bi-gear-wide-connected',
                        'Middlewares': 'bi-layers-fill',
                        'Groups': 'bi-collection-fill',
                        'Pages': 'bi-file-earmark-text',
                        'Incidents': 'bi-shield-fill-exclamation',
                        'Users': 'bi-people-fill',
                        'Templates': 'bi-journal-code',
                        'Cron Jobs': 'bi-clock-history',
                        'Security': 'bi-shield-lock-fill',
                        'Default': 'bi-app-indicator' // Ikon fallback
                    };

                    let categoriesHtml = '<ul class="nav flex-column search-categories-list">';
                    categoriesHtml += `<li class="nav-item"><a class="nav-link active" href="#" data-category="all"><i class="bi bi-grid-fill me-2"></i>All Results <span class="badge bg-secondary float-end">${result.data.length}</span></a></li>`;
                    categories.forEach(cat => {
                        categoriesHtml += `<li class="nav-item"><a class="nav-link" href="#" data-category="${cat}"><i class="bi ${categoryIcons[cat] || categoryIcons['Default']} me-2"></i>${cat} <span class="badge bg-secondary float-end">${groupedResults[cat].length}</span></a></li>`;
                    });
                    categoriesHtml += '</ul>';

                    const renderResults = (filterCategory = 'all') => {
                        let resultsHtml = '<ul class="list-group list-group-flush search-results-list">';
                        const itemsToRender = (filterCategory === 'all') ? result.data : (groupedResults[filterCategory] || []);

                        if (itemsToRender.length === 0) {
                            return '<div class="text-center text-muted p-4">No results in this category.</div>';
                        }

                        itemsToRender.forEach(item => {
                            resultsHtml += `
                                <li class="list-group-item global-search-item-wrapper p-0">
                                    <a href="${item.url}" class="text-decoration-none text-body d-flex justify-content-between align-items-center w-100 global-search-item p-3" data-category="${item.category}" ${item.js_action ? `data-js-action="${item.js_action}"` : ''}>
                                        <div class="d-flex align-items-center flex-grow-1">
                                            <i class="bi ${item.icon || 'bi-app'} me-3 fs-5 text-primary"></i>
                                            <div class="flex-grow-1">
                                                <div class="fw-bold">${item.name}</div>
                                                ${item.description ? `<small class="text-muted">${item.description}</small>` : ''}
                                            </div>
                                        </div>
                                        ${item.is_primary_action ? `
                                            <div class="ms-2">
                                                <span class="badge bg-primary"><i class="bi bi-arrow-return-left me-1"></i>Enter</span>
                                            </div>
                                        ` : ''}
                                        <div class="ms-2 me-3 search-item-actions">
                                            ${(item.actions || []).map(action => `
                                                <button type="button" 
                                                   class="btn btn-sm btn-outline-secondary quick-action-btn" 
                                                   data-action-type="${action.type}" 
                                                   data-action-url="${action.url || ''}"
                                                   data-action-target="${action.target || ''}"
                                                   data-action-value="${action.value || ''}"
                                                   title="${action.name}">
                                                    <i class="bi ${action.icon || 'bi-box-arrow-up-right'}"></i> <span class="visually-hidden">${action.name}</span>
                                                </button>`).join('')}
                                        </div>
                                    </a>
                                </li>`;
                        });
                        resultsHtml += '</ul>';
                        return resultsHtml;
                    };

                    resultsContainer.innerHTML = `
                        <div class="d-flex search-layout">
                            <div class="search-categories-pane">${categoriesHtml}</div>
                            <div class="search-results-pane flex-grow-1">${renderResults('all')}</div>
                        </div>
                    `;

                    // Add event listener for category clicks
                    const categoryPane = resultsContainer.querySelector('.search-categories-pane');
                    const resultsPane = resultsContainer.querySelector('.search-results-pane');
                    
                    categoryPane.addEventListener('click', e => {
                        const link = e.target.closest('.nav-link');
                        if (!link) return;
                        e.preventDefault();
                        e.stopPropagation(); // Prevent the main link from being triggered

                        // --- IDE: Theme-aware active category (Enhanced for Contrast) ---
                        const currentActive = categoryPane.querySelector('.nav-link.active');
                        if (currentActive) {
                            currentActive.classList.remove('active');
                            // Reset inline styles when not active
                            currentActive.style.backgroundColor = '';
                            currentActive.style.color = '';
                        }

                        link.classList.add('active');

                        // Re-render results
                        const category = link.dataset.category;
                        resultsPane.innerHTML = renderResults(category);
                    });
                })
                .catch(error => {
                    resultsContainer.innerHTML = `<div class="alert alert-danger m-3">${error.message}</div>`;
                })
                .finally(() => {
                    // Selalu sembunyikan progress bar setelah selesai
                    if (progressBar) progressBar.style.display = 'none';
                });
        };

        // --- FIX: Hide modal on result click ---
        // The main SPA router in main.js handles the navigation.
        // We just need to ensure the modal closes after a selection is made.
        resultsContainer.addEventListener('click', function(e) {
            const recentSearchItem = e.target.closest('.recent-search-item');
            const clearRecentBtn = e.target.closest('#clear-recent-searches-btn');

            if (recentSearchItem) {
                e.preventDefault();
                e.stopPropagation(); // Hentikan event agar tidak ditangani oleh listener lain
                searchInput.value = recentSearchItem.dataset.query;
                performSearch();
                return;
            }

            if (clearRecentBtn) {
                localStorage.removeItem(RECENT_SEARCHES_KEY);
                renderRecentSearches();
                return;
            }

            // FIX: Target the wrapper to check for clicks on the item or quick actions inside it.
            const searchItemWrapper = e.target.closest('.global-search-item-wrapper');
            if (!searchItemWrapper) return;
            
            // --- FIX: Handle quick action button clicks ---
            const quickActionBtn = e.target.closest('.quick-action-btn');
            if (quickActionBtn) {
                e.preventDefault();
                e.stopPropagation(); // Prevent the main link from being triggered
                
                const actionType = quickActionBtn.dataset.actionType;
                if (actionType === 'copy') {
                    const valueToCopy = quickActionBtn.dataset.actionValue;
                    navigator.clipboard.writeText(valueToCopy).then(() => {
                        showToast(`'${valueToCopy}' copied to clipboard.`, true);
                    });
                } else if (actionType === 'link') {
                    const url = quickActionBtn.dataset.actionUrl;
                    if (url) {
                        // FIX: Ensure the URL is absolute for the SPA router.
                        const absoluteUrl = new URL(url, window.location.origin).href;
                        loadPage(absoluteUrl); // Use SPA navigation
                        searchModal.hide();
                    }
                } else if (actionType === 'modal') {
                    const targetSelector = quickActionBtn.dataset.actionTarget;
                    const modalEl = document.querySelector(targetSelector);
                    if (modalEl) {
                        // Pass data from the quick action button to the modal
                        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
                        // We need to simulate the 'relatedTarget' that Bootstrap uses
                        // to pass data to the 'show.bs.modal' event listener.
                        modalEl.relatedTarget = quickActionBtn;
                        modal.show(quickActionBtn);
                        
                        // Hide the search modal after opening the target modal
                        searchModal.hide();
                    }
                }
                // Stop further execution, as we only wanted to perform the quick action.
                return;
            }
            // --- End FIX ---
            
            const searchItem = searchItemWrapper.querySelector('.global-search-item');
            if (searchItem) {
                // The SPA router in main.js will handle the navigation. We just need to hide the modal.
                searchModal.hide();
            }
        });

        // --- IDE: Input and Clear Button Logic ---
        searchInput.addEventListener('input', debounce(performSearch, 300));

        if (clearBtn) {
            clearBtn.addEventListener('click', function() {
                searchInput.value = '';
                this.style.display = 'none';
                // Tampilkan kembali recent searches atau pesan default
                const recentSearches = getRecentSearches();
                if (recentSearches.length > 0) {
                    renderRecentSearches();
                } else {
                    resultsContainer.innerHTML = `<div class="text-center text-muted p-4"><p class="mb-1">Start typing to search.</p></div>`;
                }
                resetModalFooter();
                searchInput.focus();
            });
        }

        // Keyboard navigation for results
        searchModalEl.addEventListener('keydown', function(e) {
            // FIX: Target the actual link elements for navigation logic
            const resultItems = resultsContainer.querySelectorAll('.global-search-item');
            const categoryItems = resultsContainer.querySelectorAll('.search-categories-list .nav-link');

            if (e.key === 'Tab') {
                e.preventDefault();
                if (focusedPane === 'results') {
                    focusedPane = 'categories';
                    const activeResult = resultsContainer.querySelector('.global-search-item.active');
                    activeResult?.classList.remove('active');
                    activeResult?.closest('.global-search-item-wrapper')?.classList.remove('active');
                    if (categoryItems.length > 0) categoryItems[0].classList.add('active');
                } else { // categories
                    focusedPane = 'results';
                    const activeCategory = resultsContainer.querySelector('.search-categories-list .nav-link.active');
                    activeCategory?.classList.remove('active');
                    if (resultItems.length > 0) {
                        resultItems[0].classList.add('active');
                        resultItems[0].closest('.global-search-item-wrapper').classList.add('active');
                    }
                }
                return;
            }

            if (['ArrowUp', 'ArrowDown', 'Enter'].indexOf(e.key) === -1) {
                return;
            }

            if (focusedPane === 'categories') {
                if (categoryItems.length === 0) return;
                e.preventDefault();
                const activeCategory = resultsContainer.querySelector('.search-categories-list .nav-link.active');
                let currentIndex = activeCategory ? Array.from(categoryItems).indexOf(activeCategory) : -1;
                activeCategory?.classList.remove('active');

                if (e.key === 'ArrowDown') currentIndex = (currentIndex + 1) % categoryItems.length;
                else if (e.key === 'ArrowUp') currentIndex = (currentIndex - 1 + categoryItems.length) % categoryItems.length;
                
                categoryItems[currentIndex].classList.add('active');
                categoryItems[currentIndex].click(); // Trigger filter
                if (e.key === 'Enter') focusedPane = 'results'; // Move focus back to results on Enter
                return;
            }

            if (resultItems.length === 0) return;
            e.preventDefault();

            const activeItem = resultsContainer.querySelector('.global-search-item.active');
            const activeWrapper = activeItem ? activeItem.closest('.global-search-item-wrapper') : null;
            let currentIndex = activeItem ? Array.from(resultItems).indexOf(activeItem) : -1;

            if (e.key === 'ArrowDown') {
                activeItem?.classList.remove('active');
                activeWrapper?.classList.remove('active');
                currentIndex = (currentIndex + 1) % resultItems.length;
                resultItems[currentIndex].classList.add('active');
                resultItems[currentIndex].closest('.global-search-item-wrapper').classList.add('active');
                resultItems[currentIndex].scrollIntoView({ block: 'nearest' });
            } else if (e.key === 'ArrowUp') {
                activeItem?.classList.remove('active');
                activeWrapper?.classList.remove('active');
                currentIndex = (currentIndex - 1 + resultItems.length) % resultItems.length;
                resultItems[currentIndex].classList.add('active');
                resultItems[currentIndex].closest('.global-search-item-wrapper').classList.add('active');
                resultItems[currentIndex].scrollIntoView({ block: 'nearest' });
            } else if (e.key === 'Enter' && activeItem) {
                // --- IDE: Command Palette Enter Key Logic ---
                // Check if there's a primary action button (like Restart, View Logs)
                const primaryActionBtn = activeItem.querySelector('.quick-action-btn[data-is-primary="true"]');
                if (primaryActionBtn) {
                    primaryActionBtn.click();
                } else {
                    // If no primary action, perform the default navigation
                    const link = activeItem; // The active item is the link itself
                    link.click();
                }
                // --- End IDE ---
            }
        });
    })();
</script>
</body>
</html> 
<?php
} // --- End of non-AJAX wrapper ---
?>