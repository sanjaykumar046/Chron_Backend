<?php
include 'apiMain.php';

header('Content-Type: application/json');

// Get parameters
$userid = isset($_GET['userid']) ? $_GET['userid'] : NULL;
$reportType = isset($_GET['reportType']) ? $_GET['reportType'] : 'GROUP_REPORT';

// ==========================================
// HELPER FUNCTIONS
// ==========================================

/**
 * Convert HH:MM:SS time string to seconds
 */
function convertToSeconds($timeString) {
    if (empty($timeString)) return 0;
    $parts = explode(':', $timeString);
    if (count($parts) === 3) {
        return ((int)$parts[0] * 3600) + ((int)$parts[1] * 60) + (int)$parts[2];
    }
    return 0;
}

/**
 * Convert seconds to HH:MM:SS format
 */
function formatSecondsToHMS($totalSeconds) {
    $hours = floor($totalSeconds / 3600);
    $minutes = floor(($totalSeconds % 3600) / 60);
    $seconds = $totalSeconds % 60;
    return sprintf("%02d:%02d:%02d", $hours, $minutes, $seconds);
}

/**
 * Calculate Efficiency Score
 * Formula: Efficiency % = Productive Time / (Logged Time - Approved Breaks) × 100
 */
function calculateEfficiency($employeeData) {
    if (!$employeeData || !is_array($employeeData) || count($employeeData) === 0) {
        return [
            'efficiency_percentage' => 0,
            'total_logged_hours' => '00:00:00',
            'total_productive_hours' => '00:00:00',
            'total_idle_hours' => '00:00:00',
            'total_break_hours' => '00:00:00',
            'total_away_hours' => '00:00:00',
            'effective_work_hours' => '00:00:00',
            'employee_count' => 0,
            'avg_efficiency' => 0
        ];
    }

    $totalLoggedSeconds = 0;
    $totalProductiveSeconds = 0;
    $totalIdleSeconds = 0;
    $totalBreakSeconds = 0;
    $totalAwaySeconds = 0;
    $employeeCount = 0;
    $individualEfficiencies = [];

    // Process each employee's data
    foreach ($employeeData as $emp) {
        // Get values with fallback to different field name variations
        $loggedHours = $emp['TotalLoggedHours'] ?? 
                       $emp['LOGGED_HOURS'] ?? 
                       $emp['logged_hours'] ?? 
                       $emp['LoggedHours'] ?? null;

        $productiveHours = $emp['TotalProductiveHours'] ?? 
                          $emp['PRODUCTIVE_HOURS'] ?? 
                          $emp['productive_hours'] ?? 
                          $emp['ProductiveHours'] ?? null;

        $idleHours = $emp['TotalIdleHours'] ?? 
                    $emp['IDLE_HOURS'] ?? 
                    $emp['idle_hours'] ?? 
                    $emp['IdleHours'] ?? null;

        $breakHours = $emp['ApprovedBreaks'] ?? 
                     $emp['APPROVED_BREAKS'] ?? 
                     $emp['approved_breaks'] ?? 
                     $emp['BreakHours'] ?? 
                     $emp['BREAK_HOURS'] ?? null;

        $awayHours = $emp['AwayFromSystem'] ?? 
                    $emp['AWAY_HOURS'] ?? 
                    $emp['away_hours'] ?? 
                    $emp['AwayHours'] ?? 
                    $emp['TOTAL_AWAY_FROM_SYSTEM'] ?? null;

        // Convert to seconds
        $logged = convertToSeconds($loggedHours);
        $productive = convertToSeconds($productiveHours);
        $idle = convertToSeconds($idleHours);
        $breaks = convertToSeconds($breakHours);
        $away = convertToSeconds($awayHours);

        // Only count employees with actual logged time
        if ($logged > 0) {
            $totalLoggedSeconds += $logged;
            $totalProductiveSeconds += $productive;
            $totalIdleSeconds += $idle;
            $totalBreakSeconds += $breaks;
            $totalAwaySeconds += $away;
            $employeeCount++;

            // Calculate individual efficiency
            $effectiveTime = max($logged - $breaks, 1); // Prevent division by zero
            $individualEfficiency = ($productive / $effectiveTime) * 100;
            $individualEfficiencies[] = $individualEfficiency;
        }
    }

    // Calculate overall efficiency
    // Formula: Efficiency % = Productive Time / (Logged Time - Approved Breaks) × 100
    $effectiveLoggedTime = max($totalLoggedSeconds - $totalBreakSeconds, 1);
    $efficiencyPercentage = ($totalProductiveSeconds / $effectiveLoggedTime) * 100;

    // Calculate average efficiency across all employees
    $avgEfficiency = $employeeCount > 0 ? array_sum($individualEfficiencies) / $employeeCount : 0;

    // Ensure values are within valid range and handle edge cases
    $efficiencyPercentage = min(max($efficiencyPercentage, 0), 100);
    $avgEfficiency = min(max($avgEfficiency, 0), 100);

    return [
        'efficiency_percentage' => round($efficiencyPercentage, 2),
        'total_logged_hours' => formatSecondsToHMS($totalLoggedSeconds),
        'total_productive_hours' => formatSecondsToHMS($totalProductiveSeconds),
        'total_idle_hours' => formatSecondsToHMS($totalIdleSeconds),
        'total_break_hours' => formatSecondsToHMS($totalBreakSeconds),
        'total_away_hours' => formatSecondsToHMS($totalAwaySeconds),
        'effective_work_hours' => formatSecondsToHMS($effectiveLoggedTime),
        'employee_count' => $employeeCount,
        'avg_efficiency' => round($avgEfficiency, 2),
        'total_logged_seconds' => $totalLoggedSeconds,
        'total_productive_seconds' => $totalProductiveSeconds,
        'effective_logged_seconds' => $effectiveLoggedTime
    ];
}

