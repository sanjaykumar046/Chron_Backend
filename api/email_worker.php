<?php
include 'apiMain.php';
require 'vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

date_default_timezone_set('Asia/Kolkata');

echo "===========================================\n";
echo "  Email Worker Started - " . date('Y-m-d H:i:s') . "\n";
echo "===========================================\n\n";

// Worker loop - runs forever
while (true) {
    try {
        // Get pending jobs from queue
        $sql = "SELECT * FROM email_queue 
                WHERE status = 'pending' 
                ORDER BY priority DESC, created_at ASC 
                LIMIT 1";
        
        $result = $conn->query($sql);
        
        if ($result && $result->num_rows > 0) {
            $job = $result->fetch_assoc();
            $job_id = $job['id'];
            $schedule_id = $job['schedule_id'];
            $test_mode = $job['test_mode'];
            $userid = $job['userid'];
            
            echo "[" . date('H:i:s') . "] Processing Job #$job_id (Schedule #$schedule_id)...\n";
            
            // Mark as processing
            $sql = "UPDATE email_queue SET status = 'processing', processed_at = NOW() WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('i', $job_id);
            $stmt->execute();
            
            // Process the job
            $result = processEmailJob($schedule_id, $test_mode, $userid, $conn);
            
            if ($result['success']) {
                // Mark as completed
                $sql = "UPDATE email_queue SET status = 'completed', processed_at = NOW() WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('i', $job_id);
                $stmt->execute();
                
                echo "[" . date('H:i:s') . "] ? Job #$job_id completed successfully\n";
                echo "   Recipients: {$result['recipients_count']}, Records: {$result['records_count']}\n\n";
            } else {
                // Mark as failed
                $attempts = $job['attempts'] + 1;
                $error_msg = $result['message'];
                
                if ($attempts >= $job['max_attempts']) {
                    $sql = "UPDATE email_queue SET status = 'failed', attempts = ?, error_message = ? WHERE id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param('isi', $attempts, $error_msg, $job_id);
                    $stmt->execute();
                    
                    echo "[" . date('H:i:s') . "] ? Job #$job_id FAILED after $attempts attempts\n";
                    echo "   Error: $error_msg\n\n";
                } else {
                    // Retry later
                    $sql = "UPDATE email_queue SET status = 'pending', attempts = ?, error_message = ? WHERE id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param('isi', $attempts, $error_msg, $job_id);
                    $stmt->execute();
                    
                    echo "[" . date('H:i:s') . "] ? Job #$job_id failed (Attempt $attempts/{$job['max_attempts']}), will retry\n";
                    echo "   Error: $error_msg\n\n";
                }
            }
            
            // Rate limiting: Wait 2 seconds between emails
            sleep(2);
            
        } else {
            // No jobs in queue, wait 5 seconds before checking again
            echo "[" . date('H:i:s') . "] Queue empty, waiting...\r";
            sleep(5);
        }
        
    } catch (Exception $e) {
        echo "[" . date('H:i:s') . "] ERROR: " . $e->getMessage() . "\n\n";
        sleep(5);
    }
}

// ============ EMAIL PROCESSING FUNCTION ============

function processEmailJob($schedule_id, $test_mode, $userid, $conn) {
    // Fetch schedule details
    $sql = "SELECT * FROM EMAIL_SCHEDULES WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $schedule_id);
    $stmt->execute();
    $schedule = $stmt->get_result()->fetch_assoc();
    
    if (!$schedule) {
        return ['success' => false, 'message' => 'Schedule not found'];
    }
    
    // Get recipient emails
    $recipients = [];
    if ($schedule['recipient_type'] == 'email') {
        $recipients = array_map('trim', explode(',', $schedule['recipients']));
    } else {
        // Get emails for designation categories
        $designationCategories = array_map('trim', explode(',', $schedule['recipients']));
        $placeholders = implode(',', array_fill(0, count($designationCategories), '?'));
        
        $sql = "SELECT DISTINCT EMAIL FROM EMP_DB WHERE DESIGNATION_CATEGORY IN ($placeholders) AND EMAIL IS NOT NULL AND EMAIL != ''";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param(str_repeat('s', count($designationCategories)), ...$designationCategories);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $recipients[] = $row['EMAIL'];
        }
    }
    
    if (empty($recipients)) {
        return ['success' => false, 'message' => 'No recipients found'];
    }
    
    // Generate report data
    $reportData = generateReportDataFromDB($schedule['report_type'], $conn, $userid);
    
    if (!$reportData || !isset($reportData['data']) || empty($reportData['data'])) {
        return ['success' => false, 'message' => 'Failed to generate report data'];
    }
    
    // Send email using PHPMailer
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'spritha888@gmail.com';
        $mail->Password = 'uxzm qbbq jpkf saur';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        
        $mail->setFrom('spritha888@gmail.com', 'Productivity Report System');
        
        $mail->clearAddresses();
        foreach ($recipients as $recipient) {
            $mail->addAddress($recipient);
        }
        
        $mail->isHTML(true);
        $mail->Subject = getEmailSubject($schedule['report_type']);
        
        // Generate charts
        $charts = generateChartImages($reportData, $schedule['report_type']);
        
        // Generate email body
        $mail->Body = generateEmailBody($reportData, $schedule['report_type'], $charts);
        
        // Attach CSV
        $csvData = generateCSVFromProcedure($reportData);
        if ($csvData) {
            $filename = $schedule['report_type'] . '_' . date('Y-m-d_His') . '.csv';
            $mail->addStringAttachment($csvData, $filename);
        }
        
        // Embed charts
        if ($charts && !empty($charts)) {
            foreach ($charts as $index => $chart) {
                $cid = 'chart_' . $index;
                $mail->addStringEmbeddedImage(
                    $chart['data'], 
                    $cid, 
                    $chart['name'], 
                    'base64', 
                    'image/jpeg'
                );
            }
        }
        
        $mail->send();
        
        // Update schedule
        if (!$test_mode) {
            $send_time = $schedule['send_time'];
            $next_send_date = date('Y-m-d', strtotime("+{$schedule['frequency_days']} days"));
            $next_send = $next_send_date . ' ' . $send_time;
            
            $sql = "UPDATE EMAIL_SCHEDULES SET last_sent = NOW(), next_send = ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('si', $next_send, $schedule_id);
            $stmt->execute();
        }
        
        return [
            'success' => true,
            'recipients_count' => count($recipients),
            'records_count' => count($reportData['data'])
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Email sending failed: ' . $mail->ErrorInfo . ' | ' . $e->getMessage()
        ];
    }
}

