<?php

class SlaReportGenerator
{
    private $conn;

    public function __construct(mysqli $conn)
    {
        $this->conn = $conn;
    }

    public function getSlaData(array $params): array
    {
        $total_seconds_in_period = strtotime($params['end_date']) - strtotime($params['start_date']);
        if ($total_seconds_in_period <= 0) $total_seconds_in_period = 1;

        $downtime_statuses = ['unhealthy', 'stopped'];
        $placeholders = implode(',', array_fill(0, count($downtime_statuses), '?'));
        $types = str_repeat('s', count($downtime_statuses));

        if ($params['container_id'] && $params['container_id'] !== 'all') {
            // --- SCENARIO 1: DETAILED REPORT FOR A SINGLE CONTAINER ---
            $stmt_name = $this->conn->prepare("SELECT container_name FROM container_health_history WHERE container_id = ? ORDER BY start_time DESC LIMIT 1");
            $stmt_name->bind_param("s", $params['container_id']);
            $stmt_name->execute();
            $container_name = $stmt_name->get_result()->fetch_assoc()['container_name'] ?? $params['container_id'];
            $stmt_name->close(); 

            $sql = "SELECT status, start_time, end_time FROM container_health_history WHERE host_id = ? AND container_id = ? AND status IN ({$placeholders}) AND start_time <= ? AND (end_time >= ? OR end_time IS NULL) ORDER BY start_time ASC";
            $sql_params = array_merge([$params['host_id'], $params['container_id']], $downtime_statuses, [$params['end_date'], $params['start_date']]);
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("is" . $types . "ss", ...$sql_params);
            $stmt->execute();
            $downtime_events = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            $total_downtime_seconds = 0;
            $downtime_details = [];
            foreach ($downtime_events as $event) {
                $duration = $this->calculateClampedDuration($event, $params['start_date'], $params['end_date']);
                if ($duration > 0) {
                    $total_downtime_seconds += $duration;
                    $downtime_details[] = [
                        'start_time' => date('Y-m-d H:i:s', max(strtotime($event['start_time']), strtotime($params['start_date']))),
                        'end_time' => $event['end_time'] ? date('Y-m-d H:i:s', min(strtotime($event['end_time']), strtotime($params['end_date']))) : null,
                        'duration_seconds' => $duration,
                        'duration_human' => $this->formatDuration($duration),
                        'status' => $event['status']
                    ];
                }
            }

            $uptime_seconds = $total_seconds_in_period - $total_downtime_seconds;
            $sla_percentage_raw = ($total_seconds_in_period > 0) ? ($uptime_seconds / $total_seconds_in_period) * 100 : 100;

            return [
                'report_type' => 'single_container',
                'container_name' => $this->getCleanContainerName($container_name),
                'summary' => [
                    'start_date' => date('Y-m-d', strtotime($params['start_date'])),
                    'end_date' => date('Y-m-d', strtotime($params['end_date'])),
                    'sla_percentage' => number_format($sla_percentage_raw, 2),
                    'sla_percentage_raw' => $sla_percentage_raw,
                    'total_downtime_seconds' => $total_downtime_seconds,
                    'total_downtime_human' => $this->formatDuration($total_downtime_seconds),
                    'downtime_incidents' => count($downtime_details),
                ],
                'downtime_details' => $downtime_details
            ];
        } else {
            // --- SCENARIO 2: SUMMARY REPORT FOR ALL CONTAINERS ON A HOST ---
            $stmt_host = $this->conn->prepare("SELECT name FROM docker_hosts WHERE id = ?");
            $stmt_host->bind_param("i", $params['host_id']);
            $stmt_host->execute();
            $host_name = $stmt_host->get_result()->fetch_assoc()['name'] ?? 'Unknown Host';
            $stmt_host->close();

            // Variables for overall host SLA calculation
            $overall_total_downtime = 0;
            $total_container_periods = 0;

            $summary_data = $this->getSummaryData($params, $total_seconds_in_period, $downtime_statuses, $placeholders, $types);
            $container_slas = [];
            foreach ($summary_data as $row) {
                $container_slas[] = [ 
                    'container_name' => $row[0],
                    'sla_percentage' => str_replace('%', '', $row[1]),
                    'sla_percentage_raw' => (float)$row[1],
                    'total_downtime_human' => $row[2],
                    'downtime_incidents' => $row[3],
                ];
                $overall_total_downtime += $row[4]; // Index 4 holds raw downtime seconds
                $total_container_periods += $total_seconds_in_period;
            }

            // Calculate overall host SLA
            $overall_host_sla_raw = 100;
            if ($total_container_periods > 0) {
                $overall_uptime_seconds = $total_container_periods - $overall_total_downtime;
                $overall_host_sla_raw = ($overall_uptime_seconds / $total_container_periods) * 100;
            }

            return [
                'report_type' => 'host_summary',
                'host_name' => $host_name, 
                'container_slas' => $container_slas,
                'overall_host_sla' => number_format($overall_host_sla_raw, 2),
                'overall_host_sla_raw' => $overall_host_sla_raw,
                'overall_total_downtime_human' => $this->formatDuration($overall_total_downtime),
            ];
        }
    }

