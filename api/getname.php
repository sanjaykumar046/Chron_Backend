<?php
include 'apiMain.php';
// Check if the request is a GET request
if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    // Get the token from the request headers
    $headers = getallheaders();
    $token = isset($headers['Tokenname']) ? trim($headers['Tokenname']) : null;

    if ($token) {
        // Prepare the SQL statement to get the username associated with the token
        $sql = "SELECT username FROM login WHERE token = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log("Prepare failed: " . $conn->error);
            echo json_encode(["status" => "error", "message" => "Prepare statement failed"]);
            exit();
        }

        $stmt->bind_param("s", $token);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            echo json_encode([
                "status" => "success",
                "username" => $row['username']
            ]);
        } else {
            echo json_encode(["status" => "error", "message" => "Invalid or expired token"]);
        }

        $stmt->close();
    } else {
        echo json_encode(["status" => "error", "message" => "Token not provided"]);
    }
}

$conn->close();
?>
