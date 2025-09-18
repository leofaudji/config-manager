<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$conn = Database::getInstance()->getConnection();
// Ambil daftar middleware untuk dropdown di modal
$middlewares_result = $conn->query("SELECT id, name FROM middlewares ORDER BY name ASC");

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Configuration Templates</h1>
    <div class="btn-toolbar mb-2 mb-md-0">
        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#templateModal" data-action="add">
            <i class="bi bi-plus-circle"></i> Add New Template
        </button>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-sm table-hover">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Description</th>
                        <th>Created At</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody id="templates-container">
                    <!-- Data will be loaded by AJAX -->
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer d-flex justify-content-between align-items-center">
        <div class="text-muted small" id="templates-info"></div>
        <div class="d-flex align-items-center">
            <nav id="templates-pagination"></nav>
            <div class="ms-3">
                <select name="limit_templates" class="form-select form-select-sm limit-selector" data-type="templates" style="width: auto;">
                    <option value="10">10</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </div>
        </div>
    </div>
</div>

<!-- Template Modal (for Add/Edit) -->
<div class="modal fade" id="templateModal" tabindex="-1" aria-labelledby="templateModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="templateModalLabel">Add New Template</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form id="template-form">
            <input type="hidden" name="id" id="template-id">
            <div class="mb-3">
                <label for="template-name" class="form-label">Template Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="template-name" name="name" required>
            </div>
            <div class="mb-3">
                <label for="template-description" class="form-label">Description</label>
                <textarea class="form-control" id="template-description" name="description" rows="2"></textarea>
            </div>
            <hr>
            <h5>Default Router Configuration</h5>
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="template-entry_points" class="form-label">Entry Points</label>
                        <input type="text" class="form-control" id="template-entry_points" name="entry_points" value="web" placeholder="e.g., web,websecure">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="template-cert_resolver" class="form-label">Certificate Resolver</label>
                        <input type="text" class="form-control" id="template-cert_resolver" name="cert_resolver" placeholder="e.g., cloudflare">
                    </div>
                </div>
            </div>
            <div class="mb-3 form-check">
                <input type="hidden" name="tls" value="0">
                <input type="checkbox" class="form-check-input" id="template-tls" name="tls" value="1">
                <label class="form-check-label" for="template-tls">Enable TLS by default</label>
            </div>
            <hr>
            <h5>Default Middlewares</h5>
             <div class="mb-3">
                <label for="template-available-middlewares" class="form-label">Available Middlewares</label>
                <div class="input-group">
                    <select class="form-select" id="template-available-middlewares">
                        <option value="">-- Select a middleware to add --</option>
                        <?php mysqli_data_seek($middlewares_result, 0); ?>
                        <?php while($mw = $middlewares_result->fetch_assoc()): ?>
                            <option value="<?= $mw['id'] ?>" data-name="<?= htmlspecialchars($mw['name']) ?>"><?= htmlspecialchars($mw['name']) ?></option>
                        <?php endwhile; ?>
                    </select>
                    <button class="btn btn-outline-secondary" type="button" id="template-add-middleware-btn">Add</button>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label">Attached Middlewares</label>
                <div id="template-attached-middlewares-container" class="p-2 border rounded" style="min-height: 100px;">
                    <!-- Items will be added here by JS -->
                </div>
            </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="save-template-btn">Save Template</button>
      </div>
    </div>
  </div>
</div>

<?php
$conn->close();
require_once __DIR__ . '/../includes/footer.php';
?>