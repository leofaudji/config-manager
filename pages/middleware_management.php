<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$conn = Database::getInstance()->getConnection();
$groups_result = $conn->query("SELECT id, name FROM `groups` ORDER BY name ASC");

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Middlewares</h1>
    <div class="btn-toolbar mb-2 mb-md-0">
        <form class="search-form me-2" data-type="middlewares">
            <div class="input-group input-group-sm">
                <input type="text" name="search_middlewares" class="form-control" placeholder="Cari berdasarkan nama...">
                <button class="btn btn-outline-secondary" type="submit" title="Search"><i class="bi bi-search"></i></button>
                <button class="btn btn-outline-secondary reset-search-btn" type="button" title="Reset"><i class="bi bi-x-lg"></i></button>
            </div>
        </form>
        <select id="middleware-group-filter" class="form-select form-select-sm me-2" style="width: auto;" title="Filter by group">
            <option value="">All Groups</option>
            <?php while($group = $groups_result->fetch_assoc()): ?>
                <option value="<?= $group['id'] ?>"><?= htmlspecialchars($group['name']) ?></option>
            <?php endwhile; ?>
        </select>
        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#middlewareModal" data-action="add">
            <i class="bi bi-plus-circle"></i> Add New Middleware
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
                        <th>Type</th>
                        <th>Description</th>
                        <th>Last Updated</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody id="middlewares-container">
                    <!-- Data will be loaded by AJAX -->
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer d-flex justify-content-between align-items-center">
        <div class="text-muted small" id="middlewares-info"></div>
        <div class="d-flex align-items-center">
            <nav id="middlewares-pagination"></nav>
            <div class="ms-3">
                <select name="limit_middlewares" class="form-select form-select-sm limit-selector" data-type="middlewares" style="width: auto;">
                    <option value="10">10</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </div>
        </div>
    </div>
</div>

<!-- Middleware Modal (for Add/Edit) -->
<div class="modal fade" id="middlewareModal" tabindex="-1" aria-labelledby="middlewareModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="middlewareModalLabel">Add New Middleware</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form id="middleware-form">
            <input type="hidden" name="id" id="middleware-id">
            <div class="mb-3">
                <label for="middleware-name" class="form-label">Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="middleware-name" name="name" required>
                <small class="form-text text-muted">Nama unik untuk middleware, contoh: `my-auth`, `secure-headers`.</small>
            </div>
            <div class="mb-3">
                <label for="middleware-type" class="form-label">Type <span class="text-danger">*</span></label>
                <select class="form-select" id="middleware-type" name="type" required>
                    <option value="" disabled selected>-- Pilih Tipe --</option>
                    <option value="addPrefix">Add Prefix</option>
                    <option value="basicAuth">Basic Authentication</option>
                    <option value="chain">Chain</option>
                    <option value="circuitBreaker">Circuit Breaker</option>
                    <option value="compress">Compress</option>
                    <option value="digestAuth">Digest Authentication</option>
                    <option value="errorPage">Error Page</option>
                    <option value="forwardAuth">Forward Authentication</option>
                    <option value="headers">Headers</option>
                    <option value="ipWhiteList">IP WhiteList</option>
                    <option value="inFlightReq">In-Flight Requests</option>
                    <option value="rateLimit">Rate Limit</option>
                    <option value="redirectRegex">Redirect Regex</option>
                    <option value="redirectScheme">Redirect Scheme</option>
                    <option value="replacePath">Replace Path</option>
                    <option value="replacePathRegex">Replace Path Regex</option>
                    <option value="retry">Retry</option>
                    <option value="stripPrefix">Strip Prefix</option>
                    <option value="stripPrefixRegex">Strip Prefix Regex</option>
                </select>
                <small class="form-text text-muted">Pilih tipe middleware sesuai dokumentasi Traefik.</small>
            </div>
            <div class="mb-3">
                <label for="middleware-description" class="form-label">Description</label>
                <textarea class="form-control" id="middleware-description" name="description" rows="2"></textarea>
            </div>
            <div class="mb-3">
                <label for="middleware-config" class="form-label">Configuration (JSON) <span class="text-danger">*</span></label>
                <textarea class="form-control" id="middleware-config" name="config_json" rows="5" placeholder='{"customRequestHeaders": {"X-Powered-By": "Traefik-Manager"}}' required></textarea>
                <small class="form-text text-muted d-block">Konfigurasi dalam format JSON. Pastikan valid.</small>
                <small class="form-text text-info">Tips: Pilih 'Type' terlebih dahulu untuk mendapatkan template konfigurasi.</small>
            </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="save-middleware-btn">Save Middleware</button>
      </div>
    </div>
  </div>
</div>

<?php
$conn->close();
require_once __DIR__ . '/../includes/footer.php';
?>