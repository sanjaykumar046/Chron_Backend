<?php
include 'apiMain.php';
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Get JSON input data
$data = file_get_contents("php://input");
$employee = json_decode($data, true);

// Display the received data
echo json_encode([
    "received_data" => $employee
]);

// Validate input data
if (!is_array($employee) || !isset($employee['EMPID'])) {
    http_response_code(400);
    echo json_encode(["message" => "Invalid data format"]);
    exit;
}

// Prepare SQL statement for update
$stmt = $conn->prepare("
    UPDATE EMP_DB SET
        CREATE_DT = ?, UPDATED_DT = ?, DB_ID = ?, SL_NO = ?, EMPNAME = ?, EMAIL = ?, SYS_USER_NAME = ?, ROLE = ?,
        REPORTING_1 = ?, REPORTING_2 = ?, DEPARTMENT = ?, TEAM = ?, PROJECT = ?, SHIFT = ?, SHIFT_START_TIME = ?,
        SHIFT_END_TIME = ?, ALLOTED_BREAK = ?, ACTIVE_YN = ?, HOLIDAY_COUNTRY = ?, REGION = ?, UPDATED_BY = ?
    WHERE EMPID = ?
");

// Check if statement preparation was successful
if (!$stmt) {
    http_response_code(500);
    echo json_encode(["message" => "Failed to prepare SQL statement: " . $conn->error]);
    exit;
}

// Bind parameters
$stmt->bind_param(
    "ssssssssssssssssssssss",
    $create_dt, $updated_dt, $db_id, $sl_no, $empname, $email, $sys_user_name, $role,
    $reporting_1, $reporting_2, $department, $team, $project, $shift, $shift_start_time,
    $shift_end_time, $alloted_break, $active_yn, $holiday_country, $region, $updated_by, $empid
);

// Assign values from the input data
$create_dt = $employee['CREATE_DT'] ?? '';
$updated_dt = $employee['UPDATED_DT'] ?? '';
$db_id = $employee['DB_ID'] ?? '';
$sl_no = $employee['SL_NO'] ?? '';
$empid = $employee['EMPID'] ?? '';
$empname = $employee['EMPNAME'] ?? '';
$email = $employee['EMAIL'] ?? '';
$sys_user_name = $employee['SYS_USER_NAME'] ?? ''; // Ensure this key matches with the input data
$role = $employee['ROLE'] ?? '';
$reporting_1 = $employee['REPORTING_1'] ?? '';
$reporting_2 = $employee['REPORTING_2'] ?? '';
$department = $employee['DEPARTMENT'] ?? '';
$team = $employee['TEAM'] ?? '';
$project = $employee['PROJECT'] ?? '';
$shift = $employee['SHIFT'] ?? '';
$shift_start_time = $employee['SHIFT_START_TIME'] ?? '';
$shift_end_time = $employee['SHIFT_END_TIME'] ?? '';
$alloted_break = $employee['ALLOTED_BREAK'] ?? '';
$active_yn = $employee['ACTIVE_YN'] ?? '';
$holiday_country = $employee['HOLIDAY_COUNTRY'] ?? '';
$region = $employee['REGION'] ?? '';
$updated_by = $employee['UPDATED_BY'] ?? ''; // Add this field

// Execute the statement
if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(["message" => "Error executing query: " . $stmt->error]);
    exit;
}

// Close statement and connection
$stmt->close();
$conn->close();

// Confirm success
echo json_encode(["message" => "Data updated successfully"]);
?>
