<?php
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
    exit;
}

$conn = Database::getInstance()->getConnection();

try {
    // Query to get deployment counts per day for the last 30 days, aggregated across all hosts.
    $sql = "
        SELECT
            DATE(created_at) as deployment_date,
            change_type,
            COUNT(*) as count
        FROM
            stack_change_log
        WHERE
            created_at >= NOW() - INTERVAL 30 DAY
        GROUP BY
            deployment_date,
            change_type
        ORDER BY
            deployment_date ASC
    ";

    $result = $conn->query($sql);
    $data = $result->fetch_all(MYSQLI_ASSOC);

    // Process data for Chart.js
    $labels = [];
    $created_data = [];
    $updated_data = [];
    $deleted_data = [];

    // Initialize arrays for the last 30 days to ensure all days are present
    for ($i = 29; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-{$i} days"));
        $labels[] = $date;
        $created_data[$date] = 0;
        $updated_data[$date] = 0;
        $deleted_data[$date] = 0;
    }

    foreach ($data as $row) {
        $date = $row['deployment_date'];
        if ($row['change_type'] === 'created') {
            $created_data[$date] = (int)$row['count'];
        } elseif ($row['change_type'] === 'updated') {
            $updated_data[$date] = (int)$row['count'];
        } elseif ($row['change_type'] === 'deleted') {
            $deleted_data[$date] = (int)$row['count'];
        }
    }

    $datasets = [
        ['label' => 'Created', 'data' => array_values($created_data), 'backgroundColor' => 'rgba(75, 192, 192, 0.5)', 'borderColor' => 'rgb(75, 192, 192)', 'borderWidth' => 1],
        ['label' => 'Updated', 'data' => array_values($updated_data), 'backgroundColor' => 'rgba(255, 159, 64, 0.5)', 'borderColor' => 'rgb(255, 159, 64)', 'borderWidth' => 1],
        ['label' => 'Deleted', 'data' => array_values($deleted_data), 'backgroundColor' => 'rgba(255, 99, 132, 0.5)', 'borderColor' => 'rgb(255, 99, 132)', 'borderWidth' => 1]
    ];

    echo json_encode(['labels' => $labels, 'datasets' => $datasets]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();
?>