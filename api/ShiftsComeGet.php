<?php
include 'apiMain.php';
// Handle GET request for shifts
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $empId = isset($_GET['EMPID']) ? $conn->real_escape_string($_GET['EMPID']) : '';

    // Prepare SQL query
    $sql = "SELECT EMPID, EMPNAME, SYS_USER_NAME, SHIFTTYPE, SHIFT_START_TIME, SHIFT_END_TIME, SHIFTSTART_DT, SHIFTEND_DT, TIME_ZONE, WEEKOFF, COMMENTS FROM TBL_SHIFT WHERE EMPID = '$empId'";

    // Execute the query
    $result = $conn->query($sql);

    // Prepare response
    $response = array(
        "EMPID" => "",
        "EMPNAME" => "",
        "SYS_USER_NAME" => ""
    );

    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $response = array(
            "EMPID" => $row['EMPID'],
            "EMPNAME" => $row['EMPNAME'],
            "SYS_USER_NAME" => $row['SYS_USER_NAME']
        );
    }

    // Return the JSON response
    echo json_encode($response);
}

// Close connection
$conn->close();
?>
