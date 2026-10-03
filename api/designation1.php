<?php
include 'apiMain.php';

// Get the input from the request
$input = json_decode(file_get_contents('php://input'), true);

// Extract filter parameters
$startDate = isset($input['startDate']) ? $input['startDate'] : null;
$endDate = isset($input['endDate']) ? $input['endDate'] : null;

// Handle empty array inputs
$ids = !empty($input['EMPID']) && is_array($input['EMPID']) ? implode(',', $input['EMPID']) : 'ALL';
$names = !empty($input['EMPNAME']) && is_array($input['EMPNAME']) ? implode(',', $input['EMPNAME']) : 'ALL';
$departments = !empty($input['DEPARTMENT']) && is_array($input['DEPARTMENT']) ? implode(',', $input['DEPARTMENT']) : 'ALL';
$roles = !empty($input['ROLE']) && is_array($input['ROLE']) ? implode(',', $input['ROLE']) : 'ALL';
$projects = !empty($input['PROJECT']) && is_array($input['PROJECT']) ? implode(',', $input['PROJECT']) : 'ALL';
$shifts = !empty($input['SHIFT']) && is_array($input['SHIFT']) ? implode(',', $input['SHIFT']) : 'ALL';
$teams = !empty($input['TEAM']) && is_array($input['TEAM']) ? implode(',', $input['TEAM']) : 'ALL';
$designations = !empty($input['DESIGNATION']) && is_array($input['DESIGNATION']) ? implode(',', $input['DESIGNATION']) : 'ALL';
$workMode = isset($input['work_mode']) ? strtoupper(trim((string)$input['work_mode'])) : 'ALL';
if (!in_array($workMode, ['ALL', 'WFO', 'WFH'], true)) {
    $workMode = 'ALL';
}
$userid = isset($input['userid']) ? $input['userid'] : 'ALL';
$reportType = isset($input['reportType']) ? $input['reportType'] : 'SUMMARY_REPORT'; // Default to SUMMARY_REPORT

// Check if dates are valid
if ($startDate === null || $endDate === null) {
    echo json_encode(['error' => 'Start date and end date are required.']);
    exit();
}

// PR_EMPLOYEE_ACTIVITY_FLAT expects 13 inputs, including P_WORK_MODE.
$stmt = $conn->prepare("CALL PR_EMPLOYEE_ACTIVITY_FLAT(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['error' => 'Prepare failed: ' . $conn->error]);
    exit();
}

// Bind parameters in the same order as the stored procedure signature.
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
    echo json_encode(['error' => 'Query execution failed: ' . $error]);
    $conn->close();
    exit();
}

$result = $stmt->get_result();
$data = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    $result->free();
}

$response = [
    'data' => $data,
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
        'ids' => $ids,
        'names' => $names,
        'workMode' => $workMode
    ]
];

// Check for JSON encoding errors
$jsonResponse = json_encode($response);
if ($jsonResponse === false) {
    echo json_encode(['error' => 'JSON encoding failed: ' . json_last_error_msg()]);
} else {
    echo $jsonResponse;
}

// Close the statement and connection
$stmt->close();
while ($conn->more_results() && $conn->next_result()) {
    $extra = $conn->use_result();
    if ($extra instanceof mysqli_result) {
        $extra->free();
    }
}
$conn->close();
?>