// ============ COPY ALL FUNCTIONS FROM send_report_email.php BELOW ============

function generateReportDataFromDB($reportType, $conn, $userid) {
    // Set end date to YESTERDAY (not today, as today's data is still changing)
    $endDate = date('Y-m-d', strtotime('-1 day'));
    $startDate = date('Y-m-d', strtotime('-30 days'));
    
    // Default parameters (ALL)
    $departments = 'ALL';
    $roles = 'ALL';
    $projects = 'ALL';
    $shifts = 'ALL';
    $teams = 'ALL';
    $ids = 'ALL';
    $names = 'ALL';
    $designations = 'ALL';
    
    $data = [];
    $columns = [];
    
    // Map report types to stored procedure report types
    $spReportType = '';
    switch ($reportType) {
        case 'MONTHLY_REPORT':
            $spReportType = 'MONTHLY_REPORT';
            $startDate = date('Y-m-01'); // First day of current month
            $endDate = date('Y-m-d', strtotime('-1 day')); // Yesterday
            break;
            
        case 'GROUP_REPORT':
            $spReportType = 'GROUP_REPORT';
            $startDate = date('Y-m-01'); // First day of current month
            $endDate = date('Y-m-d', strtotime('-1 day')); // Yesterday
            break;
            
        case 'SUMMARY_REPORT':
            $spReportType = 'SUMMARY_REPORT';
            $startDate = date('Y-m-01'); // First day of current month
            $endDate = date('Y-m-d', strtotime('-1 day')); // Yesterday
            break;
            
        case 'CEO_REPORT':
            $spReportType = 'SUMMARY_REPORT';
            $startDate = date('Y-m-01'); // First day of current month
            $endDate = date('Y-m-d', strtotime('-1 day')); // Yesterday
            break;
            
        default:
            return null;
    }
    
    // Rest of the function remains the same...
    // Call stored procedure
    if ($stmt = $conn->prepare("CALL PR_EMPLOYEE_ACTIVITY_test(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")) {
        $stmt->bind_param('ssssssssssss', 
            $startDate, 
            $endDate, 
            $ids, 
            $names, 
            $departments, 
            $roles, 
            $designations, 
            $projects, 
            $shifts, 
            $teams, 
            $userid, 
            $spReportType
        );
        
        if (!$stmt->execute()) {
            error_log("Stored procedure execution failed: " . $stmt->error);
            return null;
        }

        $result_data = $stmt->get_result();
        if ($result_data) {
            $fields = $result_data->fetch_fields();
            $columns = array_map(function($field) {
                return $field->name;
            }, $fields);

            while ($row = $result_data->fetch_assoc()) {
                $data[] = $row;
            }
            $result_data->free();
        }
        $stmt->close();
    } else {
        error_log("Failed to prepare stored procedure statement");
        return null;
    }
    
    // Process CEO Report - Calculate department-wise aggregations
    if ($reportType === 'CEO_REPORT' && !empty($data)) {
        $data = processCEOReport($data);
        $columns = [
            'Department',
            'Number of Employees',
            'Average Logged Hours',
            'Average Productive Hours',
            'Average Idle Hours',
            'Productive Employees Count',
            'Unproductive Employees Count'
        ];
    }
    
    return [
        'data' => $data,
        'columns' => $columns,
        'reportType' => $reportType,
        'startDate' => $startDate,
        'endDate' => $endDate
    ];
}
function processCEOReport($rawData) {
    $departmentStats = [];
    
    foreach ($rawData as $row) {
        $dept = $row['Department'] ?? 'Unknown';
        
        if (!isset($departmentStats[$dept])) {
            $departmentStats[$dept] = [
                'total_logged_hours' => 0,
                'total_productive_hours' => 0,
                'total_idle_hours' => 0,
                'employee_count' => 0,
                'productive_count' => 0,
                'unproductive_count' => 0
            ];
        }
        
        $loggedHours = parseTimeToHours($row['TotalLoggedHours'] ?? '0');
        $productiveHours = parseTimeToHours($row['TotalProductiveHours'] ?? '0');
        $idleHours = parseTimeToHours($row['TotalIdleHours'] ?? '0');
        
        $departmentStats[$dept]['total_logged_hours'] += $loggedHours;
        $departmentStats[$dept]['total_productive_hours'] += $productiveHours;
        $departmentStats[$dept]['total_idle_hours'] += $idleHours;
        $departmentStats[$dept]['employee_count']++;
        
        if ($productiveHours >= 7.5) {
            $departmentStats[$dept]['productive_count']++;
        } else {
            $departmentStats[$dept]['unproductive_count']++;
        }
    }
    
    $ceoReportData = [];
    foreach ($departmentStats as $dept => $stats) {
        $empCount = $stats['employee_count'];
        
        if ($empCount > 0) {
            $ceoReportData[] = [
                'Department' => $dept,
                'Number of Employees' => $empCount,
                'Average Logged Hours' => number_format($stats['total_logged_hours'] / $empCount, 2),
                'Average Productive Hours' => number_format($stats['total_productive_hours'] / $empCount, 2),
                'Average Idle Hours' => number_format($stats['total_idle_hours'] / $empCount, 2),
                'Productive Employees Count' => $stats['productive_count'],
                'Unproductive Employees Count' => $stats['unproductive_count']
            ];
        }
    }
    
    // Add Grand Total row
    $totalEmployees = array_sum(array_column($departmentStats, 'employee_count'));
    $totalLoggedHours = array_sum(array_column($departmentStats, 'total_logged_hours'));
    $totalProductiveHours = array_sum(array_column($departmentStats, 'total_productive_hours'));
    $totalIdleHours = array_sum(array_column($departmentStats, 'total_idle_hours'));
    $totalProductive = array_sum(array_column($departmentStats, 'productive_count'));
    $totalUnproductive = array_sum(array_column($departmentStats, 'unproductive_count'));
    
    if ($totalEmployees > 0) {
        $ceoReportData[] = [
            'Department' => 'Grand Total',
            'Number of Employees' => $totalEmployees,
            'Average Logged Hours' => number_format($totalLoggedHours / $totalEmployees, 2),
            'Average Productive Hours' => number_format($totalProductiveHours / $totalEmployees, 2),
            'Average Idle Hours' => number_format($totalIdleHours / $totalEmployees, 2),
            'Productive Employees Count' => $totalProductive,
            'Unproductive Employees Count' => $totalUnproductive
        ];
    }
    
    return $ceoReportData;
}

