<?php
// Router sudah menangani otentikasi dan otorisasi.
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Groups</h1>
    <div class="btn-toolbar mb-2 mb-md-0">
        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#groupModal" data-action="add">
            <i class="bi bi-plus-circle"></i> Add New Group
        </button>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-sm table-hover">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Description</th>
                        <th>Created At</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody id="groups-container">
                    <!-- Data will be loaded by AJAX -->
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer d-flex justify-content-between align-items-center">
        <div class="text-muted small" id="groups-info"></div>
        <div class="d-flex align-items-center">
            <nav id="groups-pagination"></nav>
            <div class="ms-3">
                <select name="limit_groups" class="form-select form-select-sm limit-selector" data-type="groups" style="width: auto;">
                    <option value="10">10</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </div>
        </div>
    </div>
</div>

<!-- Group Modal (for Add/Edit) -->
<div class="modal fade" id="groupModal" tabindex="-1" aria-labelledby="groupModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="groupModalLabel">Add New Group</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form id="group-form">
            <input type="hidden" name="id" id="group-id">
            <div class="mb-3">
                <label for="group-name" class="form-label">Group Name</label>
                <input type="text" class="form-control" id="group-name" name="name" required>
            </div>
            <div class="mb-3">
                <label for="group-description" class="form-label">Description</label>
                <textarea class="form-control" id="group-description" name="description" rows="3"></textarea>
            </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="save-group-btn">Save Group</button>
      </div>
    </div>
  </div>
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>