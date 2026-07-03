<?php
include 'apiMain.php';

// Enable CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Get parameters
$userid = isset($_GET['userid']) ? $_GET['userid'] : NULL;

// Support both month-based and date range based queries
// If startDate and endDate are provided, use those
// Otherwise, use currentMonth and previousMonth
$startDate = isset($_GET['startDate']) ? $_GET['startDate'] : NULL;
$endDate = isset($_GET['endDate']) ? $_GET['endDate'] : NULL;
$compareStartDate = isset($_GET['compareStartDate']) ? $_GET['compareStartDate'] : NULL;
$compareEndDate = isset($_GET['compareEndDate']) ? $_GET['compareEndDate'] : NULL;

// Fallback to month-based if date ranges not provided
$currentMonth = isset($_GET['currentMonth']) ? $_GET['currentMonth'] : date('Y-m');
$previousMonth = isset($_GET['previousMonth']) ? $_GET['previousMonth'] : date('Y-m', strtotime('-1 month'));

// Initialize response
$response = [
    'success' => false,
    'currentPeriodData' => null,
    'comparePeriodData' => null,
    'efficiencyScores' => [
        'current' => [
            'score' => 0,
            'breakdown' => [
                'productivity' => 0,
                'attendance' => 0,
                'idleControl' => 0
            ]
        ],
        'previous' => [
            'score' => 0,
            'breakdown' => [
                'productivity' => 0,
                'attendance' => 0,
                'idleControl' => 0
            ]
        ],
        'change' => 0
    ],
    'debug' => [
        'mode' => $startDate ? 'date_range' : 'month',
        'currentPeriod' => $startDate ? "$startDate to $endDate" : $currentMonth,
        'comparePeriod' => $compareStartDate ? "$compareStartDate to $compareEndDate" : $previousMonth,
        'userid' => $userid
    ]
];

// Helper function to convert time strings to total seconds
function convertToSeconds($timeString) {
    if (empty($timeString)) return 0;
    $parts = explode(':', $timeString);
    if (count($parts) === 3) {
        list($hours, $minutes, $seconds) = $parts;
        return ($hours * 3600) + ($minutes * 60) + (int)$seconds;
    }
    return 0;
}

// Helper function to convert seconds to hours
function secondsToHours($seconds) {
    return $seconds / 3600;
}

