<?php
include 'apiMain.php';

// Set CORS headers
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Reset Password
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Get JSON input
        $input = file_get_contents("php://input");
        $data = json_decode($input, true);
        
        // Basic validation
        if (!$data || !isset($data['EMPID']) || !isset($data['newPassword'])) {
            echo json_encode(['success' => false, 'error' => 'EMPID and newPassword are required']);
            exit;
        }

        $EMPID = $data['EMPID'];
        $newPassword = $data['newPassword'];

        // Password validation
        if (strlen($newPassword) < 6) {
            echo json_encode(['success' => false, 'error' => 'Password must be at least 6 characters long']);
            exit;
        }

        if (strpos($newPassword, ';') !== false || strpos($newPassword, "'") !== false) {
            echo json_encode(['success' => false, 'error' => 'Password cannot contain semicolon (;) or single quote (\')']);
            exit;
        }

        // Check if user exists
        $checkSql = "SELECT EMPNAME FROM EMP_DB WHERE EMPID = ? AND ACTIVE_YN = 'Y'";
        $checkStmt = $conn->prepare($checkSql);
        $checkStmt->bind_param("s", $EMPID);
        $checkStmt->execute();
        $result = $checkStmt->get_result();
        
        if ($result->num_rows === 0) {
            echo json_encode(['success' => false, 'error' => 'Employee not found or inactive']);
            exit;
        }
        
        $user = $result->fetch_assoc();
        $checkStmt->close();

        // Update password
        $updateSql = "UPDATE EMP_DB SET PASSWORD = ? WHERE EMPID = ? AND ACTIVE_YN = 'Y'";
        $updateStmt = $conn->prepare($updateSql);
        $updateStmt->bind_param("ss", $newPassword, $EMPID);
        
        if ($updateStmt->execute() && $updateStmt->affected_rows > 0) {
            echo json_encode([
                'success' => true, 
                'message' => 'Password reset successfully for ' . $user['EMPNAME']
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to update password']);
        }
        
        $updateStmt->close();

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Only POST method allowed']);
}

if ($conn) {
    $conn->close();
}
?>