function parseTimeToHours($timeStr) {
    if (empty($timeStr) || $timeStr === '0' || $timeStr === '0:00:00') {
        return 0;
    }
    
    if (strpos($timeStr, ':') !== false) {
        $parts = explode(':', $timeStr);
        $hours = intval($parts[0]);
        $minutes = isset($parts[1]) ? intval($parts[1]) : 0;
        $seconds = isset($parts[2]) ? intval($parts[2]) : 0;
        
        return $hours + ($minutes / 60) + ($seconds / 3600);
    }
    
    return floatval($timeStr);
}

// NEW: Function to generate chart images for ALL report types
function generateChartImages($reportData, $reportType) {
    if (!isset($reportData['data']) || empty($reportData['data'])) {
        return null;
    }
    
    $data = $reportData['data'];
    $charts = [];
    
    // Different chart generation based on report type
    if ($reportType === 'CEO_REPORT') {
        // CEO Report: Department-wise charts
        $departments = [];
        $avgLoggedHours = [];
        $avgProductiveHours = [];
        $avgIdleHours = [];
        $productiveCounts = [];
        $unproductiveCounts = [];
        
        foreach ($data as $row) {
            if ($row['Department'] === 'Grand Total') continue;
            
            $departments[] = $row['Department'];
            $avgLoggedHours[] = floatval($row['Average Logged Hours']);
            $avgProductiveHours[] = floatval($row['Average Productive Hours']);
            $avgIdleHours[] = floatval($row['Average Idle Hours']);
            $productiveCounts[] = intval($row['Productive Employees Count']);
            $unproductiveCounts[] = intval($row['Unproductive Employees Count']);
        }
        
        if (!empty($departments)) {
            // Chart 1: Hours Analysis
            $chart1Config = [
                'type' => 'bar',
                'data' => [
                    'labels' => $departments,
                    'datasets' => [
                        [
                            'label' => 'Logged Hours',
                            'data' => $avgLoggedHours,
                            'backgroundColor' => 'rgba(54, 162, 235, 0.9)',
                            'borderColor' => 'rgba(54, 162, 235, 1)',
                            'borderWidth' => 2
                        ],
                        [
                            'label' => 'Productive Hours',
                            'data' => $avgProductiveHours,
                            'backgroundColor' => 'rgba(75, 192, 192, 0.9)',
                            'borderColor' => 'rgba(75, 192, 192, 1)',
                            'borderWidth' => 2
                        ],
                        [
                            'label' => 'Idle Hours',
                            'data' => $avgIdleHours,
                            'backgroundColor' => 'rgba(255, 99, 132, 0.9)',
                            'borderColor' => 'rgba(255, 99, 132, 1)',
                            'borderWidth' => 2
                        ]
                    ]
                ],
                'options' => [
                    'title' => [
                        'display' => true,
                        'text' => 'Department-wise Average Hours Analysis',
                        'fontSize' => 18,
                        'fontColor' => '#2c3e50',
                        'fontStyle' => 'bold'
                    ],
                    'scales' => [
                        'yAxes' => [[
                            'ticks' => ['beginAtZero' => true, 'fontSize' => 12],
                            'scaleLabel' => ['display' => true, 'labelString' => 'Hours', 'fontSize' => 14],
                            'gridLines' => ['color' => 'rgba(0, 0, 0, 0.1)']
                        ]],
                        'xAxes' => [[
                            'ticks' => ['fontSize' => 11],
                            'gridLines' => ['display' => false]
                        ]]
                    ],
                    'legend' => ['display' => true, 'position' => 'top', 'labels' => ['fontSize' => 13]]
                ]
            ];
            
            // Chart 2: Productivity Status
            $chart2Config = [
                'type' => 'bar',
                'data' => [
                    'labels' => $departments,
                    'datasets' => [
                        [
                            'label' => 'Productive (=7.5 hrs)',
                            'data' => $productiveCounts,
                            'backgroundColor' => 'rgba(46, 204, 113, 0.9)',
                            'borderColor' => 'rgba(46, 204, 113, 1)',
                            'borderWidth' => 2
                        ],
                        [
                            'label' => 'Unproductive (<7.5 hrs)',
                            'data' => $unproductiveCounts,
                            'backgroundColor' => 'rgba(231, 76, 60, 0.9)',
                            'borderColor' => 'rgba(231, 76, 60, 1)',
                            'borderWidth' => 2
                        ]
                    ]
                ],
                'options' => [
                    'title' => [
                        'display' => true,
                        'text' => 'Department-wise Productivity Status',
                        'fontSize' => 18,
                        'fontColor' => '#2c3e50',
                        'fontStyle' => 'bold'
                    ],
                    'scales' => [
                        'xAxes' => [[
                            'stacked' => true,
                            'ticks' => ['fontSize' => 11],
                            'gridLines' => ['display' => false]
                        ]],
                        'yAxes' => [[
                            'stacked' => true,
                            'ticks' => ['beginAtZero' => true, 'fontSize' => 12],
                            'scaleLabel' => ['display' => true, 'labelString' => 'Number of Employees', 'fontSize' => 14],
                            'gridLines' => ['color' => 'rgba(0, 0, 0, 0.1)']
                        ]]
                    ],
                    'legend' => ['display' => true, 'position' => 'top', 'labels' => ['fontSize' => 13]]
                ]
            ];
            
            $charts = generateChartsFromConfig([$chart1Config, $chart2Config], 'CEO');
        }
    } else {
        // For MONTHLY, GROUP, SUMMARY reports - generate summary charts
        $charts = generateSummaryCharts($data, $reportType);
    }
    
    return $charts;
}

