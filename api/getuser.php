<?php
include 'apiMain.php';
// Check if the request method is GET
if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    // Check if EMPID is provided in the URL
    if (isset($_GET['EMPID']) && !empty($_GET['EMPID'])) {
        $empid = $_GET['EMPID'];

        // Prepare SQL statement to prevent SQL injection
        $stmt = $conn->prepare("SELECT EMPID, EMPNAME, EMAIL, SYS_USER_NAME, ROLE, REPORTING_1, REPORTING_2, DEPARTMENT, TEAM, PROJECT, SHIFT, ALLOTED_BREAK, ACTIVE_YN, HOLIDAY_COUNTRY, REGION FROM EMP_DB WHERE EMPID = ?");
        $stmt->bind_param("s", $empid);

        if ($stmt->execute()) {
            // Get the result
            $result = $stmt->get_result();

            // Check if the employee exists
            if ($result->num_rows > 0) {
                // Fetch the employee data
                $employee = $result->fetch_assoc();
                
                // Return the employee data as JSON
                echo json_encode(['success' => true, 'data' => $employee]);
            } else {
                // Employee not found
                echo json_encode(['success' => false, 'error' => 'Employee not found']);
            }
        } else {
            // Query execution failure
            echo json_encode(['success' => false, 'error' => $stmt->error]);
        }

        $stmt->close();
    } else {
        // EMPID is missing in the request
        echo json_encode(['success' => false, 'error' => 'EMPID is required']);
    }
} else {
    // Invalid request method
    echo json_encode(['success' => false, 'error' => 'Invalid request method.']);
}

$conn->close();
?>
