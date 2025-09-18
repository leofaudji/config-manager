<?php
// File ini adalah view untuk manajemen router.
// Sesi dan otentikasi sudah diperiksa oleh Router.
require_once __DIR__ . '/../includes/bootstrap.php';
$conn = Database::getInstance()->getConnection();
// Ambil daftar grup untuk filter dan modal
$groups_result = $conn->query("SELECT id, name FROM `groups` ORDER BY name ASC");
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-sign-turn-right"></i> Routers</h1>
    <div class="btn-toolbar mb-2 mb-md-0">
        <?php if ($_SESSION['role'] === 'admin'): ?>
        <button type="button" class="btn btn-sm btn-outline-secondary me-2" id="preview-config-btn"><i class="bi bi-eye"></i> Preview Config</button>
        <a href="<?= base_url('/routers/new') ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-plus-circle"></i> Add New Router</a>
        <?php endif; ?>
    </div>
</div>

<div class="card d-flex flex-column flex-grow-1">
    <div class="card-header d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center">
            <div id="router-bulk-actions-dropdown" class="dropdown me-2" style="display: none;">
                <button class="btn btn-secondary btn-sm dropdown-toggle" type="button" id="bulk-actions-btn" data-bs-toggle="dropdown" aria-expanded="false">
                    Bulk Actions
                </button>
                <ul class="dropdown-menu" aria-labelledby="bulk-actions-btn">
                    <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#moveGroupModal">Move to Group...</a></li>
                    <li><a class="dropdown-item text-danger" href="#" data-bs-toggle="modal" data-bs-target="#bulkDeleteModal">Delete Selected...</a></li>
                </ul>
            </div>
        </div>
        <div class="d-flex align-items-center ms-auto">
            <form class="search-form me-2" data-type="routers">
                <div class="input-group input-group-sm">
                    <input type="text" name="search_routers" class="form-control" placeholder="Cari router...">
                    <button class="btn btn-outline-secondary" type="submit" title="Search"><i class="bi bi-search"></i></button>
                    <button class="btn btn-outline-secondary reset-search-btn" type="button" title="Reset"><i class="bi bi-x-lg"></i></button>
                </div>
            </form>
            <select id="router-group-filter" class="form-select form-select-sm me-2" style="width: auto;" title="Filter by group">
                <option value="">All Groups</option>
                <?php mysqli_data_seek($groups_result, 0); ?>
                <?php while($group = $groups_result->fetch_assoc()): ?>
                    <option value="<?= $group['id'] ?>"><?= htmlspecialchars($group['name']) ?></option>
                <?php endwhile; ?>
            </select>
            <a href="<?= base_url('/services') ?>" class="btn btn-info btn-sm me-2"><i class="bi bi-hdd-stack-fill"></i> Manage Services</a>
        </div>
    </div>
    <div class="card-body card-body-scrollable">
        <div class="table-responsive">
            <table class="table table-hover table-dashboard">
                <thead>
                    <tr>
                        <th><input class="form-check-input" type="checkbox" id="select-all-routers" title="Select all routers"></th>
                        <th>Name</th>
                        <th>Rule</th>
                        <th>Entry Points</th>
                        <th>Middlewares</th>
                        <th>Service</th>
                        <th>Group</th>
                        <th style="min-width: 150px;">Last Updated</th>
                        <?php if ($_SESSION['role'] === 'admin'): ?>
                        <th class="table-actions">Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody id="routers-container">
                    <!-- Data Routers akan dimuat di sini oleh AJAX -->
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer d-flex justify-content-between align-items-center">
        <div class="text-muted small" id="routers-info">
            <!-- Info paginasi akan dimuat di sini -->
        </div>
        <div class="d-flex align-items-center">
            <nav id="routers-pagination">
                <!-- Kontrol paginasi akan dimuat di sini -->
            </nav>
            <div class="ms-3">
                <select name="limit_routers" class="form-select form-select-sm limit-selector" data-type="routers" style="width: auto;">
                    <option value="10">10</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                    <option value="-1">All</option>
                </select>
            </div>
        </div>
    </div>
</div>

<!-- Move to Group Modal -->
<div class="modal fade" id="moveGroupModal" tabindex="-1" aria-labelledby="moveGroupModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="moveGroupModalLabel">Move Routers to Group</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p>Move <strong id="selected-router-count">0</strong> selected router(s) to:</p>
        <div class="mb-3">
            <label for="target_group_id" class="form-label">Target Group</label>
            <select class="form-select" id="target_group_id" name="target_group_id">
                <?php mysqli_data_seek($groups_result, 0); ?>
                <?php while($group = $groups_result->fetch_assoc()): ?>
                    <option value="<?= $group['id'] ?>"><?= htmlspecialchars($group['name']) ?></option>
                <?php endwhile; ?>
            </select>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="confirm-move-group-btn">Confirm Move</button>
      </div>
    </div>
  </div>
</div>

<!-- Bulk Delete Modal -->
<div class="modal fade" id="bulkDeleteModal" tabindex="-1" aria-labelledby="bulkDeleteModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="bulkDeleteModalLabel">Confirm Bulk Deletion</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p>Are you sure you want to permanently delete <strong id="bulk-delete-router-count">0</strong> selected router(s)?</p>
        <p class="text-danger">This action cannot be undone.</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-danger" id="confirm-bulk-delete-btn">Confirm Delete</button>
      </div>
    </div>
  </div>
</div>

<?php
$conn->close();
require_once __DIR__ . '/../includes/footer.php';
?>