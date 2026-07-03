<?php
include 'apiMain.php';

// Get parameters with default values
$endDate = isset($_GET['dateRange']['end']) ? $_GET['dateRange']['end'] : date('Y-m-d', strtotime('yesterday'));
$startDate = isset($_GET['dateRange']['start']) ? $_GET['dateRange']['start'] : date('Y-m-d', strtotime('yesterday - 6 days'));
$departments = isset($_GET['department']) ? $_GET['department'] : ['ALL'];
$roles = isset($_GET['role']) ? $_GET['role'] : ['ALL'];
$projects = isset($_GET['project']) ? $_GET['project'] : ['ALL'];
$shifts = isset($_GET['shift']) ? $_GET['shift'] : ['ALL'];
$teams = isset($_GET['team']) ? $_GET['team'] : ['ALL'];
$ids = isset($_GET['ids']) ? $_GET['ids'] : ['ALL'];
$names = isset($_GET['names']) ? $_GET['names'] : ['ALL'];
$designations = isset($_GET['designations']) ? $_GET['designations'] : ['ALL'];
$userid = isset($_GET['userid']) ? $_GET['userid'] : NULL;
$reportType = isset($_GET['reportType']) ? $_GET['reportType'] : 'GROUP_REPORT'; // Default to GROUP_REPORT

// Convert arrays to comma-separated strings for stored procedure parameters
$departments = implode(",", array_map([$conn, 'real_escape_string'], $departments));
$roles = implode(",", array_map([$conn, 'real_escape_string'], $roles));
$projects = implode(",", array_map([$conn, 'real_escape_string'], $projects));
$shifts = implode(",", array_map([$conn, 'real_escape_string'], $shifts));
$teams = implode(",", array_map([$conn, 'real_escape_string'], $teams));
$ids = implode(",", array_map([$conn, 'real_escape_string'], $ids));
$names = implode(",", array_map([$conn, 'real_escape_string'], $names));
$designations = implode(",", array_map([$conn, 'real_escape_string'], $designations));

// Initialize variables for aggregation
$aggregate_data_by_date = [];
$recordCount = 0;

// Prepare and execute stored procedure with new parameter
if ($stmt = $conn->prepare("CALL PR_EMPLOYEE_ACTIVITY_FLAT(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")) {
    $stmt->bind_param('ssssssssssss', $startDate, $endDate, $ids, $names, $departments, $roles, $designations, $projects, $shifts, $teams, $userid, $reportType);
    if (!$stmt->execute()) {
        http_response_code(500);
        die(json_encode([
            "error" => "Execution failed: " . $stmt->error,
            "data1" => [],
            "columns" => [],
            "aggregateByDate" => [],
            "totals" => [],
            "unique_empid_count" => 0
        ]));
    }

    $result_data = $stmt->get_result();
    if ($result_data) {
        $fields = $result_data->fetch_fields();
        $columns = array_map(function($field) {
            return $field->name;
        }, $fields);

        while ($row = $result_data->fetch_assoc()) {
            $data[] = $row;
            $recordCount++;
            
            // Aggregate data for each date
            $date = $row['Date'];
            if (!isset($aggregate_data_by_date[$date])) {
                $aggregate_data_by_date[$date] = initializeAggregateData();
            }
            aggregateData($aggregate_data_by_date[$date], $row);
        }
        $result_data->free();
    }
    $stmt->close();
} else {
    die(json_encode(["error" => "Failed to prepare statement."]));
}

// Function to initialize aggregate data
function initializeAggregateData() {
    return [
        'total_logged_hours' => 0,
        'total_idle_hours' => 0,
        'total_productive_hours' => 0,
        'total_time_on_system' => 0,
        'total_time_away_from_system' => 0,
        'count' => 0
    ];
}

// Function to aggregate data
function aggregateData(&$aggregate, $row) {
    $aggregate['total_logged_hours'] += convertToSeconds($row['TotalLoggedHours']);
    $aggregate['total_idle_hours'] += convertToSeconds($row['TotalIdleHours']);
    $aggregate['total_productive_hours'] += convertToSeconds($row['TotalProductiveHours']);
    $aggregate['total_time_on_system'] += convertToSeconds($row['TOTAL_ON_SYSTEM']);
    $aggregate['total_time_away_from_system'] += convertToSeconds($row['AwayFromSystem']);
    $aggregate['count']++;
}

