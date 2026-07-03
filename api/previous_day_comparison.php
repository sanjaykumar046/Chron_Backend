<?php
include 'apiMain.php';

header('Content-Type: application/json');

// Get parameters
$userid = isset($_GET['userid']) ? $_GET['userid'] : NULL;
$yesterday = isset($_GET['yesterday']) ? $_GET['yesterday'] : date('Y-m-d', strtotime('yesterday'));
$dayBefore = isset($_GET['dayBefore']) ? $_GET['dayBefore'] : date('Y-m-d', strtotime('yesterday - 1 day'));

// Initialize response
$response = [
    'success' => false,
    'yesterdayData' => null,
    'dayBeforeData' => null,
    'percentageChanges' => [
        'logged_hours_change' => 0,
        'idle_hours_change' => 0,
        'productive_hours_change' => 0,
        'time_on_system_change' => 0,
        'away_from_system_change' => 0
    ],
    'debug' => [
        'yesterday' => $yesterday,
        'dayBefore' => $dayBefore,
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

// Helper function to get average data for a specific date
function getAverageDataForDate($conn, $date, $userid) {
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
        'total_logged_hours' => 0,
        'total_idle_hours' => 0,
        'total_productive_hours' => 0,
        'total_time_on_system' => 0,
        'total_time_away_from_system' => 0,
        'count' => 0
    ];
    
    if ($stmt = $conn->prepare("CALL PR_EMPLOYEE_ACTIVITY_FLAT(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")) {
        $stmt->bind_param('ssssssssssss', $date, $date, $ids, $names, $departments, $roles, $designations, $projects, $shifts, $teams, $userid, $reportType);
        
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $aggregateData['total_logged_hours'] += convertToSeconds($row['TotalLoggedHours']);
                    $aggregateData['total_idle_hours'] += convertToSeconds($row['TotalIdleHours']);
                    $aggregateData['total_productive_hours'] += convertToSeconds($row['TotalProductiveHours']);
                    $aggregateData['total_time_on_system'] += convertToSeconds($row['TOTAL_ON_SYSTEM']);
                    $aggregateData['total_time_away_from_system'] += convertToSeconds($row['AwayFromSystem']);
                    $aggregateData['count']++;
                }
                $result->free();
            }
        }
        $stmt->close();
    }
    
    // Calculate averages
    if ($aggregateData['count'] > 0) {
        $aggregateData['avg_logged_hours'] = $aggregateData['total_logged_hours'] / $aggregateData['count'];
        $aggregateData['avg_idle_hours'] = $aggregateData['total_idle_hours'] / $aggregateData['count'];
        $aggregateData['avg_productive_hours'] = $aggregateData['total_productive_hours'] / $aggregateData['count'];
        $aggregateData['avg_time_on_system'] = $aggregateData['total_time_on_system'] / $aggregateData['count'];
        $aggregateData['avg_time_away_from_system'] = $aggregateData['total_time_away_from_system'] / $aggregateData['count'];
    } else {
        $aggregateData['avg_logged_hours'] = 0;
        $aggregateData['avg_idle_hours'] = 0;
        $aggregateData['avg_productive_hours'] = 0;
        $aggregateData['avg_time_on_system'] = 0;
        $aggregateData['avg_time_away_from_system'] = 0;
    }
    
    return $aggregateData;
}

// Helper function to calculate percentage change
function calculatePercentageChange($dayBeforeValue, $yesterdayValue) {
    if ($dayBeforeValue == 0) {
        return $yesterdayValue > 0 ? 100 : 0;
    }
    return (($yesterdayValue - $dayBeforeValue) / $dayBeforeValue) * 100;
}

try {
    // Get data for yesterday
    $yesterdayData = getAverageDataForDate($conn, $yesterday, $userid);
    $response['yesterdayData'] = $yesterdayData;
    
    // Get data for day before yesterday
    $dayBeforeData = getAverageDataForDate($conn, $dayBefore, $userid);
    $response['dayBeforeData'] = $dayBeforeData;
    
    // Calculate percentage changes
    $response['percentageChanges']['logged_hours_change'] = calculatePercentageChange(
        $dayBeforeData['avg_logged_hours'],
        $yesterdayData['avg_logged_hours']
    );
    
    $response['percentageChanges']['idle_hours_change'] = calculatePercentageChange(
        $dayBeforeData['avg_idle_hours'],
        $yesterdayData['avg_idle_hours']
    );
    
    $response['percentageChanges']['productive_hours_change'] = calculatePercentageChange(
        $dayBeforeData['avg_productive_hours'],
        $yesterdayData['avg_productive_hours']
    );
    
    $response['percentageChanges']['time_on_system_change'] = calculatePercentageChange(
        $dayBeforeData['avg_time_on_system'],
        $yesterdayData['avg_time_on_system']
    );
    
    $response['percentageChanges']['away_from_system_change'] = calculatePercentageChange(
        $dayBeforeData['avg_time_away_from_system'],
        $yesterdayData['avg_time_away_from_system']
    );
    
    $response['success'] = true;
    
} catch (Exception $e) {
    $response['error'] = $e->getMessage();
}

// Close the connection
$conn->close();

// Return JSON response
echo json_encode($response);
?>