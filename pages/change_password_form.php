<?php
require_once __DIR__ . '/../includes/bootstrap.php';
// Sesi dan otentikasi/otorisasi sudah ditangani oleh Router.

$conn = Database::getInstance()->getConnection();

if (!isset($_GET['id'])) {
    header('Location: ' . base_url('/users'));
    exit;
}

$user_id = $_GET['id'];
$stmt = $conn->prepare("SELECT username FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    header('Location: ' . base_url('/users'));
    exit;
}
$user = $result->fetch_assoc();
$stmt->close();

require_once __DIR__ . '/../includes/header.php';
?>

<h3>Change Password for <?= htmlspecialchars($user['username']) ?></h3>
<hr>

<div class="card">
    <div class="card-body">
        <form id="main-form" action="<?= base_url('/users/' . $user_id . '/change-password') ?>" method="POST" data-redirect="/users">
            <input type="hidden" name="id" value="<?= $user_id ?>">
            <div class="mb-3">
                <label for="password" class="form-label">New Password</label>
                <input type="password" class="form-control" id="password" name="password" required>
            </div>
            <div class="mb-3">
                <label for="confirm_password" class="form-label">Confirm New Password</label>
                <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
            </div>
            <a href="<?= base_url('/users') ?>" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">Update Password</button>
        </form>
    </div>
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>