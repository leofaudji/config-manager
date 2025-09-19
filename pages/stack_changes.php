<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-calendar-week"></i> Stack Change History</h1>
</div>

<div class="card">
    <div class="card-header">
        <div class="row align-items-center">
            <div class="col-md-6">
                <p class="mb-0 small text-muted">This page shows a log of all stacks that were created, updated, or deleted, grouped by host and date.</p>
            </div>
            <div class="col-md-6">
                <form id="filter-form" class="d-flex justify-content-end align-items-center">
                    <div class="input-group input-group-sm" style="width: auto;">
                        <input type="date" class="form-control" id="start-date" title="Start Date">
                        <input type="date" class="form-control" id="end-date" title="End Date">
                        <button class="btn btn-outline-primary" type="submit" id="filter-btn"><i class="bi bi-funnel-fill"></i> Filter</button>
                        <button class="btn btn-outline-secondary" type="button" id="reset-filter-btn" title="Reset Filter"><i class="bi bi-x-lg"></i></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="card-body" id="stack-changes-container">
        <!-- Content will be loaded by AJAX -->
        <div class="text-center">
            <div class="spinner-border" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
        </div>
    </div>
</div>

<script>
(function() { // IIFE to ensure script runs on AJAX load
    const container = document.getElementById('stack-changes-container');
    const filterForm = document.getElementById('filter-form');
    const startDateInput = document.getElementById('start-date');
    const endDateInput = document.getElementById('end-date');
    const resetFilterBtn = document.getElementById('reset-filter-btn');

    function loadStackChanges() {
        container.innerHTML = `<div class="text-center"><div class="spinner-border" role="status"><span class="visually-hidden">Loading...</span></div></div>`;

        const startDate = startDateInput.value;
        const endDate = endDateInput.value;

        let apiUrl = '<?= base_url('/api/stack-changes') ?>';
        const params = new URLSearchParams();
        if (startDate) {
            params.append('start_date', startDate);
        }
        if (endDate) {
            params.append('end_date', endDate);
        }

        if (params.toString()) {
            apiUrl += '?' + params.toString();
        }

        fetch(apiUrl)
            .then(response => response.json())
            .then(result => {
                if (result.status === 'error') {
                    throw new Error(result.message);
                }

                container.innerHTML = ''; // Clear spinner

                if (Object.keys(result.data).length === 0) {
                    container.innerHTML = '<div class="alert alert-info">No stack changes recorded yet.</div>';
                    return;
                }

                let html = '';
                // Data is grouped by host_name -> date -> changes
                for (const hostName in result.data) {
                    html += `<h4 class="mt-4"><i class="bi bi-hdd-network-fill me-2"></i>${hostName}</h4>`;
                    
                    const dates = result.data[hostName];
                    for (const date in dates) {
                        html += `<h5 class="mt-3 text-muted">${date}</h5>`;
                        html += '<ul class="list-group">';
                        
                        const changes = dates[date];
                        changes.forEach(change => {
                            const badgeClass = { created: 'success', updated: 'warning', deleted: 'danger' }[change.change_type] || 'secondary';
                            const icon = { created: 'plus-circle', updated: 'arrow-repeat', deleted: 'trash' }[change.change_type] || 'info-circle';

                            const shortDetails = change.details.length > 80 ? change.details.substring(0, 80) + '...' : change.details;
                            const escapedDetails = change.details.replace(/"/g, '&quot;');

                            html += `
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <div>
                                        <span class="badge bg-${badgeClass} me-2"><i class="bi bi-${icon} me-1"></i> ${change.change_type}</span>
                                        <strong>${change.stack_name}</strong>
                                        <small class="d-block text-muted">${shortDetails} (by ${change.changed_by})</small>
                                    </div>
                                    <div class="d-flex align-items-center">
                                        <small class="text-muted me-3">${new Date(change.created_at).toLocaleTimeString()}</small>
                                        <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#stackChangeDetailModal" data-stack-name="${change.stack_name}" data-change-type="${change.change_type}" data-details="${escapedDetails}" data-changed-by="${change.changed_by}" data-created-at="${change.created_at}" title="View Details"><i class="bi bi-info-circle"></i></button>
                                    </div>
                                 </li>
                            `;
                        });
                        html += '</ul>';
                    }
                }
                container.innerHTML = html;
            })
            .catch(error => {
                container.innerHTML = `<div class="alert alert-danger">Failed to load stack changes: ${error.message}</div>`;
            });
    }

    filterForm.addEventListener('submit', function(e) {
        e.preventDefault();
        loadStackChanges();
    });

    resetFilterBtn.addEventListener('click', function() {
        startDateInput.value = '';
        endDateInput.value = '';
        loadStackChanges();
    });

    loadStackChanges();

    const stackChangeDetailModal = document.getElementById('stackChangeDetailModal');
    if (stackChangeDetailModal) {
        stackChangeDetailModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            const modalTitle = stackChangeDetailModal.querySelector('.modal-title');
            
            const stackName = button.dataset.stackName;
            const changeType = button.dataset.changeType;
            const details = button.dataset.details;
            const changedBy = button.dataset.changedBy;
            const createdAt = new Date(button.dataset.createdAt).toLocaleString();

            modalTitle.textContent = `Details for: ${stackName}`;
            
            document.getElementById('detail-stack-name').textContent = stackName;
            document.getElementById('detail-change-type').textContent = changeType;
            document.getElementById('detail-created-at').textContent = createdAt;
            document.getElementById('detail-changed-by').textContent = changedBy;
            document.getElementById('detail-details').textContent = details.split(' | ').join('\n');
        });
    }
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>