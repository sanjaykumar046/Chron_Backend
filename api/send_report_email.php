<?php
ob_start(); // Start output buffering to catch any errors
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '/var/www/html/api/email_error.log');

include 'apiMain.php';
require 'vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

date_default_timezone_set('Asia/Kolkata');
$conn->query("SET time_zone = '+05:30'");

$timeout_sql = "UPDATE EMAIL_SCHEDULES 
                SET status = 'idle' 
                WHERE status = 'sending' 
                AND TIMESTAMPDIFF(MINUTE, last_sent, NOW()) >= 10";
$timeout_stmt = $conn->prepare($timeout_sql);
$timeout_stmt->execute();
$affected_rows = $timeout_stmt->affected_rows;
if ($affected_rows > 0) {
    error_log("Auto-reset $affected_rows stuck schedules from 'sending' to 'idle' (timeout: 10 minutes)");
}
$timeout_stmt->close();

header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
$schedule_id = $data['schedule_id'];
$test_mode = $data['test_mode'] ?? false;
$userid = $data['userid'] ?? ''; 

// Fetch schedule details
$sql = "SELECT * FROM EMAIL_SCHEDULES WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $schedule_id);
$stmt->execute();
$schedule = $stmt->get_result()->fetch_assoc(); 
   
if (!$schedule) {
    echo json_encode(['success' => false, 'message' => 'Schedule not found']);
    exit;
}

// Get recipient configuration
$recipientConfigs = [];

if ($schedule['recipient_type'] == 'email') {
    // NEW: Check if recipient_config exists (new format with email + empid mapping)
    if (!empty($schedule['recipient_config'])) {
        $recipientConfigs = json_decode($schedule['recipient_config'], true);

        // Validate JSON decode
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("Failed to decode recipient_config: " . json_last_error_msg());
            // Fallback to legacy format
            $emails = array_map('trim', explode(',', $schedule['recipients']));
            foreach ($emails as $email) {
                $recipientConfigs[] = [
                    'email' => $email,
                    'empid' => $schedule['target_empid'] ?? $userid
                ];
            }
        }
    } else {
        // Legacy format: recipients column with target_empid
        $emails = array_map('trim', explode(',', $schedule['recipients']));
        foreach ($emails as $email) {
            $recipientConfigs[] = [
                'email' => $email,
                'empid' => $schedule['target_empid'] ?? $userid
            ];
        }
    }
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
        $recipientConfigs[] = [
            'email' => $row['EMAIL'],
            'empid' => $schedule['target_empid'] ?? $userid
        ];
    }
}

if (empty($recipientConfigs)) {
    echo json_encode(['success' => false, 'message' => 'No recipients found']);
    exit;
}

$mail = new PHPMailer(true);
$mail->isSMTP();
$mail->SMTPDebug = 0;
$mail->Host = 'smtp.gmail.com';
$mail->SMTPAuth = true;
$mail->Username = 'spritha888@gmail.com';
$mail->Password = 'uxzm qbbq jpkf saur';
$mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
$mail->Port = 587;
$mail->Timeout = 30;

// SSL Options
$mail->SMTPOptions = array(
    'ssl' => array(
        'verify_peer' => false,
        'verify_peer_name' => false,
        'allow_self_signed' => true
    )
);

// Email encoding
$mail->CharSet = 'UTF-8';
$mail->Encoding = 'base64';

// Sender
$mail->setFrom('spritha888@gmail.com', 'Productivity Report System');


// Track success/failure
$successCount = 0;
$failedRecipients = [];

// BATCH PROCESSING: Get batch settings from schedule
$BATCH_SIZE = intval($schedule['batch_size'] ?? 50);
$batch_offset = intval($schedule['batch_offset'] ?? 0);
$totalRecipients = count($recipientConfigs);

// Calculate current batch
$currentBatch = array_slice($recipientConfigs, $batch_offset, $BATCH_SIZE);
$batchEndIndex = $batch_offset + count($currentBatch);

