<?php
include 'apiMain.php';
// Handle POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get POST data
    $postData = file_get_contents("php://input");
    parse_str($postData, $postData);

    $username = $postData['username'];
    $password = $postData['password'];

    // Validate inputs
    if (empty($username) || empty($password)) {
        echo json_encode(['status' => 'error', 'message' => 'Username and password are required.']);
        exit();
    }

    // Query the database
    $stmt = $conn->prepare("SELECT * FROM UserLogin WHERE EMPID = ? AND password = ?");
    $stmt->bind_param("ss", $username, $password);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        // Login successful
        echo json_encode(['status' => 'success']);
    } else {
        // Login failed
        echo json_encode(['status' => 'error', 'message' => 'Invalid username or password.']);
    }

    $stmt->close();
}

$conn->close();
?>
