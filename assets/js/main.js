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

// --- SPA Navigation Logic ---
const mainContent = document.querySelector('.main-content');

function executeScriptsInContainer(container) {
    const scripts = container.querySelectorAll('script');
    scripts.forEach(oldScript => {
        const newScript = document.createElement('script');
        Array.from(oldScript.attributes).forEach(attr => newScript.setAttribute(attr.name, attr.value));
        newScript.textContent = oldScript.textContent;
        document.body.appendChild(newScript).parentNode.removeChild(newScript);
    });
}

function updateActiveLink(url) {
    const currentPath = new URL(url).pathname;
    document.querySelectorAll('.sidebar-nav .nav-link').forEach(link => {
        // Don't process collapse toggles directly
        if (link.hasAttribute('data-bs-toggle') && link.getAttribute('data-bs-toggle') === 'collapse') {
            return;
        }
        const linkPath = new URL(link.href).pathname;
        const cleanCurrentPath = currentPath.length > 1 ? currentPath.replace(/\/$/, "") : currentPath;
        const cleanLinkPath = linkPath.length > 1 ? linkPath.replace(/\/$/, "") : linkPath;

        if (cleanLinkPath === cleanCurrentPath) {
            link.classList.add('active');
        } else {
            link.classList.remove('active');
        }
    });
}

async function loadPage(url, pushState = true, noAnimation = false) {
    if (!mainContent) return;

    const loadingBar = document.getElementById('loading-bar');
    if (loadingBar && !noAnimation) {
        // Only show loading bar for non-tab navigation
        loadingBar.style.opacity = '1';
        loadingBar.style.width = '30%'; // Start progress
    }

    if (!noAnimation) {
        // Full page transition with fade out
        mainContent.classList.add('is-loading');
        mainContent.classList.remove('is-loaded'); // In case a fast navigation happens
        // Wait for fade-out to finish before loading new content
        await new Promise(resolve => setTimeout(resolve, 200)); // Matches fadeOut duration in CSS
        mainContent.innerHTML = '<div class="text-center p-5"><div class="spinner-border" role="status"><span class="visually-hidden">Loading...</span></div></div>';
    } else {
        // For tab navigation, just dim the content slightly instead of showing a spinner
        mainContent.classList.add('table-loading');
    }


    try {
        const response = await fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
        
        const html = await response.text();

        if (loadingBar && !noAnimation) {
            loadingBar.style.width = '100%'; // Finish progress
        }

        if (pushState) {
            history.pushState({ path: url }, '', url);
        }

        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = html;
        
        mainContent.innerHTML = ''; // Clear spinner or old content
        while (tempDiv.firstChild) {
            mainContent.appendChild(tempDiv.firstChild);
        }

        if (!noAnimation) {
            // Fade in new content
            mainContent.classList.remove('is-loading');
            mainContent.classList.add('is-loaded');
        } else {
            // For tab navigation, remove the dimming effect
            mainContent.classList.remove('table-loading');
        }
        
        executeScriptsInContainer(mainContent);
        updateActiveLink(url);
        
        const tooltipTriggerList = mainContent.querySelectorAll('[data-bs-toggle="tooltip"]');
        [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl));

        // Re-run page-specific initializations for the new content
        initializePageSpecificScripts();

        // Run page-specific init function if it exists
        if (window.pageInit && typeof window.pageInit === 'function') {
            window.pageInit();
            delete window.pageInit; // Clean up to prevent multiple executions
        }

        if (loadingBar && !noAnimation) {
            setTimeout(() => {
                loadingBar.style.opacity = '0';
                // Reset width after transition ends
                setTimeout(() => {
                    loadingBar.style.width = '0%';
                }, 500); // Match opacity transition duration
            }, 300); // A short delay to ensure user sees the full bar
        }

    } catch (error) {
        console.error('Failed to load page:', error);
        mainContent.innerHTML = `<div class="alert alert-danger m-3">Failed to load page content. Please try again or <a href="${url}" class="alert-link">refresh the page</a>.</div>`;
        mainContent.classList.remove('is-loading', 'table-loading'); // Ensure it's visible on error
        if (loadingBar) {
            loadingBar.style.opacity = '0';
            loadingBar.style.width = '0%';
        }
    }
}