// Helper function to get aggregate data for a date range
function getDateRangeAggregateData($conn, $startDate, $endDate, $userid) {
    $departments = 'ALL';
    $roles = 'ALL';
    $projects = 'ALL';
    $shifts = 'ALL';
    $teams = 'ALL';
    $ids = 'ALL';
    $names = 'ALL';
    $designations = 'ALL';
    $reportType = 'GROUP_REPORT';
    
    $aggregateData = [
        'total_logged_seconds' => 0,
        'total_productive_seconds' => 0,
        'total_idle_seconds' => 0,
        'total_break_seconds' => 0,
        'total_away_seconds' => 0,
        'active_employees' => 0,
        'total_work_days' => 0
    ];
    
    if ($stmt = $conn->prepare("CALL PR_EMPLOYEE_ACTIVITY_FLAT(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")) {
        $stmt->bind_param('ssssssssssss', $startDate, $endDate, $ids, $names, $departments, $roles, $designations, $projects, $shifts, $teams, $userid, $reportType);
        
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            
            if ($result) {
                $employeeSet = [];
                
                while ($row = $result->fetch_assoc()) {
                    // Try different field name variations
                    $loggedField = $row['TotalLoggedHours'] ?? $row['total_logged_hours'] ?? $row['LOGGED_HOURS'] ?? null;
                    $productiveField = $row['TotalProductiveHours'] ?? $row['total_productive_hours'] ?? $row['PRODUCTIVE_HOURS'] ?? null;
                    $idleField = $row['TotalIdleHours'] ?? $row['total_idle_hours'] ?? $row['IDLE_HOURS'] ?? null;
                    $breakField = $row['ApprovedBreaks'] ?? $row['approved_breaks'] ?? $row['BREAK_HOURS'] ?? null;
                    $awayField = $row['AwayFromSystem'] ?? $row['away_from_system'] ?? $row['AWAY_HOURS'] ?? null;
                    
                    $logged = convertToSeconds($loggedField);
                    
                    // Only count employees with actual logged time
                    if ($logged > 0) {
                        $aggregateData['total_logged_seconds'] += $logged;
                        $aggregateData['total_productive_seconds'] += convertToSeconds($productiveField);
                        $aggregateData['total_idle_seconds'] += convertToSeconds($idleField);
                        $aggregateData['total_break_seconds'] += convertToSeconds($breakField);
                        $aggregateData['total_away_seconds'] += convertToSeconds($awayField);
                        $aggregateData['total_work_days']++;
                        
                        // Track unique employees - try multiple field names
                        $empKey = $row['EMPID'] ?? $row['empid'] ?? $row['EmpId'] ?? $row['emp_id'] ?? $row['EmployeeID'] ?? $row['employee_id'] ?? null;
                        
                        // If EMPID is not available, count work days as proxy for employees
                        // Each work day = one employee-day of activity
                        if (!empty($empKey) && !isset($employeeSet[$empKey])) {
                            $employeeSet[$empKey] = true;
                            $aggregateData['active_employees']++;
                        }
                    }
                }
                
                // If no employees were counted via EMPID but we have work days, estimate employee count
                // This happens when EMPID field is not available in the stored procedure results
                if ($aggregateData['active_employees'] === 0 && $aggregateData['total_work_days'] > 0) {
                    // Estimate: If we have 822 work days over 15 calendar days, 
                    // that's roughly 822/15 = ~55 employees average per day
                    $dateStart = new DateTime($startDate);
                    $dateEnd = new DateTime($endDate);
                    $dateEnd->modify('+1 day'); // Include end date
                    $calendarDays = $dateStart->diff($dateEnd)->days;
                    
                    if ($calendarDays > 0) {
                        $aggregateData['active_employees'] = ceil($aggregateData['total_work_days'] / $calendarDays);
                    } else {
                        // Fallback: assume at least 1 employee if we have data
                        $aggregateData['active_employees'] = 1;
                    }
                }
                
                $result->free();
            }
        }
        $stmt->close();
        
        // ?? IMPORTANT: Close and reopen connection for next stored procedure call
        // This prevents "Commands out of sync" error
        mysqli_next_result($conn);
    }
    
    return $aggregateData;
}

// Helper function to calculate efficiency score
function calculateEfficiencyScore($data) {
    if ($data['active_employees'] === 0 || $data['total_logged_seconds'] === 0) {
        return [
            'score' => 0,
            'breakdown' => [
                'productivity' => 0,
                'attendance' => 0,
                'idleControl' => 0
            ]
        ];
    }
    
    // ==========================================
    // STEP 1: Core Productivity/Efficiency Score
    // Formula: Productive Time / (Logged Time - Approved Breaks) × 100
    // This is the PRIMARY efficiency metric
    // ==========================================
    $effectiveLoggedTime = max($data['total_logged_seconds'] - $data['total_break_seconds'], 1);
    $baseProductivity = ($data['total_productive_seconds'] / $effectiveLoggedTime) * 100;
    $safeBaseProductivity = (is_nan($baseProductivity) || !is_finite($baseProductivity)) ? 0 : $baseProductivity;
    
    // ==========================================
    // STEP 2: Attendance Consistency Score
    // Measures actual logged time vs expected time
    // Formula: (Actual Logged Hours / Expected Hours) × 100
    // Expected = active_employees × work_days × 8 hours
    // Actual = total_logged_seconds converted to hours
    // ==========================================
    $expectedHours = $data['active_employees'] * ($data['total_work_days'] / max($data['active_employees'], 1)) * 8;
    $actualLoggedHours = secondsToHours($data['total_logged_seconds']);
    
    // If expected hours is 0, use alternative calculation
    if ($expectedHours > 0) {
        $attendanceScore = min(($actualLoggedHours / $expectedHours) * 100, 100);
    } else {
        // Fallback: measure average hours per work day vs 8-hour standard
        $avgHoursPerDay = $data['total_work_days'] > 0 
            ? $actualLoggedHours / $data['total_work_days'] 
            : 0;
        $attendanceScore = min(($avgHoursPerDay / 8) * 100, 100);
    }
    
    $safeAttendanceScore = (is_nan($attendanceScore) || !is_finite($attendanceScore)) ? 0 : $attendanceScore;
    
    // ==========================================
    // STEP 3: Idle Control Score  
    // Penalizes idle time - lower idle = better score
    // Formula: 100 - (Idle Percentage × 10)
    // If idle > 10% of logged time, score drops to 0
    // ==========================================
    $idlePercentage = ($data['total_idle_seconds'] / $data['total_logged_seconds']) * 100;
    $idleControlScore = max(100 - ($idlePercentage * 3), 0);
    $safeIdleControlScore = (is_nan($idleControlScore) || !is_finite($idleControlScore)) ? 0 : $idleControlScore;
    
    // ==========================================
    // FINAL WEIGHTED EFFICIENCY SCORE
    // 60% Core Productivity (Productive / (Logged - Breaks))
    // 20% Attendance Consistency (Actual vs Expected hours)
    // 20% Idle Control (Penalty for idle time)
    // ==========================================
    $finalScore = ($safeBaseProductivity * 0.6) + ($safeAttendanceScore * 0.2) + ($safeIdleControlScore * 0.2);
    $safeFinalScore = (is_nan($finalScore) || !is_finite($finalScore)) ? 0 : round($finalScore);
    
    return [
        'score' => $safeFinalScore,
        'breakdown' => [
            'productivity' => round($safeBaseProductivity),
            'attendance' => round($safeAttendanceScore),
            'idleControl' => round($safeIdleControlScore)
        ]
    ];
}