error_log("=== BATCH PROCESSING ===");
error_log("Schedule ID: {$schedule['id']}");
error_log("Total Recipients: $totalRecipients");
error_log("Batch Size: $BATCH_SIZE");
error_log("Current Offset: $batch_offset");
error_log("Processing: $batch_offset to $batchEndIndex");
error_log("Remaining after this batch: " . ($totalRecipients - $batchEndIndex));

// SEND INDIVIDUAL EMAILS TO EACH RECIPIENT IN CURRENT BATCH
foreach ($currentBatch as $recipientConfig) {
    $recipientEmail = $recipientConfig['email'];
    $targetEmpId = $recipientConfig['empid'];

    error_log("Processing email for: $recipientEmail with empid: $targetEmpId");

    try {
        // Generate report data for THIS specific employee
        $reportData = generateReportDataFromDB($schedule['report_type'], $conn, $targetEmpId);

        if (!$reportData || !isset($reportData['data']) || empty($reportData['data'])) {
            error_log("No data available for employee: $targetEmpId");
            $failedRecipients[] = [
                'email' => $recipientEmail,
                'reason' => 'No data available for employee ' . $targetEmpId
            ];
            continue; // Skip this recipient
        }

        // Clear previous recipients and add current one
        $mail->clearAddresses();
        $mail->addAddress($recipientEmail);

        // Clear previous attachments
        $mail->clearAttachments();

        // Email content
        $mail->isHTML(true);
        $mail->Subject = getEmailSubject($schedule['report_type']);

        // Generate charts for this employee's data
        $charts = generateChartImages($reportData, $schedule['report_type']);

        // Generate email body with embedded chart CIDs
        $mail->Body = generateEmailBody($reportData, $schedule['report_type'], $charts);

        // Generate and attach CSV
        if ($schedule['report_type'] === 'CEO_REPORT' && isset($reportData['isSplitByShift'])) {
            $csvDataCombined = generateCombinedShiftCSV($reportData);
            if ($csvDataCombined) {
                $filename = 'CEO_Report_' . $targetEmpId . '_' . date('Y-m-d_His') . '.csv';
                $mail->addStringAttachment($csvDataCombined, $filename);
            }
        } else {
            $csvData = generateCSVFromProcedure($reportData);
            if ($csvData) {
                $filename = $schedule['report_type'] . '_' . $targetEmpId . '_' . date('Y-m-d_His') . '.csv';
                $mail->addStringAttachment($csvData, $filename);
            }
        }

        // Embed charts in email body using CID
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

        // Send email to this individual recipient
        $mail->send();
        $successCount++;

        error_log("Email sent successfully to: $recipientEmail");

        // Small delay between emails to avoid rate limiting
        usleep(500000); // 0.5 second delay

    } catch (Exception $e) {
        error_log("Failed to send email to $recipientEmail: " . $e->getMessage());
        $failedRecipients[] = [
            'email' => $recipientEmail,
            'reason' => $e->getMessage()
        ];
    }
}

// Update schedule after batch is processed
if (!$test_mode) {
    if ($successCount > 0) {
        // Calculate new offset
        $new_offset = $batch_offset + $successCount;

        // Check if there are more batches remaining
        if ($new_offset < $totalRecipients) {
            // MORE BATCHES REMAINING: Keep schedule active for next cron run
            $sql = "UPDATE EMAIL_SCHEDULES SET last_sent = NOW(), batch_offset = ?, status = 'idle' WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ii', $new_offset, $schedule_id);
            $stmt->execute();

            $remaining = $totalRecipients - $new_offset;
            error_log("=== BATCH COMPLETE ===");
            error_log("Schedule $schedule_id: Sent $successCount emails in this batch");
            error_log("Progress: $new_offset of $totalRecipients sent ($remaining remaining)");
            error_log("Status: 'idle' - Will continue in next cron run");
        } else {
            // ALL BATCHES COMPLETE: Schedule for next period
            $send_time = $schedule['send_time'];
            $next_send_date = date('Y-m-d', strtotime("+{$schedule['frequency_days']} days"));
            $next_send = $next_send_date . ' ' . $send_time;

            $sql = "UPDATE EMAIL_SCHEDULES SET last_sent = NOW(), next_send = ?, batch_offset = 0, status = 'idle' WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('si', $next_send, $schedule_id);
            $stmt->execute();

            error_log("=== ALL BATCHES COMPLETE ===");
            error_log("Schedule $schedule_id: All $totalRecipients emails sent successfully!");
            error_log("Status: 'idle', batch_offset reset to 0");
            error_log("Next send: $next_send");
        }
    } else {
        // FAILED: No emails sent successfully in this batch
        $sql = "UPDATE EMAIL_SCHEDULES SET status = 'failed' WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $schedule_id);
        $stmt->execute();
        error_log("Schedule $schedule_id failed. Status set to 'failed'. No emails sent successfully in batch.");
    }
}

