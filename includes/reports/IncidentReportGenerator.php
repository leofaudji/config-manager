<?php

class IncidentReportGenerator
{
    private $conn;

    public function __construct(mysqli $conn)
    {
        $this->conn = $conn;
    }

    private function format_duration_human($seconds) {
        if ($seconds === null) return 'Ongoing';
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

    public function getIncidentData(array $params): array
    {
        $search = $params['search'] ?? '';
        $status = $params['status'] ?? '';
        $severity = $params['severity'] ?? '';
        $assignee = $params['assignee'] ?? '';
        $date_range = $params['date_range'] ?? '';

        $where_conditions = [];
        $query_params = [];
        $types = '';

        if (!empty($search)) {
            $where_conditions[] = "i.target_name LIKE ?";
            $query_params[] = "%{$search}%";
            $types .= 's';
        }

        if (!empty($status)) {
            $where_conditions[] = "i.status = ?";
            $query_params[] = $status;
            $types .= 's';
        }

        if (!empty($severity)) {
            $where_conditions[] = "i.severity = ?";
            $query_params[] = $severity;
            $types .= 's';
        }

        if (!empty($assignee)) {
            $where_conditions[] = "i.assignee_user_id = ?";
            $query_params[] = $assignee;
            $types .= 'i';
        }


        if (!empty($date_range)) {
            $dates = explode(' - ', $date_range);
            if (count($dates) === 2) {
                $start_date = date('Y-m-d 00:00:00', strtotime($dates[0]));
                $end_date = date('Y-m-d 23:59:59', strtotime($dates[1]));
                $where_conditions[] = "i.start_time BETWEEN ? AND ?";
                $query_params[] = $start_date;
                $query_params[] = $end_date;
                $types .= 'ss';
            }
        }

        $where_clause = empty($where_conditions) ? '' : 'WHERE ' . implode(' AND ', $where_conditions);

        $sql = "SELECT i.*, h.name as host_name, u.username as assignee_username 
                FROM incident_reports i 
                LEFT JOIN docker_hosts h ON i.host_id = h.id 
                LEFT JOIN users u ON i.assignee_user_id = u.id
                {$where_clause} 
                ORDER BY i.start_time DESC LIMIT 500";
        
        $stmt = $this->conn->prepare($sql);
        if (!empty($query_params)) {
            $stmt->bind_param($types, ...$query_params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $incidents = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $incidents;
    }

    public function generatePdf(array $params): void
    {
        $pdf = new PDF();
        $pdf->setReportTitle('Incident Summary Report');
        $pdf->AliasNbPages();
        $pdf->AddPage('P', 'A4'); // Portrait mode for better readability
        $pdf->SetFont('Arial', '', 12);

        $incidents = $this->getIncidentData($params);
        
        // --- Report Header ---
        $pdf->SetFont('Arial', 'B', 16);
        $pdf->Cell(0, 10, 'Incident Report', 0, 1, 'C');
        $pdf->SetFont('Arial', '', 10);
        $period = 'All Time';
        if (!empty($params['dates'])) {
            $period = $params['dates'][0] . ' to ' . $params['dates'][1];
        }
        $pdf->Cell(0, 6, 'Period: ' . $period, 0, 1, 'C');
        $pdf->Ln(5);

        // --- Summary Section ---
        $total_incidents = count($incidents);
        $open_incidents = count(array_filter($incidents, fn($i) => in_array($i['status'], ['Open', 'Investigating'])));
        $resolved_incidents = $total_incidents - $open_incidents;
        $resolved_durations = array_column(array_filter($incidents, fn($i) => $i['duration_seconds'] !== null), 'duration_seconds');
        $avg_duration_seconds = !empty($resolved_durations) ? array_sum($resolved_durations) / count($resolved_durations) : 0;

        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 8, 'Summary', 0, 1, 'L');
        $pdf->SetLineWidth(0.2);
        $pdf->Line($pdf->GetX(), $pdf->GetY(), $pdf->GetX() + 277, $pdf->GetY());
        $pdf->Ln(4);

        $summary_items = [
            ['label' => 'Total Incidents', 'value' => $total_incidents, 'color' => [23, 162, 184]], // Blue
            ['label' => 'Open / Investigating', 'value' => $open_incidents, 'color' => [220, 53, 69]], // Red
            ['label' => 'Resolved / Closed', 'value' => $resolved_incidents, 'color' => [25, 135, 84]], // Green
            ['label' => 'Avg. Resolution Time', 'value' => $this->format_duration_human($avg_duration_seconds), 'color' => [108, 117, 125]] // Grey
        ];

        // Two-column summary
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(40, 7, 'Total Incidents:', 0, 0);
        $pdf->Cell(55, 7, $total_incidents, 0, 0);
        $pdf->Cell(40, 7, 'Resolved / Closed:', 0, 0);
        $pdf->Cell(55, 7, $resolved_incidents, 0, 1);

        $pdf->Cell(40, 7, 'Open / Investigating:', 0, 0);
        $pdf->Cell(55, 7, $open_incidents, 0, 0);
        $pdf->Cell(40, 7, 'Avg. Resolution Time:', 0, 0);
        $pdf->Cell(55, 7, $this->format_duration_human($avg_duration_seconds), 0, 1);

        $pdf->Ln(6);

        // --- Table Section ---
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 8, 'Incident Details', 0, 1, 'L');
        $pdf->Line($pdf->GetX(), $pdf->GetY(), $pdf->GetX() + 190, $pdf->GetY());
        $pdf->Ln(2);
        $pdf->SetDrawColor(222, 226, 230); // Set border color for cards
        $pdf->SetLineWidth(0.2);

        // --- NEW: Group incidents by status ---
        $grouped_incidents = ['open' => [], 'resolved' => []];
        foreach ($incidents as $incident) {
            if (in_array($incident['status'], ['Open', 'Investigating'])) {
                $grouped_incidents['open'][] = $incident;
            } else {
                $grouped_incidents['resolved'][] = $incident;
            }
        }

        $status_colors = [
            'Open' => [220, 53, 69], 'Investigating' => [255, 193, 7],
            'Resolved' => [13, 110, 253], 'Closed' => [25, 135, 84]
        ];

        // --- Render Open/Investigating Incidents ---
        if (!empty($grouped_incidents['open'])) {
            $pdf->SetFont('Arial', 'B', 11);
            $pdf->SetFillColor(255, 235, 238); // Light red background
            $pdf->Cell(0, 8, 'Open & Investigating Incidents', 0, 1, 'L', true);
            $pdf->Ln(2);
            foreach ($grouped_incidents['open'] as $incident) {
                $this->drawIncidentCard($pdf, $incident, $status_colors);
            }
        }

        // --- Render Resolved/Closed Incidents ---
        if (!empty($grouped_incidents['resolved'])) {
            $pdf->SetFont('Arial', 'B', 11);
            $pdf->SetFillColor(233, 245, 239); // Light green background
            $pdf->Cell(0, 8, 'Resolved & Closed Incidents', 0, 1, 'L', true);
            $pdf->Ln(2);
            foreach ($grouped_incidents['resolved'] as $incident) {
                $this->drawIncidentCard($pdf, $incident, $status_colors);
            }
        }

        $pdf->Output('I', 'incident_report.pdf');
    }

    private function drawIncidentCard(FPDF $pdf, array $incident, array $status_colors): void
    {
        $pdf->SetFillColor(248, 249, 250);
        $pdf->Cell(0, 8, '', 0, 1, 'L', true); // Placeholder for top padding
        $pdf->SetY($pdf->GetY() - 8);
        $pdf->SetX($pdf->GetX() + 2);

        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(0, 7, 'Incident #' . $incident['id'] . ': ' . $incident['target_name'], 0, 1, 'L');
        $pdf->SetX($pdf->GetX() + 2);
        $pdf->SetFont('Arial', '', 9);

        $pdf->Cell(20, 6, 'Status:', 0, 0);
        $status_color = $status_colors[$incident['status']] ?? [108, 117, 125];
        $pdf->SetTextColor($status_color[0], $status_color[1], $status_color[2]);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell(75, 6, $incident['status'], 0, 0, 'L');
        $pdf->SetTextColor(0);
        $pdf->SetFont('Arial', '', 9);
        $pdf->Cell(20, 6, 'Host:', 0, 0);
        $pdf->Cell(0, 6, $incident['host_name'] ?? 'N/A', 0, 1);
        $pdf->SetX($pdf->GetX() + 2);

        $pdf->Cell(20, 6, 'Severity:', 0, 0);
        $pdf->Cell(75, 6, $incident['severity'], 0, 0);
        $pdf->Cell(20, 6, 'Assignee:', 0, 0);
        $pdf->Cell(0, 6, $incident['assignee_username'] ?? 'Unassigned', 0, 1);
        $pdf->SetX($pdf->GetX() + 2);
        
        $pdf->Cell(20, 6, 'Started:', 0, 0);
        $pdf->Cell(75, 6, $incident['start_time'], 0, 0);
        $pdf->Cell(20, 6, 'Duration:', 0, 0);
        $pdf->Cell(0, 6, $this->format_duration_human($incident['duration_seconds']), 0, 1);
        $pdf->SetX($pdf->GetX() + 2);

        $pdf->Cell(20, 6, 'Ended:', 0, 0);
        $pdf->Cell(0, 6, $incident['end_time'] ?? 'Ongoing', 0, 1);

        $pdf->Rect($pdf->GetX(), $pdf->GetY() - 31, 190, 38);
        $pdf->Ln(12);
    }

    public function generateSingleIncidentPdf(int $incident_id): void
    {
        $stmt = $this->conn->prepare("
            SELECT i.*, h.name as host_name, u.username as assignee_username 
            FROM incident_reports i 
            LEFT JOIN docker_hosts h ON i.host_id = h.id 
            LEFT JOIN users u ON i.assignee_user_id = u.id
            WHERE i.id = ?");
        $stmt->bind_param("i", $incident_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if (!($incident = $result->fetch_assoc())) {
            throw new Exception("Incident with ID {$incident_id} not found.");
        }
        $stmt->close();

        $pdf = new PDF();
        $pdf->setReportTitle('Incident Detail Report');
        $pdf->AliasNbPages();
        $pdf->AddPage('P', 'A4'); // Portrait mode for details

        // --- Main Title ---
        $pdf->SetFont('Arial', 'B', 16);
        $pdf->Cell(0, 10, 'Incident Report #' . $incident['id'], 0, 1, 'C');
        $pdf->Ln(2);

        // --- Status Banner ---
        $status_colors = [
            'Open' => [220, 53, 69],    // Red
            'Investigating' => [255, 193, 7], // Yellow
            'Resolved' => [13, 110, 253], // Blue
            'Closed' => [25, 135, 84],   // Green
        ];
        $status_color = $status_colors[$incident['status']] ?? [108, 117, 125]; // Grey for default
        $pdf->SetFillColor($status_color[0], $status_color[1], $status_color[2]);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 8, 'Status: ' . $incident['status'], 0, 1, 'C', true);
        $pdf->Ln(8);

        // Reset colors
        $pdf->SetTextColor(0, 0, 0); 

        // --- Details Section ---
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 8, 'Summary', 0, 1, 'L');
        $pdf->SetLineWidth(0.2);
        $pdf->Line($pdf->GetX(), $pdf->GetY(), $pdf->GetX() + 190, $pdf->GetY());
        $pdf->Ln(2);

        // Two-column layout for details
        $pdf->SetFont('Arial', '', 10);
        $col1_width = 95;
        $col2_width = 95;
        $current_y = $pdf->GetY();

        // Column 1
        $pdf->SetFont('Arial', 'B', 10); $pdf->Cell(25, 6, 'Target:', 0, 0);
        $pdf->SetFont('Arial', '', 10); $pdf->Cell($col1_width - 25, 6, $incident['target_name'], 0, 0);

        // Column 2
        $pdf->SetFont('Arial', 'B', 10); $pdf->Cell(25, 6, 'Start Time:', 0, 0);
        $pdf->SetFont('Arial', '', 10); $pdf->Cell($col2_width - 25, 6, $incident['start_time'], 0, 1);

        // Next row
        $pdf->SetFont('Arial', 'B', 10); $pdf->Cell(25, 6, 'Type:', 0, 0);
        $pdf->SetFont('Arial', '', 10); $pdf->Cell($col1_width - 25, 6, $incident['incident_type'], 0, 0);

        $pdf->SetFont('Arial', 'B', 10); $pdf->Cell(25, 6, 'End Time:', 0, 0);
        $pdf->SetFont('Arial', '', 10); $pdf->Cell($col2_width - 25, 6, $incident['end_time'] ?? 'Ongoing', 0, 1);

        // Next row
        $pdf->SetFont('Arial', 'B', 10); $pdf->Cell(25, 6, 'Host:', 0, 0);
        $pdf->SetFont('Arial', '', 10); $pdf->Cell($col1_width - 25, 6, $incident['host_name'] ?? 'N/A', 0, 0);

        $pdf->SetFont('Arial', 'B', 10); $pdf->Cell(25, 6, 'Duration:', 0, 0);
        $pdf->SetFont('Arial', '', 10); $pdf->Cell($col2_width - 25, 6, $this->format_duration_human($incident['duration_seconds']), 0, 1);

        // Next row for Severity and Assignee
        $pdf->SetFont('Arial', 'B', 10); $pdf->Cell(25, 6, 'Severity:', 0, 0);
        $pdf->SetFont('Arial', '', 10); $pdf->Cell($col1_width - 25, 6, $incident['severity'], 0, 0);

        $pdf->SetFont('Arial', 'B', 10); $pdf->Cell(25, 6, 'Assignee:', 0, 0);
        $pdf->SetFont('Arial', '', 10); $pdf->Cell($col2_width - 25, 6, $incident['assignee_username'] ?? 'Unassigned', 0, 1);

        $pdf->Ln(5);

        // --- Notes Sections ---
        $this->addNotesSection($pdf, 'Investigation Notes', $incident['investigation_notes']);
        $this->addNotesSection($pdf, 'Resolution Notes', $incident['resolution_notes']);

        // --- NEW: RCA Sections ---
        $pdf->Ln(5);
        $pdf->SetFont('Arial', 'B', 14);
        $pdf->Cell(0, 8, 'Post-Mortem / Root Cause Analysis', 0, 1, 'L');
        $pdf->Line($pdf->GetX(), $pdf->GetY(), $pdf->GetX() + 190, $pdf->GetY());
        $pdf->Ln(2);

        $this->addNotesSection($pdf, 'Executive Summary', $incident['executive_summary']);
        $this->addNotesSection($pdf, 'Root Cause', $incident['root_cause']);

        // Action Items
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 8, 'Action Items', 0, 1, 'L');
        $pdf->Line($pdf->GetX(), $pdf->GetY(), $pdf->GetX() + 190, $pdf->GetY());
        $pdf->Ln(2);
        $action_items = json_decode($incident['action_items'] ?? '[]', true);
        $this->renderActionItems($pdf, $action_items);
        $pdf->Ln(5);

        $this->addNotesSection($pdf, 'Lessons Learned', $incident['lessons_learned']);

        // --- Snapshot Section ---
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 8, 'Monitoring Snapshot', 0, 1, 'L');
        $pdf->Line($pdf->GetX(), $pdf->GetY(), $pdf->GetX() + 190, $pdf->GetY());
        $pdf->Ln(2);
        $pdf->SetFont('Courier', '', 8);
        $pdf->SetFillColor(230, 230, 230);
        $snapshot_content = json_encode(json_decode($incident['monitoring_snapshot'] ?? '[]'), JSON_PRETTY_PRINT);
        $pdf->MultiCell(0, 5, $snapshot_content, 0, 'L', true);