// Helper function to generate summary charts matching dashboard
function generateSummaryCharts($data, $reportType) {
    $charts = [];
    
    // Different logic for different report types
    if ($reportType === 'GROUP_REPORT') {
        // GROUP_REPORT: Aggregate by Team/Department
        $charts = generateGroupChart($data);
    } else {
        // MONTHLY_REPORT and SUMMARY_REPORT: Show by date
        $charts = generateDateChart($data, $reportType);
    }
    
    return $charts;
}

// Generate chart for GROUP_REPORT (grouped by teams/departments)
// Replace the generateGroupChart function with this corrected version:
function generateGroupChart($data) {
    $charts = [];
    
    // Aggregate data by Department - Calculate AVERAGES per employee per day
    $deptData = [];
    
    foreach ($data as $row) {
        $dept = $row['Department'] ?? 'Unknown';
        
        if (!isset($deptData[$dept])) {
            $deptData[$dept] = [
                'total_logged' => 0,
                'total_idle' => 0,
                'total_productive' => 0,
                'total_on_system' => 0,
                'total_away' => 0,
                'employee_days' => 0  // Count employee-day combinations
            ];
        }
        
        $logged = parseTimeToHours($row['TotalLoggedHours'] ?? '0');
        $idle = parseTimeToHours($row['TotalIdleHours'] ?? '0');
        $productive = parseTimeToHours($row['TotalProductiveHours'] ?? '0');
        $onSystem = parseTimeToHours($row['TOTAL_ON_SYSTEM'] ?? '0');
        $away = parseTimeToHours($row['AwayFromSystem'] ?? '0');
        
        $deptData[$dept]['total_logged'] += $logged;
        $deptData[$dept]['total_idle'] += $idle;
        $deptData[$dept]['total_productive'] += $productive;
        $deptData[$dept]['total_on_system'] += $onSystem;
        $deptData[$dept]['total_away'] += $away;
        $deptData[$dept]['employee_days']++;
    }
    
    // Prepare chart data - Calculate AVERAGES
    $departments = [];
    $loggedHours = [];
    $idleHours = [];
    $productiveHours = [];
    $onSystemHours = [];
    $breakHours = [];
    
    foreach ($deptData as $dept => $metrics) {
        $empDays = $metrics['employee_days'];
        
        if ($empDays > 0) {
            $departments[] = $dept;
            // Calculate average per employee per day
            $loggedHours[] = round($metrics['total_logged'] / $empDays, 2);
            $idleHours[] = round($metrics['total_idle'] / $empDays, 2);
            $productiveHours[] = round($metrics['total_productive'] / $empDays, 2);
            $onSystemHours[] = round($metrics['total_on_system'] / $empDays, 2);
            $breakHours[] = round($metrics['total_away'] / $empDays, 2);
        }
    }
    
    if (empty($departments)) {
        return null;
    }
    
    // Calculate dynamic Y-axis max (should be reasonable now - around 10-12 hours max)
    $allValues = array_merge($loggedHours, $idleHours, $productiveHours, $onSystemHours, $breakHours);
    $maxHours = !empty($allValues) ? max($allValues) : 10;
    
    // Round up to nearest reasonable value
    if ($maxHours <= 10) {
        $yAxisMax = 10;
    } elseif ($maxHours <= 12) {
        $yAxisMax = 12;
    } else {
        $yAxisMax = ceil($maxHours);
    }
    
    // Simple grouped bar chart configuration
    $chartConfig = [
        'type' => 'bar',
        'data' => [
            'labels' => $departments,
            'datasets' => [
                [
                    'label' => 'LoggedHours',
                    'data' => $loggedHours,
                    'backgroundColor' => 'rgba(33, 150, 243, 0.8)',
                    'borderWidth' => 0
                ],
                [
                    'label' => 'IdleHours',
                    'data' => $idleHours,
                    'backgroundColor' => 'rgba(76, 175, 80, 0.8)',
                    'borderWidth' => 0
                ],
                [
                    'label' => 'ProductiveHours',
                    'data' => $productiveHours,
                    'backgroundColor' => 'rgba(255, 193, 7, 0.8)',
                    'borderWidth' => 0
                ],
                [
                    'label' => 'OnSystem',
                    'data' => $onSystemHours,
                    'backgroundColor' => 'rgba(156, 39, 176, 0.8)',
                    'borderWidth' => 0
                ],
                [
                    'label' => 'breaks',
                    'data' => $breakHours,
                    'backgroundColor' => 'rgba(244, 67, 54, 0.8)',
                    'borderWidth' => 0
                ]
            ]
        ],
        'options' => [
            'title' => [
                'display' => true,
                'text' => 'Group Report Overview',
                'fontSize' => 16,
                'fontColor' => '#333'
            ],
            'scales' => [
                'yAxes' => [[
                    'ticks' => [
                        'beginAtZero' => true,
                        'max' => $yAxisMax,
                        'stepSize' => 1,
                        'fontSize' => 11
                    ],
                    'scaleLabel' => [
                        'display' => true,
                        'labelString' => 'Time (Hours)',
                        'fontSize' => 12
                    ],
                    'gridLines' => ['color' => 'rgba(0, 0, 0, 0.05)']
                ]],
                'xAxes' => [[
                    'ticks' => [
                        'fontSize' => 10,
                        'autoSkip' => false
                    ],
                    'gridLines' => ['display' => false]
                ]]
            ],
            'legend' => [
                'display' => true,
                'position' => 'bottom',
                'labels' => [
                    'fontSize' => 10,
                    'usePointStyle' => true,
                    'padding' => 8
                ]
            ],
            'layout' => [
                'padding' => [
                    'left' => 10,
                    'right' => 10,
                    'top' => 10,
                    'bottom' => 10
                ]
            ]
        ]
    ];
    
    $charts = generateChartsFromConfig([$chartConfig], 'GROUP');
    
    return $charts;
}

