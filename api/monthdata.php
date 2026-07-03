<?php
include 'apiMain.php';

// Function to sanitize and escape input
function sanitizeInput($conn, $input) {
    return $conn->real_escape_string(trim($input));
}

// Get filter parameters with default "ALL"
// Get filter parameters with default "ALL"
$departments = isset($_GET['department']) ? $_GET['department'] : ['ALL'];
$roles = isset($_GET['role']) ? $_GET['role'] : ['ALL'];
$projects = isset($_GET['project']) ? $_GET['project'] : ['ALL'];
$shifts = isset($_GET['shift']) ? $_GET['shift'] : ['ALL'];
$teams = isset($_GET['team']) ? $_GET['team'] : ['ALL'];
$ids = isset($_GET['ids']) ?  $_GET['ids'] : ['ALL'];
$names = isset($_GET['names']) ?  $_GET['names'] : ['ALL'];
$userid = isset($_GET['userid']) ? $_GET['userid'] :NULL;
$designations=isset($_GET['designations'])?$_GET['designations']:['ALL'];
$empid = isset($_GET['empid']) ? $_GET['empid'] : null;
// Calculate the start and end dates for the month and year
// Get filter parameters with default "current month" and "current year"
$month = isset($_GET['month']) ? $_GET['month'] : [date('F')]; // Default to current month as an array
$year = isset($_GET['year']) ? $_GET['year'] : date('Y'); // Default to current year
$empid = isset($_GET['empid']) ? $_GET['empid'] : null;

if ($empid && !validateEmpId($empid)) {
    echo json_encode(['error' => 'Invalid employee ID format']);
    exit;
}
// Use the first element if multiple months are provided
$month = is_array($month) ? $month[0] : $month;

// Validate and sanitize the month and year inputs
$validMonths = [
    'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December'
];

if (!in_array($month, $validMonths)) {
    $month = date('F'); // Reset to current month if invalid
}

if (!is_numeric($year) || strlen($year) !== 4) {
    $year = date('Y'); // Reset to current year if invalid
}

// Calculate the start and end dates for the specified month and year
$startDate = date('Y-m-01', strtotime("$year-$month"));
$endDate = date('Y-m-t', strtotime("$year-$month"));
// Convert arrays to CSV format for stored procedure parameters
function arrayToCsv($arr, $conn) {
    return implode(',', array_map(function($item) use ($conn) {
        return sanitizeInput($conn, $item);
    }, $arr));
}

$departmentsCsv = arrayToCsv($departments, $conn);
$rolesCsv = arrayToCsv($roles, $conn);
$projectsCsv = arrayToCsv($projects, $conn);
$shiftsCsv = arrayToCsv($shifts, $conn);
$teamsCsv = arrayToCsv($teams, $conn);
$idsCsv = arrayToCsv($ids, $conn);
$namesCsv = arrayToCsv($names, $conn);
$designationsCsv = arrayToCsv($designations, $conn);

// Function to fetch aggregate data by date
function getAggregateByDate($conn, $startDate, $endDate,$idsCsv,$namesCsv, $departmentsCsv, $rolesCsv,$designationsCsv, $projectsCsv, $shiftsCsv, $teamsCsv, $userid) {
    if ($stmt = $conn->prepare("CALL PR_EMPLOYEE_ACTIVITY(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")) {
        $stmt->bind_param('sssssssssss', $startDate, $endDate,$idsCsv,$namesCsv, $departmentsCsv, $rolesCsv,$designationsCsv, $projectsCsv, $shiftsCsv, $teamsCsv, $userid);
        
        $stmt->execute();
        
        // Fetch the result
        $result = $stmt->get_result();
        $data = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        
        // Get column names
        $columns = [];
        $fieldInfo = $result->fetch_fields();
        foreach ($fieldInfo as $field) {
            $columns[] = $field->name;
        }
        
        // Close the statement
        $stmt->close();
        
        return ['data' => $data, 'columns' => $columns];
        
    } else {
        return ['error' => 'Failed to prepare the statement'];
    }
}

// In your addTimes function
function addTimes($time1, $time2) {
    list($h1, $m1, $s1) = explode(':', $time1);
    list($h2, $m2, $s2) = explode(':', $time2);

    if (is_numeric($h1) && is_numeric($m1) && is_numeric($s1) && is_numeric($h2) && is_numeric($m2) && is_numeric($s2)) {
        $seconds = ($h1 * 3600 + $m1 * 60 + $s1) + ($h2 * 3600 + $m2 * 60 + $s2);
        $hours = (int)floor($seconds / 3600); // Explicit casting
        $minutes = (int)floor(($seconds % 3600) / 60); // Explicit casting
        $seconds = (int)($seconds % 60); // Explicit casting
        return sprintf("%02d:%02d:%02d", $hours, $minutes, $seconds);
    } else {
        return '00:00:00';
    }
}

