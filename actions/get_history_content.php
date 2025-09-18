<?php
require_once __DIR__ . '/../includes/bootstrap.php';
// Sesi dan otentikasi/otorisasi sudah ditangani oleh Router.

header('Content-Type: application/json');

if (!isset($_GET['id'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'History ID is required.']);
    exit;
}

$id = $_GET['id'];
$conn = Database::getInstance()->getConnection();
$stmt = $conn->prepare("SELECT yaml_content FROM config_history WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    echo json_encode(['status' => 'success', 'content' => $row['yaml_content']]);
} else {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'History not found.']);
}

$stmt->close();
$conn->close();
?>