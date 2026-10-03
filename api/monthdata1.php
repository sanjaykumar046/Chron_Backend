<?php
include 'apiMain.php';

function sanitizeInput($conn, $input) {
    return $conn->real_escape_string(trim($input));
}

$departments = isset($_GET['department']) ? explode(',', $_GET['department']) : ['ALL'];
$roles = isset($_GET['role']) ? explode(',', $_GET['role']) : ['ALL'];
$projects = isset($_GET['project']) ? explode(',', $_GET['project']) : ['ALL'];
$shifts = isset($_GET['shift']) ? explode(',', $_GET['shift']) : ['ALL'];
$teams = isset($_GET['team']) ? explode(',', $_GET['team']) : ['ALL'];
$ids = isset($_GET['ids']) ? explode(',', $_GET['ids']) : ['ALL'];
$names = isset($_GET['names']) ? explode(',', $_GET['names']) : ['ALL'];
$userid = isset($_GET['userid']) ? $_GET['userid'] : NULL;
$designations = isset($_GET['designations']) ? explode(',', $_GET['designations']) : ['ALL'];
$reportType = isset($_GET['reportType']) ? $_GET['reportType'] : 'GROUP_REPORT';

$procedureReportType = ($reportType === 'MONTH_EXPORT') ? 'MONTHLY_EXPORT' : $reportType;
$empid = isset($_GET['empid']) ? $_GET['empid'] : null;
$specificDate = isset($_GET['date']) ? $_GET['date'] : null;

$yesterday = new DateTime('yesterday');
$month = isset($_GET['month']) ? $_GET['month'] : $yesterday->format('F');
$year = isset($_GET['year']) ? $_GET['year'] : $yesterday->format('Y');

if ($empid && !validateEmpId($empid)) {
    echo json_encode(['error' => 'Invalid employee ID format']);
    exit;
}

$month = is_array($month) ? $month[0] : $month;

$validMonths = [
    'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December'
];

if (!in_array($month, $validMonths)) {
    $month = date('F');
}

if (!is_numeric($year) || strlen($year) !== 4) {
    $year = date('Y');
}

if ($reportType === 'MONTH_EXPORT' && $specificDate) {
    $dateObj = DateTime::createFromFormat('Y-m-d', $specificDate);
    if ($dateObj && $dateObj->format('Y-m-d') === $specificDate) {
        $startDate = $specificDate;
        $endDate = $specificDate;
    } else {
        $startDate = date('Y-m-01', strtotime("$year-$month"));
        $monthEndDate = date('Y-m-t', strtotime("$year-$month"));
        $yesterdayDate = date('Y-m-d', strtotime('yesterday'));
        $endDate = (strtotime($monthEndDate) > strtotime($yesterdayDate)) ? $yesterdayDate : $monthEndDate;
    }
} else {
    $startDate = date('Y-m-01', strtotime("$year-$month"));
    $monthEndDate = date('Y-m-t', strtotime("$year-$month"));
    $yesterdayDate = date('Y-m-d', strtotime('yesterday'));
    $endDate = (strtotime($monthEndDate) > strtotime($yesterdayDate)) ? $yesterdayDate : $monthEndDate;
}

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

function getAggregateByDate($conn, $startDate, $endDate, $idsCsv, $namesCsv, $departmentsCsv, $rolesCsv, $designationsCsv, $projectsCsv, $shiftsCsv, $teamsCsv, $workMode, $userid, $reportType) {
    $stmt = $conn->prepare("CALL PR_EMPLOYEE_ACTIVITY_FLAT(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        return ['error' => 'Failed to prepare the statement: ' . $conn->error];
    }

    $stmt->bind_param(
        'sssssssssssss',
        $startDate,
        $endDate,
        $idsCsv,
        $namesCsv,
        $departmentsCsv,
        $rolesCsv,
        $designationsCsv,
        $projectsCsv,
        $shiftsCsv,
        $teamsCsv,
        $workMode,
        $userid,
        $reportType
    );

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        return ['error' => 'Failed to execute procedure: ' . $error];
    }

    $result = $stmt->get_result();
    $data = [];
    $columns = [];

    if ($result) {
        foreach ($result->fetch_fields() as $field) {
            $columns[] = $field->name;
        }
        $data = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
    }

    $stmt->close();

    while ($conn->more_results() && $conn->next_result()) {
        $extra = $conn->use_result();
        if ($extra instanceof mysqli_result) {
            $extra->free();
        }
    }

    return ['data' => $data, 'columns' => $columns];
}

