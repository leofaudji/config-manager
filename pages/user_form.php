<?php
// Router sudah menangani otentikasi dan otorisasi.
require_once __DIR__ . '/../includes/bootstrap.php';

$is_edit = false;
$conn = Database::getInstance()->getConnection();
$user = [
    'id' => '',
    'username' => '',
    'role' => 'viewer'
];

// Cek apakah ini mode edit dengan mengambil ID dari URL
if (isset($_GET['id'])) {
    $is_edit = true;
    $id = $_GET['id'];
    $stmt = $conn->prepare("SELECT id, username, role FROM users WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        header('Location: ' . base_url('/users?status=error&message=User not found.'));
        exit;
    }
    $user = $result->fetch_assoc();
    $stmt->close();
}

require_once __DIR__ . '/../includes/header.php';
?>

<h3><?= $is_edit ? 'Edit User' : 'Add New User' ?></h3>
<hr>

<div class="card">
    <div class="card-body">
        <form id="main-form" action="<?= base_url($is_edit ? '/users/' . htmlspecialchars($user['id']) . '/edit' : '/users/new') ?>" method="POST" data-redirect="/users">
            <input type="hidden" name="id" value="<?= htmlspecialchars($user['id']) ?>">
            <div class="mb-3">
                <label for="username" class="form-label">Username</label>
                <input type="text" class="form-control" id="username" name="username" value="<?= htmlspecialchars($user['username']) ?>" required>
            </div>
            <?php if (!$is_edit): ?>
            <div class="mb-3">
                <label for="password" class="form-label">Password</label>
                <input type="password" class="form-control" id="password" name="password" required>
            </div>
            <?php endif; ?>
            <div class="mb-3">
                <label for="role" class="form-label">Role</label>
                <select class="form-select" id="role" name="role" required>
                    <option value="viewer" <?= $user['role'] == 'viewer' ? 'selected' : '' ?>>Viewer</option>
                    <option value="admin" <?= $user['role'] == 'admin' ? 'selected' : '' ?>>Admin</option>
                </select>
            </div>
            <a href="<?= base_url('/users') ?>" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary"><?= $is_edit ? 'Update User' : 'Save User' ?></button>
        </form>
    </div>
</div>

<?php
if ($conn) $conn->close();
require_once __DIR__ . '/../includes/footer.php';
?>