/**
 * Displays a toast notification.
 * @param {string} message The message to display.
 * @param {boolean} isSuccess Whether the toast should be a success or error style.
 */
function showToast(message, isSuccess = true) {
    const toastContainer = document.getElementById('toast-container');
    if (!toastContainer) return;

    const toastId = 'toast-' + Date.now();
    const toastIcon = isSuccess
        ? '<i class="bi bi-check-circle-fill text-success me-2"></i>'
        : '<i class="bi bi-x-circle-fill text-danger me-2"></i>';

    const toastHTML = `
        <div id="${toastId}" class="toast" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="toast-header">
                ${toastIcon}
                <strong class="me-auto">${isSuccess ? 'Sukses' : 'Error'}</strong>
                <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
            <div class="toast-body">
                ${message}
            </div>
        </div>
    `;

    toastContainer.insertAdjacentHTML('beforeend', toastHTML);
    const toastElement = document.getElementById(toastId);
    const toast = new bootstrap.Toast(toastElement, { delay: 5000 });
    toast.show();
    toastElement.addEventListener('hidden.bs.toast', () => toastElement.remove());
}

/**
 * Returns a function, that, as long as it continues to be invoked, will not
 * be triggered. The function will be called after it stops being called for
 * N milliseconds.
 * @param {Function} func The function to debounce.
 * @param {number} delay The delay in milliseconds.
 */
function debounce(func, delay) {
    let timeout;
    return function(...args) {
        clearTimeout(timeout);
        timeout = setTimeout(() => func.apply(this, args), delay);
    };
}

/**
 * Formats bytes into a human-readable string.
 * @param {number} bytes The number of bytes.
 * @param {number} decimals The number of decimal places.
 * @returns {string} The formatted string.
 */
function formatBytes(bytes, decimals = 2) {
    if (!+bytes) return '0 Bytes';

    const k = 1024;
    const dm = decimals < 0 ? 0 : decimals;
    const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];

    const i = Math.floor(Math.log(bytes) / Math.log(k));

    return `${parseFloat((bytes / Math.pow(k, i)).toFixed(dm))} ${sizes[i]}`;
}