function addTimes($time1, $time2) {
    list($h1, $m1, $s1) = explode(':', $time1);
    list($h2, $m2, $s2) = explode(':', $time2);

    if (is_numeric($h1) && is_numeric($m1) && is_numeric($s1) && is_numeric($h2) && is_numeric($m2) && is_numeric($s2)) {
        $seconds = ($h1 * 3600 + $m1 * 60 + $s1) + ($h2 * 3600 + $m2 * 60 + $s2);
        $hours = (int)floor($seconds / 3600);
        $minutes = (int)floor(($seconds % 3600) / 60);
        $seconds = (int)($seconds % 60);
        return sprintf("%02d:%02d:%02d", $hours, $minutes, $seconds);
    } else {
        return '00:00:00';
    }
}

function divideTime($time, $count) {
    list($h, $m, $s) = explode(':', $time);
    $totalSeconds = ($h * 3600) + ($m * 60) + $s;
    $averageSeconds = $totalSeconds / $count;
    $hours = (int)floor($averageSeconds / 3600);
    $minutes = (int)floor(($averageSeconds % 3600) / 60);
    $seconds = (int)($averageSeconds % 60);
    return sprintf("%02d:%02d:%02d", $hours, $minutes, $seconds);
}

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

$result = getAggregateByDate($conn, $startDate, $endDate, $idsCsv, $namesCsv, $departmentsCsv, $rolesCsv, $designationsCsv, $projectsCsv, $shiftsCsv, $teamsCsv, 'ALL', $userid, $procedureReportType);

if (isset($result['error'])) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => $result['error']]);
    $conn->close();
    exit;
}

$aggregateData = $result['data'];
$columns = $result['columns'];

if ($reportType === 'MONTH_EXPORT') {
    $response = [
        'receivedParameters' => [
            'month' => $month,
            'year' => $year,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'reportType' => $reportType,
            'procedureReportType' => $procedureReportType,
            'specificDate' => $specificDate
        ],
        'data12' => $aggregateData,
        'columns' => $columns,
        'dataCount' => count($aggregateData)
    ];
    
    header('Content-Type: application/json');
    echo json_encode($response);
    
    $conn->close();
    exit;
}

$aggregatedData = [];
$dateCounts = [];

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

foreach ($aggregatedData as $date => $times) {
    $count = $dateCounts[$date];
    $aggregatedData[$date]['total_logged_hours'] = divideTime($times['total_logged_hours'], $count);
    $aggregatedData[$date]['total_idle_hours'] = divideTime($times['total_idle_hours'], $count);
    $aggregatedData[$date]['total_productive_hours'] = divideTime($times['total_productive_hours'], $count);
    $aggregatedData[$date]['total_time_on_system'] = divideTime($times['total_time_on_system'], $count);
    $aggregatedData[$date]['total_time_away_from_system'] = divideTime($times['total_time_away_from_system'], $count);
}

$uniqueDepartments = fetchUniqueValues($conn, 'Department');
$uniqueRoles = fetchUniqueValues($conn, 'ROLE');
$uniqueProjects = fetchUniqueValues($conn, 'Project');
$uniqueShifts = fetchUniqueValues($conn, 'Shift');
$uniqueTeams = fetchUniqueValues($conn, 'Team');
$uniqueDesignations = fetchUniqueValues($conn, 'DESIGNATION_CATEGORY');
$uniqueEMPid = fetchUniqueValues($conn, 'EMPID');
$uniqueEMPName = fetchUniqueValues($conn, 'EMPNAME');

$response = [
    'receivedParameters' => [
        'month' => $month,
        'year' => $year,
        'startDate' => $startDate,
        'endDate' => $endDate,
        'reportType' => $reportType
    ],
    'aggregateByDate' => $aggregatedData,
    'uniqueDepartments' => $uniqueDepartments,
    'uniqueRoles' => $uniqueRoles,
    'uniqueProjects' => $uniqueProjects,
    'uniqueShifts' => $uniqueShifts,
    'uniqueTeams' => $uniqueTeams,
    'uniqueids' => $uniqueEMPid,
    'uniquename' => $uniqueEMPName,
    'data12' => $aggregateData,
    'columns' => $columns,
    'uniquedesignations' => $uniqueDesignations
];

header('Content-Type: application/json');
echo json_encode($response);

$conn->close();
?>