        $pdf->Output('I', 'incident_report_' . $incident['id'] . '.pdf');
    }

    private function addNotesSection(FPDF $pdf, string $title, ?string $notes): void
    {
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 8, $title, 0, 1, 'L');
        $pdf->Line($pdf->GetX(), $pdf->GetY(), $pdf->GetX() + 190, $pdf->GetY());
        $pdf->Ln(2);
        $pdf->SetFont('Arial', '', 10);
        if (!empty($notes)) {
            $pdf->MultiCell(0, 6, $notes, 0, 'L');
        } else {
            $pdf->SetFont('Arial', 'I', 10);
            $pdf->Cell(0, 6, 'No notes provided.', 0, 1);
        }
        $pdf->Ln(5);
    }

    private function renderActionItems(FPDF $pdf, array $items): void
    {
        if (empty($items)) {
            $pdf->SetFont('Arial', 'I', 10);
            $pdf->Cell(0, 6, 'No action items provided.', 0, 1);
            return;
        }

        $status_icons = ['todo' => 'T', 'inprogress' => 'P', 'done' => 'D']; // Simple text icons

        foreach ($items as $item) {
            $pdf->SetFont('Arial', 'B', 10);
            $pdf->Cell(10, 6, '[' . ($status_icons[$item['status']] ?? '?') . ']', 0, 0, 'C');
            $pdf->SetFont('Arial', '', 10);
            $pdf->MultiCell(0, 6, $item['task'], 0, 'L');
            $pdf->Ln(1);
        }
        $pdf->SetFont('Arial', 'I', 8);
        $pdf->Cell(0, 6, 'Legend: [T] To Do, [P] In Progress, [D] Done', 0, 1);
    }
}