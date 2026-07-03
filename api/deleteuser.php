<?php
// Handle the OPTIONS request (preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

include 'apiMain.php';

// Check if the request method is DELETE
if ($_SERVER['REQUEST_METHOD'] == 'DELETE') {
    // Get EMPID from query string
    $empid = isset($_GET['EMPID']) ? $_GET['EMPID'] : '';

    if (empty($empid)) {
        echo json_encode(['success' => false, 'error' => 'EMPID is required']);
        exit;
    }

    // Prepare SQL statement to prevent SQL injection
    $stmt = $conn->prepare("DELETE FROM EMP_DB WHERE EMPID = ?");
    $stmt->bind_param("s", $empid);

    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            // Success response if employee is deleted
            echo json_encode(['success' => true, 'message' => 'Employee deleted successfully']);
        } else {
            // Failure response if employee not found
            echo json_encode(['success' => false, 'error' => 'Employee not found']);
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