// Return comprehensive result
$response = [
    'success' => $successCount > 0,
    'message' => "Sent $successCount out of " . count($currentBatch) . " emails in this batch",
    'sent_at_ist' => date('Y-m-d H:i:s'),
    'batch_info' => [
        'total_recipients' => $totalRecipients,
        'batch_size' => $BATCH_SIZE,
        'batch_offset' => $batch_offset,
        'processed_in_batch' => count($currentBatch),
        'successful_in_batch' => $successCount,
        'failed_in_batch' => count($failedRecipients),
        'total_sent_so_far' => $batch_offset + $successCount,
        'remaining' => max(0, $totalRecipients - ($batch_offset + $successCount)),
        'is_complete' => ($batch_offset + $successCount) >= $totalRecipients
    ],
    'smtp_host' => $mail->Host
];

if (!empty($failedRecipients)) {
    $response['failed_recipients'] = $failedRecipients;
}

echo json_encode($response);

function generateReportDataFromDB($reportType, $conn, $userid) {
    $endDate = date('Y-m-d');
    $startDate = date('Y-m-d', strtotime('-60 days'));

    if (in_array($reportType, ['MONTHLY_EXPORT', 'MONTHLY_EXPORT', 'GROUP_REPORT', 'SUMMARY_REPORT', 'CEO_REPORT'])) {
        $startDate = date('Y-m-01');
        $endDate = date('Y-m-d');
    }
    
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
    
    $spReportType = '';
    switch ($reportType) {
        case 'MONTHLY_EXPORT':
        case 'MONTHLY_EXPORT':  // Support legacy format from database
            $spReportType = 'MONTHLY_EXPORT';
            break;
        case 'GROUP_REPORT':
            $spReportType = 'GROUP_REPORT';
            break;
        case 'SUMMARY_REPORT':
            $spReportType = 'SUMMARY_REPORT';
            break;
        case 'CEO_REPORT':
            $spReportType = 'SUMMARY_REPORT';
            break;
        default:
            error_log("Invalid report type: $reportType");
            return null;
    }
    
    error_log("=== STORED PROCEDURE CALL ===");
    error_log("Input reportType: $reportType");
    error_log("Converted spReportType: $spReportType");
    error_log("Dates: $startDate to $endDate");
    error_log("Employee ID (userid): $userid");

    try {
        if ($stmt = $conn->prepare("CALL PR_EMPLOYEE_ACTIVITY_FLAT(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")) {
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
            
            $rowCount = count($data);
            error_log("Stored procedure returned $rowCount rows");
            
        } else {
            error_log("Failed to prepare stored procedure statement: " . $conn->error);
            return null;
        }
    } catch (Exception $e) {
        error_log("Exception in stored procedure call: " . $e->getMessage());
        return null;
    }
    
    if (empty($data)) {
        error_log("No data returned from stored procedure");
        return null;
    }
    
    // Process CEO Report - NOW RETURNS BOTH DAY AND NIGHT SHIFTS
    if ($reportType === 'CEO_REPORT' && !empty($data)) {
        // Make TWO separate calls to get Day and Night shift data
        $dayData = [];
        $nightData = [];

        // Call stored procedure for DAY shift
        if ($dayStmt = $conn->prepare("CALL PR_EMPLOYEE_ACTIVITY_FLAT(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")) {
            $dayShift = 'DAY';
            $dayStmt->bind_param('ssssssssssss',
                $startDate,
                $endDate,
                $ids,
                $names,
                $departments,
                $roles,
                $designations,
                $projects,
                $dayShift,  // Pass 'DAY' instead of 'ALL'
                $teams,
                $userid,
                $spReportType
            );

            if ($dayStmt->execute()) {
                $dayResult = $dayStmt->get_result();
                if ($dayResult) {
                    while ($row = $dayResult->fetch_assoc()) {
                        $dayData[] = $row;
                    }
                    $dayResult->free();
                }
            }
            $dayStmt->close();
        }

        // Call stored procedure for NIGHT shift
        if ($nightStmt = $conn->prepare("CALL PR_EMPLOYEE_ACTIVITY_FLAT(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")) {
            $nightShift = 'NIGHT';
            $nightStmt->bind_param('ssssssssssss',
                $startDate,
                $endDate,
                $ids,
                $names,
                $departments,
                $roles,
                $designations,
                $projects,
                $nightShift,  // Pass 'NIGHT' instead of 'ALL'
                $teams,
                $userid,
                $spReportType
            );

            if ($nightStmt->execute()) {
                $nightResult = $nightStmt->get_result();
                if ($nightResult) {
                    while ($row = $nightResult->fetch_assoc()) {
                        $nightData[] = $row;
                    }
                    $nightResult->free();
                }
            }
            $nightStmt->close();
        }

        // Process each shift's data separately
        $dayReport = calculateShiftReport($dayData, 'DAY');
        $nightReport = calculateShiftReport($nightData, 'NIGHT');

        $processedData = [
            'day' => $dayReport,
            'night' => $nightReport
        ];

        return [
            'data' => $processedData, // Contains 'day' and 'night' arrays
            'columns' => [
                'Department',
                'Average Logged Hours',
                'Average Productive Hours',
                'Average Idle Hours'
            ],
            'reportType' => $reportType,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'isSplitByShift' => true // Flag to indicate split data
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
// Removed processCEOReport - now handled directly in generateReportDataFromDB

function calculateShiftReport($shiftData, $shiftName) {
    if (empty($shiftData)) {
        return [];
    }
    
    $departmentStats = [];
    
    foreach ($shiftData as $row) {
        $dept = $row['Department'] ?? 'Unknown';
        
        if (!isset($departmentStats[$dept])) {
            $departmentStats[$dept] = [
                'total_logged_hours' => 0,
                'total_productive_hours' => 0,
                'total_idle_hours' => 0,
                'employee_count' => 0
            ];
        }
        
        $loggedHours = parseTimeToHours($row['TotalLoggedHours'] ?? '0');
        $productiveHours = parseTimeToHours($row['TotalProductiveHours'] ?? '0');
        $idleHours = parseTimeToHours($row['TotalIdleHours'] ?? '0');
        
        $departmentStats[$dept]['total_logged_hours'] += $loggedHours;
        $departmentStats[$dept]['total_productive_hours'] += $productiveHours;
        $departmentStats[$dept]['total_idle_hours'] += $idleHours;
        $departmentStats[$dept]['employee_count']++;
    }
    
    $reportData = [];
    foreach ($departmentStats as $dept => $stats) {
        $empCount = $stats['employee_count'];
        
        if ($empCount > 0) {
            $avgLogged = $stats['total_logged_hours'] / $empCount;
            $avgProductive = $stats['total_productive_hours'] / $empCount;
            $avgIdle = $stats['total_idle_hours'] / $empCount;
            
            $reportData[] = [
                'Department' => $dept,
                'Average Logged Hours' => hoursToHHMMSS($avgLogged),
                'Average Productive Hours' => hoursToHHMMSS($avgProductive),
                'Average Idle Hours' => hoursToHHMMSS($avgIdle)
            ];
        }
    }
    
    // Add Grand Total row
    $totalEmployees = array_sum(array_column($departmentStats, 'employee_count'));
    $totalLoggedHours = array_sum(array_column($departmentStats, 'total_logged_hours'));
    $totalProductiveHours = array_sum(array_column($departmentStats, 'total_productive_hours'));
    $totalIdleHours = array_sum(array_column($departmentStats, 'total_idle_hours'));
    
    if ($totalEmployees > 0) {
        $reportData[] = [
            'Department' => 'Grand Total',
            'Average Logged Hours' => hoursToHHMMSS($totalLoggedHours / $totalEmployees),
            'Average Productive Hours' => hoursToHHMMSS($totalProductiveHours / $totalEmployees),
            'Average Idle Hours' => hoursToHHMMSS($totalIdleHours / $totalEmployees)
        ];
    }
    
    return $reportData;
}


// Add this new helper function to convert hours to HH:MM:SS format:
function hoursToHHMMSS($hours) {
    $hours = floatval($hours);
    $h = floor($hours);
    $m = floor(($hours - $h) * 60);
    $s = floor((($hours - $h) * 60 - $m) * 60);
    
    return sprintf('%02d:%02d:%02d', $h, $m, $s);
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
    
    $charts = [];
    
    if ($reportType === 'CEO_REPORT') {
        // Use DAY shift data for charts (or you can create separate charts for both)
        $data = [];
        
        if (isset($reportData['isSplitByShift']) && $reportData['isSplitByShift']) {
            $data = $reportData['data']['day'] ?? [];
        } else {
            $data = $reportData['data'];
        }
        
        if (empty($data)) {
            return null;
        }
        
        // Rest of the CEO chart generation code remains the same
        $departments = [];
        $avgLoggedHours = [];
        $avgProductiveHours = [];
        $avgIdleHours = [];
        
        foreach ($data as $row) {
            if ($row['Department'] === 'Grand Total') continue;
            
            $departments[] = $row['Department'];
            $avgLoggedHours[] = parseTimeToHours($row['Average Logged Hours']);
            $avgProductiveHours[] = parseTimeToHours($row['Average Productive Hours']);
            $avgIdleHours[] = parseTimeToHours($row['Average Idle Hours']);
        }
        
        if (!empty($departments)) {
            $chartConfig = [
                'type' => 'bar',
                'data' => [
                    'labels' => $departments,
                    'datasets' => [
                        [
                            'label' => 'Logged Hours',
                            'data' => $avgLoggedHours,
                            'backgroundColor' => 'rgba(54, 162, 235, 0.9)'
                        ],
                        [
                            'label' => 'Productive Hours',
                            'data' => $avgProductiveHours,
                            'backgroundColor' => 'rgba(75, 192, 192, 0.9)'
                        ],
                        [
                            'label' => 'Idle Hours',
                            'data' => $avgIdleHours,
                            'backgroundColor' => 'rgba(255, 99, 132, 0.9)'
                        ]
                    ]
                ],
                'options' => [
                    'title' => [
                        'display' => true,
                        'text' => 'Department-wise Average Hours Analysis (Day Shift)',
                        'fontSize' => 18
                    ],
                    'scales' => [
                        'yAxes' => [[
                            'ticks' => ['beginAtZero' => true],
                            'scaleLabel' => ['display' => true, 'labelString' => 'Hours']
                        ]]
                    ]
                ]
            ];
            
            $charts = generateChartsFromConfig([$chartConfig], 'CEO');
        }
    } else {
        $charts = generateSummaryCharts($reportData['data'], $reportType);
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
        // MONTHLY_EXPORT and SUMMARY_REPORT: Show by date
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
        'MONTHLY_EXPORT' => 'Monthly Productivity Trend Analysis',
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

// NEW: Generate combined CSV with both DAY and NIGHT shifts in one file
function generateCombinedShiftCSV($reportData) {
    if (!isset($reportData['columns']) || !isset($reportData['isSplitByShift'])) {
        return null;
    }

    $columns = $reportData['columns'];
    $dayData = $reportData['data']['day'] ?? [];
    $nightData = $reportData['data']['night'] ?? [];

    if (empty($columns)) {
        return null;
    }

    $csv = '';

    // Day Shift Section
    if (!empty($dayData)) {
        $csv .= "Day Shift\n\n";

        // Header row
        $csv .= implode(',', $columns) . "\n";

        // Data rows
        foreach ($dayData as $row) {
            $rowData = [];
            foreach ($columns as $col) {
                $value = isset($row[$col]) ? $row[$col] : '';
                $rowData[] = '"' . str_replace('"', '""', $value) . '"';
            }
            $csv .= implode(',', $rowData) . "\n";
        }
    }

    // Add spacing between shifts
    $csv .= "\n\n";

    // Night Shift Section
    if (!empty($nightData)) {
        $csv .= "Night Shift\n\n";

        // Header row
        $csv .= implode(',', $columns) . "\n";

        // Data rows
        foreach ($nightData as $row) {
            $rowData = [];
            foreach ($columns as $col) {
                $value = isset($row[$col]) ? $row[$col] : '';
                $rowData[] = '"' . str_replace('"', '""', $value) . '"';
            }
            $csv .= implode(',', $rowData) . "\n";
        }
    }

    return $csv;
}

function generateCSVFromProcedure($reportData, $shiftType = null) {
    if (!isset($reportData['columns'])) {
        return null;
    }
    
    $columns = $reportData['columns'];
    $data = [];
    
    // Handle CEO Report with shift split
    if (isset($reportData['isSplitByShift']) && $reportData['isSplitByShift']) {
        if ($shiftType === 'DAY') {
            $data = $reportData['data']['day'] ?? [];
        } elseif ($shiftType === 'NIGHT') {
            $data = $reportData['data']['night'] ?? [];
        } else {
            // If no shift type specified, return both in one CSV (not recommended)
            $data = $reportData['data'] ?? [];
        }
    } else {
        $data = $reportData['data'] ?? [];
    }
    
    if (empty($data) || empty($columns)) {
        return null;
    }
    
    $csv = '';
    
    // Add shift label if specified
    if ($shiftType !== null) {
        $csv .= $shiftType . " Shift\n\n";
    }
    
    // Header row
    $csv .= implode(',', $columns) . "\n";
    
    // Data rows
    foreach ($data as $row) {
        $rowData = [];
        foreach ($columns as $col) {
            $value = isset($row[$col]) ? $row[$col] : '';
            // Properly escape CSV values
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
        'MONTHLY_EXPORT' => "PMS Report | Monthly Productivity View | $date",
        'SUMMARY_REPORT' => "PMS Report | Weekly Summary View | $date"
    ];
    
    return $subjects[$reportType] ?? "PMS Report | Productivity Analysis | $date";
}

function generateEmailBody($reportData, $reportType, $charts = null) {
    $reportNames = [
        'CEO_REPORT' => 'Organizational Productivity Snapshot',
        'GROUP_REPORT' => 'Department-wise Productivity Comparison',
        'MONTHLY_EXPORT' => 'Monthly Productivity View',
        'SUMMARY_REPORT' => 'Weekly Summary View'
    ];
    
    $reportName = $reportNames[$reportType] ?? 'Productivity Report';
    $currentDateTime = date('d M Y, h:i A');
    
    // FIXED: Handle record count for split data
    if (isset($reportData['isSplitByShift']) && $reportData['isSplitByShift']) {
        $dayCount = count($reportData['data']['day'] ?? []);
        $nightCount = count($reportData['data']['night'] ?? []);
        $recordCount = "Day: $dayCount, Night: $nightCount";
    } else {
        $recordCount = count($reportData['data']);
    }
    
    $startDate = date('d M Y', strtotime($reportData['startDate']));
    $endDate = date('d M Y', strtotime($reportData['endDate']));

    $chartSection = '';
    if ($charts && !empty($charts)) {
        $chartSection = '<div class="charts-section">';
        $chartSection .= '<h3 style="color: #2c3e50; margin-top: 30px; border-bottom: 2px solid #667eea; padding-bottom: 10px;">Visual Analytics</h3>';
        
        foreach ($charts as $index => $chart) {
            $cid = 'chart_' . $index;
            $chartSection .= '<div style="margin: 25px 0; text-align: center; background: white; padding: 15px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">';
            $chartSection .= '<img src="cid:' . $cid . '" alt="' . $chart['name'] . '" style="max-width: 100%; height: auto; border: 1px solid #e0e0e0; border-radius: 4px;">';
            $chartSection .= '</div>';
        }
        
        $chartSection .= '</div>';
    }

    $attachmentNote = '';
    if ($reportType === 'CEO_REPORT' && isset($reportData['isSplitByShift'])) {
        $attachmentNote = '
        <div class="attachment-note">
            <strong>?? CSV Attachment:</strong> One combined CSV file is attached containing both Day Shift and Night Shift data with department-wise averages.
        </div>';
    } else {
        $attachmentNote = '
        <div class="attachment-note">
            <strong>?? CSV Attachment:</strong> The detailed data is available in the attached CSV file for further analysis and record-keeping.
        </div>';
    }
    
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
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1>' . $reportName . '</h1>                
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

                ' . $attachmentNote . '

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
                <p><strong> Automated Email Notice</strong></p>
                <p>This is an automated email. Please do not reply to this message.</p>
                <p style="margin-top: 15px;">? ' . date('Y') . ' Productivity Monitoring System. All rights reserved.</p>
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
        
        'MONTHLY_EXPORT' => "This monthly view displays <strong>day-by-day productivity trends</strong> for the reporting period ($startDate to $endDate). The data represents average daily hours across all tracked employees, helping you identify patterns, peak productivity days, and potential issues over the month.",
        
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
        case 'MONTHLY_EXPORT':
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
    
    // Check if data is split by shift
    if (isset($data['day']) && isset($data['night'])) {
        $dayData = $data['day'];
        $nightData = $data['night'];
        
        // Count departments (exclude Grand Total)
        $totalDayDepts = 0;
        $totalNightDepts = 0;
        $dayProductiveDepts = 0;
        $nightProductiveDepts = 0;
        
        foreach ($dayData as $row) {
            if ($row['Department'] === 'Grand Total') continue;
            $totalDayDepts++;
            $avgProductive = parseTimeToHours($row['Average Productive Hours']);
            if ($avgProductive >= 7.5) {
                $dayProductiveDepts++;
            }
        }
        
        foreach ($nightData as $row) {
            if ($row['Department'] === 'Grand Total') continue;
            $totalNightDepts++;
            $avgProductive = parseTimeToHours($row['Average Productive Hours']);
            if ($avgProductive >= 7.5) {
                $nightProductiveDepts++;
            }
        }
        
        $observations[] = "Analysis covers <strong>Day Shift: $totalDayDepts departments</strong> and <strong>Night Shift: $totalNightDepts departments</strong>.";
        $observations[] = "<strong>Day Shift:</strong> $dayProductiveDepts out of $totalDayDepts departments meeting 7.5+ hour productivity threshold.";
        $observations[] = "<strong>Night Shift:</strong> $nightProductiveDepts out of $totalNightDepts departments meeting 7.5+ hour productivity threshold.";
        $observations[] = "Two separate CSV files have been attached - one for each shift schedule.";
        
    } else {
        // Fallback for non-split data
        $totalDepts = 0;
        $productiveDepts = 0;
        
        foreach ($data as $row) {
            if ($row['Department'] === 'Grand Total') continue;
            
            $totalDepts++;
            $avgProductive = parseTimeToHours($row['Average Productive Hours']);
            
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