// Generate chart for MONTHLY/SUMMARY reports (by date)
function generateDateChart($data, $reportType) {
    $charts = [];
    
    // Aggregate data by DATE
    $dateData = [];
    
    foreach ($data as $row) {
        $date = $row['Date'] ?? $row['date'] ?? $row['LOGIN_DATE'] ?? 'Unknown';
        
        if (!isset($dateData[$date])) {
            $dateData[$date] = [
                'total_productive' => 0,
                'total_idle' => 0,
                'employee_count' => 0
            ];
        }
        
        $productive = isset($row['TotalProductiveHours']) ? parseTimeToHours($row['TotalProductiveHours']) : 0;
        $idle = isset($row['TotalIdleHours']) ? parseTimeToHours($row['TotalIdleHours']) : 0;
        
        $dateData[$date]['total_productive'] += $productive;
        $dateData[$date]['total_idle'] += $idle;
        $dateData[$date]['employee_count']++;
    }
    
    // Sort by date
    ksort($dateData);
    
    // Calculate averages per date
    $dates = [];
    $productiveHours = [];
    $idleHours = [];
    
    foreach ($dateData as $date => $dayData) {
        $avgProductive = $dayData['employee_count'] > 0 ? $dayData['total_productive'] / $dayData['employee_count'] : 0;
        $avgIdle = $dayData['employee_count'] > 0 ? $dayData['total_idle'] / $dayData['employee_count'] : 0;
        
        $formattedDate = date('M d', strtotime($date));
        
        $dates[] = $formattedDate;
        $productiveHours[] = round($avgProductive, 2);
        $idleHours[] = round($avgIdle, 2);
    }
    
    if (empty($dates)) {
        return null;
    }
    
    // Calculate dynamic Y-axis max
    $maxHours = 0;
    foreach ($productiveHours as $index => $prod) {
        $total = $prod + $idleHours[$index];
        if ($total > $maxHours) {
            $maxHours = $total;
        }
    }
    
    $yAxisMax = ceil($maxHours / 2) * 2;
    if ($yAxisMax < 10) $yAxisMax = 10;
    
    // Chart configuration for date-based view (MONTHLY & SUMMARY)
    $chartConfig = [
        'type' => 'bar',
        'data' => [
            'labels' => $dates,
            'datasets' => [
                [
                    'label' => 'Productive Hours',
                    'data' => $productiveHours,
                    'backgroundColor' => 'rgba(144, 238, 144, 0.9)',
                    'borderColor' => 'rgba(144, 238, 144, 1)',
                    'borderWidth' => 1
                ],
                [
                    'label' => 'Idle Hours',
                    'data' => $idleHours,
                    'backgroundColor' => 'rgba(52, 152, 219, 0.9)',
                    'borderColor' => 'rgba(52, 152, 219, 1)',
                    'borderWidth' => 1
                ]
            ]
        ],
        'options' => [
            'title' => [
                'display' => true,
                'text' => getChartTitle($reportType),
                'fontSize' => 18,
                'fontColor' => '#2c3e50',
                'fontStyle' => 'bold'
            ],
            'scales' => [
                'yAxes' => [[
                    'stacked' => true,
                    'ticks' => [
                        'beginAtZero' => true, 
                        'max' => $yAxisMax,
                        'stepSize' => 2,
                        'fontSize' => 12
                    ],
                    'scaleLabel' => [
                        'display' => true, 
                        'labelString' => 'Hours (SQ Time)', 
                        'fontSize' => 14
                    ],
                    'gridLines' => ['color' => 'rgba(0, 0, 0, 0.1)']
                ]],
                'xAxes' => [[
                    'stacked' => true,
                    'ticks' => [
                        'fontSize' => 11,
                        'maxRotation' => 0,
                        'minRotation' => 0
                    ],
                    'gridLines' => ['display' => false]
                ]]
            ],
            'legend' => [
                'display' => true, 
                'position' => 'bottom', 
                'labels' => [
                    'fontSize' => 13,
                    'usePointStyle' => true
                ]
            ]
        ]
    ];
    
    $charts = generateChartsFromConfig([$chartConfig], $reportType);
    
    return $charts;
}
// Helper function to get chart title based on report type
function getChartTitle($reportType) {
    $titles = [
        'MONTHLY_REPORT' => 'Monthly Productivity Trend Analysis',
        'GROUP_REPORT' => 'Department-wise Productivity Comparison',
        'SUMMARY_REPORT' => 'Productivity Summary'
    ];
    
    return $titles[$reportType] ?? 'Productivity Analysis';
}

