<?php

require_once __DIR__ . '/../DockerClient.php';

class SlaReportGenerator
{
    private $conn;

    public function __construct(mysqli $conn)
    {
        $this->conn = $conn;
    }

    public function getSlaData(array $params): array
    {
        // Add +1 to include the last second of the day, ensuring a full 24-hour period is 86400 seconds.
        $total_seconds_in_period = (strtotime($params['end_date']) - strtotime($params['start_date'])) + 1;
        if ($total_seconds_in_period <= 0) $total_seconds_in_period = 1;

        $downtime_statuses = ['unhealthy', 'stopped'];
        $placeholders = implode(',', array_fill(0, count($downtime_statuses), '?'));
        $types = str_repeat('s', count($downtime_statuses));
 
        if ($params['host_id'] === 'all') {
            return $this->getGlobalSummaryData($params, $total_seconds_in_period, $downtime_statuses);
        }
        if ($params['container_id'] && $params['container_id'] !== 'all') {
            // --- SCENARIO 1: DETAILED REPORT FOR A SINGLE CONTAINER ---
            $stmt_host = $this->conn->prepare("SELECT * FROM docker_hosts WHERE id = ?");
            $stmt_host->bind_param("i", $params['host_id']);
            $stmt_host->execute();
            $host_db_details = $stmt_host->get_result()->fetch_assoc();
            $stmt_host->close();

            if (!$host_db_details) {
                throw new Exception("Host with ID {$params['host_id']} not found.");
            }
            $host_name = $host_db_details['name'];

            $host_specs = [];
            try {
                $dockerClient = new DockerClient($host_db_details);
                $info = $dockerClient->getInfo();
                $host_specs = [
                    'os' => $info['OperatingSystem'] ?? 'N/A',
                    'cpus' => $info['NCPU'] ?? 'N/A',
                    'memory' => isset($info['MemTotal']) ? $this->formatBytes($info['MemTotal']) : 'N/A',
                    'docker_version' => $info['ServerVersion'] ?? 'N/A',
                ];
            } catch (Exception $e) {
                // Could not connect, fill with N/A
                $host_specs = ['os' => 'N/A', 'cpus' => 'N/A', 'memory' => 'N/A', 'docker_version' => 'N/A'];
            }

            // --- NEW: Get Container-specific specifications ---
            $container_specs = [];
            try {
                $container_details = $dockerClient->inspectContainer($params['container_id']);
                $container_specs = [
                    'image' => $container_details['Config']['Image'] ?? 'N/A',
                    'cpu_limit' => isset($container_details['HostConfig']['NanoCpus']) ? $this->formatCpus($container_details['HostConfig']['NanoCpus']) : 'Unlimited',
                    'memory_limit' => isset($container_details['HostConfig']['Memory']) && $container_details['HostConfig']['Memory'] > 0 ? $this->formatBytes($container_details['HostConfig']['Memory']) : 'Unlimited',
                    'ports' => $this->formatPorts($container_details['NetworkSettings']['Ports'] ?? []),
                    'networks' => $this->formatNetworks($container_details['NetworkSettings']['Networks'] ?? []),
                ];
            } catch (Exception $e) {
                // Container might not exist anymore, but its history does.
                $container_specs = ['image' => 'N/A', 'cpu_limit' => 'N/A', 'memory_limit' => 'N/A', 'ports' => 'N/A', 'networks' => 'N/A'];
            }

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

            // NEW: Fetch all relevant incidents for this container in the date range
            $stmt_incidents = $this->conn->prepare(
                "SELECT id, start_time, end_time 
                 FROM incident_reports 
                 WHERE target_id = ? AND incident_type = 'container' AND start_time <= ? AND (end_time >= ? OR end_time IS NULL)"
            );
            $stmt_incidents->bind_param("sss", $params['container_id'], $params['end_date'], $params['start_date']);
            $stmt_incidents->execute();
            $incidents = $stmt_incidents->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt_incidents->close();

            // NEW: Get maintenance windows for the period
            $maintenance_windows = $this->getMaintenanceWindows($params['start_date'], $params['end_date']);

            $total_downtime_seconds = 0;
            $downtime_details = [];
            foreach ($downtime_events as $event) {
                $clamped_duration = $this->calculateClampedDuration($event, $params['start_date'], $params['end_date']);
                if ($clamped_duration > 0) {
                    $maintenance_overlap = $this->calculateMaintenanceOverlap(strtotime($event['start_time']), $event['end_time'] ? strtotime($event['end_time']) : strtotime($params['end_date']), $maintenance_windows);
                    $chargeable_downtime = $clamped_duration - $maintenance_overlap;

                    $total_downtime_seconds += $chargeable_downtime;
                    $event_start_ts = strtotime($event['start_time']);
                    $event_end_ts = $event['end_time'] ? strtotime($event['end_time']) : strtotime($params['end_date']);

                    $matching_incident_id = $this->findMatchingIncident($event_start_ts, $event_end_ts, $incidents);

                    $downtime_details[] = [
                        'start_time' => date('Y-m-d H:i:s', max(strtotime($event['start_time']), strtotime($params['start_date']))),
                        'end_time' => $event['end_time'] ? date('Y-m-d H:i:s', min(strtotime($event['end_time']), strtotime($params['end_date']))) : null,
                        'duration_seconds' => $clamped_duration,
                        'duration_human' => $this->formatDuration($clamped_duration),
                        'status' => $event['status'],
                        'incident_id' => $matching_incident_id,
                        'maintenance_overlap_seconds' => $maintenance_overlap,
                    ];
                }
            }

            $uptime_seconds = $total_seconds_in_period - $total_downtime_seconds;
            $sla_percentage_raw = ($total_seconds_in_period > 0) ? ($uptime_seconds / $total_seconds_in_period) * 100 : 100;

            return [
                'report_type' => 'single_container',
                'host_name' => $host_name,
                'host_specs' => $host_specs,
                'container_specs' => $container_specs,
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
            $stmt_host = $this->conn->prepare("SELECT * FROM docker_hosts WHERE id = ?");
            $stmt_host->bind_param("i", $params['host_id']);
            $stmt_host->execute();
            $host_db_details = $stmt_host->get_result()->fetch_assoc();
            $stmt_host->close();

            if (!$host_db_details) {
                throw new Exception("Host with ID {$params['host_id']} not found.");
            }
            $host_name = $host_db_details['name'];

            $host_specs = [];
            try {
                $dockerClient = new DockerClient($host_db_details);
                $info = $dockerClient->getInfo();
                $host_specs = [
                    'os' => $info['OperatingSystem'] ?? 'N/A',
                    'cpus' => $info['NCPU'] ?? 'N/A',
                    'memory' => isset($info['MemTotal']) ? $this->formatBytes($info['MemTotal']) : 'N/A',
                    'docker_version' => $info['ServerVersion'] ?? 'N/A',
                ];
            } catch (Exception $e) {
                // Could not connect, fill with N/A
                $host_specs = ['os' => 'N/A', 'cpus' => 'N/A', 'memory' => 'N/A', 'docker_version' => 'N/A'];
            }

            // --- REFACTORED FOR SERVICE-LEVEL SLA ---
            // The logic now treats each group of containers as a single "service".
            $summary_data = $this->getServiceSummaryData($params, $total_seconds_in_period, $downtime_statuses);
            $container_slas = [];
            $overall_total_downtime = 0;
            $total_service_periods = 0;
            foreach ($summary_data as $row) {
                $container_slas[] = [
                    'container_id' => $row[5], // Add container/service identifier
                    'container_name' => $row[0],
                    'sla_percentage' => str_replace('%', '', $row[1]),
                    'sla_percentage_raw' => (float)str_replace('%', '', $row[1]),
                    'total_downtime_human' => $row[2],
                    'downtime_incidents' => $row[3],
                    'total_downtime_seconds' => $row[4], // Keep raw seconds for sorting
                ];
                $overall_total_downtime += $row[4];
                $total_service_periods += $total_seconds_in_period;
            }

            // Sort by downtime descending, then by container name ascending
            usort($container_slas, function ($a, $b) {
                if ($a['total_downtime_seconds'] !== $b['total_downtime_seconds']) {
                    return $b['total_downtime_seconds'] <=> $a['total_downtime_seconds']; // Descending
                }
                return $a['container_name'] <=> $b['container_name']; // Ascending
            });

            // If the filter is active, remove containers with 100% SLA
            if (!empty($params['show_only_downtime'])) {
                $container_slas = array_filter($container_slas, fn($c) => $c['sla_percentage_raw'] < 100);
            }

            // Calculate overall host SLA
            $overall_host_sla_raw = 100;
            if ($total_service_periods > 0) {
                $overall_uptime_seconds = $total_service_periods - $overall_total_downtime;
                $overall_host_sla_raw = ($overall_uptime_seconds / $total_service_periods) * 100;
            }

            return [
                'report_type' => 'host_summary',
                'host_name' => $host_name, 
                'host_specs' => $host_specs,
                'container_slas' => $container_slas,
                'overall_host_sla' => number_format($overall_host_sla_raw, 2),
                'overall_host_sla_raw' => $overall_host_sla_raw,
                'overall_total_downtime_human' => $this->formatDuration($overall_total_downtime),
            ];
        }
    }

    private function getGlobalSummaryData(array $params, int $total_seconds_in_period, array $downtime_statuses): array
    {
        $stmt_hosts = $this->conn->prepare("SELECT * FROM docker_hosts ORDER BY name ASC");
        $stmt_hosts->execute();
        $all_hosts = $stmt_hosts->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt_hosts->close();

        $global_summary = [];
        $violation_details = []; // NEW: Array to hold specific violations
        foreach ($all_hosts as $host) {
            $host_specs = [];
            try {
                $dockerClient = new DockerClient($host);
                $info = $dockerClient->getInfo();
                $host_specs = [
                    'os' => $info['OperatingSystem'] ?? 'N/A',
                    'cpus' => $info['NCPU'] ?? 'N/A',
                    'memory' => isset($info['MemTotal']) ? $this->formatBytes($info['MemTotal']) : 'N/A',
                ];
            } catch (Exception $e) {
                // Could not connect, fill with N/A
                $host_specs = ['os' => 'Unreachable', 'cpus' => 'N/A', 'memory' => 'N/A'];
            }

            $host_params = array_merge($params, ['host_id' => $host['id']]);
            $summary_data = $this->getServiceSummaryData($host_params, $total_seconds_in_period, $downtime_statuses);

            $overall_total_downtime = 0;
            $total_service_periods = 0;
            foreach ($summary_data as $row) {
                // --- NEW: Check for individual violations ---
                $sla_percentage_raw = (float)str_replace('%', '', $row[1]);
                if ($sla_percentage_raw < (float)get_setting('minimum_sla_percentage', 99.9)) {
                    $violation_details[] = [
                        'host_id' => $host['id'],
                        'container_id' => $row[5], // The identifier (container_id or service:name)
                        'host_name' => $host['name'],
                        'container_name' => $row[0], // Service/Container Name
                        'sla_percentage' => $row[1], // Formatted SLA
                        'sla_percentage_raw' => $sla_percentage_raw,
                        'total_downtime_human' => $row[2], // Formatted Downtime
                    ];
                }

                $overall_total_downtime += $row[4];
                $total_service_periods += $total_seconds_in_period;
            }

            $overall_host_sla_raw = 100;
            if ($total_service_periods > 0) {
                $overall_uptime_seconds = $total_service_periods - $overall_total_downtime;
                $overall_host_sla_raw = ($overall_uptime_seconds / $total_service_periods) * 100;
            }

            $global_summary[] = [
                'host_id' => $host['id'],
                'host_name' => $host['name'],
                'host_specs' => $host_specs,
                'overall_host_sla' => number_format($overall_host_sla_raw, 2),
                'overall_host_sla_raw' => $overall_host_sla_raw,
                'overall_total_downtime_human' => $this->formatDuration($overall_total_downtime),
            ];
        }

        // Sort the detailed violations by the lowest SLA percentage first
        usort($violation_details, function ($a, $b) {
            return $a['sla_percentage_raw'] <=> $b['sla_percentage_raw'];
        });

        return [
            'report_type' => 'global_summary',
            'host_slas' => $global_summary,
            'violation_details' => $violation_details, // NEW: Return the detailed list
        ];
    }

    public function generatePdf(array $params): void
    {
        $pdf = new PDF();
        $pdf->setReportTitle('Service Level Agreement Report'); // Set the header title
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
            $pdf->SetFont('Arial', 'I', 10);
            $pdf->Cell(0, 6, 'Host: ' . $data['host_name'], 0, 1, 'C');
            $pdf->Ln(3);
            
            // --- Two-column layout for Specs and Summary ---
            $startY = $pdf->GetY();
            // Get margins indirectly. GetX() after AddPage() is at the left margin.
            $lMargin = $pdf->GetX(); 
            $pageWidth = $pdf->GetPageWidth(); 
            $printableWidth = $pageWidth - ($lMargin * 2);
            $column1Width = $printableWidth * 0.6; // 60% for specs
            $column2Width = $printableWidth * 0.4; // 40% for summary

            // Column 1: Host Specifications
            $pdf->SetFont('Arial', 'B', 11);
            $pdf->Cell($column1Width, 7, 'Container Specifications', 0, 1);
            $pdf->SetFont('Arial', '', 10);
            $pdf->Cell(35, 6, 'Image:', 0, 0);
            $pdf->MultiCell($column1Width - 35, 6, $data['container_specs']['image'], 0, 'L');
            $pdf->Cell(35, 6, 'CPU Limit:', 0, 0);
            $pdf->Cell($column1Width - 35, 6, $data['container_specs']['cpu_limit'], 0, 1);
            $pdf->Cell(35, 6, 'Memory Limit:', 0, 0);
            $pdf->Cell($column1Width - 35, 6, $data['container_specs']['memory_limit'], 0, 1);
            $pdf->Cell(35, 6, 'Published Ports:', 0, 0);
            $pdf->MultiCell($column1Width - 35, 6, $data['container_specs']['ports'], 0, 'L');
            $pdf->Cell(35, 6, 'Networks:', 0, 0);
            $pdf->MultiCell($column1Width - 35, 6, $data['container_specs']['networks'], 0, 'L');
            $endY1 = $pdf->GetY();

            // Column 2: Summary
            $pdf->SetXY($lMargin + $column1Width, $startY);
            $pdf->SetFont('Arial', 'B', 12);
            $pdf->Cell($column2Width, 8, 'Summary', 0, 1, 'L');
            $pdf->SetX($lMargin + $column1Width);
            $pdf->SetFont('Arial', '', 10);
            $pdf->Cell(40, 6, 'SLA Percentage:', 0, 0);
            $pdf->Cell($column2Width - 40, 6, $data['summary']['sla_percentage'] . '%', 0, 1);
            $pdf->SetX($lMargin + $column1Width);
            $pdf->Cell(40, 6, 'Total Downtime:', 0, 0);
            $pdf->Cell($column2Width - 40, 6, $data['summary']['total_downtime_human'], 0, 1);
            $pdf->SetX($lMargin + $column1Width);
            $pdf->Cell(40, 6, 'Downtime Incidents:', 0, 0);
            $pdf->Cell($column2Width - 40, 6, $data['summary']['downtime_incidents'], 0, 1);
            $endY2 = $pdf->GetY();

            // Move cursor below the taller of the two columns
            $pdf->SetY(max($endY1, $endY2) + 5);

            // --- End of two-column layout ---
            $pdf->SetFont('Arial', 'B', 12);
            $pdf->Cell(0, 8, 'Downtime Details', 0, 1);
            $downtime_details_for_pdf = array_map(fn($d) => [$d['start_time'], $d['end_time'] ?? 'Ongoing', $d['duration_human'], $d['status']], $data['downtime_details']);
            $pdf->FancyTable(['Start Time', 'End Time', 'Duration', 'Status'], $downtime_details_for_pdf, [60, 60, 35, 35], ['C', 'C', 'R', 'C']);

            $filename = "sla_report_{$data['container_name']}.pdf";
        } elseif ($data['report_type'] === 'global_summary') {
            $pdf->SetFont('Arial', 'B', 16);
            $pdf->Cell(0, 10, 'Global SLA Summary', 0, 1, 'C');
            $pdf->SetFont('Arial', '', 10);
            $pdf->Cell(0, 6, 'Period: ' . $params['dates'][0] . ' to ' . $params['dates'][1], 0, 1, 'C');
            $pdf->Ln(3);
            $host_slas_for_pdf = array_map(fn($h) => [$h['host_name'], $h['host_specs']['os'], $h['host_specs']['cpus'], $h['host_specs']['memory'], $h['overall_host_sla'] . '%', $h['overall_total_downtime_human']], $data['host_slas']);
            $pdf->FancyTable(['Host Name', 'Operating System', 'CPUs', 'Memory', 'SLA', 'Downtime'], $host_slas_for_pdf, [45, 45, 15, 25, 20, 35], ['L', 'L', 'C', 'R', 'R', 'R']);
            $filename = "sla_global_summary.pdf";
        } elseif ($data['report_type'] === 'host_summary') {
            // --- SUMMARY REPORT ---
            $pdf->SetFont('Arial', 'B', 16);
            $pdf->Cell(0, 10, 'SLA Summary for: ' . $data['host_name'], 0, 1, 'C');
            $pdf->SetFont('Arial', '', 10);
            $pdf->Cell(0, 6, 'Period: ' . $params['dates'][0] . ' to ' . $params['dates'][1], 0, 1, 'C');
            $pdf->Ln(3);

            // Host Specifications
            $pdf->SetFont('Arial', 'B', 12);
            $pdf->Cell(0, 8, 'Host Specifications', 0, 1);
            $pdf->SetFont('Arial', '', 10);
            $pdf->Cell(50, 6, 'Operating System:', 0, 0);
            $pdf->Cell(0, 6, $data['host_specs']['os'], 0, 1);
            $pdf->Cell(50, 6, 'Docker Version:', 0, 0);
            $pdf->Cell(0, 6, $data['host_specs']['docker_version'], 0, 1);
            $pdf->Cell(50, 6, 'CPUs:', 0, 0);
            $pdf->Cell(0, 6, $data['host_specs']['cpus'], 0, 1);
            $pdf->Cell(50, 6, 'Total Memory:', 0, 0);
            $pdf->Cell(0, 6, $data['host_specs']['memory'], 0, 1);
            $pdf->Ln(3);
 
            $container_slas_for_pdf = array_map(fn($c) => [$c['container_name'], $c['sla_percentage'] . '%', $c['total_downtime_human'], $c['downtime_incidents']], $data['container_slas']);
            $pdf->FancyTable(['Container Name', 'SLA', 'Total Downtime', 'Incidents'], $container_slas_for_pdf, [70, 30, 45, 45], ['L', 'R', 'R', 'R']);
            $filename = "sla_summary_{$data['host_name']}.pdf";
        } else {
            throw new Exception("Unknown report type '{$data['report_type']}' for PDF generation.");
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
        } elseif ($data['report_type'] === 'global_summary') {
            $filename = "sla_global_summary.csv";
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Host Name', 'OS', 'CPUs', 'Memory', 'Overall SLA', 'Total Aggregated Downtime']);

            foreach ($data['host_slas'] as $row) {
                fputcsv($output, [$row['host_name'], $row['host_specs']['os'], $row['host_specs']['cpus'], $row['host_specs']['memory'], $row['overall_host_sla'] . '%', $row['overall_total_downtime_human']]);
            }
            fclose($output);
        } elseif ($data['report_type'] === 'host_summary') {
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
        } else {
            throw new Exception("Unknown report type '{$data['report_type']}' for CSV generation.");
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

    private function getServiceSummaryData(array $params, int $total_seconds_in_period, array $downtime_statuses): array
    {
        // 1. Get all containers on the host within the period
        $stmt_containers = $this->conn->prepare("SELECT DISTINCT container_id, container_name FROM container_health_history WHERE host_id = ? AND start_time <= ? AND (end_time >= ? OR end_time IS NULL)");
        $stmt_containers->bind_param("iss", $params['host_id'], $params['end_date'], $params['start_date']);
        $stmt_containers->execute();
        $containers = $stmt_containers->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt_containers->close();

        // 2. Group containers by service name (e.g., 'stack_webapp.1.xyz' and 'stack_webapp.2.abc' both belong to 'stack_webapp')
        $services = [];
        foreach ($containers as $container) {
            $service_name = preg_replace('/(\.\d+)\..*$/', '', $container['container_name']);
            $services[$service_name]['ids'][] = $container['container_id'];
        }

        $summary_data = [];
        $placeholders = implode(',', array_fill(0, count($downtime_statuses), '?'));

        foreach ($services as $service_name => $container_ids) {
            // 3. Fetch all downtime events for all containers in this service
            $ids = $container_ids['ids'];
            $identifier = count($ids) > 1 ? 'service:' . $service_name : $ids[0];
            $container_placeholders = implode(',', array_fill(0, count($ids), '?'));
            $sql_downtime = "SELECT start_time, end_time FROM container_health_history WHERE host_id = ? AND container_id IN ({$container_placeholders}) AND status IN ({$placeholders}) AND start_time <= ? AND (end_time >= ? OR end_time IS NULL)";
            
            $sql_params = array_merge([$params['host_id']], $ids, $downtime_statuses, [$params['end_date'], $params['start_date']]);
            $types = 'i' . str_repeat('s', count($ids)) . str_repeat('s', count($downtime_statuses)) . 'ss';

            $stmt_downtime = $this->conn->prepare($sql_downtime);
            $stmt_downtime->bind_param($types, ...$sql_params);
            $stmt_downtime->execute();
            $downtime_events = $stmt_downtime->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt_downtime->close();

            if (empty($downtime_events)) {
                $summary_data[] = [$this->getCleanContainerName($service_name), '100.00', '0s', 0, 0, $identifier];
                continue;
            }

            // 4. Merge overlapping intervals
            $intervals = array_map(fn($e) => [strtotime($e['start_time']), $e['end_time'] ? strtotime($e['end_time']) : strtotime($params['end_date'])], $downtime_events);
            sort($intervals); // Sort by start time

            $merged = [$intervals[0]];
            for ($i = 1; $i < count($intervals); $i++) {
                $last_merged = &$merged[count($merged) - 1];
                if ($intervals[$i][0] <= $last_merged[1]) { // Overlap detected
                    $last_merged[1] = max($last_merged[1], $intervals[$i][1]);
                } else {
                    $merged[] = $intervals[$i];
                }
            }

            // 5. Calculate total downtime from merged intervals, clamped to the report period
            $total_downtime_seconds = 0;
            foreach ($merged as $interval) {
                $total_downtime_seconds += max(0, min($interval[1], strtotime($params['end_date'])) - max($interval[0], strtotime($params['start_date'])));
            }

            $uptime_seconds = $total_seconds_in_period - $total_downtime_seconds;
            $sla_percentage = ($total_seconds_in_period > 0) ? ($uptime_seconds / $total_seconds_in_period) * 100 : 100;
            $summary_data[] = [$this->getCleanContainerName($service_name), number_format($sla_percentage, 2), $this->formatDuration($total_downtime_seconds), count($merged), $total_downtime_seconds, $identifier];
        }
        return $summary_data;
    }

    private function calculateClampedDuration(array $event, string $start_date, string $end_date): int
    {
        $event_start = strtotime($event['start_time']);
        // If the event is ongoing (end_time is NULL), use the report's end date as the end point.
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

    private function formatBytes($bytes, $precision = 2) {
        if ($bytes <= 0) return '0 Bytes';
        $base = log($bytes, 1024);
        $suffixes = array('Bytes', 'KB', 'MB', 'GB', 'TB');

        if (!isset($suffixes[floor($base)])) {
            return $bytes . ' Bytes';
        }

        return round(pow(1024, $base - floor($base)), $precision) .' '. $suffixes[floor($base)];
    }

    private function formatPorts(array $portsData): string
    {
        if (empty($portsData)) {
            return 'None';
        }

        $formattedPorts = [];
        foreach ($portsData as $containerPort => $hostBindings) {
            if (is_array($hostBindings) && !empty($hostBindings)) {
                foreach ($hostBindings as $binding) {
                    if (isset($binding['HostPort']) && !empty($binding['HostPort'])) {
                        $formattedPorts[] = "{$binding['HostPort']}:{$containerPort}";
                    }
                }
            }
        }

        if (empty($formattedPorts)) {
            return 'None (only exposed)';
        }
        return implode(', ', $formattedPorts);
    }

    private function formatNetworks(array $networksData): string
    {
        if (empty($networksData)) {
            return 'None';
        }

        $formattedNetworks = [];
        foreach ($networksData as $networkName => $networkDetails) {
            $ipAddress = $networkDetails['IPAddress'] ?? '';
            if ($ipAddress) {
                $formattedNetworks[] = "{$networkName} ({$ipAddress})";
            } else {
                $formattedNetworks[] = $networkName;
            }
        }

        return implode(', ', $formattedNetworks);
    }

    private function formatCpus($nanoCpus) {
        if (empty($nanoCpus) || $nanoCpus <= 0) {
            return 'Unlimited';
        }
        // 1 CPU = 1,000,000,000 nanoCPUs
        $cpus = $nanoCpus / 1000000000;
        return number_format($cpus, 2) . ' vCPU';
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

    private function findMatchingIncident(int $event_start_ts, int $event_end_ts, array $incidents): ?int
    {
        foreach ($incidents as $incident) {
            $incident_start_ts = strtotime($incident['start_time']);
            // If incident is ongoing, its end time is effectively infinity for this check
            $incident_end_ts = $incident['end_time'] ? strtotime($incident['end_time']) : PHP_INT_MAX;

            // Check for overlap: (StartA <= EndB) and (EndA >= StartB)
            if ($incident_start_ts <= $event_end_ts && $incident_end_ts >= $event_start_ts) {
                return $incident['id'];
            }
        }
        return null;
    }

    private function getMaintenanceWindows(string $start_date, string $end_date): array
    {
        if (!(bool)get_setting('maintenance_window_enabled', false)) {
            return [];
        }

        $day = get_setting('maintenance_window_day', 'Sunday');
        $start_time = get_setting('maintenance_window_start_time', '02:00');
        $end_time = get_setting('maintenance_window_end_time', '04:00');

        $windows = [];
        $current_ts = strtotime("next {$day}", strtotime($start_date) - 86400);
        $end_ts = strtotime($end_date);

        while ($current_ts <= $end_ts) {
            $window_start = strtotime(date('Y-m-d', $current_ts) . ' ' . $start_time);
            $window_end = strtotime(date('Y-m-d', $current_ts) . ' ' . $end_time);

            // Handle overnight windows
            if ($window_end <= $window_start) {
                $window_end += 86400; // Add one day
            }

            $windows[] = ['start' => $window_start, 'end' => $window_end];
            $current_ts = strtotime('+1 week', $current_ts);
        }

        return $windows;
    }

    private function calculateMaintenanceOverlap(int $event_start, int $event_end, array $maintenance_windows): int
    {
        $total_overlap = 0;
        foreach ($maintenance_windows as $window) {
            $overlap_start = max($event_start, $window['start']);
            $overlap_end = min($event_end, $window['end']);

            if ($overlap_start < $overlap_end) {
                $total_overlap += ($overlap_end - $overlap_start);
            }
        }
        return $total_overlap;
    }

    public function getDailySlaForMonth(array $params): array
    {
        $year = $params['year'];
        $month = $params['month'];
        $days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);
        $daily_sla_data = [];

        for ($day = 1; $day <= $days_in_month; $day++) {
            $current_date_str = sprintf('%d-%02d-%02d', $year, $month, $day);
            
            $day_params = [
                'host_id'      => $params['host_id'],
                'container_id' => $params['container_id'],
                'start_date'   => $current_date_str . ' 00:00:00',
                'end_date'     => $current_date_str . ' 23:59:59',
            ];

            // Use the existing getSlaData method to calculate SLA for a single day
            // We wrap this in a try-catch to handle cases where there's no data for a day
            try {
                $sla_result = $this->getSlaData($day_params);
                if (isset($sla_result['summary']['sla_percentage_raw'])) {
                    $daily_sla_data[$current_date_str] = $sla_result['summary']['sla_percentage_raw'];
                } else {
                    // This can happen if there's no history at all for that day. Assume 100%.
                    $daily_sla_data[$current_date_str] = 100.00;
                }
            } catch (Exception $e) {
                // If getSlaData throws an error (e.g., host not found), we can't calculate.
                $daily_sla_data[$current_date_str] = null;
            }
        }

        return $daily_sla_data;
    }
}

?>