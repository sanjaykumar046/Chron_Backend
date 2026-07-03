<?php
include 'apiMain.php';
// Get input data
$parsedData = json_decode(file_get_contents('php://input'), true);

$date = isset($parsedData['date']) ? $parsedData['date'] : date('Y-m-d');
$enddate = isset($parsedData['enddate']) ? $parsedData['enddate'] : date('Y-m-d');
$empid = isset($parsedData['EMPID']) ? $parsedData['EMPID'] : 'ALL';
$userId = isset($parsedData['userid']) ? $parsedData['userid'] : 'ALL'; // New input for userid

// Prepare the stored procedure call
$stmt = $conn->prepare("CALL PR_USER_TIMELINE(?, ?, ?, 'ALL', 'ALL', 'ALL', 'ALL', 'ALL', ?)");

// Bind parameters
$stmt->bind_param('ssss', $date, $enddate, $empid, $userId); // Adjust this according to your procedure requirements

// Execute the stored procedure
if ($stmt->execute()) {
    // Fetch the result if needed
    $result = $stmt->get_result();
    $data = $result->fetch_all(MYSQLI_ASSOC);
    echo json_encode($data);
} else {
    echo json_encode(['error' => "Execution failed: " . $stmt->error]);
}

// Close the statement and connection
$stmt->close();
$conn->close();
?>