/**
 * Fetch employee data for a given date range
 */
function fetchEmployeeData($conn, $startDate, $endDate, $userid, $reportType) {
    $departments = 'ALL';
    $roles = 'ALL';
    $projects = 'ALL';
    $shifts = 'ALL';
    $teams = 'ALL';
    $ids = 'ALL';
    $names = 'ALL';
    $designations = 'ALL';

    $data = [];

    if ($stmt = $conn->prepare("CALL PR_EMPLOYEE_ACTIVITY_FLAT(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")) {
        $stmt->bind_param('ssssssssssss', $startDate, $endDate, $ids, $names, $departments, $roles, $designations, $projects, $shifts, $teams, $userid, $reportType);
        
        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }

        $result_data = $stmt->get_result();
        if ($result_data) {
            while ($row = $result_data->fetch_assoc()) {
                $data[] = $row;
            }
            $result_data->free();
        }
        $stmt->close();
    } else {
        return null;
    }

    return $data;
}

// ==========================================
// CALCULATE DATE RANGES
// ==========================================

// Current Month
$currentMonthStart = date('Y-m-01'); // First day of current month
$currentMonthEnd = date('Y-m-d'); // Today

// Last Month
$lastMonthStart = date('Y-m-01', strtotime('first day of last month'));
$lastMonthEnd = date('Y-m-t', strtotime('first day of last month')); // Last day of last month

// ==========================================
// FETCH DATA FOR BOTH PERIODS
// ==========================================