    public function generatePdf(array $params): void
    {
        $pdf = new PDF();
        $pdf->AliasNbPages();
        $pdf->AddPage();
        $pdf->SetFont('Arial', '', 12);

        $data = $this->getSlaData($params);

        if ($data['report_type'] === 'single_container') {
            // --- DETAILED REPORT ---
            $pdf->SetFont('Arial', 'B', 16);
            $pdf->Cell(0, 10, 'SLA Report for: ' . $data['container_name'], 0, 1, 'C');
            $pdf->SetFont('Arial', '', 10);
            $pdf->Cell(0, 6, 'Period: ' . $params['dates'][0] . ' to ' . $params['dates'][1], 0, 1, 'C');
            $pdf->Ln(3);

            $pdf->SetFont('Arial', 'B', 12);
            $pdf->Cell(0, 8, 'Summary', 0, 1);
            $pdf->SetFont('Arial', '', 10);
            $pdf->Cell(50, 6, 'SLA Percentage:', 0, 0);
            $pdf->Cell(0, 6, $data['summary']['sla_percentage'] . '%', 0, 1);
            $pdf->Cell(50, 6, 'Total Downtime:', 0, 0);
            $pdf->Cell(0, 6, $data['summary']['total_downtime_human'], 0, 1);
            $pdf->Cell(50, 6, 'Downtime Incidents:', 0, 0);
            $pdf->Cell(0, 6, $data['summary']['downtime_incidents'], 0, 1);
            $pdf->Ln(3);

            $pdf->SetFont('Arial', 'B', 12);
            $pdf->Cell(0, 8, 'Downtime Details', 0, 1);
            $downtime_details_for_pdf = array_map(fn($d) => [$d['start_time'], $d['end_time'] ?? 'Ongoing', $d['duration_human'], $d['status']], $data['downtime_details']);
            $pdf->FancyTable(['Start Time', 'End Time', 'Duration', 'Status'], $downtime_details_for_pdf, [60, 60, 35, 35], ['C', 'C', 'R', 'C']);

            $filename = "sla_report_{$data['container_name']}.pdf";
        } else {
            // --- SUMMARY REPORT ---
            $pdf->SetFont('Arial', 'B', 16);
            $pdf->Cell(0, 10, 'SLA Summary for: ' . $data['host_name'], 0, 1, 'C');
            $pdf->SetFont('Arial', '', 10);
            $pdf->Cell(0, 6, 'Period: ' . $params['dates'][0] . ' to ' . $params['dates'][1], 0, 1, 'C');
            $pdf->Ln(3);

            $container_slas_for_pdf = array_map(fn($c) => [$c['container_name'], $c['sla_percentage'] . '%', $c['total_downtime_human'], $c['downtime_incidents']], $data['container_slas']);
            $pdf->FancyTable(['Container Name', 'SLA', 'Total Downtime', 'Incidents'], $container_slas_for_pdf, [70, 30, 45, 45], ['L', 'R', 'R', 'R']);
            $filename = "sla_summary_{$data['host_name']}.pdf";
        }

        $pdf->Output('I', $filename);
    }

