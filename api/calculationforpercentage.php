<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

include 'apiMain.php';

// Log the incoming request
error_log("Percentage API called with params: " . print_r($_GET, true));

// Get parameters with default values
$userid = isset($_GET['userid']) ? $_GET['userid'] : NULL;
$departments = isset($_GET['department']) ? $_GET['department'] : ['ALL'];
$roles = isset($_GET['role']) ? $_GET['role'] : ['ALL'];
$projects = isset($_GET['project']) ? $_GET['project'] : ['ALL'];
$shifts = isset($_GET['shift']) ? $_GET['shift'] : ['ALL'];
$teams = isset($_GET['team']) ? $_GET['team'] : ['ALL'];
$ids = isset($_GET['ids']) ? $_GET['ids'] : ['ALL'];
$names = isset($_GET['names']) ? $_GET['names'] : ['ALL'];
$designations = isset($_GET['designations']) ? $_GET['designations'] : ['ALL'];
$reportType = isset($_GET['reportType']) ? $_GET['reportType'] : 'GROUP_REPORT';

// Convert arrays to comma-separated strings
$departments = is_array($departments) ? implode(",", array_map([$conn, 'real_escape_string'], $departments)) : $departments;
$roles = is_array($roles) ? implode(",", array_map([$conn, 'real_escape_string'], $roles)) : $roles;
$projects = is_array($projects) ? implode(",", array_map([$conn, 'real_escape_string'], $projects)) : $projects;
$shifts = is_array($shifts) ? implode(",", array_map([$conn, 'real_escape_string'], $shifts)) : $shifts;
$teams = is_array($teams) ? implode(",", array_map([$conn, 'real_escape_string'], $teams)) : $teams;
$ids = is_array($ids) ? implode(",", array_map([$conn, 'real_escape_string'], $ids)) : $ids;
$names = is_array($names) ? implode(",", array_map([$conn, 'real_escape_string'], $names)) : $names;
$designations = is_array($designations) ? implode(",", array_map([$conn, 'real_escape_string'], $designations)) : $designations;

// Define today and yesterday dates
$todayDate = date('Y-m-d');
$yesterdayDate = date('Y-m-d', strtotime('-1 day'));

error_log("Fetching data for today: $todayDate and yesterday: $yesterdayDate");

// Function to fetch data for a specific date
function fetchDataForDate($conn, $date, $ids, $names, $departments, $roles, $designations, $projects, $shifts, $teams, $userid, $reportType) {
    $aggregate = [
        'total_logged_hours' => 0,
        'total_idle_hours' => 0,
        'total_productive_hours' => 0,
        'total_time_on_system' => 0,
        'total_time_away_from_system' => 0,
        'count' => 0
    ];

    try {
        if ($stmt = $conn->prepare("CALL PR_EMPLOYEE_ACTIVITY_FLAT(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")) {
            $stmt->bind_param('ssssssssssss', $date, $date, $ids, $names, $departments, $roles, $designations, $projects, $shifts, $teams, $userid, $reportType);
            
            if (!$stmt->execute()) {
                error_log("Query execution failed for date $date: " . $stmt->error);
                $stmt->close();
                return $aggregate;
            }

            $result_data = $stmt->get_result();
            if ($result_data) {
                while ($row = $result_data->fetch_assoc()) {
                    $aggregate['total_logged_hours'] += convertToSeconds($row['TotalLoggedHours']);
                    $aggregate['total_idle_hours'] += convertToSeconds($row['TotalIdleHours']);
                    $aggregate['total_productive_hours'] += convertToSeconds($row['TotalProductiveHours']);
                    $aggregate['total_time_on_system'] += convertToSeconds($row['TOTAL_ON_SYSTEM']);
                    $aggregate['total_time_away_from_system'] += convertToSeconds($row['AwayFromSystem']);
                    $aggregate['count']++;
                }
                $result_data->free();
            }
            $stmt->close();
        } else {
            error_log("Failed to prepare statement for date $date: " . $conn->error);
        }
    } catch (Exception $e) {
        error_log("Exception in fetchDataForDate: " . $e->getMessage());
    }

    error_log("Data for $date - Count: {$aggregate['count']}, Logged Hours: {$aggregate['total_logged_hours']}");
    return $aggregate;
}

// Helper function to convert time strings to total seconds
function convertToSeconds($timeString) {
    if (empty($timeString) || $timeString === '00:00:00') return 0;
    
    $parts = explode(':', $timeString);
    if (count($parts) != 3) return 0;
    
    list($hours, $minutes, $seconds) = $parts;
    return ($hours * 3600) + ($minutes * 60) + (int)$seconds;
}

// Function to calculate average from aggregate data
function calculateAverage($aggregate) {
    if ($aggregate['count'] == 0) {
        return [
            'logged_hours' => 0,
            'idle_hours' => 0,
            'productive_hours' => 0,
            'time_on_system' => 0,
            'time_away_from_system' => 0
        ];
    }

    return [
        'logged_hours' => $aggregate['total_logged_hours'] / $aggregate['count'],
        'idle_hours' => $aggregate['total_idle_hours'] / $aggregate['count'],
        'productive_hours' => $aggregate['total_productive_hours'] / $aggregate['count'],
        'time_on_system' => $aggregate['total_time_on_system'] / $aggregate['count'],
        'time_away_from_system' => $aggregate['total_time_away_from_system'] / $aggregate['count']
    ];
}

// Function to calculate percentage change
function calculatePercentageChange($today, $yesterday) {
    if ($yesterday == 0) {
        return $today > 0 ? 100 : 0;
    }
    return (($today - $yesterday) / $yesterday) * 100;
}

