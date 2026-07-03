<?php
include 'apiMain.php';
// Get data from POST request
$date = $_POST['date'] ?? null;
$empID = $_POST['EMPID'] ?? null;
$userId = $_POST['userid'] ?? null;


if (!$date || !$empID) {
    echo json_encode(['error' => 'Date and Employee ID are required.']);
    exit;
}

// Prepare and bind
$stmt = $conn->prepare("call PR_APP_URL_USAGE_TIMELINE_AGG_EX(?, ?, ?)");
$stmt->bind_param("sss", $date, $empID, $userId); // Assuming both are strings

// Execute the statement
if ($stmt->execute()) {
    $result = $stmt->get_result();
    $data = $result->fetch_all(MYSQLI_ASSOC);
    echo json_encode($data);
} else {
    echo json_encode(['error' => 'Error executing stored procedure: ' . $stmt->error]);
}

// Close connections
$stmt->close();
$conn->close();
?>
