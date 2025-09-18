<?php
// Router sudah menangani start sesi dan otentikasi/otorisasi.
require_once 'includes/bootstrap.php';

$conn = Database::getInstance()->getConnection();

require_once 'includes/header.php';
?>

<!-- Pesan Sukses/Error (jika ada dari redirect) -->
<?php if (isset($_GET['status'])): ?>
<div class="alert alert-<?= $_GET['status'] == 'success' ? 'success' : 'danger' ?> alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($_GET['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Users</h1>
    <div class="btn-toolbar mb-2 mb-md-0">
        <form class="search-form me-2" data-type="users">
            <div class="input-group input-group-sm">
                <input type="text" name="search_users" class="form-control" placeholder="Cari berdasarkan user...">
                <button class="btn btn-outline-secondary" type="submit" title="Search"><i class="bi bi-search"></i></button>
                <button class="btn btn-outline-secondary reset-search-btn" type="button" title="Reset"><i class="bi bi-x-lg"></i></button>
            </div>
        </form>
        <a href="<?= base_url('/users/new') ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-plus-circle"></i> Add New User</a>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-sm table-hover">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Username</th>
                        <th>Role</th>
                        <th>Created At</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody id="users-container">
                    <!-- Data will be loaded by AJAX -->
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer d-flex justify-content-between align-items-center">
        <div class="text-muted small" id="users-info"></div>
        <div class="d-flex align-items-center">
            <nav id="users-pagination"></nav>
            <div class="ms-3">
                <select name="limit_users" class="form-select form-select-sm limit-selector" data-type="users" style="width: auto;">
                    <option value="10">10</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </div>
        </div>
    </div>
</div>

<?php
$conn->close();
require_once 'includes/footer.php';
?>