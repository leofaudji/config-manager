<?php
require_once __DIR__ . '/../includes/bootstrap.php';
// Sesi dan otentikasi/otorisasi sudah ditangani oleh Router.

$is_edit = false;
$conn = Database::getInstance()->getConnection();
$server = [
    'id' => '',
    'url' => '',
    'service_id' => ''
];

if (!isset($_GET['service_id'])) {
    header("Location: " . base_url('/?status=error&message=' . urlencode("Service ID tidak ditemukan.")));
    exit();
}

$server['service_id'] = $_GET['service_id'];

if (isset($_GET['id'])) {
    $is_edit = true;
    $id = $_GET['id'];
    $stmt = $conn->prepare("SELECT * FROM servers WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $server = $result->fetch_assoc();
    $stmt->close();
}

require_once __DIR__ . '/../includes/header.php';
?>

<h3><?= $is_edit ? 'Edit' : 'Tambah' ?> Server</h3>
<hr>

<div class="card">
    <div class="card-body">
        <form id="main-form" action="<?= base_url($is_edit ? '/servers/' . $server['id'] . '/edit' : '/servers/new') ?>" method="POST" data-redirect="/">
            <input type="hidden" name="id" value="<?= htmlspecialchars($server['id']) ?>">
            <input type="hidden" name="service_id" value="<?= htmlspecialchars($server['service_id']) ?>">
            
            <div class="mb-3">
                <label for="url" class="form-label">Server URL</label>
                <input type="url" class="form-control" id="url" name="url" placeholder="http://10.0.0.1:8080" value="<?= htmlspecialchars($server['url']) ?>" required>
            </div>
            
            <a href="<?= base_url('/') ?>" class="btn btn-secondary">Batal</a>
            <button type="submit" class="btn btn-primary"><?= $is_edit ? 'Update' : 'Simpan' ?></button>
        </form>
    </div>
</div>

<?php
$conn->close();
require_once __DIR__ . '/../includes/footer.php';
?>