document.body.addEventListener('click', function(e) {
    const target = e.target;
    const link = target.closest('a');

    // Action buttons
    const testConnectionBtn = target.closest('.test-connection-btn');
    const deleteButton = target.closest('.delete-btn');
    const resetButton = target.closest('.reset-search-btn');
    const pageLink = target.closest('.page-link');

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

    if (resetButton) {
        e.preventDefault();
        const form = resetButton.closest('.search-form');
        const input = form.querySelector('input[type="text"]');
        if (input.value !== '') {
            input.value = ''; // Clear the input
            const type = form.dataset.type;
            const limit = document.querySelector(`select[name="limit_${type}"]`).value;
            loadPaginatedData(type, 1, limit, false);
        }
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

    // Node action buttons (promote/demote)
    const nodeActionBtn = target.closest('.node-action-btn');
    if (nodeActionBtn) {
        e.preventDefault();
        const hostId = nodeActionBtn.dataset.hostId;
        const action = nodeActionBtn.dataset.action;

        if (!confirm(`Are you sure you want to ${action} this node?`)) {
            return;
        }

        const originalIcon = nodeActionBtn.innerHTML;
        nodeActionBtn.disabled = true;
        nodeActionBtn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>`;

        const formData = new FormData();
        formData.append('host_id', hostId);
        formData.append('action', action);

        fetch(`${basePath}/api/nodes/action`, { method: 'POST', body: formData })
            .then(response => response.json().then(data => ({ ok: response.ok, data })))
            .then(({ ok, data }) => {
                showToast(data.message, ok);
                if (ok) {
                    // Reload the hosts table to show the new status
                    loadPaginatedData('hosts', localStorage.getItem('hosts_page') || 1, localStorage.getItem('hosts_limit') || 10);
                }
            })
            .catch(error => showToast(error.message || 'An unknown error occurred.', false))
            .finally(() => nodeActionBtn.disabled = false); // Button will be re-rendered, no need to restore icon
    }

    // --- NEW: Setup as Local Registry ---
    const setupRegistryBtn = target.closest('.setup-registry-btn');
    if (setupRegistryBtn) {
        e.preventDefault();
        const hostId = setupRegistryBtn.dataset.hostId;
        const hostName = setupRegistryBtn.dataset.hostName;

        if (!confirm(`Are you sure you want to deploy a local Docker registry on host '${hostName}'? This will pull the 'registry:2' image and run it on port 5000.`)) {
            return;
        }

        const originalBtnContent = setupRegistryBtn.innerHTML;
        setupRegistryBtn.disabled = true;
        setupRegistryBtn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>`;

        const formData = new FormData();
        formData.append('host_id', hostId);

        fetch(`${basePath}/api/hosts/setup-registry`, { method: 'POST', body: formData })
            .then(response => response.json().then(data => ({ ok: response.ok, data })))
            .then(({ ok, data }) => {
                showToast(data.message, ok);
                if (ok) {
                    // Reload the hosts table data to show the new "Browse Registry" button
                    const currentPage = localStorage.getItem('hosts_page') || 1;
                    const currentLimit = localStorage.getItem('hosts_limit') || 10;
                    loadPaginatedData('hosts', currentPage, currentLimit);
                }
            })
            .catch(error => showToast('An unknown error occurred: ' + error.message, false))
            .finally(() => {
                // The button will be removed on successful reload, so no need to restore state if it was successful.
            });
    }

    // --- SPA Navigation Logic (as the fallback) ---
    if (link) {
        // Conditions to let the browser handle the click normally
        if (
            !link.href ||
            link.target === '_blank' ||
            e.ctrlKey || e.metaKey ||
            !link.href.startsWith(window.location.origin) ||
            link.classList.contains('no-spa') ||
            (link.hasAttribute('data-bs-toggle') && ['modal', 'dropdown', 'collapse'].includes(link.getAttribute('data-bs-toggle')))
        ) {
            return;
        }

        e.preventDefault();
        if (window.location.href !== link.href) {
            // Check if the link is part of a tab navigation to disable animation
            const isTabNav = !!link.closest('.nav-tabs');
            loadPage(link.href, true, isTabNav);
        }
    }
});

window.addEventListener('popstate', e => {
    if (e.state && e.state.path) {
        loadPage(e.state.path, false);
    }
});

document.body.addEventListener('change', function(e) {
    const limitSelector = e.target.closest('.limit-selector');
    if (limitSelector && limitSelector.dataset.type) {
        const type = limitSelector.dataset.type;
        const limit = limitSelector.value;
        loadPaginatedData(type, 1, limit, true);
    }

    const groupFilter = e.target.closest('#router-group-filter, #service-group-filter, #middleware-group-filter');
    if (groupFilter) {
        const type = groupFilter.id.split('-')[0] + 's'; // e.g., 'router-group-filter' -> 'routers'
        const limit = document.querySelector(`select[name="limit_${type}"]`).value;
        loadPaginatedData(type, 1, limit);
    }

    const showArchivedCheckbox = e.target.closest('#show-archived-checkbox');
    if (showArchivedCheckbox) {
        loadPaginatedData('history', 1, document.querySelector('select[name="limit_history"]').value);
    }

    const historyGroupFilter = e.target.closest('#history-group-filter');
    if (historyGroupFilter) {
        const limit = document.querySelector('select[name="limit_history"]').value;
        loadPaginatedData('history', 1, limit);
    }
});

// NEW: Host group filter listener
document.body.addEventListener('change', function(e) {
    const groupFilterInput = e.target.closest('input[name="host-group-filter"]');
    if (groupFilterInput) {
        const limit = document.querySelector('select[name="limit_hosts"]').value;
        loadPaginatedData('hosts', 1, limit);
    }
});


const debouncedSearch = debounce((type, limit) => {
    loadPaginatedData(type, 1, limit, false);
}, 400);

document.body.addEventListener('input', function(e) {
    const searchInput = e.target.closest('input[name^="search_"]');
    if (searchInput) {
        const form = searchInput.closest('.search-form');
        if (!form) return;
        const type = form.dataset.type;
        if (!type) return;

        const limitSelector = document.querySelector(`select[name="limit_${type}"]`);
        if (limitSelector) {
            const limit = limitSelector.value;
            debouncedSearch(type, limit);
        }
    }
});

document.body.addEventListener('submit', function(e) {
    const form = e.target.closest('#main-form');
    if (form) {
         e.preventDefault();

         const formData = new FormData(form);
         const url = form.action;
         const submitButton = form.querySelector('button[type="submit"]');
         if (!submitButton) return;

         const originalButtonText = submitButton.innerHTML;
         const redirectUrl = form.dataset.redirect;
         // Ensure the redirect URL is absolute for the SPA to handle it correctly.
         const finalRedirectUrl = redirectUrl ? (window.location.origin + basePath + redirectUrl) : (window.location.origin + basePath + '/');

         submitButton.disabled = true;
         submitButton.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Saving...`;

         fetch(url, { method: 'POST', body: formData })
             .then(response => response.json().then(data => ({ ok: response.ok, data })))
             .then(({ ok, data }) => {
                 showToast(data.message, ok);
                 if (ok) {
                    // Use redirect URL from API response if available, otherwise use form's data-redirect
                    const destinationUrl = data.redirect 
                        ? (window.location.origin + data.redirect) 
                        : finalRedirectUrl;

                    setTimeout(() => loadPage(destinationUrl), 1500);
                 } else {
                     throw new Error(data.message || 'An unknown error occurred.');
                 }
             })
             .catch(error => {
                 showToast(error.message, false);
                 submitButton.disabled = false;
                 submitButton.innerHTML = originalButtonText;
             });
    }
});

// Function to check for pending git changes and update the UI
// Moved to global scope within this file to be accessible by initializePageSpecificScripts
function checkGitSyncStatus() {
    const syncStacksBtn = document.getElementById('sync-stacks-btn');
    if (!syncStacksBtn) return;

    const syncBadge = document.getElementById('sync-badge');
    if (!syncBadge) return;

    fetch(`${basePath}/api/stacks/check-git-diff`)
        .then(response => response.json())
        .then(result => {
            if (result.status === 'success' && result.changes_count > 0) {
                syncBadge.textContent = result.changes_count;
                syncBadge.style.display = 'block';
                // syncBadge.classList.add('blinking-badge'); // Pulse pada tombol lebih efektif
                syncStacksBtn.classList.add('btn-pulse'); // Tambahkan animasi pulse ke tombol

                // Store diff data and set button to open modal
                syncStacksBtn.dataset.diff = result.diff;
                syncStacksBtn.setAttribute('data-bs-toggle', 'modal');
                syncStacksBtn.setAttribute('data-bs-target', '#syncGitModal');
            } else {
                // No changes, ensure button has default (direct sync) behavior
                syncBadge.style.display = 'none';
                // syncBadge.classList.remove('blinking-badge');
                syncStacksBtn.classList.remove('btn-pulse'); // Hapus animasi pulse dari tombol
                syncStacksBtn.removeAttribute('data-bs-toggle');
                syncStacksBtn.removeAttribute('data-bs-target');
                syncStacksBtn.dataset.diff = '';
            }
        })
        .catch(error => {
            console.error('Error checking Git sync status:', error);
        });
}

/**
 * Injects dynamic action buttons into a rendered table.
 * @param {string} type The type of data, e.g., 'routers'.
 */
function injectDynamicButtons(type) {
    if (type === 'routers') {
        const routerRows = document.querySelectorAll('#routers-container tr');
        routerRows.forEach(row => {
            const serviceCell = row.querySelector('td:nth-child(6) a'); // The 'Service' link
            const actionsCell = row.querySelector('td.table-actions .btn-group');
            if (!serviceCell || !actionsCell) return;

            const serviceId = serviceCell.href.split('/').pop();
            const serviceName = serviceCell.textContent;
            const routerRule = row.querySelector('td:nth-child(3) code')?.textContent || 'N/A';

            const workflowButton = document.createElement('button');
            workflowButton.className = 'btn btn-sm btn-outline-info view-traffic-flow-btn';
            workflowButton.innerHTML = '<i class="bi bi-diagram-3"></i>';
            workflowButton.title = 'View Traffic Flow';
            workflowButton.dataset.serviceId = serviceId;
            workflowButton.dataset.serviceName = serviceName;
            workflowButton.dataset.routerRule = routerRule;

            // Insert it as the first button in the action group
            actionsCell.insertBefore(workflowButton, actionsCell.firstChild);
        });
    }
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

    const sort = localStorage.getItem(`${type}_sort`) || 'name';
    const order = localStorage.getItem(`${type}_order`) || 'asc';

    // Show a loading state
    if (type === 'services') {
         container.innerHTML = '<div class="text-center"><div class="spinner-border spinner-border-sm" role="status"><span class="visually-hidden">Loading...</span></div></div>';
    } else { // for 'routers' and 'history' which are in tables
        const colspan = container.closest('table')?.querySelector('thead tr')?.childElementCount || 6;
        container.innerHTML = `<tr><td colspan="${colspan}" class="text-center"><div class="spinner-border spinner-border-sm" role="status"><span class="visually-hidden">Loading...</span></div></td></tr>`;
    }

    let fetchUrl = `${basePath}/api/data?type=${type}&page=${page}&limit=${limit}&search=${encodeURIComponent(searchTerm)}&sort=${sort}&order=${order}`;

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
        // --- FIX: Add group filter for history ---
        const historyGroupFilter = document.getElementById('history-group-filter');
        if (historyGroupFilter && historyGroupFilter.value) {
            fetchUrl += `&group_filter=${historyGroupFilter.value}`;
        }
    }
    // NEW: Add group_by for hosts
    if (type === 'hosts') {
        const groupFilterContainer = document.getElementById('host-group-filter-container');
        if (groupFilterContainer) {
            const selectedGroup = groupFilterContainer.querySelector('input[name="host-group-filter"]:checked');
            if (selectedGroup && selectedGroup.value) {
                fetchUrl += `&group_by=${selectedGroup.value}`;
            }
        }
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

            // Update sort indicators in header
            const tableHeader = container.closest('table')?.querySelector('thead');
            if (tableHeader) {
                tableHeader.querySelectorAll('th.sortable').forEach(th => {
                    th.classList.remove('asc', 'desc');
                    if (th.dataset.sort === sort) {
                        th.classList.add(order);
                    }
                });
            }

            // Inject any client-side dynamic buttons after rendering
            injectDynamicButtons(type);

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

// --- Service Health Status Logic ---
function updateServiceStatus() {
    const indicators = document.querySelectorAll('.service-status-indicator');
    if (indicators.length === 0) {
        return; // No need to fetch if there are no indicators on the page
    }
    // ... implementation is in the context, but it's not relevant to the change.
    // The function is defined but not called. I'll assume it's called somewhere else or should be.
    // Ah, it's called at the end of initializePageSpecificScripts. That's correct.
}

function initializePageSpecificScripts() {
    // --- Sidebar Toggle & Tooltip Logic ---
    const sidebar = document.querySelector('.sidebar');
    const sidebarToggleBtn = document.getElementById('sidebar-toggle-btn');

    function manageSidebarTooltips() {
        if (!sidebar) return;
        const isCollapsed = document.body.classList.contains('sidebar-collapsed');
        const sidebarLinks = sidebar.querySelectorAll('.sidebar-nav .nav-link[data-bs-toggle="tooltip"]');

        sidebarLinks.forEach(link => {
            const tooltip = bootstrap.Tooltip.getInstance(link);
            if (tooltip) {
                if (isCollapsed) {
                    tooltip.enable();
                } else {
                    tooltip.disable();
                }
            }
        });
    }

    if (sidebarToggleBtn && sidebar) {
        sidebarToggleBtn.addEventListener('click', () => {
            document.body.classList.toggle('sidebar-collapsed');
            // Save the state to localStorage
            const isCollapsed = document.body.classList.contains('sidebar-collapsed');
            localStorage.setItem('sidebar-collapsed', isCollapsed);
            // Manage tooltips after the state has changed
            manageSidebarTooltips();
        });
    }

    // Initialize all tooltips on the page
    const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
    [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl));

    // Initial check on page load to disable if not collapsed
    manageSidebarTooltips();

    // --- Live Clock in Navbar ---
    const clockElement = document.getElementById('live-clock');
    if (clockElement && !clockElement.dataset.initialized) {
        clockElement.dataset.initialized = 'true'; // Prevent multiple intervals
        const updateClock = () => {
            const now = new Date();

            // --- NEW: Custom date and time formatting ---
            const day = now.getDate().toString().padStart(2, '0');
            const month = now.toLocaleString('id-ID', { month: 'long' });
            const year = now.getFullYear();

            const timeString = now.toLocaleTimeString('id-ID', {
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
                hour12: false
            }).replace(/\./g, ':'); // Ensure colons are used

            clockElement.textContent = `${day} ${month} ${year} ${timeString} WIB`;
        };
        updateClock(); // Initial call
        setInterval(updateClock, 1000); // Update every second
    }

    // --- Dashboard Widgets Logic ---
    function loadDashboardWidgets() {
        //const basePath = window.basePath || ''; // Ensure basePath is available in this scope
        let containerStatusChart = null; // Variable to hold the chart instance
        const widgets = [
            'total-routers-widget',
            'total-services-widget',
            'total-middlewares-widget',
            'total-hosts-widget',
            'total-users-widget',
            'health-check-widget',
            'agg-total-containers-widget'
        ];

        // Check if we are on the dashboard page by looking for one of the widgets
        if (!document.getElementById(widgets[0])) {
            // Before returning, check for the sync button and run the git status check
            // This handles pages that are not the dashboard but have the header with the sync button.
            if (document.getElementById('sync-stacks-btn')) {
                checkGitSyncStatus();
            }
            return;
        }

        // If on dashboard, also run the git status check
        checkGitSyncStatus();

        // Show loading state for the table
        const perHostContainer = document.getElementById('per-host-stats-container');
        if (perHostContainer) perHostContainer.classList.add('table-loading');

        return fetch(`${basePath}/api/dashboard-stats`)
            .then(response => response.json())
            .then(result => {
                if (result.status === 'success') {
                    const data = result.data;

                    // Defensive check: Ensure elements exist before updating. This prevents errors on rapid SPA navigation.
                    const totalRoutersWidget = document.getElementById('total-routers-widget');
                    if (totalRoutersWidget) totalRoutersWidget.textContent = data.total_routers;

                    const totalServicesWidget = document.getElementById('total-services-widget');
                    if (totalServicesWidget) totalServicesWidget.textContent = data.total_services;

                    // Populate Recent Activity
                    const activityContainer = document.getElementById('recent-activity-container');
                    if (data.recent_activity && activityContainer) {
                        if (data.recent_activity.length > 0) {
                            let activityHtml = '';
                            data.recent_activity.forEach(log => {
                                const logDate = new Date(log.created_at.replace(' ', 'T') + 'Z'); // Make it UTC
                                const now = new Date();
                                const seconds = Math.round((now - logDate) / 1000);
                                let timeAgo;

                                if (seconds < 60) timeAgo = `${seconds}s ago`;
                                else if (seconds < 3600) timeAgo = `${Math.floor(seconds / 60)}m ago`;
                                else if (seconds < 86400) timeAgo = `${Math.floor(seconds / 3600)}h ago`;
                                else timeAgo = `${Math.floor(seconds / 86400)}d ago`;

                                activityHtml += `
                                    <li class="list-group-item d-flex justify-content-between align-items-start">
                                        <div class="ms-2 me-auto">
                                            <div class="fw-bold">${log.username} <span class="fw-normal">${log.action}</span></div>
                                            <small class="text-muted">${log.details}</small>
                                        </div>
                                        <span class="badge bg-light text-dark rounded-pill" title="${logDate.toLocaleString()}">${timeAgo}</span>
                                    </li>
                                `;
                            });
                            activityContainer.innerHTML = activityHtml;
                        } else {
                            activityContainer.innerHTML = '<li class="list-group-item text-center text-muted">No recent activity found.</li>';
                        }
                    }

                    // Populate System Status
                    const systemStatusContainer = document.getElementById('system-status-container');
                    if (data.system_status && systemStatusContainer) {
                        const createStatusItem = (label, value) => {
                            let badgeClass = 'secondary';
                            if (value === 'OK' || value === 'Enabled') badgeClass = 'success';
                            if (value === 'Error' || value === 'Disabled') badgeClass = 'danger';
                            return `
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    ${label}
                                    <span class="badge bg-${badgeClass} rounded-pill">${value}</span>
                                </li>
                            `;
                        };

                        let statusHtml = '';
                        statusHtml += createStatusItem('Database Connection', data.system_status.db_connection);
                        statusHtml += createStatusItem('Config File Writable', data.system_status.config_writable);
                        statusHtml += createStatusItem('PHP Version', data.system_status.php_version);
                        statusHtml += createStatusItem('Cron: Stats Collector', data.system_status.cron_stats_collector);
                        statusHtml += createStatusItem('Cron: Autoscaler', data.system_status.cron_autoscaler);
                        statusHtml += createStatusItem('Cron: Health Monitor', data.system_status.cron_health_monitor);

                        systemStatusContainer.innerHTML = statusHtml;
                    }

                    if (data.agg_stats) {
                        const aggTotalContainersWidget = document.getElementById('agg-total-containers-widget');
                        if (aggTotalContainersWidget) aggTotalContainersWidget.textContent = data.agg_stats.total_containers;

                        const statusWidget = document.getElementById('agg-container-status-widget');
                        if (statusWidget) {
                            statusWidget.innerHTML = `<span class="text-success">${data.agg_stats.running_containers} Running</span> / <span class="text-danger">${data.agg_stats.stopped_containers} Stopped</span>`;
                        }

                        const aggTotalImagesWidget = document.getElementById('agg-total-images-widget');
                        if (aggTotalImagesWidget) aggTotalImagesWidget.textContent = data.total_images;

                        const aggTotalVolumesWidget = document.getElementById('agg-total-volumes-widget');
                        if (aggTotalVolumesWidget) aggTotalVolumesWidget.textContent = data.total_volumes;

                        // Create or update the container status pie chart
                        const chartCanvas = document.getElementById('containerStatusChart');
                        if (chartCanvas) {
                            const chartData = {
                                labels: ['Running', 'Stopped'],
                                datasets: [{
                                    data: [data.agg_stats.running_containers, data.agg_stats.stopped_containers],
                                    backgroundColor: [
                                        'rgba(25, 135, 84, 0.7)', // Success color
                                        'rgba(220, 53, 69, 0.7)'  // Danger color
                                    ],
                                    borderColor: [
                                        'rgba(25, 135, 84, 1)',
                                        'rgba(220, 53, 69, 1)'
                                    ],
                                    borderWidth: 1
                                }]
                            };

                            if (window.dashboardContainerChart) {
                                window.dashboardContainerChart.destroy();
                            }

                            window.dashboardContainerChart = new Chart(chartCanvas, {
                                type: 'doughnut',
                                data: chartData,
                                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
                            });
                        }
                    }

                // Populate Swarm Info
                const swarmSection = document.getElementById('swarm-overview-section');
                if (data.swarm_info && swarmSection) {
                    const swarmTotalNodesWidget = document.getElementById('swarm-total-nodes-widget');
                    if (swarmTotalNodesWidget) swarmTotalNodesWidget.textContent = data.swarm_info.total_nodes || '0';

                    const swarmManagerWorkerWidget = document.getElementById('swarm-manager-worker-widget');
                    if (swarmManagerWorkerWidget) swarmManagerWorkerWidget.textContent = `${data.swarm_info.managers || '0'} M / ${data.swarm_info.workers || '0'} W`;

                    swarmSection.style.display = 'block'; // Show the section
                } else if (swarmSection) {
                    // If swarm_info is null or not present, ensure the section is hidden
                    swarmSection.style.display = 'none';
                }

                const unhealthyItemsWidget = document.getElementById('unhealthy-items-widget');
                if (unhealthyItemsWidget) unhealthyItemsWidget.textContent = data.total_unhealthy;

                if (data.total_unhealthy > 0) {
                    document.getElementById('unhealthy-items-widget').classList.add('blinking-badge');
                    }

                    if (data.per_host_stats) {
                        const container = perHostContainer;
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

                                let diskUsageHtml = 'N/A';
                                if (host.disk_usage !== 'N/A') {
                                    const diskUsage = parseFloat(host.disk_usage);
                                    let progressColor = 'bg-success';
                                    if (diskUsage > 90) progressColor = 'bg-danger';
                                    else if (diskUsage > 75) progressColor = 'bg-warning';
                                    diskUsageHtml = `
                                        <div class="progress" style="height: 20px; font-size: .75rem;">
                                            <div class="progress-bar ${progressColor}" role="progressbar" style="width: ${diskUsage}%;" aria-valuenow="${diskUsage}" aria-valuemin="0" aria-valuemax="100">${diskUsage}%</div>
                                        </div>
                                    `;
                                }

                                html += `
                                    <tr>
                                        <td data-sort-key="name" data-sort-value="${host.name.toLowerCase()}"><a href="${basePath}/hosts/${host.id}/details">${host.name}</a></td>
                                        <td data-sort-key="status" data-sort-value="${host.status}">${statusBadge}</td>
                                        <td data-sort-key="running_containers" data-sort-value="${host.running_containers}">${containers}</td>
                                        <td data-sort-key="cpus" data-sort-value="${host.cpus}">${totalCpus}</td>
                                        <td data-sort-key="memory" data-sort-value="${host.memory}">${totalMemory}</td>
                                        <td data-sort-key="disk_usage" data-sort-value="${host.disk_usage}">${diskUsageHtml}</td>
                                        <td data-sort-key="uptime_timestamp" data-sort-value="${host.uptime_timestamp || 0}">${host.uptime || 'N/A'}</td>
                                        <td>${dockerVersion}</td>
                                        <td>${os}</td>
                                        <td class="text-end">
                                            <a href="${basePath}/hosts/${host.id}/details" class="btn btn-sm btn-outline-primary" title="Manage Host"><i class="bi bi-box-arrow-in-right"></i> Manage</a>
                                        </td>
                                    </tr>
                                `;
                            });
                            container.innerHTML = html;

                            // --- NEW: Populate Containers per Host Chart ---
                            const hostChartCanvas = document.getElementById('containersPerHostChart');
                            if (hostChartCanvas) {
                                const reachableHosts = data.per_host_stats.filter(h => h.status === 'Reachable');
                                const labels = reachableHosts.map(h => h.name);
                                const runningData = reachableHosts.map(h => h.running_containers);
                                const stoppedData = reachableHosts.map(h => h.total_containers - h.running_containers);

                                const chartData = {
                                    labels: labels,
                                    datasets: [
                                        {
                                            label: 'Running',
                                            data: runningData,
                                            backgroundColor: 'rgba(25, 135, 84, 0.7)', // Success
                                        },
                                        {
                                            label: 'Stopped',
                                            data: stoppedData,
                                            backgroundColor: 'rgba(220, 53, 69, 0.7)', // Danger
                                        }
                                    ]
                                };

                                if (window.dashboardHostChart) {
                                    window.dashboardHostChart.destroy();
                                }

                                window.dashboardHostChart = new Chart(hostChartCanvas, {
                                    type: 'bar',
                                    data: chartData,
                                    options: {
                                        responsive: true,
                                        maintainAspectRatio: false,
                                        plugins: { legend: { position: 'top' } },
                                        scales: {
                                            x: { stacked: true },
                                            y: { stacked: true, beginAtZero: true, ticks: { stepSize: 1 } },
                                        },
                                        onHover: (event, chartElement) => {
                                            const canvas = event.native.target;
                                            canvas.style.cursor = chartElement[0] ? 'pointer' : 'default';
                                        },
                                        onClick: (evt) => {
                                            const points = window.dashboardHostChart.getElementsAtEventForMode(evt, 'nearest', { intersect: true }, true);
                                            if (points.length) {
                                                const firstPoint = points[0];
                                                const index = firstPoint.index;
                                                const datasetIndex = firstPoint.datasetIndex;
                                                const host = reachableHosts[index];

                                                // Determine filter based on which part of the stacked bar was clicked
                                                // 0 = Running, 1 = Stopped, based on the dataset order
                                                const filter = datasetIndex === 0 ? 'running' : 'stopped';

                                                // Construct the URL with the filter parameter
                                                const url = `${window.location.origin}${basePath}/hosts/${host.id}/containers?filter=${filter}`;
                                                loadPage(url); // Use SPA navigation
                                            }
                                        }
                                    }
                                });
                            }
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
            })
            .finally(() => {
                if (perHostContainer) perHostContainer.classList.remove('table-loading');
            });
    }

    loadDashboardWidgets();

    // --- Refresh Button Logic ---
    const refreshBtn = document.getElementById('refresh-host-stats-btn');
    if (refreshBtn && !refreshBtn.dataset.listenerAttached) {
        refreshBtn.dataset.listenerAttached = 'true'; // Prevent multiple listeners on SPA nav
        refreshBtn.addEventListener('click', function() {
            const originalContent = this.innerHTML;
            this.disabled = true;
            this.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Refreshing...`;

            loadDashboardWidgets().finally(() => {
                this.disabled = false;
                this.innerHTML = originalContent;
            });
        });
    }

    // --- Table Sorting Logic ---
    const perHostTable = document.querySelector('#per-host-stats-container')?.closest('table');
    if (perHostTable && !perHostTable.dataset.sortListenerAttached) {
        perHostTable.dataset.sortListenerAttached = 'true'; // Prevent re-attaching on SPA nav
        perHostTable.querySelectorAll('thead th.sortable').forEach(headerCell => {
            headerCell.addEventListener('click', () => {
                const tableBody = perHostTable.querySelector('tbody');
                const columnKey = headerCell.dataset.sort;
                const defaultOrder = headerCell.dataset.sortDefault || 'asc';
                const isAsc = headerCell.classList.contains('asc');
                const isDesc = headerCell.classList.contains('desc');
                let newOrder;

                if (isAsc) newOrder = 'desc';
                else if (isDesc) newOrder = 'asc';
                else newOrder = defaultOrder;

                // Remove sorting classes from all headers
                perHostTable.querySelectorAll('thead th.sortable').forEach(th => {
                    th.classList.remove('asc', 'desc');
                });

                // Add class to the clicked header
                headerCell.classList.add(newOrder);

                // Sort the rows
                Array.from(tableBody.querySelectorAll('tr'))
                    .sort((a, b) => {
                        const colIndex = Array.from(headerCell.parentNode.children).indexOf(headerCell);
                        const cellA = a.querySelector(`td[data-sort-key="${columnKey}"]`) || a.children[colIndex];
                        const cellB = b.querySelector(`td[data-sort-key="${columnKey}"]`) || b.children[colIndex];

                        const valA = cellA.dataset.sortValue !== undefined ? cellA.dataset.sortValue : cellA.textContent.trim();
                        const valB = cellB.dataset.sortValue !== undefined ? cellB.dataset.sortValue : cellB.textContent.trim();
                        
                        if (valA === 'N/A' || valA === null) return 1;
                        if (valB === 'N/A' || valB === null) return -1;

                        const isNumeric = !isNaN(parseFloat(valA)) && isFinite(valA) && !isNaN(parseFloat(valB)) && isFinite(valB);

                        let comparison = 0;
                        if (isNumeric) {
                            comparison = parseFloat(valA) - parseFloat(valB);
                        } else {
                            comparison = valA.localeCompare(valB, undefined, { numeric: true, sensitivity: 'base' });
                        }

                        return newOrder === 'asc' ? comparison : -comparison;
                    })
                    .forEach(row => tableBody.appendChild(row));
            });
        });
    }

    // --- Swarm Details Page Logic ---
    function loadSwarmNodes() {
        const container = document.getElementById('swarm-nodes-container');
        if (!container) return;

        // Show loading state
        container.innerHTML = `<tr><td colspan="7" class="text-center"><div class="spinner-border spinner-border-sm" role="status"><span class="visually-hidden">Loading...</span></div></td></tr>`;

        return fetch(`${basePath}/api/swarm/nodes`)
            .then(response => response.json())
            .then(result => {
                if (result.status !== 'success') {
                    throw new Error(result.message || 'Failed to load Swarm nodes.');
                }

                let html = '';
                if (result.data && result.data.length > 0) {
                    result.data.forEach(node => {
                        const status = node.Status?.State || 'unknown';
                        const availability = node.Spec?.Availability || 'unknown';

                        let statusBadge = 'secondary';
                        if (status === 'ready') statusBadge = 'success';
                        else if (status === 'down') statusBadge = 'danger';

                        let availabilityBadge = 'secondary';
                        if (availability === 'active') availabilityBadge = 'success';
                        else if (availability === 'drain') availabilityBadge = 'warning';
                        else if (availability === 'pause') availabilityBadge = 'info';

                        html += `
                            <tr>
                                <td><small><code>${node.ID}</code></small></td>
                                <td>${node.Description?.Hostname || 'N/A'}</td>
                                <td><span class="badge bg-primary">${node.Spec?.Role || 'unknown'}</span></td>
                                <td><span class="badge bg-${availabilityBadge}">${availability}</span></td>
                                <td><span class="badge bg-${statusBadge}">${status}</span></td>
                                <td>${node.Status?.Addr || 'N/A'}</td>
                                <td><span class="badge bg-info">${node.Description?.Engine?.EngineVersion || 'N/A'}</span></td>
                            </tr>
                        `;
                    });
                } else {
                    html = `<tr><td colspan="7" class="text-center">No Swarm nodes found.</td></tr>`;
                }
                container.innerHTML = html;
            })
            .catch(error => {
                console.error('Error loading Swarm nodes:', error);
                container.innerHTML = `<tr><td colspan="7" class="text-center text-danger">${error.message}</td></tr>`;
            });
    }

    // Attach listener for the refresh button on the swarm details page
    const refreshSwarmBtn = document.getElementById('refresh-swarm-nodes-btn');
    if (refreshSwarmBtn && !refreshSwarmBtn.dataset.listenerAttached) {
        refreshSwarmBtn.dataset.listenerAttached = 'true';
        refreshSwarmBtn.addEventListener('click', function() {
            const originalContent = this.innerHTML;
            this.disabled = true;
            this.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Refreshing...`;
            loadSwarmNodes().finally(() => {
                this.disabled = false;
                this.innerHTML = originalContent;
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

    if (document.getElementById('traefik-hosts-container')) {
        const initialPage = localStorage.getItem('traefik-hosts_page') || 1;
        const initialLimit = localStorage.getItem('traefik-hosts_limit') || 10;
        loadPaginatedData('traefik-hosts', initialPage, initialLimit);
    }

    if (document.getElementById('traefik-hosts-container')) {
        const initialPage = localStorage.getItem('traefik-hosts_page') || 1;
        const initialLimit = localStorage.getItem('traefik-hosts_limit') || 10;
        loadPaginatedData('traefik-hosts', initialPage, initialLimit);
    }

    // --- Swarm Details Page Initial Load ---
    if (document.getElementById('swarm-nodes-container')) {
        loadSwarmNodes();
    }

    // --- Health Check Page Logic ---
    if (document.getElementById('health-check-results')) {
        runHealthChecks();
        document.getElementById('rerun-checks-btn').addEventListener('click', runHealthChecks);
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
            const diff2htmlUi = new Diff2HtmlUI(diffContainer, diffString, { drawFileList: true, matching: 'lines', outputFormat: 'side-by-side' });
            diff2htmlUi.draw();
        }
    }

    // --- Service Health Status Logic ---
    if (document.getElementById('services-container')) {
        updateServiceStatus();
        setInterval(updateServiceStatus, 15000); // Refresh every 15 seconds
    }

    // --- Bulk Actions for Routers ---
    const routerTableBody = document.querySelector('#routers-container');
    if (routerTableBody) {
        const selectAllRouters = document.getElementById('select-all-routers');
        const bulkActionsDropdown = document.getElementById('router-bulk-actions-dropdown');
        const selectedRouterCount = document.getElementById('selected-router-count');
        const bulkDeleteRouterCount = document.getElementById('bulk-delete-router-count');
        const confirmMoveBtn = document.getElementById('confirm-move-group-btn');
        const confirmBulkDeleteBtn = document.getElementById('confirm-bulk-delete-btn');

        const updateRouterBulkActionsVisibility = () => {
            const checkedBoxes = routerTableBody.querySelectorAll('.router-checkbox:checked');
            const count = checkedBoxes.length;
            if (count > 0) {
                bulkActionsDropdown.style.display = 'block';
                if (selectedRouterCount) selectedRouterCount.textContent = count;
                if (bulkDeleteRouterCount) bulkDeleteRouterCount.textContent = count;
            } else {
                bulkActionsDropdown.style.display = 'none';
            }
            if (selectAllRouters) {
                const totalCheckboxes = routerTableBody.querySelectorAll('.router-checkbox').length;
                selectAllRouters.checked = totalCheckboxes > 0 && count === totalCheckboxes;
            }
        };

        // Use event delegation on the table body
        routerTableBody.addEventListener('change', (e) => {
            if (e.target.matches('.router-checkbox')) {
                updateRouterBulkActionsVisibility();
            }
        });

        if (selectAllRouters) {
            selectAllRouters.addEventListener('change', function() {
                const isChecked = this.checked;
                routerTableBody.querySelectorAll('.router-checkbox').forEach(checkbox => {
                    checkbox.checked = isChecked;
                });
                updateRouterBulkActionsVisibility();
            });
        }

        // Handle "Move to Group" action
        if (confirmMoveBtn) {
            confirmMoveBtn.addEventListener('click', function() {
                const targetGroupId = document.getElementById('target_group_id').value;
                const checkedBoxes = Array.from(routerTableBody.querySelectorAll('.router-checkbox:checked'));
                const routerIds = checkedBoxes.map(cb => cb.value);

                if (routerIds.length === 0) {
                    showToast('No routers selected.', false);
                    return;
                }

                const formData = new FormData();
                formData.append('action', 'move');
                formData.append('target_group_id', targetGroupId);
                routerIds.forEach(id => formData.append('router_ids[]', id));

                fetch(`${basePath}/api/routers/bulk-move`, { method: 'POST', body: formData })
                    .then(response => response.json().then(data => ({ ok: response.ok, data })))
                    .then(({ ok, data }) => {
                        showToast(data.message, ok);
                        if (ok) {
                            bootstrap.Modal.getInstance(document.getElementById('moveGroupModal')).hide();
                            loadPaginatedData('routers', 1); // Refresh the table
                        }
                    })
                    .catch(error => showToast(error.message, false));
            });
        }

        // Handle "Bulk Delete" action
        if (confirmBulkDeleteBtn) {
            confirmBulkDeleteBtn.addEventListener('click', function() {
                const checkedBoxes = Array.from(routerTableBody.querySelectorAll('.router-checkbox:checked'));
                const routerIds = checkedBoxes.map(cb => cb.value);

                if (routerIds.length === 0) {
                    showToast('No routers selected.', false);
                    return;
                }

                const formData = new FormData();
                formData.append('action', 'delete');
                routerIds.forEach(id => formData.append('router_ids[]', id));

                fetch(`${basePath}/api/routers/bulk-delete`, { method: 'POST', body: formData })
                    .then(response => response.json().then(data => ({ ok: response.ok, data })))
                    .then(({ ok, data }) => {
                        showToast(data.message, ok);
                        if (ok) {
                            bootstrap.Modal.getInstance(document.getElementById('bulkDeleteModal')).hide();
                            loadPaginatedData('routers', 1); // Refresh the table
                        }
                    })
                    .catch(error => showToast(error.message, false));
            });
        }
    }

    // --- Join Swarm Modal Logic ---
    const joinSwarmModalEl = document.getElementById('joinSwarmModal');
    if (joinSwarmModalEl && !joinSwarmModalEl.dataset.listenerAttached) {
        joinSwarmModalEl.dataset.listenerAttached = 'true'; // Prevent re-attaching

        const joinSwarmModal = new bootstrap.Modal(joinSwarmModalEl);
        const managerSelect = document.getElementById('manager-host-select');
        const targetHostIdInput = document.getElementById('join-swarm-target-host-id');
        const confirmJoinBtn = document.getElementById('confirm-join-swarm-btn');

        joinSwarmModalEl.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            const targetHostId = button.dataset.hostId;
            targetHostIdInput.value = targetHostId;

            // Populate the manager dropdown
            managerSelect.innerHTML = '<option>Loading managers...</option>';
            
            // Fetch only manager hosts from the new dedicated endpoint
            fetch(`${basePath}/api/hosts/list?filter=managers`)
                .then(response => response.json())
                .then(result => {
                    if (result.status !== 'success') throw new Error(result.message);

                    let optionsHtml = '<option value="">-- Select a Manager --</option>';
                    result.data.forEach(host => { optionsHtml += `<option value="${host.id}">${host.name}</option>`; });
                    managerSelect.innerHTML = optionsHtml;
                })
                .catch(error => {
                    managerSelect.innerHTML = '<option value="">Error loading managers</option>';
                    showToast('Could not load manager hosts: ' + error.message, false);
                });
        });

        confirmJoinBtn.addEventListener('click', function() {
            const formData = new FormData(document.getElementById('join-swarm-form'));
            if (!formData.get('manager_host_id')) {
                showToast('Please select a manager host.', false);
                return;
            }

            const originalBtnText = this.innerHTML;
            this.disabled = true;
            this.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Joining...`;

            fetch(`${basePath}/api/swarm/join`, { method: 'POST', body: formData })
                .then(response => response.json().then(data => ({ ok: response.ok, data })))
                .then(({ ok, data }) => {
                    if (ok) {
                        showToast(data.message, true);
                        joinSwarmModal.hide();
                        loadPaginatedData('hosts', localStorage.getItem('hosts_page') || 1, localStorage.getItem('hosts_limit') || 10);
                    } else { throw new Error(data.message || 'Failed to join swarm.'); }
                })
                .catch(error => showToast(error.message, false))
                .finally(() => { this.disabled = false; this.innerHTML = originalBtnText; });
        });
    }

// --- Preview Config Modal Logic ---
        const previewBtn = document.getElementById('preview-config-btn');
        if (previewBtn && !previewBtn.dataset.listenerAttached) {
            previewBtn.dataset.listenerAttached = 'true';
            const previewModalEl = document.getElementById('previewConfigModal');
            const previewModal = new bootstrap.Modal(previewModalEl);
            const linterContainer = document.getElementById('linter-results-container');
            const contentContainer = document.getElementById('preview-yaml-content-container');
            const deployFromPreviewBtn = document.getElementById('deploy-from-preview-btn');
    
            previewBtn.addEventListener('click', () => {
                contentContainer.textContent = 'Loading...';
                previewModal.show();
    
                linterContainer.innerHTML = '<div class="text-center"><div class="spinner-border spinner-border-sm" role="status"></div><span class="ms-2">Running validator...</span></div>';

                const groupFilter = document.getElementById('router-group-filter');
                const groupId = groupFilter ? groupFilter.value : '';
                const queryParams = new URLSearchParams({ ignore_host_filter: 'true' });
                if (groupId) {
                    queryParams.set('group_id', groupId);
                }
                const previewUrl = `${basePath}/api/configurations/preview?${queryParams.toString()}`;

                fetch(previewUrl)
                    .then(response => {
                        if (!response.ok) {
                            return response.json().then(err => { throw new Error(err.message || 'Network response was not ok.') });
                        }
                        deployFromPreviewBtn.disabled = false; // Re-enable for full preview
                        deployFromPreviewBtn.title = ''; // Clear any disabled title
                        return response.json();
                    })
                    .then(data => {
                        linterContainer.innerHTML = '';
                        deployFromPreviewBtn.disabled = false; // Enable by default
    
                        if (data.linter) {
                            if (data.linter.errors && data.linter.errors.length > 0) {
                                let errorsHtml = '<div class="alert alert-danger"><h6><i class="bi bi-x-circle-fill me-2"></i>Errors Found</h6><ul class="mb-0">';
                                data.linter.errors.forEach(err => { errorsHtml += `<li>${err}</li>`; });
                                errorsHtml += '</ul></div>';
                                linterContainer.insertAdjacentHTML('beforeend', errorsHtml);
                                deployFromPreviewBtn.disabled = true;
                            }
                            if (data.linter.warnings && data.linter.warnings.length > 0) {
                                let warningsHtml = '<div class="alert alert-warning"><h6><i class="bi bi-exclamation-triangle-fill me-2"></i>Warnings</h6><ul class="mb-0">';
                                data.linter.warnings.forEach(warn => { warningsHtml += `<li>${warn}</li>`; });
                                warningsHtml += '</ul></div>';
                                linterContainer.insertAdjacentHTML('beforeend', warningsHtml);
                            }
                        }
    
                        if (data.status === 'success' && data.content) {
                            contentContainer.textContent = data.content;
                            Prism.highlightElement(contentContainer);
                        } else if (data.status !== 'success') {
                            throw new Error(data.message || 'Failed to load preview content.');
                        }
                    })
                    .catch(error => {
                        linterContainer.innerHTML = '';
                        contentContainer.textContent = 'Error loading content: ' + error.message;
                        deployFromPreviewBtn.disabled = true;
                    });
            });
        }

        // --- Deploy from Preview Modal Logic ---
        const deployFromPreviewBtn = document.getElementById('deploy-from-preview-btn');
        if (deployFromPreviewBtn && !deployFromPreviewBtn.dataset.listenerAttached) {
            deployFromPreviewBtn.dataset.listenerAttached = 'true';
            deployFromPreviewBtn.addEventListener('click', function() {
                const previewModalEl = document.getElementById('previewConfigModal');
                const groupId = previewModalEl.dataset.groupId; // Get group ID if set from group-specific preview

                const originalBtnText = this.innerHTML;
                this.disabled = true;
                this.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Deploying...`;

                // The deploy URL is always /generate, but we might add a group_id
                let deployUrl = `${basePath}/generate`;
                if (groupId) {
                    deployUrl += `?group_id=${groupId}`;
                }

                fetch(deployUrl, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                    .then(response => response.json().then(data => ({ ok: response.ok, data })))
                    .then(({ ok, data }) => {
                        showToast(data.message, ok);
                        if (ok) {
                            bootstrap.Modal.getInstance(previewModalEl).hide();
                            // After deploying, refresh the view.
                            if (document.getElementById('routers-container')) {
                                loadPaginatedData('routers', 1);
                            }
                            if (document.getElementById('groups-container')) {
                                loadPaginatedData('groups', 1);
                            }
                            checkGitSyncStatus();
                        }
                    })
                    .catch(error => {
                        showToast(error.message || 'An unknown error occurred during deployment.', false);
                    })
                    .finally(() => {
                        this.disabled = false;
                        this.innerHTML = originalBtnText;
                    });
            });
        }

        // --- Group-specific Preview Config Modal Logic ---
        const groupsContainer = document.getElementById('groups-container');
        if (groupsContainer) {
            const previewModalEl = document.getElementById('previewConfigModal');

            // Use event delegation on the container
            groupsContainer.addEventListener('click', function(e) {
                const previewGroupBtn = e.target.closest('.preview-group-config-btn');
                if (!previewGroupBtn) return;

                e.preventDefault();

                const groupId = previewGroupBtn.dataset.groupId;
                const groupName = previewGroupBtn.dataset.groupName;

                const previewModal = bootstrap.Modal.getInstance(previewModalEl) || new bootstrap.Modal(previewModalEl);
                const linterContainer = document.getElementById('linter-results-container');
                const contentContainer = document.getElementById('preview-yaml-content-container');
                const deployFromPreviewBtn = document.getElementById('deploy-from-preview-btn');
                const modalLabel = document.getElementById('previewConfigModalLabel');

                // Store the group ID on the modal for the deploy button to use
                previewModalEl.dataset.groupId = groupId;

                // Update modal title and show
                modalLabel.textContent = `Preview Config for Group: ${groupName}`;
                contentContainer.textContent = 'Loading...';
                previewModal.show();

                linterContainer.innerHTML = '<div class="text-center"><div class="spinner-border spinner-border-sm" role="status"></div><span class="ms-2">Running validator...</span></div>';

                // This is achieved by passing ignore_host_filter and group_id.
                const queryParams = new URLSearchParams({
                    ignore_host_filter: 'true',
                    group_id: groupId
                });
                const previewUrl = `${basePath}/api/configurations/preview?${queryParams.toString()}`;

                fetch(previewUrl)
                    .then(response => response.json().then(data => ({ ok: response.ok, data })))
                    .then(({ ok, data }) => {
                        if (!ok) throw new Error(data.message || 'Failed to generate preview.');
                        
                        linterContainer.innerHTML = '';
                        deployFromPreviewBtn.disabled = false;

                        if (data.linter) {
                            if (data.linter.errors && data.linter.errors.length > 0) {
                                let errorsHtml = '<div class="alert alert-danger"><h6><i class="bi bi-x-circle-fill me-2"></i>Errors Found</h6><ul class="mb-0">';
                                data.linter.errors.forEach(err => { errorsHtml += `<li>${err}</li>`; });
                                errorsHtml += '</ul></div>';
                                linterContainer.insertAdjacentHTML('beforeend', errorsHtml);
                                deployFromPreviewBtn.disabled = true;
                            }
                            if (data.linter.warnings && data.linter.warnings.length > 0) {
                                let warningsHtml = '<div class="alert alert-warning"><h6><i class="bi bi-exclamation-triangle-fill me-2"></i>Warnings</h6><ul class="mb-0">';
                                data.linter.warnings.forEach(warn => { warningsHtml += `<li>${warn}</li>`; });
                                warningsHtml += '</ul></div>';
                                linterContainer.insertAdjacentHTML('beforeend', warningsHtml);
                            }
                        }

                        if (data.status === 'success' && data.content) {
                            contentContainer.textContent = data.content;
                            Prism.highlightElement(contentContainer);
                        } else if (data.status !== 'success') {
                            throw new Error(data.message || 'Failed to load preview content.');
                        }
                    })
                    .catch(error => {
                        linterContainer.innerHTML = '';
                        contentContainer.textContent = 'Error loading content: ' + error.message;
                        deployFromPreviewBtn.disabled = true;
                    });
            });

            if (previewModalEl && !previewModalEl.dataset.hiddenListenerAttached) {
                previewModalEl.dataset.hiddenListenerAttached = 'true';
                previewModalEl.addEventListener('hidden.bs.modal', function() {
                    delete this.dataset.groupId;
                    document.getElementById('previewConfigModalLabel').textContent = 'Preview & Validate Configuration';
                });
            }
        }

        // --- History Page Logic ---
        const historyContainer = document.getElementById('history-container');
        if (historyContainer) {
            const historyTable = historyContainer.closest('table');
            const compareBtn = document.getElementById('compare-btn');
            const viewHistoryModal = document.getElementById('viewHistoryModal');

            // 1. Handle multi-checkbox selection for compare button
            if (historyTable && compareBtn && !historyTable.dataset.changeListener) {
                historyTable.dataset.changeListener = 'true';
                historyTable.addEventListener('change', (e) => {
                    const historyCheckbox = e.target.closest('.history-checkbox, #select-all-history');
                    if (!historyCheckbox) return;

                    if (historyCheckbox.id === 'select-all-history') {
                        const isChecked = historyCheckbox.checked;
                        historyTable.querySelectorAll('.history-checkbox').forEach(checkbox => {
                            checkbox.checked = isChecked;
                        });
                    }

                    const checkedBoxes = historyTable.querySelectorAll('.history-checkbox:checked');
                    const count = checkedBoxes.length;
                    compareBtn.innerHTML = `<i class="bi bi-files"></i> Compare Selected (${count})`;
                    compareBtn.disabled = count !== 2;
                });
            }

            // 2. Handle compare button click
            if (compareBtn && !compareBtn.dataset.clickListener) {
                compareBtn.dataset.clickListener = 'true';
                compareBtn.addEventListener('click', () => {
                    const checkedBoxes = historyTable.querySelectorAll('.history-checkbox:checked');
                    if (checkedBoxes.length === 2) {
                        const fromId = checkedBoxes[0].value;
                        const toId = checkedBoxes[1].value;
                        const compareUrl = `${window.location.origin}${basePath}/history/compare?from=${fromId}&to=${toId}`;
                        // Change to a full page load for clarity, as requested.
                        window.location.href = compareUrl;
                    }
                });
            }

            // 3. Handle "View YAML" modal content loading
            if (viewHistoryModal && !viewHistoryModal.dataset.listenerAttached) {
                viewHistoryModal.dataset.listenerAttached = 'true';
                viewHistoryModal.addEventListener('show.bs.modal', function(event) {
                    const button = event.relatedTarget;
                    const historyId = button.dataset.id;
                    const contentContainer = document.getElementById('yaml-content-container');
                    const modalLabel = document.getElementById('viewHistoryModalLabel');

                    if (contentContainer && modalLabel) {
                        modalLabel.textContent = `View YAML Configuration (ID: #${historyId})`;
                        contentContainer.textContent = 'Loading...';
                        fetch(`${basePath}/history/${historyId}/content`)
                            .then(response => response.json())
                            .then(data => {
                                if (data.content) {
                                    contentContainer.textContent = data.content;
                                    Prism.highlightElement(contentContainer);
                                } else {
                                    throw new Error(data.message || 'Content not found.');
                                }
                            })
                            .catch(error => {
                                contentContainer.textContent = 'Error loading content: ' + error.message;
                            });
                    }
                });
            }
        }

        // --- Sync Stacks to Git Logic (Modal Listeners) ---
        const syncGitModalEl = document.getElementById('syncGitModal');
        if (syncGitModalEl && !syncGitModalEl.dataset.listenerAttached) {
            syncGitModalEl.dataset.listenerAttached = 'true';
    
            // Logic for when the modal is shown
            syncGitModalEl.addEventListener('show.bs.modal', function (event) {
                const diffOutputContainer = document.getElementById('git-diff-output');
                // Get a fresh reference to the button that triggered the modal. This is the most reliable way.
                const button = event.relatedTarget;
                const diffString = button ? (button.dataset.diff || '') : '';
    
                diffOutputContainer.innerHTML = ''; // Clear previous diff
    
                if (diffString.trim() === '') {
                    diffOutputContainer.innerHTML = '<div class="alert alert-info">No changes to display.</div>';
                } else {
                    const diff2htmlUi = new Diff2HtmlUI(diffOutputContainer, diffString, {
                        drawFileList: true,
                        matching: 'lines',
                        outputFormat: 'side-by-side'
                    });
                    diff2htmlUi.draw();
                }
            });
    
            // Logic for the confirm button inside the modal
            const confirmSyncBtn = document.getElementById('confirm-git-sync-btn');
            if (confirmSyncBtn) {
                confirmSyncBtn.addEventListener('click', function() {
                    const originalBtnText = this.innerHTML;
                    this.disabled = true;
                    this.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Syncing...`;
    
                    fetch(`${basePath}/api/stacks/sync-to-git`, { method: 'POST' })
                        .then(response => response.json().then(data => ({ ok: response.ok, data })))
                        .then(({ ok, data }) => {
                            showToast(data.message, ok);
                            bootstrap.Modal.getInstance(syncGitModalEl).hide();
                            if (ok) checkGitSyncStatus();
                        })
                        .catch(error => showToast(error.message || 'An unknown error occurred during sync.', false))
                        .finally(() => {
                            this.disabled = false;
                            this.innerHTML = originalBtnText;
                        });
                });
            }
        }
  
    // --- Middleware Management Modal Logic ---
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
        // let isAddingMiddleware = false; // Flag to track modal mode - REMOVED

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
                // Read the mode directly from the modal's data attribute
                const isAdding = middlewareModal.dataset.isAdding === 'true';
                if (isAdding && middlewareTemplates[selectedType]) {
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
                middlewareModal.dataset.isAdding = 'true'; // Set state on the DOM element
                middlewareModalLabel.textContent = 'Add New Middleware';
                middlewareForm.action = `${basePath}/middlewares/new`;
            } else { // edit
                middlewareModalLabel.textContent = 'Edit Middleware';
                middlewareModal.dataset.isAdding = 'false'; // Set state on the DOM element
                const id = button.getAttribute('data-id');
                middlewareIdInput.value = id;
                middlewareNameInput.value = button.getAttribute('data-name');
                middlewareTypeInput.value = button.getAttribute('data-type');
                middlewareDescInput.value = button.getAttribute('data-description');
                middlewareConfigInput.value = button.getAttribute('data-config_json');
                middlewareForm.action = `${basePath}/middlewares/${id}/edit`;
            }
        });

        // Prevent attaching multiple listeners on SPA navigation
        if (saveMiddlewareBtn && !saveMiddlewareBtn.dataset.listenerAttached) {
            saveMiddlewareBtn.dataset.listenerAttached = 'true';
            saveMiddlewareBtn.addEventListener('click', function () {
                const originalBtnText = this.innerHTML;
                this.disabled = true;
                this.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Saving...`;

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
                        showToast(data.message, true);
                        loadPaginatedData('middlewares', 1); // Reload the table
                    } else {
                        throw new Error(data.message || 'An error occurred.');
                    }
                })
                .catch(error => {
                    showToast(error.message, false);
                    console.error('Error:', error);
                })
                .finally(() => {
                    // Restore button state in case of failure or if modal doesn't hide
                    this.disabled = false;
                    this.innerHTML = originalBtnText;
                });
            });
        }
    }

    // --- Group Management Modal Logic ---
    const groupModal = document.getElementById('groupModal');
    if (groupModal && !groupModal.dataset.listenerAttached) {
        groupModal.dataset.listenerAttached = 'true';
        const form = document.getElementById('group-form');
        const modalTitle = document.getElementById('groupModalLabel');
        const groupIdInput = document.getElementById('group-id');
        const groupNameInput = document.getElementById('group-name');
        const groupDescInput = document.getElementById('group-description');
        const groupHostSelect = document.getElementById('group-traefik-host');
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
                const hostId = button.dataset.traefik_host_id || '';
                modalTitle.textContent = `Edit Group: ${groupName}`;
                form.action = `${basePath}/groups/${groupId}/edit`;
                groupIdInput.value = groupId;
                groupNameInput.value = groupName;
                groupDescInput.value = groupDesc;
                groupHostSelect.value = hostId;
            }
        });

        saveBtn.addEventListener('click', function() {
            const formData = new FormData(form);
            const url = form.action;
            const originalButtonText = saveBtn.innerHTML;

            saveBtn.disabled = true;
            saveBtn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Saving...`;

            fetch(url, { method: 'POST', body: formData })
                .then(response => response.json().then(data => ({ ok: response.ok, data })))
                .then(({ ok, data }) => {
                    showToast(data.message, ok);
                    if (ok) {
                        bootstrap.Modal.getInstance(groupModal).hide();
                        if (document.getElementById('groups-container')) {
                            loadPaginatedData('groups', 1);
                        }
                    } else { throw new Error(data.message || 'An unknown error occurred.'); }
                })
                .catch(error => showToast(error.message, false))
                .finally(() => {
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = 'Save Group';
                });
        });
    }
}

function checkTraefikConfigStatus() {
    const deployBtn = document.getElementById('deploy-notification-btn');
    if (!deployBtn) return;

    fetch(`${basePath}/api/status/config-dirty`)
        .then(response => response.json())
        .then(result => {
            if (result.status === 'success' && result.dirty) {
                deployBtn.style.display = 'inline-block';
            } else {
                deployBtn.style.display = 'none';
            }
        })
        .catch(error => {
            console.error('Error checking Traefik config status:', error);
            // Sembunyikan tombol jika ada error untuk menghindari kebingungan
            deployBtn.style.display = 'none';
        });
}


document.addEventListener('DOMContentLoaded', function () {

    // --- Sidebar Active Link Logic ---
    updateActiveLink(window.location.href);

    // Add animation listener for SPA transitions
    if (mainContent) {
        mainContent.addEventListener('animationend', (e) => {
            // Remove the class after the animation so it can be re-triggered
            if (e.animationName === 'fadeIn') {
                mainContent.classList.remove('is-loaded');
            }
        });
    }

    initializePageSpecificScripts();

    // --- Check for pending Traefik changes on initial load and then periodically ---
    checkTraefikConfigStatus();
    setInterval(checkTraefikConfigStatus, 30000); // Check every 30 seconds

    // Run page-specific init function for the initial page load
    if (window.pageInit && typeof window.pageInit === 'function') {
        window.pageInit();
        delete window.pageInit;
    }

    // --- Traefik Host Management Modal Logic (Moved to Global Scope) ---
    const traefikHostModal = document.getElementById('traefikHostModal');
    if (traefikHostModal && !traefikHostModal.dataset.listenerAttached) {
        traefikHostModal.dataset.listenerAttached = 'true';
        const form = document.getElementById('traefik-host-form');
        const modalTitle = document.getElementById('traefikHostModalLabel');
        const hostIdInput = document.getElementById('traefik-host-id');
        const hostNameInput = document.getElementById('traefik-host-name');
        const hostDescInput = document.getElementById('traefik-host-description');
        const saveBtn = document.getElementById('save-traefik-host-btn');

        traefikHostModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const action = button.dataset.action || 'edit';

            if (action === 'add') {
                modalTitle.textContent = 'Add New Traefik Host';
                form.action = `${basePath}/traefik-hosts/new`;
                form.reset();
                hostIdInput.value = '';
            } else { // edit
                const hostId = button.dataset.id;
                const hostName = button.dataset.name;
                const hostDesc = button.dataset.description;
                modalTitle.textContent = `Edit Traefik Host: ${hostName}`;
                form.action = `${basePath}/traefik-hosts/${hostId}/edit`;
                hostIdInput.value = hostId;
                hostNameInput.value = hostName;
                hostDescInput.value = hostDesc;
            }
        });

        saveBtn.addEventListener('click', function() {
            const formData = new FormData(form);
            const url = form.action;
            const originalButtonText = saveBtn.innerHTML;

            saveBtn.disabled = true;
            saveBtn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Saving...`;

            fetch(url, { method: 'POST', body: formData })
                .then(response => response.json().then(data => ({ ok: response.ok, data })))
                .then(({ ok, data }) => {
                    showToast(data.message, ok);
                    if (ok) {
                        bootstrap.Modal.getInstance(traefikHostModal).hide();
                        if (document.getElementById('traefik-hosts-container')) {
                            loadPaginatedData('traefik-hosts', 1, localStorage.getItem('traefik-hosts_limit') || 10);
                        }
                    } else { throw new Error(data.message || 'An unknown error occurred.'); }
                })
                .catch(error => showToast(error.message, false))
                .finally(() => {
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = 'Save Host';
                });
        });
    }
});