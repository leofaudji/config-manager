<?php
require_once __DIR__ . '/../includes/bootstrap.php';
// Sesi dan otentikasi/otorisasi sudah ditangani oleh Router.

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    http_response_code(400);
    die('Invalid History ID.');
}

$id = (int)$_GET['id'];
$conn = Database::getInstance()->getConnection();

$stmt = $conn->prepare("SELECT yaml_content, created_at FROM config_history WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    $yaml_content = $row['yaml_content'];
    $timestamp = strtotime($row['created_at']);
    $filename = 'dynamic-config-' . date('Y-m-d_H-i-s', $timestamp) . '.yml';

    header('Content-Type: application/x-yaml');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($yaml_content));

    echo $yaml_content;
} else {
    http_response_code(404);
    die('History record not found.');
}

$stmt->close();
$conn->close();
?>