document.addEventListener('DOMContentLoaded', function () {

    // --- Sidebar Active Link Logic ---
    // This script highlights the current page's link in the sidebar.
    try {
        const currentPath = window.location.pathname;
        const sidebarLinks = document.querySelectorAll('.sidebar-nav .nav-link');

        sidebarLinks.forEach(link => {
            const linkPath = new URL(link.href).pathname;

            // Normalize paths by removing trailing slashes (if they exist and it's not the root)
            const cleanCurrentPath = currentPath.length > 1 ? currentPath.replace(/\/$/, "") : currentPath;
            const cleanLinkPath = linkPath.length > 1 ? linkPath.replace(/\/$/, "") : linkPath;

            if (cleanLinkPath === cleanCurrentPath) {
                link.classList.add('active');
            }
        });
    } catch (e) {
        console.error("Error setting active sidebar link:", e);
    }

    // --- Sidebar Toggle Logic ---
    const sidebarToggleBtn = document.getElementById('sidebar-toggle-btn');
    if (sidebarToggleBtn) {
        sidebarToggleBtn.addEventListener('click', () => {
            document.body.classList.toggle('sidebar-collapsed');
            // Save the state to localStorage
            const isCollapsed = document.body.classList.contains('sidebar-collapsed');
            localStorage.setItem('sidebar-collapsed', isCollapsed);
        });
    }

    /**
     * Fetches paginated data and updates the corresponding UI section.
     * @param {string} type - The type of data to fetch ('routers' or 'services').
     * @param {number} page - The page number to fetch.
     * @param {number} limit - The number of items per page.
     */
    function loadPaginatedData(type, page = 1, limit = 10, preserveScroll = false, extraParams = {}) {
        const scrollY = window.scrollY;
        const searchForm = document.querySelector(`.search-form[data-type="${type}"]`);
        const searchTerm = searchForm ? searchForm.querySelector('input[type="text"]').value : '';
        const container = document.getElementById(`${type}-container`);
        const paginationContainer = document.getElementById(`${type}-pagination`);
        const infoContainer = document.getElementById(`${type}-info`);
        if (!container || !paginationContainer || !infoContainer) return;

        // Show a loading state
        if (type === 'services') {
             container.innerHTML = '<div class="text-center"><div class="spinner-border spinner-border-sm" role="status"><span class="visually-hidden">Loading...</span></div></div>';
        } else { // for 'routers' and 'history' which are in tables
            const colspan = container.closest('table')?.querySelector('thead tr')?.childElementCount || 6;
            container.innerHTML = `<tr><td colspan="${colspan}" class="text-center"><div class="spinner-border spinner-border-sm" role="status"><span class="visually-hidden">Loading...</span></div></td></tr>`;
        }

        let fetchUrl = `${basePath}/api/data?type=${type}&page=${page}&limit=${limit}&search=${encodeURIComponent(searchTerm)}`;

        if (type === 'routers') {
            const groupFilter = document.getElementById('router-group-filter');
            if (groupFilter && groupFilter.value) {
                fetchUrl += `&group_id=${groupFilter.value}`;
            }
        }
        if (type === 'services') {
            const groupFilter = document.getElementById('service-group-filter');
            if (groupFilter && groupFilter.value) {
                fetchUrl += `&group_id=${groupFilter.value}`;
            }
        }
        if (type === 'middlewares') {
            const groupFilter = document.getElementById('middleware-group-filter');
            if (groupFilter && groupFilter.value) {
                fetchUrl += `&group_id=${groupFilter.value}`;
            }
        }
        if (type === 'history') {
            const showArchived = document.getElementById('show-archived-checkbox')?.checked || false;
            fetchUrl += `&show_archived=${showArchived}`;
        }

        // Add extra params to URL
        for (const key in extraParams) {
            fetchUrl += `&${key}=${encodeURIComponent(extraParams[key])}`;
        }
        if (type === 'activity_log') {
            fetchUrl = `${basePath}/api/logs?page=${page}&limit=${limit}&search=${encodeURIComponent(searchTerm)}`;
        }

        fetch(fetchUrl)
            .then(response => response.json())
            .then(data => {
                if (data.html) {
                    container.innerHTML = data.html;
                } else {
                    const tableTypes = ['routers', 'history', 'users', 'groups', 'middlewares', 'activity_log', 'hosts', 'stacks', 'templates'];
                    if (tableTypes.includes(type)) {
                        const colspan = container.closest('table')?.querySelector('thead tr')?.childElementCount || 6;
                        container.innerHTML = `<tr><td colspan="${colspan}" class="text-center">No data found.</td></tr>`;
                    } else { // for services
                        container.innerHTML = '<div class="text-center">No data found.</div>';
                    }
                }
                infoContainer.innerHTML = data.info;

                // Initialize Bootstrap tooltips for the new content
                const tooltipTriggerList = container.querySelectorAll('[data-bs-toggle="tooltip"]');
                [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl));

                // Build pagination controls
                let paginationHtml = '';
                if (data.total_pages > 1) {
                    paginationHtml += '<ul class="pagination pagination-sm mb-0">';
                    // Previous button
                    paginationHtml += `<li class="page-item ${data.current_page <= 1 ? 'disabled' : ''}"><a class="page-link" href="#" data-page="${data.current_page - 1}" data-type="${type}">«</a></li>`;
                    // Page numbers
                    for (let i = 1; i <= data.total_pages; i++) {
                        paginationHtml += `<li class="page-item ${data.current_page == i ? 'active' : ''}"><a class="page-link" href="#" data-page="${i}" data-type="${type}">${i}</a></li>`;
                    }
                    // Next button
                    paginationHtml += `<li class="page-item ${data.current_page >= data.total_pages ? 'disabled' : ''}"><a class="page-link" href="#" data-page="${parseInt(data.current_page) + 1}" data-type="${type}">»</a></li>`;
                    paginationHtml += '</ul>';
                }
                paginationContainer.innerHTML = paginationHtml;

                // Update limit selector
                const limitSelector = document.querySelector(`select[name="limit_${type}"]`);
                if (limitSelector) {
                    limitSelector.value = data.limit;
                }

                // Save state to localStorage
                localStorage.setItem(`${type}_page`, data.current_page);
                localStorage.setItem(`${type}_limit`, data.limit);

                if (preserveScroll) {
                    window.scrollTo(0, scrollY);
                }
            })
            .catch(error => {
                console.error('Error loading data:', error);
                 if (type === 'services') {
                    container.innerHTML = '<div class="text-center text-danger">Failed to load data.</div>';
                } else {
                    const colspan = container.closest('table')?.querySelector('thead tr')?.childElementCount || 6;
                    container.innerHTML = `<tr><td colspan="${colspan}" class="text-center text-danger">Failed to load data.</td></tr>`;
                }
            });
    }

    // --- Main Event Delegation ---
    document.body.addEventListener('click', function(e) {
        const pageLink = e.target.closest('.page-link');
        const resetButton = e.target.closest('.reset-search-btn');
        const deleteButton = e.target.closest('.delete-btn');
        const deployButton = e.target.closest('.deploy-btn');
        const cleanupButton = e.target.closest('#cleanup-btn');
        const viewHistoryBtn = e.target.closest('.view-history-btn');
        const copyButton = e.target.closest('.copy-btn');
        const testConnectionBtn = e.target.closest('.test-connection-btn');
        const testGitConnectionBtn = e.target.closest('#test-git-connection-btn');

        if (testGitConnectionBtn) {
            e.preventDefault();
            const gitUrlInput = document.getElementById('git_url');
            if (!gitUrlInput || !gitUrlInput.value) {
                showToast('Please enter a Git URL first.', false);
                return;
            }

            const gitUrl = gitUrlInput.value;
            const originalBtnText = testGitConnectionBtn.innerHTML;
            testGitConnectionBtn.disabled = true;
            testGitConnectionBtn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Testing...`;

            const formData = new FormData();
            formData.append('git_url', gitUrl);

            fetch(`${basePath}/api/git/test`, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json().then(data => ({ ok: response.ok, data })))
            .then(({ ok, data }) => {
                showToast(data.message, ok);
            })
            .catch(error => {
                showToast(error.message || 'An unknown network error occurred.', false);
            })
            .finally(() => {
                testGitConnectionBtn.disabled = false;
                testGitConnectionBtn.innerHTML = originalBtnText;
            });
            return;
        }

        if (copyButton) {
            e.preventDefault();
            const textToCopy = copyButton.dataset.clipboardText;
            navigator.clipboard.writeText(textToCopy).then(() => {
                const originalContent = copyButton.innerHTML;
                copyButton.innerHTML = '<i class="bi bi-check-lg text-success"></i>';
                copyButton.disabled = true;
                setTimeout(() => {
                    copyButton.innerHTML = originalContent;
                    copyButton.disabled = false;
                }, 1500);
            }).catch(err => {
                console.error('Failed to copy text: ', err);
                // Fallback for older browsers or insecure contexts
                alert('Failed to copy text.');
            });
            return;
        }

        if (testConnectionBtn) {
            e.preventDefault();
            const hostId = testConnectionBtn.dataset.id;
            const url = `${basePath}/hosts/${hostId}/test`;
            const originalIcon = testConnectionBtn.innerHTML;

            testConnectionBtn.disabled = true;
            testConnectionBtn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>`;

            fetch(url, { method: 'POST' })
                .then(response => response.json().then(data => ({ ok: response.ok, data })))
                .then(({ ok, data }) => {
                    showToast(data.message, ok);
                })
                .catch(error => {
                    showToast(error.message || 'An unknown network error occurred.', false);
                })
                .finally(() => {
                    testConnectionBtn.disabled = false;
                    testConnectionBtn.innerHTML = originalIcon;
                });
            return;
        }

        if (pageLink) {
            e.preventDefault();
            const type = pageLink.dataset.type;
            const page = pageLink.dataset.page;
            const limit = document.querySelector(`select[name="limit_${type}"]`).value;
            loadPaginatedData(type, page, limit, true);
            return;
        }

        if (resetButton) {
            e.preventDefault();
            const form = resetButton.closest('.search-form');
            const input = form.querySelector('input[type="text"]');
            if (input.value !== '') {
                input.value = ''; // Clear the input
                // Trigger the search to show all results
                const type = form.dataset.type;
                const limit = document.querySelector(`select[name="limit_${type}"]`).value;
                loadPaginatedData(type, 1, limit, false);
            }
            return;
        }

        if (cleanupButton) {
            e.preventDefault();
            if (confirm('Are you sure you want to permanently delete all archived history records older than 30 days?')) {
                const originalButtonText = cleanupButton.innerHTML;
                cleanupButton.disabled = true;
                cleanupButton.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Cleaning up...`;

                fetch(`${basePath}/api/history/cleanup`, {
                    method: 'POST'
                })
                .then(response => response.json().then(data => ({ ok: response.ok, data })))
                .then(({ ok, data }) => {
                    showToast(data.message, ok);
                })
                .catch(error => {
                    showToast(error.message || 'An unknown error occurred.', false);
                })
                .finally(() => {
                    cleanupButton.disabled = false;
                    cleanupButton.innerHTML = originalButtonText;
                });
            }
            return;
        }

        if (viewHistoryBtn) {
            const historyId = viewHistoryBtn.dataset.id;
            const contentContainer = document.getElementById('yaml-content-container');
            if (contentContainer) {
                contentContainer.textContent = 'Loading...';
                fetch(`${basePath}/history/${historyId}/content`)
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Network response was not ok.');
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.content) {
                            contentContainer.textContent = data.content;
                            Prism.highlightElement(contentContainer);
                        }
                    })
                    .catch(error => {
                        contentContainer.textContent = 'Error loading content: ' + error.message;
                    });
            }
            return;
        }

        if (deployButton) {
            e.preventDefault();
            const historyId = deployButton.dataset.id;
            if (confirm(`Are you sure you want to deploy configuration #${historyId}? This will become the new active configuration.`)) {
                const formData = new FormData();
                formData.append('id', historyId);
                const url = `${basePath}/history/${historyId}/deploy`;

                fetch(url, { method: 'POST', body: formData })
                    .then(response => response.json().then(data => ({ ok: response.ok, data })))
                    .then(({ ok, data }) => {
                        showToast(data.message, ok);
                        if (ok) {
                            // Reload the history grid to reflect the change
                            const limit = document.querySelector('select[name="limit_history"]').value;
                            const currentPage = localStorage.getItem('history_page') || 1;
                            loadPaginatedData('history', currentPage, limit, true);
                        }
                    });
            }
            return;
        }

        if (deleteButton) {
            e.preventDefault();
            const url = deleteButton.dataset.url;
            const confirmMessage = deleteButton.dataset.confirmMessage;
            const dataType = deleteButton.dataset.type; // For AJAX refresh

            if (confirm(confirmMessage)) {
                const formData = new FormData();
                formData.append('id', deleteButton.dataset.id);

                fetch(url, { method: 'POST', body: formData })
                    .then(response => response.json().then(data => ({ ok: response.ok, data })))
                    .then(({ ok, data }) => {
                        showToast(data.message, ok);
                        if (ok) {
                            const limit = document.querySelector(`select[name="limit_${dataType}"]`).value;
                            const currentPage = localStorage.getItem(`${dataType}_page`) || 1;
                            loadPaginatedData(dataType, currentPage, limit, true);
                        }
                    });
            }
            return;
        }
    });

    document.body.addEventListener('change', function(e) {
        const limitSelector = e.target.closest('.limit-selector');
        if (limitSelector) {
            const type = limitSelector.dataset.type;
            const limit = limitSelector.value;
            loadPaginatedData(type, 1, limit, true);
        }

        const groupFilter = e.target.closest('#router-group-filter');
        if (groupFilter) {
            const limit = document.querySelector('select[name="limit_routers"]').value;
            loadPaginatedData('routers', 1, limit);
        }

        const serviceGroupFilter = e.target.closest('#service-group-filter');
        if (serviceGroupFilter) {
            const limit = document.querySelector('select[name="limit_services"]').value;
            loadPaginatedData('services', 1, limit);
        }

        const middlewareGroupFilter = e.target.closest('#middleware-group-filter');
        if (middlewareGroupFilter) {
            const limit = document.querySelector('select[name="limit_middlewares"]').value;
            loadPaginatedData('middlewares', 1, limit);
        }

        const showArchivedCheckbox = e.target.closest('#show-archived-checkbox');
        if (showArchivedCheckbox) {
            // Reload history data when the checkbox state changes
            loadPaginatedData('history', 1, document.querySelector('select[name="limit_history"]').value);
        }
    });

    // Handle auto-search on typing (debounced)
    const debouncedSearch = debounce((type, limit) => {
        loadPaginatedData(type, 1, limit, false); // Reset to page 1 on new search
    }, 400);

    document.body.addEventListener('input', function(e) {
        const searchInput = e.target.closest('input[name^="search_"]');
        if (searchInput) {
            const form = searchInput.closest('.search-form');
            if (!form) return;
            const type = form.dataset.type;
            if (!type) return;

            const limitSelector = document.querySelector(`select[name="limit_${type}"]`);
            // This generic handler is only for paginated tables that have a limit selector.
            if (limitSelector) {
                const limit = limitSelector.value;
                debouncedSearch(type, limit);
            }
        }
    });

    // --- AJAX Add/Edit Form Logic ---
    const mainForm = document.getElementById('main-form');
    if (mainForm) {
        mainForm.addEventListener('submit', function(e) {
            e.preventDefault();

            const formData = new FormData(mainForm);
            const url = mainForm.action;
            const submitButton = mainForm.querySelector('button[type="submit"]');
            const originalButtonText = submitButton.innerHTML;
            const redirectUrl = mainForm.dataset.redirect; // e.g. '/users' or '/'
            const finalRedirectUrl = redirectUrl ? (basePath + redirectUrl) : (basePath + '/');

            submitButton.disabled = true;
            submitButton.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Menyimpan...`;

            fetch(url, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json().then(data => ({ ok: response.ok, data })))
            .then(({ ok, data }) => {
                if (ok) {
                    showToast(data.message, true);
                    setTimeout(() => {
                        window.location.href = finalRedirectUrl;
                    }, 1500); // Wait 1.5 seconds before redirect
                } else {
                    // If not ok, throw an error to be caught by .catch
                    throw new Error(data.message || 'An unknown error occurred.');
                }
            })
            .catch(error => {
                showToast(error.message, false);
                submitButton.disabled = false;
                submitButton.innerHTML = originalButtonText;
            });
        });
    }

    // --- Sync Stacks to Git Button ---
    const syncStacksBtn = document.getElementById('sync-stacks-btn');
    if (syncStacksBtn) {
        syncStacksBtn.addEventListener('click', function(e) {
            e.preventDefault();
            if (!confirm('Are you sure you want to sync all application stacks to the configured Git repository? This will overwrite existing files in the repo.')) {
                return;
            }

            const originalBtnText = this.innerHTML;
            this.disabled = true;
            this.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Syncing...`;

            fetch(`${basePath}/api/stacks/sync-to-git`, {
                method: 'POST'
            })
            .then(response => response.json().then(data => ({ ok: response.ok, data })))
            .then(({ ok, data }) => {
                showToast(data.message, ok);
            })
            .catch(error => {
                showToast(error.message || 'An unknown error occurred during sync.', false);
            })
            .finally(() => {
                this.disabled = false;
                this.innerHTML = originalBtnText;
            });
        });
    }


    // --- Dashboard Widgets Logic ---
    function loadDashboardWidgets() {
        const widgets = [
            'total-routers-widget',
            'total-services-widget',
            'total-middlewares-widget',
            'total-hosts-widget',
            'total-users-widget',
            'health-check-widget',
            'agg-total-containers-widget',
            'agg-running-containers-widget',
            'agg-stopped-containers-widget',
            'agg-reachable-hosts-widget'
        ];

        // Check if we are on the dashboard page by looking for one of the widgets
        if (!document.getElementById(widgets[0])) {
            return;
        }

        fetch(`${basePath}/api/dashboard-stats`)
            .then(response => response.json())
            .then(result => {
                if (result.status === 'success') {
                    const data = result.data;
                    document.getElementById('total-routers-widget').textContent = data.total_routers;
                    document.getElementById('total-services-widget').textContent = data.total_services;
                    document.getElementById('total-middlewares-widget').textContent = data.total_middlewares;
                    document.getElementById('total-hosts-widget').textContent = data.total_hosts;
                    document.getElementById('total-users-widget').textContent = data.total_users;

                    const healthWidget = document.getElementById('health-check-widget');
                    const healthCard = document.getElementById('health-check-card');
                    if (healthWidget && healthCard) {
                        if (data.health_status === 'OK') {
                            healthWidget.textContent = 'OK';
                            healthCard.classList.replace('bg-danger', 'bg-success');
                        } else {
                            healthWidget.textContent = 'Error';
                            healthCard.classList.replace('bg-success', 'bg-danger');
                        }
                    }

                    if (data.agg_stats) {
                        document.getElementById('agg-total-containers-widget').textContent = data.agg_stats.total_containers;
                        document.getElementById('agg-running-containers-widget').textContent = data.agg_stats.running_containers;
                        document.getElementById('agg-stopped-containers-widget').textContent = data.agg_stats.stopped_containers;
                        document.getElementById('agg-reachable-hosts-widget').textContent = `${data.agg_stats.reachable_hosts} / ${data.agg_stats.total_hosts_scanned}`;
                    }

                    if (data.per_host_stats) {
                        const container = document.getElementById('per-host-stats-container');
                        if (container) {
                            let html = '';
                            data.per_host_stats.forEach(host => {
                                const statusBadge = host.status === 'Reachable' 
                                    ? `<span class="badge bg-success">Reachable</span>`
                                    : `<span class="badge bg-danger">Unreachable</span>`;
                                
                                const containers = host.status === 'Reachable' ? `${host.running_containers} / ${host.total_containers}` : 'N/A';
                                const dockerVersion = host.docker_version !== 'N/A' ? `<span class="badge bg-info">${host.docker_version}</span>` : 'N/A';
                                const os = host.os !== 'N/A' ? host.os : 'N/A';

                                const totalCpus = host.cpus !== 'N/A' ? `${host.cpus} vCPUs` : 'N/A';
                                const totalMemory = host.memory !== 'N/A' ? formatBytes(host.memory) : 'N/A';

                                html += `
                                    <tr>
                                        <td><a href="${basePath}/hosts/${host.id}/details">${host.name}</a></td>
                                        <td>${statusBadge}</td>
                                        <td>${containers}</td>
                                        <td>${totalCpus}</td>
                                        <td>${totalMemory}</td>
                                        <td>${dockerVersion}</td>
                                        <td>${os}</td>
                                        <td class="text-end">
                                            <a href="${basePath}/hosts/${host.id}/details" class="btn btn-sm btn-outline-primary" title="Manage Host"><i class="bi bi-box-arrow-in-right"></i> Manage</a>
                                        </td>
                                    </tr>
                                `;
                            });
                            container.innerHTML = html;
                        }
                    }
                }
            })
            .catch(error => {
                console.error('Error loading dashboard widgets:', error);
                widgets.forEach(id => {
                    const el = document.getElementById(id);
                    if (el) el.textContent = 'Error';
                });
            });
    }

    // --- Initial Data Load ---
    if (document.getElementById('routers-container')) {
        const urlParams = new URLSearchParams(window.location.search);
        const groupIdFromUrl = urlParams.get('group_id');
        const groupFilterDropdown = document.getElementById('router-group-filter');
        if (groupIdFromUrl && groupFilterDropdown) {
            groupFilterDropdown.value = groupIdFromUrl;
        }
        const initialRoutersPage = localStorage.getItem('routers_page') || 1;
        const initialRoutersLimit = localStorage.getItem('routers_limit') || 10;
        loadPaginatedData('routers', initialRoutersPage, initialRoutersLimit);
    }

    if (document.getElementById('services-container')) {
        const urlParams = new URLSearchParams(window.location.search);
        const groupIdFromUrl = urlParams.get('group_id');
        const groupFilterDropdown = document.getElementById('service-group-filter');
        if (groupIdFromUrl && groupFilterDropdown) {
            groupFilterDropdown.value = groupIdFromUrl;
        }
        const initialServicesPage = localStorage.getItem('services_page') || 1;
        const initialServicesLimit = localStorage.getItem('services_limit') || 10;
        loadPaginatedData('services', initialServicesPage, initialServicesLimit);
    }

    if (document.getElementById('history-container')) {
        const initialHistoryPage = localStorage.getItem('history_page') || 1;
        const initialHistoryLimit = localStorage.getItem('history_limit') || 10;
        loadPaginatedData('history', initialHistoryPage, initialHistoryLimit);
    }

    if (document.getElementById('activity_log-container')) {
        const initialLogPage = localStorage.getItem('activity_log_page') || 1;
        const initialLogLimit = localStorage.getItem('activity_log_limit') || 50; // Default to 50 for logs
        loadPaginatedData('activity_log', initialLogPage, initialLogLimit);
    }

    if (document.getElementById('users-container')) {
        const initialUserPage = localStorage.getItem('users_page') || 1;
        const initialUserLimit = localStorage.getItem('users_limit') || 10;
        loadPaginatedData('users', initialUserPage, initialUserLimit);
    }

    if (document.getElementById('groups-container')) {
        const initialGroupPage = localStorage.getItem('groups_page') || 1;
        const initialGroupLimit = localStorage.getItem('groups_limit') || 10;
        loadPaginatedData('groups', initialGroupPage, initialGroupLimit);
    }

    if (document.getElementById('middlewares-container')) {
        const urlParams = new URLSearchParams(window.location.search);
        const groupIdFromUrl = urlParams.get('group_id');
        const groupFilterDropdown = document.getElementById('middleware-group-filter');
        if (groupIdFromUrl && groupFilterDropdown) {
            groupFilterDropdown.value = groupIdFromUrl;
        }
        const initialMiddlewarePage = localStorage.getItem('middlewares_page') || 1;
        const initialMiddlewareLimit = localStorage.getItem('middlewares_limit') || 10;
        loadPaginatedData('middlewares', initialMiddlewarePage, initialMiddlewareLimit);
    }

    if (document.getElementById('templates-container')) {
        const initialTemplatePage = localStorage.getItem('templates_page') || 1;
        const initialTemplateLimit = localStorage.getItem('templates_limit') || 10;
        loadPaginatedData('templates', initialTemplatePage, initialTemplateLimit);
    }

    if (document.getElementById('hosts-container')) {
        const initialHostPage = localStorage.getItem('hosts_page') || 1;
        const initialHostLimit = localStorage.getItem('hosts_limit') || 10;
        loadPaginatedData('hosts', initialHostPage, initialHostLimit);
    }

    // --- Health Check Page Logic ---
    function runHealthChecks() {
        const resultsContainer = document.getElementById('health-check-results');
        if (!resultsContainer) return;

        resultsContainer.innerHTML = `
            <li class="list-group-item text-center">
                <div class="spinner-border" role="status">
                    <span class="visually-hidden">Running checks...</span>
                </div>
                <p class="mt-2 mb-0">Running checks...</p>
            </li>`;

        fetch(`${basePath}/api/health-check`)
            .then(response => response.json())
            .then(results => {
                resultsContainer.innerHTML = ''; // Clear spinner
                results.forEach(result => {
                    const statusIcon = result.status
                        ? '<i class="bi bi-check-circle-fill text-success"></i>'
                        : '<i class="bi bi-x-circle-fill text-danger"></i>';
                    
                    const listItem = `
                        <li class="list-group-item d-flex justify-content-between align-items-start">
                            <div class="ms-2 me-auto">
                                <div class="fw-bold">${result.check}</div>
                                <small class="text-muted">${result.message}</small>
                            </div>
                            <span class="badge rounded-pill">${statusIcon}</span>
                        </li>
                    `;
                    resultsContainer.insertAdjacentHTML('beforeend', listItem);
                });
                const timestampEl = document.getElementById('last-checked-timestamp');
                if (timestampEl) {
                    timestampEl.textContent = new Date().toLocaleString();
                }
            })
            .catch(error => {
                console.error('Error running health checks:', error);
                resultsContainer.innerHTML = '<li class="list-group-item list-group-item-danger">An error occurred while running the health checks. Please check the browser console.</li>';
            });
    }

    loadDashboardWidgets();

    // --- Bulk Actions Logic for Routers ---
    const routerTable = document.querySelector('#routers-container')?.closest('table');
    if (routerTable) {
        const bulkActionsDropdown = document.getElementById('router-bulk-actions-dropdown');
        const selectAllCheckbox = document.getElementById('select-all-routers');

        const updateBulkActionsVisibility = () => {
            const checkedBoxes = routerTable.querySelectorAll('.router-checkbox:checked');
            if (checkedBoxes.length > 0) {
                bulkActionsDropdown.style.display = 'block';
            } else {
                bulkActionsDropdown.style.display = 'none';
            }
        };

        routerTable.addEventListener('change', (e) => {
            if (e.target.matches('.router-checkbox') || e.target.matches('#select-all-routers')) {
                if (e.target.id === 'select-all-routers') {
                    const isChecked = e.target.checked;
                    routerTable.querySelectorAll('.router-checkbox').forEach(checkbox => {
                        checkbox.checked = isChecked;
                    });
                }
                updateBulkActionsVisibility();
            }
        });
    }

    if (document.getElementById('health-check-results')) {
        runHealthChecks();
        document.getElementById('rerun-checks-btn').addEventListener('click', runHealthChecks);
    }

    // --- Stack Deployment Logic ---
    const deployStackBtn = document.getElementById('deploy-stack-btn');
    if (deployStackBtn) {
        deployStackBtn.addEventListener('click', function() {
            const stackId = this.dataset.id;
            if (confirm(`Are you sure you want to deploy this stack via Git? This will trigger your CI/CD pipeline.`)) {
                const originalButtonText = this.innerHTML;
                this.disabled = true;
                this.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Deploying...`;

                const formData = new FormData();
                formData.append('id', stackId);

                fetch(`${basePath}/api/stacks/${stackId}/deploy`, { method: 'POST', body: formData })
                    .then(response => response.json().then(data => ({ ok: response.ok, data })))
                    .then(({ ok, data }) => {
                        showToast(data.message, ok);
                    })
                    .catch(error => {
                        showToast(error.message || 'An unknown error occurred.', false);
                    })
                    .finally(() => {
                        this.disabled = false;
                        this.innerHTML = originalButtonText;
                    });
            }
        });
    }    

    // --- Move to Group Modal Logic ---
    const moveGroupModal = document.getElementById('moveGroupModal');
    if (moveGroupModal) {
        const confirmMoveBtn = document.getElementById('confirm-move-group-btn');
        const selectedCountSpan = document.getElementById('selected-router-count');
        const targetGroupSelect = document.getElementById('target_group_id');

        moveGroupModal.addEventListener('show.bs.modal', () => {
            const checkedBoxes = document.querySelectorAll('.router-checkbox:checked');
            selectedCountSpan.textContent = checkedBoxes.length;
        });

        confirmMoveBtn.addEventListener('click', () => {
            const checkedBoxes = document.querySelectorAll('.router-checkbox:checked');
            const routerIds = Array.from(checkedBoxes).map(cb => cb.value);
            const targetGroupId = targetGroupSelect.value;

            if (!targetGroupId) {
                showToast('Please select a target group.', false);
                return;
            }

            const formData = new FormData();
            routerIds.forEach(id => formData.append('router_ids[]', id));
            formData.append('target_group_id', targetGroupId);

            fetch(`${basePath}/api/routers/bulk-move`, { method: 'POST', body: formData })
                .then(response => response.json().then(data => ({ ok: response.ok, data })))
                .then(({ ok, data }) => {
                    showToast(data.message, ok);
                    bootstrap.Modal.getInstance(moveGroupModal).hide();
                    if (ok) {
                        // Uncheck "select all" and reload data
                        document.getElementById('select-all-routers').checked = false;
                        loadPaginatedData('routers', localStorage.getItem('routers_page') || 1, localStorage.getItem('routers_limit') || 10, true);
                    }
                });
        });
    }

    // --- Bulk Delete Modal Logic ---
    const bulkDeleteModal = document.getElementById('bulkDeleteModal');
    if (bulkDeleteModal) {
        const confirmBulkDeleteBtn = document.getElementById('confirm-bulk-delete-btn');
        const selectedDeleteCountSpan = document.getElementById('bulk-delete-router-count');

        bulkDeleteModal.addEventListener('show.bs.modal', () => {
            const checkedBoxes = document.querySelectorAll('.router-checkbox:checked');
            selectedDeleteCountSpan.textContent = checkedBoxes.length;
        });

        confirmBulkDeleteBtn.addEventListener('click', () => {
            const checkedBoxes = document.querySelectorAll('.router-checkbox:checked');
            const routerIds = Array.from(checkedBoxes).map(cb => cb.value);

            if (routerIds.length === 0) {
                showToast('No routers selected for deletion.', false);
                return;
            }

            const formData = new FormData();
            routerIds.forEach(id => formData.append('router_ids[]', id));

            fetch(`${basePath}/api/routers/bulk-delete`, { method: 'POST', body: formData })
                .then(response => response.json().then(data => ({ ok: response.ok, data })))
                .then(({ ok, data }) => {
                    showToast(data.message, ok);
                    bootstrap.Modal.getInstance(bulkDeleteModal).hide();
                    if (ok) {
                        // Reload router data, uncheck "select all"
                        document.getElementById('select-all-routers').checked = false;
                        loadPaginatedData('routers', localStorage.getItem('routers_page') || 1, localStorage.getItem('routers_limit') || 10, true);
                    }
                });
        });
    }

    // --- Theme Switcher Logic ---
    const themeSwitcher = document.getElementById('theme-switcher');
    if (themeSwitcher) {
        const sunIcon = themeSwitcher.querySelector('.bi-sun-fill');
        const moonIcon = themeSwitcher.querySelector('.bi-moon-stars-fill');

        const setTheme = (theme) => {
            if (theme === 'dark') {
                document.body.classList.add('dark-mode');
                sunIcon.classList.add('d-none');
                moonIcon.classList.remove('d-none');
                localStorage.setItem('theme', 'dark');
            } else {
                document.body.classList.remove('dark-mode');
                sunIcon.classList.remove('d-none');
                moonIcon.classList.add('d-none');
                localStorage.setItem('theme', 'light');
            }
        };

        themeSwitcher.addEventListener('click', () => {
            const currentTheme = localStorage.getItem('theme') || 'light';
            setTheme(currentTheme === 'dark' ? 'light' : 'dark');
        });

        // Set initial icon state based on theme
        // The class on <body> is already set by the inline script in header.php
        const currentTheme = localStorage.getItem('theme') || 'light';
        if (currentTheme === 'dark') {
            sunIcon.classList.add('d-none');
            moonIcon.classList.remove('d-none');
        } else {
            sunIcon.classList.remove('d-none');
            moonIcon.classList.add('d-none');
        }
    }

    // --- Statistics Page Logic ---
    const statsCanvas = document.getElementById('routerStatsChart');
    if (statsCanvas) {
        fetch(`${basePath}/api/stats`)
            .then(response => response.json())
            .then(data => {
                if (data.labels && data.data) {
                    new Chart(statsCanvas, {
                        type: 'bar',
                        data: {
                            labels: data.labels,
                            datasets: [{
                                label: '# of Routers',
                                data: data.data,
                                backgroundColor: 'rgba(0, 123, 255, 0.5)',
                                borderColor: 'rgba(0, 123, 255, 1)',
                                borderWidth: 1
                            }]
                        },
                        options: {
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        stepSize: 1 // Ensure y-axis shows whole numbers
                                    }
                                }
                            },
                            responsive: true,
                            maintainAspectRatio: false
                        }
                    });
                }
            })
            .catch(error => {
                console.error('Error fetching stats data:', error);
                statsCanvas.getContext('2d').fillText('Failed to load chart data.', 10, 50);
            });
    }

    // --- History Page Compare Logic ---
    const historyTable = document.querySelector('#history-container')?.closest('table');
    if (historyTable) {
        const compareBtn = document.getElementById('compare-btn');
        const selectAllCheckbox = document.getElementById('select-all-history');

        const updateCompareButtonState = () => {
            const checkedBoxes = historyTable.querySelectorAll('.history-checkbox:checked');
            const count = checkedBoxes.length;
            if (compareBtn) {
                compareBtn.innerHTML = `<i class="bi bi-files"></i> Compare Selected (${count})`;
                compareBtn.disabled = count !== 2;
            }
        };

        historyTable.addEventListener('change', (e) => {
            if (e.target.matches('.history-checkbox') || e.target.matches('#select-all-history')) {
                if (e.target.id === 'select-all-history') {
                    const isChecked = e.target.checked;
                    historyTable.querySelectorAll('.history-checkbox').forEach(checkbox => {
                        checkbox.checked = isChecked;
                    });
                }
                updateCompareButtonState();
            }
        });

        if (compareBtn) {
            compareBtn.addEventListener('click', () => {
                const checkedBoxes = historyTable.querySelectorAll('.history-checkbox:checked');
                if (checkedBoxes.length === 2) {
                    const fromId = checkedBoxes[0].value;
                    const toId = checkedBoxes[1].value;
                    window.location.href = `${basePath}/history/compare?from=${fromId}&to=${toId}`;
                }
            });
        }
    }

    // --- Group Management Modal Logic ---
    const groupModal = document.getElementById('groupModal');
    if (groupModal) {
        const form = document.getElementById('group-form');
        const modalTitle = document.getElementById('groupModalLabel');
        const groupIdInput = document.getElementById('group-id');
        const groupNameInput = document.getElementById('group-name');
        const groupDescInput = document.getElementById('group-description');
        const saveBtn = document.getElementById('save-group-btn');

        groupModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const action = button.dataset.action || 'edit';

            if (action === 'add') {
                modalTitle.textContent = 'Add New Group';
                form.action = `${basePath}/groups/new`;
                form.reset();
                groupIdInput.value = '';
            } else { // edit
                const groupId = button.dataset.id;
                const groupName = button.dataset.name;
                const groupDesc = button.dataset.description;
                modalTitle.textContent = `Edit Group: ${groupName}`;
                form.action = `${basePath}/groups/${groupId}/edit`;
                groupIdInput.value = groupId;
                groupNameInput.value = groupName;
                groupDescInput.value = groupDesc;
            }
        });

        saveBtn.addEventListener('click', function() {
            const formData = new FormData(form);
            const url = form.action;
            const originalButtonText = saveBtn.innerHTML;

            saveBtn.disabled = true;
            saveBtn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Saving...`;

            fetch(url, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json().then(data => ({ ok: response.ok, data })))
            .then(({ ok, data }) => {
                showToast(data.message, ok);
                if (ok) {
                    const modalInstance = bootstrap.Modal.getInstance(groupModal);
                    modalInstance.hide();
                    const limit = document.querySelector('select[name="limit_groups"]').value;
                    const currentPage = localStorage.getItem('groups_page') || 1;
                    loadPaginatedData('groups', currentPage, limit, true);
                }
            })
            .catch(error => {
                showToast(error.message || 'An unknown error occurred.', false);
            })
            .finally(() => {
                saveBtn.disabled = false;
                saveBtn.innerHTML = 'Save Group';
            });
        });
    }

    // --- Middleware Management ---
    const middlewareModal = document.getElementById('middlewareModal');
    if (middlewareModal) {
        const middlewareForm = document.getElementById('middleware-form');
        const middlewareModalLabel = document.getElementById('middlewareModalLabel');
        const middlewareIdInput = document.getElementById('middleware-id');
        const middlewareNameInput = document.getElementById('middleware-name');
        const middlewareTypeInput = document.getElementById('middleware-type');
        const middlewareDescInput = document.getElementById('middleware-description');
        const middlewareConfigInput = document.getElementById('middleware-config');
        const saveMiddlewareBtn = document.getElementById('save-middleware-btn');
        let isAddingMiddleware = false; // Flag untuk melacak mode modal

        const middlewareTemplates = {
            'addPrefix': '{\n  "prefix": "/app"\n}',
            'basicAuth': '{\n  "users": [\n    "user:$apr1$....$..."\n  ]\n}',
            'chain': '{\n  "middlewares": [\n    "middleware-name-1@file",\n    "middleware-name-2@file"\n  ]\n}',
            'compress': '{}',
            'headers': '{\n  "customRequestHeaders": {\n    "X-Custom-Header": "value"\n  }\n}',
            'ipWhiteList': '{\n  "sourceRange": [\n    "127.0.0.1/32",\n    "192.168.1.7"\n  ]\n}',
            'rateLimit': '{\n  "average": 100,\n  "burst": 50\n}',
            'redirectRegex': '{\n  "regex": "^http://localhost/(.*)$",\n  "replacement": "http://mydomain/${1}",\n  "permanent": true\n}',
            'redirectScheme': '{\n  "scheme": "https",\n  "permanent": true\n}',
            'replacePath': '{\n  "path": "/new-path"\n}',
            'replacePathRegex': '{\n  "regex": "^/api/(.*)$",\n  "replacement": "/v2/${1}"\n}',
            'retry': '{\n  "attempts": 4,\n  "initialInterval": "100ms"\n}',
            'stripPrefix': '{\n  "prefixes": [\n    "/api",\n    "/v1"\n  ]\n}',
            'stripPrefixRegex': '{\n  "regex": [\n    "/api/v[0-9]+"\n  ]\n}'
        };

        if (middlewareTypeInput) {
            middlewareTypeInput.addEventListener('change', function() {
                const selectedType = this.value;
                // Hanya isi template secara otomatis saat menambah middleware baru.
                if (isAddingMiddleware && middlewareTemplates[selectedType]) {
                    middlewareConfigInput.value = middlewareTemplates[selectedType];
                }
            });
        }

        middlewareModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const action = button.getAttribute('data-action');
            middlewareIdInput.value = '';

            if (action === 'add') {
                middlewareForm.reset();
                isAddingMiddleware = true; // Set flag ke mode tambah
                middlewareModalLabel.textContent = 'Add New Middleware';
                middlewareForm.action = `${basePath}/middlewares/new`;
            } else {
                middlewareModalLabel.textContent = 'Edit Middleware';
                isAddingMiddleware = false; // Set flag ke mode edit
                const id = button.getAttribute('data-id');
                middlewareIdInput.value = id;
                middlewareNameInput.value = button.getAttribute('data-name');
                middlewareTypeInput.value = button.getAttribute('data-type');
                middlewareDescInput.value = button.getAttribute('data-description');
                middlewareConfigInput.value = button.getAttribute('data-config_json');
                middlewareForm.action = `${basePath}/middlewares/${id}/edit`;
            }
        });

        saveMiddlewareBtn.addEventListener('click', function () {
            const formData = new FormData(middlewareForm);
            const url = middlewareForm.action;

            fetch(url, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(response => response.json().then(data => ({ ok: response.ok, data })))
            .then(({ ok, data }) => {
                if (ok) {
                    bootstrap.Modal.getInstance(middlewareModal).hide();
                    showToast(data.message, 'success');
                    loadPaginatedData('middlewares', 1); // Reload the table
                } else {
                    showToast(data.message || 'An error occurred.', 'danger');
                }
            })
            .catch(error => {
                showToast('A network error occurred.', 'danger');
                console.error('Error:', error);
            });
        });
    }

    // --- Template Management ---
    const templateModal = document.getElementById('templateModal');
    if (templateModal) {
        const form = document.getElementById('template-form');
        const modalTitle = document.getElementById('templateModalLabel');
        const templateIdInput = document.getElementById('template-id');
        const saveBtn = document.getElementById('save-template-btn');

        // Middleware UI inside the modal
        const availableMiddlewareSelect = document.getElementById('template-available-middlewares');
        const attachedMiddlewareContainer = document.getElementById('template-attached-middlewares-container');
        
        new Sortable(attachedMiddlewareContainer, {
            animation: 150,
            handle: '.drag-handle',
            ghostClass: 'bg-light'
        });

        document.getElementById('template-add-middleware-btn').addEventListener('click', function() {
            const selectedOption = availableMiddlewareSelect.options[availableMiddlewareSelect.selectedIndex];
            if (!selectedOption || !selectedOption.value) return;

            const middlewareId = selectedOption.value;
            const middlewareName = selectedOption.dataset.name;

            const newItemHTML = `
                <div class="d-flex align-items-center mb-2 p-2 border rounded attached-middleware-item" data-id="${middlewareId}">
                    <i class="bi bi-grip-vertical me-2 drag-handle" style="cursor: grab;"></i>
                    <span class="flex-grow-1">${middlewareName}</span>
                    <input type="hidden" name="middlewares[]" value="${middlewareId}">
                    <button type="button" class="btn-close remove-middleware-btn ms-2" aria-label="Remove"></button>
                </div>`;
            
            attachedMiddlewareContainer.insertAdjacentHTML('beforeend', newItemHTML);
            selectedOption.remove();
        });

        attachedMiddlewareContainer.addEventListener('click', function(e) {
            if (e.target.classList.contains('remove-middleware-btn')) {
                const itemToRemove = e.target.closest('.attached-middleware-item');
                if (!itemToRemove) return;

                const middlewareId = itemToRemove.dataset.id;
                const middlewareName = itemToRemove.querySelector('span').textContent;

                const newOption = new Option(middlewareName, middlewareId);
                newOption.dataset.name = middlewareName;
                availableMiddlewareSelect.add(newOption);

                Array.from(availableMiddlewareSelect.options)
                    .sort((a, b) => a.value === "" ? -1 : b.value === "" ? 1 : a.text.localeCompare(b.text))
                    .forEach(option => availableMiddlewareSelect.add(option));

                itemToRemove.remove();
            }
        });

        templateModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const action = button.dataset.action || 'edit';
            form.reset();
            attachedMiddlewareContainer.innerHTML = ''; // Clear middlewares

            if (action === 'add') {
                modalTitle.textContent = 'Add New Template';
                form.action = `${basePath}/templates/new`;
                templateIdInput.value = '';
            } else { // edit
                modalTitle.textContent = `Edit Template: ${button.dataset.name}`;
                form.action = `${basePath}/templates/${button.dataset.id}/edit`;
                templateIdInput.value = button.dataset.id;
                document.getElementById('template-name').value = button.dataset.name;
                document.getElementById('template-description').value = button.dataset.description;
                
                const configData = JSON.parse(button.dataset.config_data);
                document.getElementById('template-entry_points').value = configData.entry_points;
                document.getElementById('template-tls').checked = configData.tls == 1;
                document.getElementById('template-cert_resolver').value = configData.cert_resolver;

                // Populate middlewares
                if (configData.middlewares && configData.middlewares.length > 0) {
                    configData.middlewares.forEach(mwId => {
                        const optionToAdd = availableMiddlewareSelect.querySelector(`option[value="${mwId}"]`);
                        if (optionToAdd) {
                            optionToAdd.selected = true;
                            document.getElementById('template-add-middleware-btn').click();
                        }
                    });
                }
            }
        });

        saveBtn.addEventListener('click', function() {
            const formData = new FormData(form);
            const url = form.action;

            fetch(url, { method: 'POST', body: formData })
                .then(response => response.json().then(data => ({ ok: response.ok, data })))
                .then(({ ok, data }) => {
                    showToast(data.message, ok);
                    if (ok) {
                        bootstrap.Modal.getInstance(templateModal).hide();
                        loadPaginatedData('templates', 1);
                    }
                })
                .catch(error => showToast(error.message || 'An unknown error occurred.', false));
        });
    }

    // --- Preview Config Modal Logic ---
    const previewBtn = document.getElementById('preview-config-btn');
    if (previewBtn) {
        const previewModalEl = document.getElementById('previewConfigModal');
        const previewModal = new bootstrap.Modal(previewModalEl);
        const linterContainer = document.getElementById('linter-results-container');
        const contentContainer = document.getElementById('preview-yaml-content-container');
        const deployFromPreviewBtn = document.getElementById('deploy-from-preview-btn');

        previewBtn.addEventListener('click', () => {
            contentContainer.textContent = 'Loading...';
            previewModal.show();

            linterContainer.innerHTML = '<div class="text-center"><div class="spinner-border spinner-border-sm" role="status"></div><span class="ms-2">Running validator...</span></div>';
            fetch(`${basePath}/api/configurations/preview`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok.');
                    }
                    return response.json();
                })
                .then(data => {
                    // Handle Linter Results
                    linterContainer.innerHTML = '';
                    deployFromPreviewBtn.disabled = false; // Enable by default

                    if (data.linter) {
                        if (data.linter.errors && data.linter.errors.length > 0) {
                            let errorsHtml = '<div class="alert alert-danger"><h6><i class="bi bi-x-circle-fill me-2"></i>Errors Found</h6><ul class="mb-0">';
                            data.linter.errors.forEach(err => {
                                errorsHtml += `<li>${err}</li>`;
                            });
                            errorsHtml += '</ul></div>';
                            linterContainer.insertAdjacentHTML('beforeend', errorsHtml);
                            deployFromPreviewBtn.disabled = true; // Disable deploy if there are errors
                        }

                        if (data.linter.warnings && data.linter.warnings.length > 0) {
                            let warningsHtml = '<div class="alert alert-warning"><h6><i class="bi bi-exclamation-triangle-fill me-2"></i>Warnings</h6><ul class="mb-0">';
                            data.linter.warnings.forEach(warn => {
                                warningsHtml += `<li>${warn}</li>`;
                            });
                            warningsHtml += '</ul></div>';
                            linterContainer.insertAdjacentHTML('beforeend', warningsHtml);
                        }
                    }

                    if (data.status === 'success' && data.content) {
                        contentContainer.textContent = data.content;
                        Prism.highlightElement(contentContainer);
                    } else {
                        throw new Error(data.message || 'Failed to load preview content.');
                    }
                })
                .catch(error => {
                    linterContainer.innerHTML = '';
                    contentContainer.textContent = 'Error loading content: ' + error.message;
                });
        });

        if (deployFromPreviewBtn) {
            deployFromPreviewBtn.addEventListener('click', () => {
                if (confirm('Anda yakin ingin men-deploy konfigurasi ini? Tindakan ini akan menimpa file dynamic.yml yang aktif.')) {
                    const originalButtonText = deployFromPreviewBtn.innerHTML;
                    deployFromPreviewBtn.disabled = true;
                    deployFromPreviewBtn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Deploying...`;

                    fetch(`${basePath}/generate`, {
                        method: 'GET', // The route is a GET route
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                    .then(response => response.json().then(data => ({ ok: response.ok, data })))
                    .then(({ ok, data }) => {
                        if (ok) {
                            previewModal.hide();
                            showToast(data.message, true);
                            // Refresh dashboard data to reflect the new active state
                            loadPaginatedData('routers', localStorage.getItem('routers_page') || 1, localStorage.getItem('routers_limit') || 10);
                            loadPaginatedData('services', localStorage.getItem('services_page') || 1, localStorage.getItem('services_limit') || 10);
                        } else {
                            throw new Error(data.message || 'Deployment failed.');
                        }
                    })
                    .catch(error => {
                        showToast(error.message, false);
                    })
                    .finally(() => {
                        deployFromPreviewBtn.disabled = false;
                        deployFromPreviewBtn.innerHTML = originalButtonText;
                    });
                }
            });
        }
    }

    // --- Diff Page Logic ---
    const diffContainer = document.getElementById('diff-output');
    if (diffContainer) {
        const fromContent = document.getElementById('from-content').textContent;
        const toContent = document.getElementById('to-content').textContent;

        // Check if there are any actual changes to display
        if (fromContent.trim() === toContent.trim()) {
            diffContainer.innerHTML = '<div class="alert alert-info">No changes detected between these two versions.</div>';
        } else {
            const diffString = Diff.createPatch('configuration.yml', fromContent, toContent, '', '', { context: 10000 });
            const diff2htmlUi = new Diff2HtmlUI(diffContainer, diffString, { drawFileList: false, matching: 'lines', outputFormat: 'side-by-side' });
            diff2htmlUi.draw();
        }
    }

    // Initialize tooltips on static elements present on page load
    const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
    [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl));
});

// --- Service Health Status Logic ---
function updateServiceStatus() {
    const indicators = document.querySelectorAll('.service-status-indicator');
    if (indicators.length === 0) {
        return; // No need to fetch if there are no indicators on the page
    }

    fetch(`${basePath}/api/services/status`)
        .then(response => {
            if (!response.ok) {
                console.error('Failed to fetch service status. Server responded with ' + response.status);
                return null;
            }
            return response.json();
        })
        .then(services => {
            if (!services || services.error) {
                console.error('Error from status API:', services ? services.error : 'Unknown error');
                return;
            }

            indicators.forEach(indicator => {
                const serviceNameFromDB = indicator.dataset.serviceName;
                const service = services.find(s => s.name.startsWith(serviceNameFromDB + '@'));
                
                let statusClass = 'text-secondary'; // Default: Unknown
                let statusTitle = 'Status Unknown';

                if (service) {
                    statusTitle = `Status: ${service.status}`;
                    if (service.status === 'enabled') statusClass = 'text-success';
                    else if (service.status === 'disabled') statusClass = 'text-warning';
                    else statusClass = 'text-danger'; // e.g., error state
                }
                indicator.innerHTML = `<i class="bi bi-circle-fill ${statusClass}"></i>`;
                indicator.title = statusTitle;
            });
        })
        .catch(error => {
            console.error('Error fetching or processing service status:', error);
        });
}

// Initial call and set interval to refresh status
if (document.getElementById('services-container')) {
    updateServiceStatus();
    setInterval(updateServiceStatus, 15000); // Refresh every 15 seconds
}

// --- User Management Edit Modal Logic ---
const editUserModal = document.getElementById('editUserModal');
if (editUserModal) {
    const editForm = document.getElementById('edit-user-form');
    const userIdInput = document.getElementById('edit-user-id');
    const usernameInput = document.getElementById('edit-username');
    const roleSelect = document.getElementById('edit-role');
    const modalTitle = document.getElementById('editUserModalLabel');
    const submitButton = editUserModal.querySelector('button[type="submit"]'); // Get the submit button

    // 1. Populate modal with data when it's about to be shown
    editUserModal.addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget; // Button that triggered the modal
        const userId = button.dataset.userId;
        const userName = button.dataset.userName;
        const userRole = button.dataset.userRole;

        // Update the modal's content.
        modalTitle.textContent = `Edit User: ${userName}`;
        userIdInput.value = userId;
        usernameInput.value = userName;
        roleSelect.value = userRole;
    });

    // 2. Handle form submission
    if (editForm) {
        editForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(editForm);
            const url = editForm.action;
            const originalButtonText = submitButton.innerHTML;

            submitButton.disabled = true;
            submitButton.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Updating...`;

            fetch(url, {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    return response.json().then(err => { throw err; });
                }
                return response.json();
            })
            .then(data => {
                const modalInstance = bootstrap.Modal.getInstance(editUserModal);
                modalInstance.hide();
                showToast(data.message, true);
                // Reload the page after a short delay to show the changes
                setTimeout(() => {
                    window.location.reload();
                }, 1500);
            })
            .catch(error => {
                // We can display the error inside the modal or as a toast. Toast is more consistent.
                showToast(error.message || 'An error occurred.', false);
            })
            .finally(() => {
                submitButton.disabled = false;
                submitButton.innerHTML = originalButtonText;
            });
        });
    }
}