try {
    // Determine which mode we're in
    if ($startDate && $endDate) {
        // Date range mode - use provided dates
        $currentStart = $startDate;
        $currentEnd = $endDate;
        $compareStart = $compareStartDate ? $compareStartDate : $startDate;
        $compareEnd = $compareEndDate ? $compareEndDate : $endDate;
    } else {
        // Month mode - convert months to date ranges
        $currentStart = $currentMonth . '-01';
        $currentEnd = date('Y-m-t', strtotime($currentStart));
        $compareStart = $previousMonth . '-01';
        $compareEnd = date('Y-m-t', strtotime($compareStart));
    }
    
    // Get data for current period
    $currentPeriodData = getDateRangeAggregateData($conn, $currentStart, $currentEnd, $userid);
    $response['currentPeriodData'] = [
        'period' => "$currentStart to $currentEnd",
        'total_logged_hours' => round(secondsToHours($currentPeriodData['total_logged_seconds']), 2),
        'total_productive_hours' => round(secondsToHours($currentPeriodData['total_productive_seconds']), 2),
        'total_idle_hours' => round(secondsToHours($currentPeriodData['total_idle_seconds']), 2),
        'total_break_hours' => round(secondsToHours($currentPeriodData['total_break_seconds']), 2),
        'active_employees' => $currentPeriodData['active_employees'],
        'work_days' => $currentPeriodData['total_work_days']
    ];
    
    // Get data for comparison period
    $comparePeriodData = getDateRangeAggregateData($conn, $compareStart, $compareEnd, $userid);
    $response['comparePeriodData'] = [
        'period' => "$compareStart to $compareEnd",
        'total_logged_hours' => round(secondsToHours($comparePeriodData['total_logged_seconds']), 2),
        'total_productive_hours' => round(secondsToHours($comparePeriodData['total_productive_seconds']), 2),
        'total_idle_hours' => round(secondsToHours($comparePeriodData['total_idle_seconds']), 2),
        'total_break_hours' => round(secondsToHours($comparePeriodData['total_break_seconds']), 2),
        'active_employees' => $comparePeriodData['active_employees'],
        'work_days' => $comparePeriodData['total_work_days']
    ];
    
    // Calculate efficiency scores
    $currentEfficiency = calculateEfficiencyScore($currentPeriodData);
    $compareEfficiency = calculateEfficiencyScore($comparePeriodData);
    
    $response['efficiencyScores']['current'] = $currentEfficiency;
    $response['efficiencyScores']['previous'] = $compareEfficiency;
    $response['efficiencyScores']['change'] = $currentEfficiency['score'] - $compareEfficiency['score'];
    
    $response['success'] = true;
    
} catch (Exception $e) {
    $response['error'] = $e->getMessage();
    $response['trace'] = $e->getTraceAsString();
}

// Close the connection
$conn->close();

// Return JSON response
echo json_encode($response);
?>