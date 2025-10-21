<?php
// File: /var/www/html/config-manager/api/pdf.php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/fpdf/fpdf.php'; // Pindahkan ke sini
require_once __DIR__ . '/../includes/reports/SlaReportGenerator.php';
require_once __DIR__ . '/../includes/reports/IncidentReportGenerator.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    die('Forbidden');
}

// --- Input Validation ---
$report_type = filter_input(INPUT_POST, 'report_type', FILTER_SANITIZE_STRING);
$date_range = filter_input(INPUT_POST, 'date_range', FILTER_SANITIZE_STRING);

if ($report_type === 'sla_report') {
    $host_id_raw = $_POST['host_id'] ?? null;
    $host_id = ($host_id_raw === 'all') ? 'all' : filter_var($host_id_raw, FILTER_VALIDATE_INT);

    // Special handling for container_id which can be 'service:service_name'
    $raw_container_id = $_POST['container_id'] ?? null;
    if (strpos($raw_container_id, 'service:') === 0) {
        $service_name = substr($raw_container_id, strlen('service:'));
        if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $service_name)) {
            http_response_code(400); die('Invalid service name format.');
        }
        $container_id = $raw_container_id;
    } else {
        $container_id = filter_input(INPUT_POST, 'container_id', FILTER_SANITIZE_STRING);
    }
    $show_only_downtime = filter_input(INPUT_POST, 'show_only_downtime', FILTER_VALIDATE_BOOLEAN);

    if (!$host_id || (!$container_id && $host_id !== 'all') || !$date_range) {
        http_response_code(400);
        die('Host ID, Container ID, and Date Range are required for SLA report.');
    }
} elseif ($report_type === 'single_incident_report') {
    if (empty($_POST['incident_id'])) {
        http_response_code(400);
        die('Incident ID is required for single incident report.');
    }
}

// The date_range can be empty for incident reports (meaning 'All Time'), so we only validate if it's not empty.
$dates = [];
if (!empty($date_range)) {
    $dates = explode(' - ', $date_range);
    if (count($dates) !== 2) {
        http_response_code(400);
        die('Invalid date range format.');
    }
}

$conn = Database::getInstance()->getConnection();

class PDF extends FPDF
{
    public $reportTitle = 'General Report'; // Default title

    // Page header
    function Header()
    {
        // Logo
        $this->Image(__DIR__ . '/../assets/img/logo-assistindo.png', 10, 8, 25);
        
        // Company Name
        $this->SetFont('Arial', 'B', 16);
        $this->Cell(0, 7, 'PT. Assist Software Indonesia Pratama', 0, 1, 'C');

        // Address
        $this->SetFont('Arial', '', 10);
        $this->Cell(0, 5, 'Pondok Blimbing Indah E1-14 Malang', 0, 1, 'C');

        // Report Title
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 7, $this->reportTitle, 0, 1, 'C');

        // Line break
        $this->Ln(5);
    }

    function setReportTitle($title)
    {
        $this->reportTitle = $title;
    }

    // Page footer
    function Footer()
    {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        // Get username from session
        $printed_by = $_SESSION['username'] ?? 'system';
        // Format: Printed by: admin on 2023-10-30 15:30:00 | Page 1/{nb}
        $left_text = 'Printed by: ' . $printed_by . ' on ' . date('Y-m-d H:i:s');
        $right_text = 'Page ' . $this->PageNo() . '/{nb}';
        $this->Cell(0, 10, $left_text, 0, 0, 'L');
        $this->Cell(0, 10, $right_text, 0, 0, 'R');
    }

    // Colored table
    function FancyTable($header, $data, $widths = [70, 30, 45, 45], $aligns = [])
    {
        $this->SetFillColor(23, 162, 184); // Bootstrap Info color
        $this->SetTextColor(255);
        $this->SetDrawColor(128, 0, 0);
        $this->SetLineWidth(.3); 
        $this->SetFont('', 'B');
        for ($i = 0; $i < count($header); $i++)
            $this->Cell($widths[$i], 7, $header[$i], 1, 0, 'C', true);
        $this->Ln();
        // Color and font restoration
        $this->SetTextColor(0);
        $this->SetFillColor(240, 240, 240); // Light grey for zebra stripes
        // Set a smaller font for the table body
        $this->SetFont('Arial', '', 10);
        $fill = false;
        foreach ($data as $row) {
            for ($i = 0; $i < count($row); $i++) {
                $this->Cell($widths[$i], 7, $row[$i], 'LR', 0, $aligns[$i] ?? 'L', $fill);
            }
            $this->Ln();
            $fill = !$fill;
        }
        $this->Cell(array_sum($widths), 0, '', 'T');
    }
}

// --- Report Dispatcher ---
$reportGenerators = [
    'sla_report' => SlaReportGenerator::class,
    'incident_report' => IncidentReportGenerator::class,
    'single_incident_report' => IncidentReportGenerator::class,
];

try {
    if (!isset($reportGenerators[$report_type])) {
        throw new InvalidArgumentException('Invalid report_type specified.');
    }

    $generatorClass = $reportGenerators[$report_type];
    $reportGenerator = new $generatorClass($conn);

    if ($report_type === 'sla_report') {
        $params = [
            'host_id'      => $host_id,
            'container_id' => $container_id,
            'start_date'   => date('Y-m-d 00:00:00', strtotime($dates[0])),
            'end_date'     => date('Y-m-d 23:59:59', strtotime($dates[1])),
            'show_only_downtime' => $show_only_downtime,
            'dates'        => $dates,
        ];
        $reportGenerator->generatePdf($params);
    } elseif ($report_type === 'incident_report') {
        $params = [
            'search' => $_POST['search'] ?? '',
            'status' => $_POST['status'] ?? '',
            'date_range' => $_POST['date_range'] ?? '',
            'dates' => $dates, // Pass parsed dates for display
        ];
        $reportGenerator->generatePdf($params);
    } elseif ($report_type === 'single_incident_report') {
        $incident_id = (int)$_POST['incident_id'];
        $reportGenerator->generateSingleIncidentPdf($incident_id);
        exit; // Stop execution after PDF is generated
    }

} catch (Exception $e) {
    http_response_code(500);
    die('Server error: ' . $e->getMessage());
} finally {
    $conn->close();
}

?>