function divideTime($time, $count) {
    list($h, $m, $s) = explode(':', $time);
    $totalSeconds = ($h * 3600) + ($m * 60) + $s;
    $averageSeconds = $totalSeconds / $count;
    $hours = (int)floor($averageSeconds / 3600); // Explicit casting
    $minutes = (int)floor(($averageSeconds % 3600) / 60); // Explicit casting
    $seconds = (int)($averageSeconds % 60); // Explicit casting
    return sprintf("%02d:%02d:%02d", $hours, $minutes, $seconds);
}

// Ensure all places where you perform division result in integers
// Function to fetch unique values for filters
function fetchUniqueValues($conn, $column) {
    $sql = "SELECT DISTINCT $column FROM EMP_DB";
    $result = $conn->query($sql);
    $values = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $values[] = $row[$column];
        }
    }
    return $values;
}

// Call the function to get aggregate data by date
$result = getAggregateByDate($conn, $startDate, $endDate,$idsCsv,$namesCsv, $departmentsCsv, $rolesCsv,$designationsCsv, $projectsCsv, $shiftsCsv, $teamsCsv, $userid);
$aggregateData = $result['data'];
$columns = $result['columns'];

// Aggregate data by date
$aggregatedData = [];
$data12 = [];
$dateCounts = []; // Keep track of counts per date

foreach ($aggregateData as $data) {
    $date = $data['Date'];
    if (!isset($aggregatedData[$date])) {
        $aggregatedData[$date] = [
            'total_logged_hours' => '00:00:00',
            'total_idle_hours' => '00:00:00',
            'total_productive_hours' => '00:00:00',
            'total_time_on_system' => '00:00:00',
            'total_time_away_from_system' => '00:00:00',
        ];
        $dateCounts[$date] = 0;
    }

    $aggregatedData[$date]['total_logged_hours'] = addTimes($aggregatedData[$date]['total_logged_hours'], $data['TotalLoggedHours'] ?? '00:00:00');
    $aggregatedData[$date]['total_idle_hours'] = addTimes($aggregatedData[$date]['total_idle_hours'], $data['TotalIdleHours'] ?? '00:00:00');
    $aggregatedData[$date]['total_productive_hours'] = addTimes($aggregatedData[$date]['total_productive_hours'], $data['TotalProductiveHours'] ?? '00:00:00');
    $aggregatedData[$date]['total_time_on_system'] = addTimes($aggregatedData[$date]['total_time_on_system'], $data['TOTAL_ON_SYSTEM'] ?? '00:00:00');
    $aggregatedData[$date]['total_time_away_from_system'] = addTimes($aggregatedData[$date]['total_time_away_from_system'], $data['AwayFromSystem'] ?? '00:00:00');

    $dateCounts[$date]++;
}

// Calculate averages
foreach ($aggregatedData as $date => $times) {
    $count = $dateCounts[$date];
    $aggregatedData[$date]['total_logged_hours'] = divideTime($times['total_logged_hours'], $count);
    $aggregatedData[$date]['total_idle_hours'] = divideTime($times['total_idle_hours'], $count);
    $aggregatedData[$date]['total_productive_hours'] = divideTime($times['total_productive_hours'], $count);
    $aggregatedData[$date]['total_time_on_system'] = divideTime($times['total_time_on_system'], $count);
    $aggregatedData[$date]['total_time_away_from_system'] = divideTime($times['total_time_away_from_system'], $count);
}

// Fetch unique values for filters
$uniqueDepartments = fetchUniqueValues($conn, 'Department');
$uniqueRoles = fetchUniqueValues($conn, 'ROLE');
$uniqueProjects = fetchUniqueValues($conn, 'Project');
$uniqueShifts = fetchUniqueValues($conn, 'Shift');
$uniqueTeams = fetchUniqueValues($conn, 'Team');
$uniqueDesignations=fetchUniqueValues($conn,'DESIGNATION_CATEGORY');
$uniqueEMPid = fetchUniqueValues($conn, 'EMPID');
$uniqueEMPName = fetchUniqueValues($conn, 'EMPNAME');
// Prepare the final response
$response = [
    'receivedParameters' => [
        'receivedParameters' => [
        'month' => $month,
        'year' => $year,
        'startDate' => $startDate,
        'endDate' => $endDate
    ],
    ],
    'aggregateByDate' => $aggregatedData,
    'uniqueDepartments' => $uniqueDepartments,
    'uniqueRoles' => $uniqueRoles,
    'uniqueProjects' => $uniqueProjects,
    'uniqueShifts' => $uniqueShifts,
    'uniqueTeams' => $uniqueTeams,
    'uniqueids'=>$uniqueEMPid,
    'uniquename'=>$uniqueEMPName,
    'data12' => $aggregateData,  // Raw data
    'columns' => $columns,
    'uniquedesignations'=>$uniqueDesignations       // Column names

];

// Send the JSON response
header('Content-Type: application/json');
echo json_encode($response);

// Close the connection
$conn->close();
?>
