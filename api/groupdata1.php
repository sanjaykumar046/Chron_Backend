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
$workMode = isset($inputData['work_mode']) ? strtoupper(trim((string)$inputData['work_mode'])) : 'ALL';
if (!in_array($workMode, ['ALL', 'WFO', 'WFH'], true)) {
    $workMode = 'ALL';
}
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

// PR_EMPLOYEE_ACTIVITY_FLAT expects 13 inputs, including P_WORK_MODE.
$stmt = $conn->prepare("CALL PR_EMPLOYEE_ACTIVITY_FLAT(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to prepare statement: ' . $conn->error]);
    $conn->close();
    exit;
}

$stmt->bind_param(
    'sssssssssssss',
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
    $workMode,
    $userid,
    $reportType
);

if (!$stmt->execute()) {
    http_response_code(500);
    $error = $stmt->error;
    $stmt->close();
    echo json_encode(['error' => 'Procedure execution failed: ' . $error]);
    $conn->close();
    exit;
}

$result = $stmt->get_result();
if ($result) {
    while ($row = $result->fetch_assoc()) {
        // Collect only the required fields.
        $aggregate_data[] = [
            'EmpID' => $row['EmpID'] ?? null,
            'EmpName' => $row['EmpName'] ?? null,
            'Departments' => $row['Department'] ?? null,
            'Projects' => $row['Project'] ?? null,
            'Team' => $row['Team'] ?? null,
            'Shifts' => $row['SHIFT'] ?? null,
            'TotalLoggedHours' => $row['TotalLoggedHours'] ?? '00:00:00',
            'TotalIdleHours' => $row['TotalIdleHours'] ?? '00:00:00',
            'TotalProductiveHours' => $row['TotalProductiveHours'] ?? '00:00:00',
            'TOTAL_ON_SYSTEM' => $row['TOTAL_ON_SYSTEM'] ?? '00:00:00',
            'AwayFromSystem' => $row['AwayFromSystem'] ?? '00:00:00'
        ];
        $recordCount++;
    }
    $result->free();
}

$stmt->close();
while ($conn->more_results() && $conn->next_result()) {
    $extra = $conn->use_result();
    if ($extra instanceof mysqli_result) {
        $extra->free();
    }
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
        'teams' => $teams,
        'workMode' => $workMode
    ]
]);
?>