// Function to format seconds to HH:MM:SS
function formatSecondsToHMS($totalSeconds) {
    $hours = floor($totalSeconds / 3600);
    $minutes = floor(($totalSeconds % 3600) / 60);
    $seconds = $totalSeconds % 60;
    return sprintf("%02d:%02d:%02d", $hours, $minutes, $seconds);
}

// Fetch data for today and yesterday
$todayData = fetchDataForDate($conn, $todayDate, $ids, $names, $departments, $roles, $designations, $projects, $shifts, $teams, $userid, $reportType);
$yesterdayData = fetchDataForDate($conn, $yesterdayDate, $ids, $names, $departments, $roles, $designations, $projects, $shifts, $teams, $userid, $reportType);

// Calculate averages
$todayAverage = calculateAverage($todayData);
$yesterdayAverage = calculateAverage($yesterdayData);

// Calculate percentage changes
$percentageChanges = [
    'logged_hours' => calculatePercentageChange($todayAverage['logged_hours'], $yesterdayAverage['logged_hours']),
    'idle_hours' => calculatePercentageChange($todayAverage['idle_hours'], $yesterdayAverage['idle_hours']),
    'productive_hours' => calculatePercentageChange($todayAverage['productive_hours'], $yesterdayAverage['productive_hours']),
    'time_on_system' => calculatePercentageChange($todayAverage['time_on_system'], $yesterdayAverage['time_on_system']),
    'time_away_from_system' => calculatePercentageChange($todayAverage['time_away_from_system'], $yesterdayAverage['time_away_from_system'])
];

error_log("Percentage changes calculated: " . print_r($percentageChanges, true));

// Prepare response
$response = [
    'success' => true,
    'today' => [
        'date' => $todayDate,
        'logged_hours' => formatSecondsToHMS($todayAverage['logged_hours']),
        'idle_hours' => formatSecondsToHMS($todayAverage['idle_hours']),
        'productive_hours' => formatSecondsToHMS($todayAverage['productive_hours']),
        'time_on_system' => formatSecondsToHMS($todayAverage['time_on_system']),
        'time_away_from_system' => formatSecondsToHMS($todayAverage['time_away_from_system']),
        'logged_hours_seconds' => round($todayAverage['logged_hours']),
        'idle_hours_seconds' => round($todayAverage['idle_hours']),
        'productive_hours_seconds' => round($todayAverage['productive_hours']),
        'time_on_system_seconds' => round($todayAverage['time_on_system']),
        'time_away_from_system_seconds' => round($todayAverage['time_away_from_system']),
        'count' => $todayData['count']
    ],
    'yesterday' => [
        'date' => $yesterdayDate,
        'logged_hours' => formatSecondsToHMS($yesterdayAverage['logged_hours']),
        'idle_hours' => formatSecondsToHMS($yesterdayAverage['idle_hours']),
        'productive_hours' => formatSecondsToHMS($yesterdayAverage['productive_hours']),
        'time_on_system' => formatSecondsToHMS($yesterdayAverage['time_on_system']),
        'time_away_from_system' => formatSecondsToHMS($yesterdayAverage['time_away_from_system']),
        'logged_hours_seconds' => round($yesterdayAverage['logged_hours']),
        'idle_hours_seconds' => round($yesterdayAverage['idle_hours']),
        'productive_hours_seconds' => round($yesterdayAverage['productive_hours']),
        'time_on_system_seconds' => round($yesterdayAverage['time_on_system']),
        'time_away_from_system_seconds' => round($yesterdayAverage['time_away_from_system']),
        'count' => $yesterdayData['count']
    ],
    'percentageChange' => [
        'logged_hours' => round($percentageChanges['logged_hours'], 1),
        'idle_hours' => round($percentageChanges['idle_hours'], 1),
        'productive_hours' => round($percentageChanges['productive_hours'], 1),
        'time_on_system' => round($percentageChanges['time_on_system'], 1),
        'time_away_from_system' => round($percentageChanges['time_away_from_system'], 1)
    ],
    'comparison' => [
        'logged_hours' => [
            'direction' => $percentageChanges['logged_hours'] > 0 ? 'up' : ($percentageChanges['logged_hours'] < 0 ? 'down' : 'neutral'),
            'percentage' => abs(round($percentageChanges['logged_hours'], 1))
        ],
        'idle_hours' => [
            'direction' => $percentageChanges['idle_hours'] > 0 ? 'up' : ($percentageChanges['idle_hours'] < 0 ? 'down' : 'neutral'),
            'percentage' => abs(round($percentageChanges['idle_hours'], 1))
        ],
        'productive_hours' => [
            'direction' => $percentageChanges['productive_hours'] > 0 ? 'up' : ($percentageChanges['productive_hours'] < 0 ? 'down' : 'neutral'),
            'percentage' => abs(round($percentageChanges['productive_hours'], 1))
        ],
        'time_on_system' => [
            'direction' => $percentageChanges['time_on_system'] > 0 ? 'up' : ($percentageChanges['time_on_system'] < 0 ? 'down' : 'neutral'),
            'percentage' => abs(round($percentageChanges['time_on_system'], 1))
        ],
        'time_away_from_system' => [
            'direction' => $percentageChanges['time_away_from_system'] > 0 ? 'up' : ($percentageChanges['time_away_from_system'] < 0 ? 'down' : 'neutral'),
            'percentage' => abs(round($percentageChanges['time_away_from_system'], 1))
        ]
    ]
];

// Return JSON response
echo json_encode($response, JSON_PRETTY_PRINT);

// Close the connection
$conn->close();
?>