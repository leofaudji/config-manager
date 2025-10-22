<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-arrow-clockwise text-info"></i> Pending Updates</h1>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Stacks Awaiting Deployment</h5>
        <div>
            <button id="deploy-all-btn" class="btn btn-sm btn-success" title="Deploy all pending updates shown on this page."><i class="bi bi-cloud-upload-fill"></i> Deploy All</button>
            <button id="refresh-btn" class="btn btn-sm btn-outline-primary" title="Refresh List"><i class="bi bi-arrow-clockwise"></i></button>
        </div>
    </div>
    <div class="card-body">
        <p class="text-muted">This page lists all application stacks that have received an update from their Git repository but are configured for scheduled or manual deployment. You can trigger the deployment manually here.</p>
        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th>Stack Name</th>
                        <th>Host</th>
                        <th>Update Received</th>
                        <th>Scheduled Time</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody id="pending-updates-container">
                    <!-- Data will be loaded here by AJAX -->
                </tbody>
            </table>
        </div>
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

<script>
window.pageInit = function() {
    const container = document.getElementById('pending-updates-container');
    const refreshBtn = document.getElementById('refresh-btn');
    const deployAllBtn = document.getElementById('deploy-all-btn');

    function loadPendingUpdates() {
        container.innerHTML = '<tr><td colspan="5" class="text-center"><div class="spinner-border spinner-border-sm"></div> Loading...</td></tr>';
        deployAllBtn.disabled = true;

        fetch('<?= base_url('/api/stacks/pending-updates') ?>')
            .then(response => response.json())
            .then(result => {
                if (result.status !== 'success') throw new Error(result.message);

                let html = '';
                if (result.stacks && result.stacks.length > 0) {
                    const now = new Date();
                    result.stacks.forEach(stack => {
                        let scheduleHtml = '<span class="text-muted">Manual Trigger</span>';
                        if (stack.webhook_update_policy === 'scheduled' && stack.webhook_schedule_time) {
                            const [hour, minute] = stack.webhook_schedule_time.split(':');
                            const scheduleTimeToday = new Date(now.getFullYear(), now.getMonth(), now.getDate(), hour, minute);
                            const isDue = now >= scheduleTimeToday;
                            scheduleHtml = `<span class="badge ${isDue ? 'bg-success' : 'bg-secondary'}" title="${isDue ? 'Ready to be deployed by cron' : 'Waiting for scheduled time'}"><i class="bi bi-clock-history me-1"></i> ${stack.webhook_schedule_time} WIB</span>`;
                        };
                        const receivedTime = stack.webhook_pending_since ? new Date(stack.webhook_pending_since).toLocaleString('id-ID', { timeZone: 'Asia/Jakarta' }) : 'N/A';
                        const receivedTimeAgo = stack.webhook_pending_since ? timeAgo(new Date(stack.webhook_pending_since)) : '';

                        html += `
                            <tr id="stack-row-${stack.id}">
                                <td><a href="<?= base_url('/hosts/') ?>${stack.host_id}/stacks">${stack.stack_name}</a></td>
                                <td>${stack.host_name}</td>
                                <td title="${receivedTime}">${receivedTimeAgo}</td>
                                <td>${scheduleHtml}</td>
                                <td class="text-end">
                                    <button class="btn btn-sm btn-success deploy-now-btn" data-stack-id="${stack.id}" title="Deploy Pending Update">
                                        <i class="bi bi-cloud-arrow-up-fill"></i> Deploy Now
                                    </button>
                                </td>
                            </tr>
                        `;
                    });
                    deployAllBtn.disabled = false;
                } else {
                    html = '<tr><td colspan="5" class="text-center text-muted">No pending updates found.</td></tr>';
                }
                container.innerHTML = html;
            })
            .catch(error => {
                container.innerHTML = `<tr><td colspan="5" class="text-center text-danger">Error: ${error.message}</td></tr>`;
            });
    }

    async function deployStack(button) {
        const stackId = button.dataset.stackId;
        const originalBtnContent = button.innerHTML;
        button.disabled = true;
        button.innerHTML = `<span class="spinner-border spinner-border-sm" role="status"></span> Deploying...`;

        // --- IDE: Logic for Log Modal ---
        const logModalEl = document.getElementById('deploymentLogModal');
        const logModal = bootstrap.Modal.getOrCreateInstance(logModalEl);
        const logContent = document.getElementById('deployment-log-content');
        const logCloseBtn = document.getElementById('deployment-log-close-btn');
        const logModalLabel = document.getElementById('deploymentLogModalLabel');

        logContent.textContent = '';
        logCloseBtn.disabled = true;
        logModalLabel.textContent = `Deployment for Stack ID #${stackId}...`;
        logModal.show();
        // --- End IDE ---

        const formData = new FormData();
        formData.append('stack_id', stackId);

        try {
            const response = await fetch('<?= base_url('/api/stacks/deploy-pending') ?>', {
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
                showToast('Deployment completed successfully!', true);
                const row = document.getElementById(`stack-row-${stackId}`);
                if (row) row.remove();
            } else {
                showToast('Deployment failed. Check logs for details.', false);
            }
        } catch (error) {
            logContent.textContent += `\n\n--- SCRIPT ERROR ---\n${error.message}`;
            showToast('A critical error occurred during deployment.', false);
        } finally {
            logCloseBtn.disabled = false;
            logModalLabel.textContent = 'Deployment Finished';
            button.disabled = false;
            button.innerHTML = originalBtnContent;
        }
    }

    container.addEventListener('click', function(e) {
        const deployBtn = e.target.closest('.deploy-now-btn');
        if (deployBtn) {
            deployStack(deployBtn);
        }
    });

    deployAllBtn.addEventListener('click', function() {
        if (!confirm('Are you sure you want to deploy all pending updates?')) return;
        
        const allDeployButtons = container.querySelectorAll('.deploy-now-btn');
        allDeployButtons.forEach(btn => deployStack(btn));
    });

    refreshBtn.addEventListener('click', loadPendingUpdates);

    function timeAgo(date) {
        const seconds = Math.floor((new Date() - date) / 1000);
        let interval = seconds / 31536000;
        if (interval > 1) return Math.floor(interval) + " years ago";
        interval = seconds / 2592000;
        if (interval > 1) return Math.floor(interval) + " months ago";
        interval = seconds / 86400;
        if (interval > 1) return Math.floor(interval) + " days ago";
        interval = seconds / 3600;
        if (interval > 1) return Math.floor(interval) + " hours ago";
        interval = seconds / 60;
        if (interval > 1) return Math.floor(interval) + " minutes ago";
        return Math.floor(seconds) + " seconds ago";
    }

    // Initial load
    loadPendingUpdates();
};
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