    public function generateCsv(array $params): void
    {
        $data = $this->getSlaData($params);

        if ($data['report_type'] === 'single_container') {
            // --- DETAILED REPORT ---
            $filename = "sla_report_{$data['container_name']}.csv";
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Start Time', 'End Time', 'Duration', 'Status']);

            foreach ($data['downtime_details'] as $row) {
                fputcsv($output, [$row['start_time'], $row['end_time'] ?? 'Ongoing', $row['duration_human'], $row['status']]);
            }
            fclose($output);
        } else {
            // --- SUMMARY REPORT ---
            $filename = "sla_summary_{$data['host_name']}.csv";
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Container Name', 'SLA', 'Total Downtime', 'Incidents']);

            foreach ($data['container_slas'] as $row) {
                fputcsv($output, [$row['container_name'], $row['sla_percentage'] . '%', $row['total_downtime_human'], $row['downtime_incidents']]);
            }
            fclose($output);
        }
    }

    private function getSummaryData(array $params, int $total_seconds_in_period, array $downtime_statuses, string $placeholders, string $types): array
    {
        $stmt_containers = $this->conn->prepare("SELECT DISTINCT container_id, container_name FROM container_health_history WHERE host_id = ? AND start_time <= ? AND (end_time >= ? OR end_time IS NULL)");
        $stmt_containers->bind_param("iss", $params['host_id'], $params['end_date'], $params['start_date']);
        $stmt_containers->execute();
        $containers = $stmt_containers->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt_containers->close();

        $summary_data = [];
        $sql_downtime = "SELECT start_time, end_time FROM container_health_history WHERE host_id = ? AND container_id = ? AND status IN ({$placeholders}) AND start_time <= ? AND (end_time >= ? OR end_time IS NULL)";
        $stmt_downtime = $this->conn->prepare($sql_downtime);

        foreach ($containers as $container) {
            $sql_params = array_merge([$params['host_id'], $container['container_id']], $downtime_statuses, [$params['end_date'], $params['start_date']]);
            $stmt_downtime->bind_param("is" . $types . "ss", ...$sql_params);
            $stmt_downtime->execute();
            $downtime_events = $stmt_downtime->get_result()->fetch_all(MYSQLI_ASSOC);
            $total_downtime_seconds = 0;
            foreach ($downtime_events as $event) {
                $total_downtime_seconds += $this->calculateClampedDuration($event, $params['start_date'], $params['end_date']);
            }
            $uptime_seconds = $total_seconds_in_period - $total_downtime_seconds;
            $sla_percentage = ($total_seconds_in_period > 0) ? ($uptime_seconds / $total_seconds_in_period) * 100 : 100;
            $summary_data[] = [$this->getCleanContainerName($container['container_name']), number_format($sla_percentage, 2) . '%', $this->formatDuration($total_downtime_seconds), count($downtime_events), $total_downtime_seconds];
        }
        $stmt_downtime->close();
        return $summary_data;
    }

    private function calculateClampedDuration(array $event, string $start_date, string $end_date): int
    {
        $event_start = strtotime($event['start_time']);
        $event_end = $event['end_time'] ? strtotime($event['end_time']) : strtotime($end_date);
        $effective_start = max($event_start, strtotime($start_date));
        $effective_end = min($event_end, strtotime($end_date));
        return max(0, $effective_end - $effective_start);
    }

    private function formatDuration($seconds): string
    {
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

    private function getCleanContainerName(string $raw_name): string
    {
        // Example: app-nginx3_app-nginx3.5.9qmggjlykfuzo1zzge77gxnly -> app-nginx3_app-nginx3.5
        // Find the first dot after the service name part
        $parts = explode('.', $raw_name, 2);
        if (count($parts) > 1) {
            // Find the second dot which usually separates the version from the random ID
            $sub_parts = explode('.', $parts[1], 2);
            return $parts[0] . '.' . $sub_parts[0];
        }
        return $raw_name;
    }
}

?>