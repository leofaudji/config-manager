<?php
// File ini adalah view untuk manajemen service.
// Sesi dan otentikasi sudah diperiksa oleh Router.
require_once __DIR__ . '/../includes/bootstrap.php';
$conn = Database::getInstance()->getConnection();
$groups_result = $conn->query("SELECT id, name FROM `groups` ORDER BY name ASC");

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Services</h1>
    <div class="btn-toolbar mb-2 mb-md-0">
        <form class="search-form me-2" data-type="services">
            <div class="input-group input-group-sm">
                <input type="text" name="search_services" class="form-control" placeholder="Cari service...">
                <button class="btn btn-outline-secondary" type="submit" title="Search"><i class="bi bi-search"></i></button>
                <button class="btn btn-outline-secondary reset-search-btn" type="button" title="Reset"><i class="bi bi-x-lg"></i></button>
            </div>
        </form>
        <select id="service-group-filter" class="form-select form-select-sm me-2" style="width: auto;" title="Filter by group">
            <option value="">All Groups</option>
            <?php while($group = $groups_result->fetch_assoc()): ?>
                <option value="<?= $group['id'] ?>"><?= htmlspecialchars($group['name']) ?></option>
            <?php endwhile; ?>
        </select>
        <?php if ($_SESSION['role'] === 'admin'): ?>
        <a href="<?= base_url('/services/new') ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-plus-circle"></i> Add New Service</a>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-body" id="services-container">
        <!-- Data Services akan dimuat di sini oleh AJAX -->
    </div>
    <div class="card-footer d-flex justify-content-between align-items-center">
        <div class="text-muted small" id="services-info">
            <!-- Info paginasi akan dimuat di sini -->
        </div>
        <div class="d-flex align-items-center">
            <nav id="services-pagination">
                <!-- Kontrol paginasi akan dimuat di sini -->
            </nav>
            <div class="ms-3">
                <select name="limit_services" class="form-select form-select-sm limit-selector" data-type="services" style="width: auto;">
                    <option value="10">10</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                    <option value="-1">All</option>
                </select>
            </div>
        </div>
    </div>
</div>

<?php
$conn->close();
require_once __DIR__ . '/../includes/footer.php';
?>