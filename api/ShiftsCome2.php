<?php
include 'apiMain.php';
// Handle GET request to fetch all columns from TBL_SHIFT
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Query to select all rows from the TBL_SHIFT table
    $sql = "SELECT EMPID, EMPNAME, SYS_USER_NAME, SHIFTTYPE, SHIFT_START_TIME, SHIFT_END_TIME, SHIFTSTART_DT, SHIFTEND_DT, TIME_ZONE, WEEKOFF, COMMENTS, UPDATED_BY FROM TBL_SHIFT";
    
    // Execute the query
    if ($result = $conn->query($sql)) {
        $data = [];
        
        // Fetch rows and store them in the $data array
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        
        // Return the data in JSON format
        echo json_encode(array("status" => "success", "data" => $data));
        
        // Free the result set
        $result->free();
    } else {
        // Handle any query errors
        echo json_encode(array("status" => "error", "message" => $conn->error));
    }
}

// Close connection
$conn->close();
?>
