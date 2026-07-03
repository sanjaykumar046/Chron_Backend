<?php
error_reporting(0);
ini_set('display_errors', 0);
include 'apiMain.php';

// Get parameters with default values
$inputData = json_decode(file_get_contents("php://input"), true);

$startDate = isset($inputData['startDate']) ? $inputData['startDate'] : date('Y-m-d', strtotime('yesterday - 6 days'));
$endDate = isset($inputData['endDate']) ? $inputData['endDate'] : date('Y-m-d', strtotime('yesterday'));
$departments = isset($inputData['selectedDepartments']) ? $inputData['selectedDepartments'] : ['ALL'];
$roles = isset($inputData['roles']) ? $inputData['roles'] : ['ALL'];
$projects = isset($inputData['selectedProjects']) ? $inputData['selectedProjects'] : ['ALL'];
$shifts = isset($inputData['selectedShift']) ? $inputData['selectedShift'] : ['ALL'];
$teams = isset($inputData['selectedTeams']) ? $inputData['selectedTeams'] : ['ALL'];
$ids = isset($inputData['ids']) ? $inputData['ids'] : ['ALL'];
$names = isset($inputData['names']) ? $inputData['names'] : ['ALL'];
$designations = isset($inputData['designations']) ? $inputData['designations'] : ['ALL'];
$userid = isset($inputData['EMPID']) ? $inputData['EMPID'] : NULL;
$reportType = isset($inputData['reportType']) ? $inputData['reportType'] : 'GROUP_REPORT'; // Default to GROUP_REPORT

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
$aggregate_data = [];
$recordCount = 0;

// Prepare and execute stored procedure with new procedure name and report type
if ($stmt = $conn->prepare("CALL PR_EMPLOYEE_ACTIVITY_FLAT(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")) {
    $stmt->bind_param('ssssssssssss', $startDate, $endDate, $ids, $names, $departments, $roles, $designations, $projects, $shifts, $teams, $userid, $reportType);
    $stmt->execute();
    
    // Fetch results
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        // Collect only the required fields
        $aggregate_data[] = [
            'EmpID' => $row['EmpID'],
            'EmpName' => $row['EmpName'],
            'Departments' => $row['Department'],
            'Projects' => $row['Project'],
            'Team' => $row['Team'],
            'Shifts' => $row['SHIFT'],
            'TotalLoggedHours' => $row['TotalLoggedHours'],
            'TotalIdleHours' => $row['TotalIdleHours'],
            'TotalProductiveHours' => $row['TotalProductiveHours'],
            'TOTAL_ON_SYSTEM' => $row['TOTAL_ON_SYSTEM'],
            'AwayFromSystem' => $row['AwayFromSystem']
        ];
        $recordCount++;
    }
    
    // Free result and close statement
    $result->free();
    $stmt->close();
} else {
    echo json_encode(['error' => 'Failed to prepare statement: ' . $conn->error]);
    $conn->close();
    exit;
}

// Close connection
$conn->close();

// Return JSON response
echo json_encode([
    'data' => $aggregate_data,
    'recordCount' => $recordCount,
    'reportType' => $reportType,
    'filters' => [
        'startDate' => $startDate,
        'endDate' => $endDate,
        'departments' => $departments,
        'roles' => $roles,
        'designations' => $designations,
        'projects' => $projects,
        'shifts' => $shifts,
        'teams' => $teams
    ]
]);
?>