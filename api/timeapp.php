<?php
include 'apiMain.php';
// Get the request body
$data = json_decode(file_get_contents("php://input"), true);

// Validate input
$date = $data['date'] ?? null;
$empId = $data['empId'] ?? null;
$userid = $data['userid'] ?? null;

// Set to current date if no date is provided
if (!$date) {
    $date = date('Y-m-d'); // Current date in YYYY-MM-DD format
}

if (!$empId || !$userid) {
    echo json_encode(['error' => 'Both Employee ID and User ID are required.']);
    exit();
}

// Prepare and execute the stored procedure
$stmt = $conn->prepare("CALL PR_APP_URL_USAGE_TIMELINE(?, ?, ?)"); // Order: date, empId, userid
$stmt->bind_param("sss", $date, $empId, $userid); // Bind parameters in the correct order

if ($stmt->execute()) {
    $result = $stmt->get_result();
    $outputData = [];

    // Fetch the results
    while ($row = $result->fetch_assoc()) {
        $outputData[] = $row;
    }

    // Prepare response data
    $response = [
        'status' => 'success',
        'data' => $outputData
    ];

    echo json_encode($response);
} else {
    echo json_encode(['error' => "Failed to execute stored procedure: " . $stmt->error]);
}

// Close connection
$stmt->close();
$conn->close();
?>
