<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/bootstrap.php';
// Sesi dan otentikasi/otorisasi sudah ditangani oleh Router.

header('Content-Type: application/json');
$conn = Database::getInstance()->getConnection();

$stats = [];
$result = $conn->query("SELECT rule FROM routers");

while ($row = $result->fetch_assoc()) {
    $base_domain = extractBaseDomain($row['rule']);
    if ($base_domain) {
        if (!isset($stats[$base_domain])) {
            $stats[$base_domain] = 0;
        }
        $stats[$base_domain]++;
    }
}

// Sort by domain name for consistent chart order
ksort($stats);

$response = [
    'labels' => array_keys($stats),
    'data' => array_values($stats),
];

$conn->close();
echo json_encode($response);
?>