// Helper function to generate charts from config with white background
function generateChartsFromConfig($configs, $prefix) {
    $charts = [];
    $chartNames = [
        'Hours_Analysis',
        'Productivity_Status',
        'Employee_Overview'
    ];
    
    foreach ($configs as $index => $config) {
        // Fix: Change productivity label for CEO Report
        if ($prefix === 'CEO' && isset($config['data']['datasets'])) {
            foreach ($config['data']['datasets'] as &$dataset) {
                if (strpos($dataset['label'], 'Productive (=7.5 hrs)') !== false) {
                    $dataset['label'] = 'Productive (=7.5 hrs)';
                }
            }
        }
        
        // Add white background
        $config['options']['plugins'] = [
            'backgroundcolor' => [
                'color' => '#FFFFFF'
            ]
        ];
        
        // Generate chart with white background and JPG format
        $chartUrl = 'https://quickchart.io/chart?width=1000&height=600&format=jpg&backgroundColor=white&c=' . urlencode(json_encode($config));
        $chartImage = @file_get_contents($chartUrl);
        
        if ($chartImage !== false) {
            $chartName = $chartNames[$index] ?? 'Chart_' . ($index + 1);
            $charts[] = [
                'name' => $prefix . '_' . $chartName . '_' . date('Y-m-d') . '.jpg',
                'data' => $chartImage
            ];
        }
    }
    
    return $charts;
}
function generateCSVFromProcedure($reportData) {
    if (!isset($reportData['data']) || !isset($reportData['columns'])) {
        return null;
    }
    
    $data = $reportData['data'];
    $columns = $reportData['columns'];
    
    if (empty($data) || empty($columns)) {
        return null;
    }
    
    $csv = '';
    
    $csv .= implode(',', array_map(function($col) {
        return '"' . str_replace('"', '""', $col) . '"';
    }, $columns)) . "\n";
    
    foreach ($data as $row) {
        $rowData = [];
        foreach ($columns as $col) {
            $value = isset($row[$col]) ? $row[$col] : '';
            $rowData[] = '"' . str_replace('"', '""', $value) . '"';
        }
        $csv .= implode(',', $rowData) . "\n";
    }
    
    return $csv;
}

function getEmailSubject($reportType) {
    $date = date('d-M-Y');
    
    $subjects = [
        'CEO_REPORT' => "PMS Report | Organizational Productivity Snapshot | $date",
        'GROUP_REPORT' => "PMS Report | Department-wise Comparison | $date",
        'MONTHLY_REPORT' => "PMS Report | Monthly Productivity View | $date",
        'SUMMARY_REPORT' => "PMS Report | Weekly Summary View | $date"
    ];
    
    return $subjects[$reportType] ?? "PMS Report | Productivity Analysis | $date";
}

