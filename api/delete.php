<?php
include 'apiMain.php';
// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Get POST data
$data = json_decode(file_get_contents("php://input"), true);

if (!$data) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid input"]);
    exit();
}

// Extract variables
$empid = $conn->real_escape_string($data['EMPID']);

// Prepare SQL statement
$sql = "DELETE FROM EMP_DB WHERE EMPID = '$empid'";

// Execute SQL statement
if ($conn->query($sql) === TRUE) {
    echo json_encode(["success" => "Record deleted successfully"]);
} else {
    http_response_code(500);
    echo json_encode(["error" => "Error deleting record: " . $conn->error]);
}

// Close connection
$conn->close();
?>
