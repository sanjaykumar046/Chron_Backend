<?php
include 'apiMain.php';
// Get data from GET request
$empId = isset($_GET['EMPID']) ? htmlspecialchars($_GET['EMPID']) : 'ALL';

// Prepare and execute the stored procedure
$sql = "CALL PR_TBL_EMP_DB(?, 'ALL', 'ALL', 'ALL', 'ALL', 'ALL', 'ALL', 'ALL')"; // Passing EMPID and default values for other parameters
$stmt = $conn->prepare($sql);
$stmt->bind_param('s', $empId);
$stmt->execute();

// Get the result from the stored procedure
$result = $stmt->get_result();

// Initialize response data
$rows = [];
if ($result->num_rows > 0) {
    // Fetch data
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
}

// Close the result set and statement
$result->free();
$stmt->close();

// Close the connection
$conn->close();

// Prepare response
$response = [
    'data' => $rows
];

// Return data as JSON
echo json_encode($response);
?>
