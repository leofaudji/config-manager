<?php
require_once __DIR__ . '/../includes/bootstrap.php';

// Fetch users for the assignee dropdown
$conn_users = Database::getInstance()->getConnection();
$users_result = $conn_users->query("SELECT id, username FROM users ORDER BY username ASC");
$users = $users_result->fetch_all(MYSQLI_ASSOC);

$incident_id = $_GET['id'] ?? null;
if (!$incident_id) {
    header("Location: " . base_url('/incident-reports?status=error&message=Incident ID not specified.'));
    exit;
}

$conn = Database::getInstance()->getConnection();
$stmt = $conn->prepare("SELECT i.*, h.name as host_name FROM incident_reports i LEFT JOIN docker_hosts h ON i.host_id = h.id WHERE i.id = ?");
$stmt->bind_param("i", $incident_id);
$stmt->execute();
$result = $stmt->get_result();
if (!($incident = $result->fetch_assoc())) {
    header("Location: " . base_url('/incident-reports?status=error&message=Incident not found.'));
    exit;
}
$stmt->close();

function format_duration_human_detail($seconds) {
    if ($seconds === null) return 'Ongoing';
    if ($seconds < 1) return '0s';
    $parts = [];
    $periods = ['d' => 86400, 'h' => 3600, 'm' => 60, 's' => 1];
    foreach ($periods as $name => $value) {
        if ($seconds >= $value) {
            $num = floor($seconds / $value);
            $parts[] = "{$num}{$name}";
            $seconds %= $value;
        }
    }
    return implode(' ', $parts);
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-shield-fill-exclamation"></i> Incident #<?= htmlspecialchars($incident['id']) ?></h1>
    <div class="btn-toolbar mb-2 mb-md-0">
        <button id="export-incident-pdf-btn" class="btn btn-sm btn-outline-danger me-2">
            <i class="bi bi-file-earmark-pdf"></i> Export to PDF
        </button>
        <a href="<?= base_url('/incident-reports') ?>" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back to All Incidents
        </a>
    </div>
</div>

<form id="main-form" action="<?= base_url('/api/incidents/' . $incident['id']) ?>" method="POST" data-redirect="<?= base_url('/incident-reports') ?>">
    <div class="row">
        <div class="col-lg-8">
            <!-- Investigation & Resolution Notes -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Investigation & Resolution</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="investigation_notes" class="form-label"><strong>Investigation Notes</strong></label>
                        <textarea class="form-control" id="investigation_notes" name="investigation_notes" rows="6"><?= htmlspecialchars($incident['investigation_notes'] ?? '') ?></textarea>
                        <small class="form-text text-muted">Document the steps taken to investigate the issue.</small>
                    </div>
                    <div class="mb-3">
                        <label for="resolution_notes" class="form-label"><strong>Resolution Notes</strong></label>
                        <textarea class="form-control" id="resolution_notes" name="resolution_notes" rows="4"><?= htmlspecialchars($incident['resolution_notes'] ?? '') ?></textarea>
                        <small class="form-text text-muted">Describe how the incident was resolved.</small>
                    </div>
                </div>
            </div>

            <!-- Post-Mortem / RCA Section -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Post-Mortem / Root Cause Analysis</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="executive_summary" class="form-label"><strong>Executive Summary</strong></label>
                        <textarea class="form-control" id="executive_summary" name="executive_summary" rows="3" placeholder="A brief, high-level summary of the incident, its impact, and the resolution."><?= htmlspecialchars($incident['executive_summary'] ?? '') ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="root_cause" class="form-label"><strong>Root Cause</strong></label>
                        <textarea class="form-control" id="root_cause" name="root_cause" rows="4" placeholder="Detailed analysis of the underlying cause(s) of the incident."><?= htmlspecialchars($incident['root_cause'] ?? '') ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="lessons_learned" class="form-label"><strong>Lessons Learned</strong></label>
                        <textarea class="form-control" id="lessons_learned" name="lessons_learned" rows="3" placeholder="What can the team learn from this incident?"><?= htmlspecialchars($incident['lessons_learned'] ?? '') ?></textarea>
                    </div>
                    <label class="form-label"><strong>Action Items</strong></label>
                    <div id="action-items-container"></div>
                    <button type="button" class="btn btn-sm btn-outline-secondary mt-2" id="add-action-item-btn"><i class="bi bi-plus-circle"></i> Add Action Item</button>
                </div>
            </div>

            <!-- Monitoring Snapshot -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Monitoring Snapshot</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted small">This is the last log message received from the health agent right before the incident was created. It can provide initial clues about the cause.</p>
                    <pre class="bg-dark text-light p-3 rounded"><code><?= htmlspecialchars(json_encode(json_decode($incident['monitoring_snapshot'] ?? '[]'), JSON_PRETTY_PRINT)) ?></code></pre>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <!-- Incident Details -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Details</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="status" class="form-label">Status</label>
                        <select class="form-select" id="status" name="status">
                            <option value="Open" <?= $incident['status'] === 'Open' ? 'selected' : '' ?>>Open</option>
                            <option value="Investigating" <?= $incident['status'] === 'Investigating' ? 'selected' : '' ?>>Investigating</option>
                            <option value="On Hold" <?= $incident['status'] === 'On Hold' ? 'selected' : '' ?>>On Hold</option>
                            <option value="Resolved" <?= $incident['status'] === 'Resolved' ? 'selected' : '' ?>>Resolved</option>
                            <option value="Closed" <?= $incident['status'] === 'Closed' ? 'selected' : '' ?>>Closed</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="severity" class="form-label">Severity</label>
                        <select class="form-select" id="severity" name="severity">
                            <option value="Low" <?= $incident['severity'] === 'Low' ? 'selected' : '' ?>>Low</option>
                            <option value="Medium" <?= $incident['severity'] === 'Medium' ? 'selected' : '' ?>>Medium</option>
                            <option value="High" <?= $incident['severity'] === 'High' ? 'selected' : '' ?>>High</option>
                            <option value="Critical" <?= $incident['severity'] === 'Critical' ? 'selected' : '' ?>>Critical</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="assignee_user_id" class="form-label">Assignee</label>
                        <select class="form-select" id="assignee_user_id" name="assignee_user_id">
                            <option value="">-- Unassigned --</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?= $user['id'] ?>" <?= ($incident['assignee_user_id'] ?? '') == $user['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($user['username']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <dl class="row">
                        <dt class="col-sm-4">Target</dt>
                        <dd class="col-sm-8"><?= htmlspecialchars($incident['target_name']) ?></dd>

                        <dt class="col-sm-4">Type</dt>
                        <dd class="col-sm-8"><span class="badge bg-dark"><?= htmlspecialchars($incident['incident_type']) ?></span></dd>

                        <dt class="col-sm-4">Host</dt>
                        <dd class="col-sm-8"><?= htmlspecialchars($incident['host_name'] ?? 'N/A') ?></dd>

                        <dt class="col-sm-4">Start Time</dt>
                        <dd class="col-sm-8"><?= htmlspecialchars($incident['start_time']) ?></dd>

                        <dt class="col-sm-4">End Time</dt>
                        <dd class="col-sm-8"><?= htmlspecialchars($incident['end_time'] ?? 'Ongoing') ?></dd>

                        <dt class="col-sm-4">Duration</dt>
                        <dd class="col-sm-8"><?= format_duration_human_detail($incident['duration_seconds']) ?></dd>
                    </dl>
                </div>
                <div class="card-footer">
                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-save-fill"></i> Save Changes</button>
                </div>
            </div>
        </div>
    </div>
</form>

<!-- Template for Action Item -->
<template id="action-item-template">
    <div class="input-group input-group-sm mb-2 action-item-row">
        <select class="form-select" name="action_items[INDEX][status]" style="flex: 0 0 120px;">
            <option value="todo">To Do</option><option value="inprogress">In Progress</option><option value="done">Done</option>
        </select>
        <input type="text" class="form-control" name="action_items[INDEX][task]" placeholder="Task description...">
        <button class="btn btn-outline-danger remove-item-btn" type="button"><i class="bi bi-trash"></i></button>
    </div>
</template>

<script>
window.pageInit = function() {
    const exportBtn = document.getElementById('export-incident-pdf-btn');
    if (exportBtn) {
        exportBtn.addEventListener('click', function(e) {
            e.preventDefault();
            const btn = this;
            const originalContent = btn.innerHTML;
            btn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Generating...`;
            btn.disabled = true;

            const formData = new FormData();
            formData.append('report_type', 'single_incident_report');
            formData.append('incident_id', '<?= $incident_id ?>');

            fetch('<?= base_url('/api/pdf') ?>', { method: 'POST', body: formData })
                .then(res => {
                    if (!res.ok) return res.text().then(text => { throw new Error(text || 'Failed to generate PDF.') });
                    return res.blob();
                })
                .then(blob => {
                    const url = window.URL.createObjectURL(blob);
                    window.open(url, '_blank');
                })
                .catch(err => showToast('Error exporting PDF: ' . err.message, false))
                .finally(() => { btn.innerHTML = originalContent; btn.disabled = false; });
        });
    }

    // --- Action Items Logic ---
    const actionItemsContainer = document.getElementById('action-items-container');
    const addActionItemBtn = document.getElementById('add-action-item-btn');
    const actionItemTemplate = document.getElementById('action-item-template');
    let actionItemIndex = 0;

    function addActionItem(task = '', status = 'todo') {
        const templateContent = actionItemTemplate.innerHTML.replace(/INDEX/g, actionItemIndex++);
        actionItemsContainer.insertAdjacentHTML('beforeend', templateContent);
        const newRow = actionItemsContainer.lastElementChild;
        if (task) {
            newRow.querySelector('input[type="text"]').value = task;
        }
        if (status) {
            newRow.querySelector('select').value = status;
        }
    }

    addActionItemBtn.addEventListener('click', () => addActionItem());

    actionItemsContainer.addEventListener('click', function(e) {
        if (e.target.closest('.remove-item-btn')) {
            e.target.closest('.action-item-row').remove();
        }
    });

    // Populate existing action items
    try {
        const existingActionItems = JSON.parse(<?= json_encode($incident['action_items'] ?? '[]') ?>);
        if (Array.isArray(existingActionItems)) {
            existingActionItems.forEach(item => {
                if (item.task) { // Ensure item is valid
                    addActionItem(item.task, item.status);
                }
            });
        }
    } catch (e) {
        console.error("Could not parse existing action items:", e);
    }

};
</script>
<?php
$conn->close();
require_once __DIR__ . '/../includes/footer.php';
?>