// Calculate totals and assign average logged hours to total logged hours
function calculateTotalsAndAverages(&$aggregate) {
    $totals = [
        'total_logged_hours' => 0,
        'total_idle_hours' => 0,
        'total_productive_hours' => 0,
        'total_time_on_system' => 0,
        'total_time_away_from_system' => 0,
        'average_logged_hours' => 0,
        'average_idle_hours' => 0,
        'average_productive_hours' => 0,
        'average_time_on_system' => 0,
        'average_time_away_from_system' => 0
    ];

    $count = 0;

    foreach ($aggregate as $data) {
        $totals['total_logged_hours'] += $data['total_logged_hours'];
        $totals['total_idle_hours'] += $data['total_idle_hours'];
        $totals['total_productive_hours'] += $data['total_productive_hours'];
        $totals['total_time_on_system'] += $data['total_time_on_system'];
        $totals['total_time_away_from_system'] += $data['total_time_away_from_system'];
        $count += $data['count'];
    }

    if ($count > 0) {
        $totals['average_logged_hours'] = $totals['total_logged_hours'] / $count;
        $totals['total_logged_hours'] = $totals['average_logged_hours'];
        $totals['average_idle_hours'] = $totals['total_idle_hours'] / $count;
        $totals['total_idle_hours'] = $totals['average_idle_hours'];
        $totals['average_productive_hours'] = $totals['total_productive_hours'] / $count;
        $totals['total_productive_hours'] = $totals['average_productive_hours'];
        $totals['average_time_on_system'] = $totals['total_time_on_system'] / $count;
        $totals['total_time_on_system'] = $totals['average_time_on_system'];
        $totals['average_time_away_from_system'] = $totals['total_time_away_from_system'] / $count;
        $totals['total_time_away_from_system'] = $totals['average_time_away_from_system'];
    }

    return array_map('formatSecondsToHMS', $totals);
}

// Helper function to convert time strings to total seconds
function convertToSeconds($timeString) {
    if (empty($timeString)) return 0;
    list($hours, $minutes, $seconds) = explode(':', $timeString);
    return ($hours * 3600) + ($minutes * 60) + (int)$seconds;
}

// Format total seconds to hh:mm:ss
function formatSecondsToHMS($totalSeconds) {
    return gmdate("H:i:s", $totalSeconds);
}

// Get unique employee count
$sql_count = "SELECT COUNT(DISTINCT EmpID) AS unique_empid_count FROM EMP_DB";
$result = $conn->query($sql_count);
$unique_empid_count = $result ? $result->fetch_assoc()['unique_empid_count'] : "Error: " . $conn->error;

// Function to fetch unique values for dropdowns
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

// Fetch unique values for filters
$uniqueDepartments = fetchUniqueValues($conn, 'Department');
$uniqueRoles = fetchUniqueValues($conn, 'ROLE');
$uniqueProjects = fetchUniqueValues($conn, 'Project');
$uniqueShifts = fetchUniqueValues($conn, 'Shift');
$uniqueTeams = fetchUniqueValues($conn, 'Team');
$uniqueEMPid = fetchUniqueValues($conn, 'EMPID');
$uniqueEMPName = fetchUniqueValues($conn, 'EMPNAME');
$uniqueDesignation = fetchUniqueValues($conn, 'DESIGNATION_CATEGORY');

// Calculate totals and averages
$totals = calculateTotalsAndAverages($aggregate_data_by_date);

// Prepare response
$response = [
    'receivedParameters' => [
        'dateRange' => [
            'start' => $startDate ?: 'Not Set',
            'end' => $endDate ?: 'Not Set'
        ],
        'department' => $departments !== 'ALL' ? explode(',', $departments) : null,
        'role' => $roles !== 'ALL' ? explode(',', $roles) : null,
        'project' => $projects !== 'ALL' ? explode(',', $projects) : null,
        'shift' => $shifts !== 'ALL' ? explode(',', $shifts) : null,
        'team' => $teams !== 'ALL' ? explode(',', $teams) : null,
        'ids' => $ids !== 'ALL' ? explode(',', $ids) : null,
        'names' => $names !== 'ALL' ? explode(',', $names) : null,
        'designations' => $designations !== 'ALL' ? explode(',', $designations) : null,
        'reportType' => $reportType
    ],
    'columns' => $columns,
    'data1' => $data ?? [],
    'unique_empid_count' => $unique_empid_count,
    'aggregateByDate' => array_map(function($agg) {
        return [
            'total_logged_hours' => formatSecondsToHMS($agg['total_logged_hours'] / ($agg['count'] > 0 ? $agg['count'] : 1)),
            'total_idle_hours' => formatSecondsToHMS($agg['total_idle_hours'] / ($agg['count'] > 0 ? $agg['count'] : 1)),
            'total_productive_hours' => formatSecondsToHMS($agg['total_productive_hours'] / ($agg['count'] > 0 ? $agg['count'] : 1)),
            'total_time_on_system' => formatSecondsToHMS($agg['total_time_on_system'] / ($agg['count'] > 0 ? $agg['count'] : 1)),
            'total_time_away_from_system' => formatSecondsToHMS($agg['total_time_away_from_system'] / ($agg['count'] > 0 ? $agg['count'] : 1)),
        ];
    }, $aggregate_data_by_date),
    'totals' => $totals,
    'uniqueDepartments' => $uniqueDepartments,
    'uniqueRoles' => $uniqueRoles,
    'uniqueProjects' => $uniqueProjects,
    'uniqueShifts' => $uniqueShifts,
    'uniqueTeams' => $uniqueTeams,
    'uniqueids' => $uniqueEMPid,
    'uniquename' => $uniqueEMPName,
    'uniqueDesignation' => $uniqueDesignation
];

// Return JSON response
echo json_encode($response);

// Close the connection
$conn->close();