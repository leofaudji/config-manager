<?php
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
    exit;
}

$conn = Database::getInstance()->getConnection();

function format_duration_human($seconds) {
    if ($seconds === null) return null;
    if ($seconds < 1) return '0s';
    $parts = [];
    $periods = ['d' => 86400, 'h' => 3600, 'm' => 60, 's' => 1];
    foreach ($periods as $name => $value) {
        if ($seconds >= $value) {
            $num = floor($seconds / $value);
            $parts[] = "{$num}{$name}";
            $seconds %= $value;
        }
    }
    return implode(' ', $parts);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit_get = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
        $limit = ($limit_get == -1) ? 1000000 : $limit_get;
        $offset = ($page - 1) * $limit;

        $search = $_GET['search'] ?? '';
        $status = $_GET['status'] ?? '';
        $severity = $_GET['severity'] ?? '';
        $assignee = $_GET['assignee'] ?? '';
        $date_range = $_GET['date_range'] ?? '';

        $where_conditions = [];
        $params = [];
        $types = '';

        if (!empty($search)) {
            $where_conditions[] = "i.target_name LIKE ?";
            $params[] = "%{$search}%";
            $types .= 's';
        }

        if (!empty($status)) {
            $where_conditions[] = "i.status = ?";
            $params[] = $status;
            $types .= 's';
        }

        if (!empty($severity)) {
            $where_conditions[] = "i.severity = ?";
            $params[] = $severity;
            $types .= 's';
        }

        if (!empty($assignee)) {
            $where_conditions[] = "i.assignee_user_id = ?";
            $params[] = $assignee;
            $types .= 'i';
        }

        if (!empty($date_range)) {
            $dates = explode(' - ', $date_range);
            if (count($dates) === 2) {
                $start_date = date('Y-m-d 00:00:00', strtotime($dates[0]));
                $end_date = date('Y-m-d 23:59:59', strtotime($dates[1]));
                $where_conditions[] = "i.start_time BETWEEN ? AND ?";
                $params[] = $start_date;
                $params[] = $end_date;
                $types .= 'ss';
            }
        }

        $where_clause = empty($where_conditions) ? '' : 'WHERE ' . implode(' AND ', $where_conditions);

        // Get total count
        $stmt_count = $conn->prepare("SELECT COUNT(*) as count FROM incident_reports i {$where_clause}");
        if (!empty($params)) {
            $stmt_count->bind_param($types, ...$params);
        }
        $stmt_count->execute();
        $total_items = $stmt_count->get_result()->fetch_assoc()['count'];
        $stmt_count->close();
        $total_pages = ceil($total_items / $limit);

        // Get data
        $sql = "SELECT i.*, u.username as assignee_username 
                FROM incident_reports i 
                LEFT JOIN users u ON i.assignee_user_id = u.id
                {$where_clause} 
                ORDER BY i.start_time DESC 
                LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        $types .= 'ii';

        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $incidents = [];
        while ($row = $result->fetch_assoc()) {
            $row['duration_human'] = format_duration_human($row['duration_seconds']);
            $incidents[] = $row;
        }
        $stmt->close();

        echo json_encode([
            'status' => 'success',
            'data' => $incidents,
            'total_pages' => $total_pages,
            'current_page' => $page,
            'info' => "Showing " . count($incidents) . " of " . $total_items . " incidents."
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Server error: ' . $e->getMessage()]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $request_uri_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $basePath = BASE_PATH;
    if ($basePath && strpos($request_uri_path, $basePath) === 0) {
        $request_uri_path = substr($request_uri_path, strlen($basePath));
    }

    if (!preg_match('/^\/api\/incidents\/(\d+)$/', $request_uri_path, $matches)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid API endpoint for update.']);
        exit;
    }
    $incident_id = $matches[1];

    try {
        // --- FIX: Logic to set end_time on resolution/closure ---
        // 1. Get the current status and start time from the database before updating
        $stmt_get_old = $conn->prepare("SELECT status, start_time FROM incident_reports WHERE id = ?");
        $stmt_get_old->bind_param("i", $incident_id);
        $stmt_get_old->execute();
        $old_incident = $stmt_get_old->get_result()->fetch_assoc();
        $stmt_get_old->close();

        if (!$old_incident) {
            throw new Exception("Incident not found.");
        }

        $old_status = $old_incident['status'];
        $new_status = $_POST['status'] ?? $old_status;

        // 2. Build the update query dynamically
        $set_clauses = [
            "status = ?", "severity = ?", "assignee_user_id = ?",
            "investigation_notes = ?", "resolution_notes = ?", "executive_summary = ?",
            "root_cause = ?", "lessons_learned = ?", "action_items = ?"
        ];

        // Filter out empty action items before encoding
        $action_items = $_POST['action_items'] ?? [];
        $filtered_action_items = array_filter($action_items, fn($item) => !empty(trim($item['task'])));
        $action_items_json = json_encode(array_values($filtered_action_items)); // Re-index array

        $params = [
            $new_status, $_POST['severity'] ?? null,
            !empty($_POST['assignee_user_id']) ? (int)$_POST['assignee_user_id'] : null,
            $_POST['investigation_notes'] ?? null, $_POST['resolution_notes'] ?? null,
            $_POST['executive_summary'] ?? null, $_POST['root_cause'] ?? null,
            $_POST['lessons_learned'] ?? null, $action_items_json
        ];
        $types = "ssissssss";

        // If status is changing to Resolved/Closed and it wasn't already, set end_time
        if (in_array($new_status, ['Resolved', 'Closed']) && !in_array($old_status, ['Resolved', 'Closed'])) {
            $set_clauses[] = "end_time = NOW()";
            $set_clauses[] = "duration_seconds = TIMESTAMPDIFF(SECOND, start_time, NOW())";
        }

        $sql = "UPDATE incident_reports SET " . implode(', ', $set_clauses) . " WHERE id = ?";
        $params[] = $incident_id;
        $types .= "i";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        // --- End FIX ---

        $investigation_notes = $_POST['investigation_notes'] ?? null;
        $resolution_notes = $_POST['resolution_notes'] ?? null;
        $executive_summary = $_POST['executive_summary'] ?? null;
        $root_cause = $_POST['root_cause'] ?? null;
        $lessons_learned = $_POST['lessons_learned'] ?? null;
        
        if ($stmt->execute()) {
            log_activity($_SESSION['username'], 'Incident Updated', "Updated incident report #{$incident_id}. Set status to {$new_status}.");
            echo json_encode([
                'status' => 'success', 
                'message' => 'Incident report updated successfully.',
                'redirect' => base_url('/incident-reports')
            ]);
        } else {
            throw new Exception("Failed to update incident report: " . $stmt->error);
        }
        $stmt->close();
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Server error: ' . $e->getMessage()]);
    }
}

$conn->close();
?>