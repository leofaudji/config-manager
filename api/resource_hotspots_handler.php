<?php
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden.']);
    exit;
}

$conn = Database::getInstance()->getConnection();

try {
    // This query fetches the latest stats for each container across all hosts.
    // It uses a subquery to get the max `created_at` for each container_id,
    // then joins back to get the full row for that latest entry.
    $sql = "
        SELECT 
            cs.container_name,
            cs.cpu_usage,
            cs.memory_usage,
            h.name AS host_name
        FROM 
            container_stats cs
        INNER JOIN (
            SELECT container_id, MAX(created_at) AS max_created_at
            FROM container_stats
            GROUP BY container_id
        ) latest ON cs.container_id = latest.container_id AND cs.created_at = latest.max_created_at
        JOIN 
            docker_hosts h ON cs.host_id = h.id
    ";

    $result = $conn->query($sql);
    $all_stats_raw = $result->fetch_all(MYSQLI_ASSOC);

    // --- FIX: Cast numeric values from string to float/int ---
    $all_stats = array_map(function($stat) {
        $stat['cpu_usage'] = (float)$stat['cpu_usage'];
        $stat['memory_usage'] = (int)$stat['memory_usage'];
        return $stat;
    }, $all_stats_raw);

    // Sort for top CPU consumers
    usort($all_stats, function($a, $b) {
        return $b['cpu_usage'] <=> $a['cpu_usage'];
    });
    $top_cpu = array_slice($all_stats, 0, 20);

    // Sort for top Memory consumers
    usort($all_stats, function($a, $b) {
        return $b['memory_usage'] <=> $a['memory_usage'];
    });
    $top_memory = array_slice($all_stats, 0, 20);

    echo json_encode([
        'status' => 'success',
        'data' => [
            'top_cpu' => $top_cpu,
            'top_memory' => $top_memory
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to fetch resource hotspots: ' . $e->getMessage()]);
} finally {
    if (isset($conn)) $conn->close();
}