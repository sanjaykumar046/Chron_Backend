<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set content type to JSON
header('Content-Type: application/json');

// Include your database connection
include 'apiMain.php';

try {
    // Get POST data
    $input = file_get_contents("php://input");
    $data = json_decode($input, true);
    
    // Log the received data for debugging
    error_log("Received data: " . print_r($data, true));
    
    $websiteName = $data['websiteName'] ?? null;
    $type = $data['type'] ?? null;
    
    if (!$websiteName || !$type) {
        throw new Exception("Missing required parameters: websiteName or type");
    }
    
    // Validate type parameter
    if (!in_array($type, ['Y', 'N', 'U'])) {
        throw new Exception("Invalid type parameter. Must be Y, N, or U");
    }
    
    // Prepare the stored procedure call
    $stmt = $conn->prepare("CALL PR_INSERT_PRODUCTIVE_APPS_WEBSITE (?, ?, ?)");
    
    if (!$stmt) {
        throw new Exception("Failed to prepare statement: " . $conn->error);
    }
    
    // Define a fixed string for the type
    $fixedType = "WEBSITE";
    
    // Bind parameters
    $stmt->bind_param("sss", $websiteName, $fixedType, $type);
    
    // Execute the statement
    if ($stmt->execute()) {
        $response = [
            "status" => "success",
            "message" => "Record inserted successfully.",
            "data" => [
                "websiteName" => $websiteName,
                "type" => $type,
                "fixedType" => $fixedType
            ]
        ];
        
        error_log("Success: " . print_r($response, true));
        echo json_encode($response);
    } else {
        throw new Exception("Error executing query: " . $stmt->error);
    }
    
    $stmt->close();
    
} catch (Exception $e) {
    $error_response = [
        "status" => "error",
        "message" => $e->getMessage(),
        "received_data" => $data ?? null
    ];
    
    error_log("Error: " . print_r($error_response, true));
    echo json_encode($error_response);
} finally {
    // Close connection
    if (isset($conn)) {
        $conn->close();
    }
}
?>
