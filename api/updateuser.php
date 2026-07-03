<?php
include 'apiMain.php';
// Check if the request method is PUT
if ($_SERVER['REQUEST_METHOD'] == 'PUT') {
    // Get JSON input
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    // Check if JSON decoding was successful
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo json_encode(['success' => false, 'error' => 'Invalid JSON input']);
        exit;
    }

    // Retrieve PUT data
    $empid = isset($data['EMPID']) ? $data['EMPID'] : '';
    $empname = isset($data['EMPNAME']) ? $data['EMPNAME'] : '';
    $email = isset($data['EMAIL']) ? $data['EMAIL'] : '';
    $sysUserName = isset($data['SYS_USER_NAME']) ? $data['SYS_USER_NAME'] : '';
    $role = isset($data['ROLE']) ? $data['ROLE'] : '';
    $reporting1 = isset($data['REPORTING_1']) ? $data['REPORTING_1'] : '';
    $reporting2 = isset($data['REPORTING_2']) ? $data['REPORTING_2'] : '';
    $department = isset($data['DEPARTMENT']) ? $data['DEPARTMENT'] : '';
    $team = isset($data['TEAM']) ? $data['TEAM'] : '';
    $project = isset($data['PROJECT']) ? $data['PROJECT'] : '';
    $shift = isset($data['SHIFT']) ? $data['SHIFT'] : '';
    $allotedBreak = isset($data['ALLOTED_BREAK']) ? $data['ALLOTED_BREAK'] : '';
    $activeYn = isset($data['ACTIVE_YN']) ? ($data['ACTIVE_YN'] ? 1 : 0) : 0;
    $holidayCountry = isset($data['HOLIDAY_COUNTRY']) ? $data['HOLIDAY_COUNTRY'] : '';
    $region = isset($data['REGION']) ? $data['REGION'] : '';
    $updatedBy = isset($data['UPDATED_BY']) ? $data['UPDATED_BY'] : '';

    // Validate EMPID
    if (empty($empid)) {
        echo json_encode(['success' => false, 'error' => 'EMPID is required']);
        exit;
    }

    // Prepare SQL statement to prevent SQL injection
    $stmt = $conn->prepare("UPDATE EMP_DB SET EMPNAME = ?, EMAIL = ?, SYS_USER_NAME = ?, ROLE = ?, REPORTING_1 = ?, REPORTING_2 = ?, DEPARTMENT = ?, TEAM = ?, PROJECT = ?, SHIFT = ?, ALLOTED_BREAK = ?, ACTIVE_YN = ?, HOLIDAY_COUNTRY = ?, REGION = ?, UPDATED_BY = ? WHERE EMPID = ?");
    $stmt->bind_param("ssssssssssssssss", $empname, $email, $sysUserName, $role, $reporting1, $reporting2, $department, $team, $project, $shift, $allotedBreak, $activeYn, $holidayCountry, $region, $updatedBy, $empid);

    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            // Success response
            echo json_encode(['success' => true, 'message' => 'Employee updated successfully']);
        } else {
            // Failure response (no record updated)
            echo json_encode(['success' => false, 'error' => 'No record found or no changes made']);
        }
    } else {
        // Failure response
        echo json_encode(['success' => false, 'error' => $stmt->error]);
    }

    $stmt->close();
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid request method.']);
}

$conn->close();
?>