try {
    // Fetch Last Month Data
    $lastMonthData = fetchEmployeeData($conn, $lastMonthStart, $lastMonthEnd, $userid, $reportType);
    
    // Fetch Current Month Data
    $currentMonthData = fetchEmployeeData($conn, $currentMonthStart, $currentMonthEnd, $userid, $reportType);

    // Calculate Efficiency for Both Periods
    $lastMonthEfficiency = calculateEfficiency($lastMonthData);
    $currentMonthEfficiency = calculateEfficiency($currentMonthData);

    // Calculate Percentage Increase/Decrease
    $efficiencyChange = $currentMonthEfficiency['efficiency_percentage'] - $lastMonthEfficiency['efficiency_percentage'];
    $efficiencyChangePercent = $lastMonthEfficiency['efficiency_percentage'] > 0 
        ? (($efficiencyChange / $lastMonthEfficiency['efficiency_percentage']) * 100) 
        : 0;

    // Determine trend
    $trend = $efficiencyChange >= 0 ? 'increased' : 'decreased';
    $trendIcon = $efficiencyChange >= 0 ? '?' : '?';

    // Prepare Response
    $response = [
        'success' => true,
        'date_ranges' => [
            'last_month' => [
                'start' => $lastMonthStart,
                'end' => $lastMonthEnd,
                'label' => date('F Y', strtotime($lastMonthStart))
            ],
            'current_month' => [
                'start' => $currentMonthStart,
                'end' => $currentMonthEnd,
                'label' => date('F Y', strtotime($currentMonthStart))
            ]
        ],
        'last_month' => [
            'efficiency_percentage' => $lastMonthEfficiency['efficiency_percentage'],
            'total_logged_hours' => $lastMonthEfficiency['total_logged_hours'],
            'total_productive_hours' => $lastMonthEfficiency['total_productive_hours'],
            'total_idle_hours' => $lastMonthEfficiency['total_idle_hours'],
            'total_break_hours' => $lastMonthEfficiency['total_break_hours'],
            'total_away_hours' => $lastMonthEfficiency['total_away_hours'],
            'effective_work_hours' => $lastMonthEfficiency['effective_work_hours'],
            'employee_count' => $lastMonthEfficiency['employee_count'],
            'avg_efficiency' => $lastMonthEfficiency['avg_efficiency']
        ],
        'current_month' => [
            'efficiency_percentage' => $currentMonthEfficiency['efficiency_percentage'],
            'total_logged_hours' => $currentMonthEfficiency['total_logged_hours'],
            'total_productive_hours' => $currentMonthEfficiency['total_productive_hours'],
            'total_idle_hours' => $currentMonthEfficiency['total_idle_hours'],
            'total_break_hours' => $currentMonthEfficiency['total_break_hours'],
            'total_away_hours' => $currentMonthEfficiency['total_away_hours'],
            'effective_work_hours' => $currentMonthEfficiency['effective_work_hours'],
            'employee_count' => $currentMonthEfficiency['employee_count'],
            'avg_efficiency' => $currentMonthEfficiency['avg_efficiency']
        ],
        'comparison' => [
            'efficiency_change' => round($efficiencyChange, 2),
            'efficiency_change_percent' => round(abs($efficiencyChangePercent), 2),
            'trend' => $trend,
            'trend_icon' => $trendIcon,
            'summary' => sprintf(
                'Efficiency has %s by %.2f%% (%.2f percentage points) compared to last month',
                $trend,
                abs($efficiencyChangePercent),
                abs($efficiencyChange)
            )
        ],
        'formula_used' => 'Efficiency % = Productive Time / (Logged Time - Approved Breaks) × 100',
        'userid' => $userid,
        'report_type' => $reportType
    ];

    echo json_encode($response, JSON_PRETTY_PRINT);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

// Close connection
$conn->close();
?>
```

## Key Features:

1. **Exact Formula Implementation**: Uses your exact formula: `Efficiency % = Productive Time / (Logged Time - Approved Breaks) × 100`

2. **Automatic Date Calculation**:
   - Last Month: First to last day of previous month
   - Current Month: First day to today

3. **Data Fetching**: Uses the same stored procedure method as `DUMP.php`

4. **Field Name Flexibility**: Handles multiple field name variations (uppercase/lowercase)

5. **Comprehensive Metrics**:
   - Total logged, productive, idle, break, and away hours
   - Efficiency percentage for both periods
   - Absolute and relative change
   - Employee count

6. **Comparison Analysis**:
   - Shows percentage point change
   - Shows relative percentage change
   - Trend indicator (?/?)

## Usage:
```
GET /efficiency.php?userid=EMP123&reportType=GROUP_REPORT