function generateEmailBody($reportData, $reportType, $charts = null) {
    $reportNames = [
        'CEO_REPORT' => 'Organizational Productivity Snapshot',
        'GROUP_REPORT' => 'Department-wise Productivity Comparison',
        'MONTHLY_REPORT' => 'Monthly Productivity View',
        'SUMMARY_REPORT' => 'Weekly Summary View'
    ];
    
    $reportName = $reportNames[$reportType] ?? 'Productivity Report';
    $currentDateTime = date('d M Y, h:i A');
    $recordCount = count($reportData['data']);
    $startDate = date('d M Y', strtotime($reportData['startDate']));
    $endDate = date('d M Y', strtotime($reportData['endDate']));
    
    // Custom descriptions based on report type
    $reportDescription = getReportDescription($reportType, $startDate, $endDate);
    
    // Build chart images section
    $chartSection = '';
    if ($charts && !empty($charts)) {
        $chartSection = '<div class="charts-section">';
        $chartSection .= '<h3 style="color: #2c3e50; margin-top: 30px; border-bottom: 2px solid #667eea; padding-bottom: 10px;"> Visual Analytics</h3>';
        
        foreach ($charts as $index => $chart) {
            $cid = 'chart_' . $index;
            $chartSection .= '<div style="margin: 25px 0; text-align: center; background: white; padding: 15px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">';
            $chartSection .= '<img src="cid:' . $cid . '" alt="' . $chart['name'] . '" style="max-width: 100%; height: auto; border: 1px solid #e0e0e0; border-radius: 4px;">';
            $chartSection .= '</div>';
        }
        
        $chartSection .= '</div>';
    }
    
    // Key observations section
    $observationsSection = getObservationsSection($reportType, $reportData);
    
    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <style>
            body { 
                font-family: "Segoe UI", Arial, sans-serif; 
                line-height: 1.6;
                color: #333;
                margin: 0;
                padding: 0;
                background-color: #f5f5f5;
            }
            .container {
                max-width: 900px;
                margin: 20px auto;
                background-color: white;
                border-radius: 12px;
                overflow: hidden;
                box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            }
            .header { 
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white; 
                padding: 35px 30px; 
                text-align: center;
            }
            .header h1 {
                margin: 0 0 8px 0;
                font-size: 26px;
                font-weight: 600;
            }
            .header p {
                margin: 0;
                font-size: 14px;
                opacity: 0.9;
            }
            .content { 
                padding: 35px 30px;
            }
            .greeting {
                font-size: 16px;
                margin-bottom: 20px;
            }
            .info-box {
                background: linear-gradient(135deg, #f6f9fc 0%, #eef2f7 100%);
                padding: 20px;
                margin: 20px 0;
                border-left: 4px solid #667eea;
                border-radius: 6px;
            }
            .info-row {
                display: flex;
                padding: 8px 0;
                border-bottom: 1px solid rgba(102, 126, 234, 0.1);
            }
            .info-row:last-child {
                border-bottom: none;
            }
            .info-label {
                font-weight: 600;
                color: #667eea;
                min-width: 140px;
            }
            .info-value {
                color: #333;
            }
            .description {
                background-color: #fff9e6;
                border-left: 4px solid #ffc107;
                padding: 15px;
                margin: 20px 0;
                border-radius: 4px;
                font-size: 14px;
            }
            .observations-box {
                background-color: #e8f5e9;
                border-left: 4px solid #4caf50;
                padding: 20px;
                margin: 25px 0;
                border-radius: 6px;
            }
            .observations-box h3 {
                margin: 0 0 15px 0;
                color: #2e7d32;
                font-size: 18px;
            }
            .observations-box ul {
                margin: 10px 0;
                padding-left: 20px;
            }
            .observations-box li {
                margin: 8px 0;
                color: #1b5e20;
            }
            .charts-section {
                margin-top: 30px;
            }
            .attachment-note {
                background-color: #e3f2fd;
                border-left: 4px solid #2196f3;
                padding: 15px;
                margin: 20px 0;
                border-radius: 4px;
            }
            .footer {
                background-color: #f8f9fa;
                text-align: center;
                padding: 25px;
                border-top: 1px solid #e0e0e0;
            }
            .footer p {
                margin: 5px 0;
                color: #666;
                font-size: 13px;
            }
            .signature {
                margin-top: 30px;
                padding-top: 20px;
                border-top: 2px solid #f0f0f0;
            }
            .button {
                display: inline-block;
                padding: 12px 24px;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                text-decoration: none;
                border-radius: 6px;
                margin: 15px 0;
                font-weight: 600;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1>' . $reportName . '</h1>
                <p>Automated Productivity Monitoring System</p>
            </div>
            
            <div class="content">
                <div class="greeting">
                    <p>Hello,</p>
                    <p>Your automated productivity report has been generated and is ready for review.</p>
                </div>
                
                <div class="info-box">
                    <div class="info-row">
                        <span class="info-label"> Report Type:</span>
                        <span class="info-value">' . $reportName . '</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label"> Generated On:</span>
                        <span class="info-value">' . $currentDateTime . ' IST</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label"> Report Period:</span>
                        <span class="info-value">' . $startDate . ' to ' . $endDate . '</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label"> Total Records:</span>
                        <span class="info-value">' . $recordCount . '</span>
                    </div>
                </div>
                
                <div class="description">
                    <strong> About This Report:</strong><br>
                    ' . $reportDescription . '
                </div>
                
                ' . $observationsSection . '
                
                <div class="attachment-note">
                    <strong> CSV Attachment:</strong> The detailed data is available in the attached CSV file for further analysis and record-keeping.
                </div>
                
                ' . $chartSection . '
                
                <div class="signature">
                    <p>If you have any questions or need assistance interpreting this report, please contact your system administrator.</p>
                    
                    <p style="margin-top: 25px;">
                        <strong>Best regards,</strong><br>
                        Productivity Monitoring System Team
                    </p>
                </div>
            </div>
            
            <div class="footer">
                <p><strong>? Automated Email Notice</strong></p>
                <p>This is an automated email. Please do not reply to this message.</p>
                <p style="margin-top: 15px;"> ' . date('Y') . ' Productivity Monitoring System. All rights reserved.</p>
            </div>
        </div>
    </body>
    </html>';
    
    return $html;

}
function getReportDescription($reportType, $startDate, $endDate) {
    $descriptions = [
        'CEO_REPORT' => "This executive summary provides a high-level overview of productivity metrics across all departments. The report aggregates data by department, showing average hours and employee productivity distribution to help identify organizational trends and areas requiring attention.",
        
        'GROUP_REPORT' => "This report presents a <strong>department-wise comparison</strong> of productivity metrics. It shows average daily hours per employee across different departments, allowing you to compare performance, identify high-performing teams, and spot departments that may need support or resources.",
        
        'MONTHLY_REPORT' => "This monthly view displays <strong>day-by-day productivity trends</strong> for the reporting period ($startDate to $endDate). The data represents average daily hours across all tracked employees, helping you identify patterns, peak productivity days, and potential issues over the month.",
        
        'SUMMARY_REPORT' => "This weekly summary provides a <strong>consolidated view of productivity patterns</strong> over the past week. It shows daily averages across your team, making it easy to spot weekly trends and plan for upcoming work periods."
    ];
    
    return $descriptions[$reportType] ?? "This report provides detailed productivity analytics for the specified period.";
}

function getObservationsSection($reportType, $reportData) {
    if (!isset($reportData['data']) || empty($reportData['data'])) {
        return '';
    }
    
    $observations = [];
    
    switch ($reportType) {
        case 'CEO_REPORT':
            $observations = generateCEOObservations($reportData['data']);
            break;
        case 'GROUP_REPORT':
            $observations = generateGroupObservations($reportData['data']);
            break;
        case 'MONTHLY_REPORT':
        case 'SUMMARY_REPORT':
            $observations = generateMonthlyObservations($reportData['data']);
            break;
    }
    
    if (empty($observations)) {
        return '';
    }
    
    $html = '<div class="observations-box">';
    $html .= '<h3> Key Observations</h3>';
    $html .= '<ul>';
    foreach ($observations as $observation) {
        $html .= '<li>' . $observation . '</li>';
    }
    $html .= '</ul>';
    $html .= '</div>';
    
    return $html;
}

function generateCEOObservations($data) {
    $observations = [];
    $totalDepts = 0;
    $productiveDepts = 0;
    
    foreach ($data as $row) {
        if ($row['Department'] === 'Grand Total') continue;
        
        $totalDepts++;
        $avgProductive = floatval($row['Average Productive Hours']);
        
        if ($avgProductive >= 7.5) {
            $productiveDepts++;
        }
    }
    
    if ($totalDepts > 0) {
        $observations[] = "Analysis covers <strong>$totalDepts departments</strong> across the organization.";
        $observations[] = "<strong>$productiveDepts out of $totalDepts departments</strong> are meeting or exceeding the productivity threshold of 7.5 hours.";
        
        $productivityRate = round(($productiveDepts / $totalDepts) * 100, 1);
        
        if ($productivityRate >= 80) {
            $observations[] = "Overall organizational productivity is <strong>strong at {$productivityRate}%</strong> of departments meeting targets.";
        } elseif ($productivityRate >= 60) {
            $observations[] = "Organizational productivity is <strong>moderate at {$productivityRate}%</strong>. Some departments may benefit from additional support.";
        } else {
            $observations[] = "Organizational productivity requires attention at <strong>{$productivityRate}%</strong>. Consider reviewing department-specific challenges.";
        }
    }
    
    return $observations;
}

function generateGroupObservations($data) {
    $observations = [];
    $deptCount = 0;
    $highPerformers = [];
    $needsAttention = [];
    
    // Group by department and calculate averages
    $deptMetrics = [];
    foreach ($data as $row) {
        $dept = $row['Department'] ?? 'Unknown';
        
        if (!isset($deptMetrics[$dept])) {
            $deptMetrics[$dept] = [
                'total_productive' => 0,
                'count' => 0
            ];
        }
        
        $productive = parseTimeToHours($row['TotalProductiveHours'] ?? '0');
        $deptMetrics[$dept]['total_productive'] += $productive;
        $deptMetrics[$dept]['count']++;
    }
    
    // Analyze departments
    foreach ($deptMetrics as $dept => $metrics) {
        if ($metrics['count'] > 0) {
            $avgProductive = $metrics['total_productive'] / $metrics['count'];
            $deptCount++;
            
            if ($avgProductive >= 7.5) {
                $highPerformers[] = $dept;
            } elseif ($avgProductive < 6.0) {
                $needsAttention[] = $dept;
            }
        }
    }
    
    $observations[] = "This report compares <strong>$deptCount departments</strong> based on average daily productivity per employee.";
    
    if (!empty($highPerformers)) {
        $deptList = implode(', ', array_slice($highPerformers, 0, 3));
        $observations[] = "<strong>High performing departments:</strong> $deptList" . (count($highPerformers) > 3 ? ' and others' : '') . " are averaging 7.5+ productive hours per employee.";
    }
    
    if (!empty($needsAttention)) {
        $deptList = implode(', ', array_slice($needsAttention, 0, 2));
        $observations[] = "<strong>Departments needing attention:</strong> $deptList" . (count($needsAttention) > 2 ? ' and others' : '') . " are below 6 hours average productivity.";
    }
    
    return $observations;
}

function generateMonthlyObservations($data) {
    $observations = [];
    
    // Calculate date-wise averages
    $dateMetrics = [];
    foreach ($data as $row) {
        $date = $row['Date'] ?? $row['date'] ?? $row['LOGIN_DATE'] ?? 'Unknown';
        
        if (!isset($dateMetrics[$date])) {
            $dateMetrics[$date] = [
                'total_productive' => 0,
                'count' => 0
            ];
        }
        
        $productive = parseTimeToHours($row['TotalProductiveHours'] ?? '0');
        $dateMetrics[$date]['total_productive'] += $productive;
        $dateMetrics[$date]['count']++;
    }
    
    $dailyAverages = [];
    foreach ($dateMetrics as $date => $metrics) {
        if ($metrics['count'] > 0) {
            $dailyAverages[$date] = $metrics['total_productive'] / $metrics['count'];
        }
    }
    
    if (!empty($dailyAverages)) {
        $avgProductivity = array_sum($dailyAverages) / count($dailyAverages);
        $maxDay = array_keys($dailyAverages, max($dailyAverages))[0];
        $minDay = array_keys($dailyAverages, min($dailyAverages))[0];
        
        $observations[] = "Report covers <strong>" . count($dailyAverages) . " working days</strong> with data from multiple employees per day.";
        $observations[] = "Average daily productivity across the period: <strong>" . number_format($avgProductivity, 2) . " hours per employee</strong>.";
        
        if ($avgProductivity >= 7.5) {
            $observations[] = "Overall productivity trend is <strong>positive</strong>, meeting organizational targets.";
        } elseif ($avgProductivity >= 6.5) {
            $observations[] = "Productivity is <strong>approaching targets</strong>. Minor improvements could achieve optimal performance.";
        } else {
            $observations[] = "Productivity is <strong>below targets</strong>. Review daily patterns to identify improvement opportunities.";
        }
        
        $observations[] = "Highest productivity: <strong>" . date('M d', strtotime($maxDay)) . "</strong> | Lowest: <strong>" . date('M d', strtotime($minDay)) . "</strong>";
    }
    
    return $observations;
}


$conn->close();
?>