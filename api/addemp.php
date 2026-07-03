<?php
include 'apiMain.php';

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Check if the request method is POST
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Get JSON input
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    // Check if JSON decoding was successful
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo json_encode(['success' => false, 'error' => 'Invalid JSON input']);
        exit;
    }

    // Retrieve POST data
    $empid = $data['empid'] ?? '';
    $empname = $data['empname'] ?? '';
    $email = $data['email'] ?? '';
    $sysUserName = $data['sysUserName'] ?? '';
    $role = $data['role'] ?? '';
    $reporting1 = $data['reporting1'] ?? '';
    $reporting2 = $data['reporting2'] ?? '';
    $department = $data['department'] ?? '';
    $team = $data['team'] ?? '';
    $project = $data['project'] ?? '';
    $shift = $data['shift'] ?? '';
    $allotedBreak = $data['allotedBreak'] ?? '';
    $activeYn = ($data['activeYn'] === 'Yes') ? 1 : 0; 
    $holidayCountry = $data['holidayCountry'] ?? '';
    $region = $data['region'] ?? '';
    $updatedBy = $data['updatedBy'] ?? '';

    // Prepare SQL statement
    $stmt = $conn->prepare(
        "INSERT INTO EMP_DB (empid, empname, email, sys_user_name, role, reporting_1, reporting_2, department, team, project, shift, alloted_break, active_yn, holiday_country, region, updated_by) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    if ($stmt === false) {
        echo json_encode(['success' => false, 'error' => 'Failed to prepare SQL statement']);
        exit;
    }

    // Bind parameters
    $stmt->bind_param(
        "ssssssssssssssss", 
        $empid, $empname, $email, $sysUserName, $role, $reporting1, $reporting2, $department, $team, $project, $shift, $allotedBreak, $activeYn, $holidayCountry, $region, $updatedBy
    );

    // Execute statement
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => $stmt->error]);
    }

    // Close the statement
    $stmt->close();
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
}

// Close the connection
$conn->close();
?>
