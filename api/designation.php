<?php
include 'apiMain.php';

// Get the input from the request
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
$userid = isset($input['userid']) ? $input['userid'] : 'ALL';
$empid = $_POST['EMPID'] ?? 'ALL';  // Get EMPID from request or 'ALL' if not provided
// $query .= " AND EMPID IN (" . implode(",", $empid) . ")";




// Check if dates are valid
if ($startDate === null || $endDate === null) {
    echo json_encode(['error' => 'Start date and end date are required.']);
    exit();
}

// Prepare the SQL statement
$stmt = $conn->prepare("CALL PR_EMPLOYEE_ACTIVITY(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

if (!$stmt) {
    echo json_encode(['error' => 'Prepare failed: ' . $conn->error]);
    exit();
}

// Bind parameters
$stmt->bind_param('sssssssssss', $startDate, $endDate, $ids, $names, $departments, $roles, $designations, $projects, $shifts, $teams, $userid);

if ($stmt->execute()) {
    // Get the result
    $result = $stmt->get_result();
    
    // Fetch data and return as JSON
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }

    $response = ['data' => $data];
} else {
    $response = ['error' => 'Query execution failed: ' . $stmt->error];
}

// Check for JSON encoding errors
$jsonResponse = json_encode($response);
if ($jsonResponse === false) {
    echo json_encode(['error' => 'JSON encoding failed: ' . json_last_error_msg()]);
} else {
    echo $jsonResponse;
}

// Close the statement and connection
$stmt->close();
$conn->close();
?>
