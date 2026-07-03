<?php
include 'apiMain.php';

// START SESSION MANAGEMENT
session_start();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $inputEmail = $_POST['email'];
    $inputPassword = $_POST['password'];
    
    // Prepare the SQL statement
    $sql = "SELECT * FROM AdminLogin WHERE email = ? AND password = ?";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        error_log("Prepare failed: " . $conn->error);
        echo json_encode(["status" => "error", "message" => "Prepare statement failed"]);
        exit();
    }
    
    $stmt->bind_param("ss", $inputEmail, $inputPassword);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        // Fetch user data
        $row = $result->fetch_assoc();
        
        // Generate a new token
        $loginToken = bin2hex(random_bytes(16)); // Creates a 32-character token
        
        // SET SESSION LOGIN TIME (NEW CODE - Session Timeout)
        $_SESSION['LAST_ACTIVITY'] = time();
        $_SESSION['LOGIN_TIME'] = time();
        $_SESSION['USER_EMAIL'] = $inputEmail;
        
        // Update the user's login token in the database
        $updateTokenSql = "UPDATE login SET token = ? WHERE username = ?";
        $updateStmt = $conn->prepare($updateTokenSql);
        
        if (!$updateStmt) {
            error_log("Token update failed: " . $conn->error);
            echo json_encode(["status" => "error", "message" => "Token update failed"]);
            exit();
        }
        
        $updateStmt->bind_param("ss", $loginToken, $inputEmail);
        $updateStmt->execute();
        
        // Return response with login time (NEW - for frontend)
        echo json_encode([
            "status" => "success",
            "message" => "Login successful",
            "token" => $loginToken,
            "loginTime" => time() // ADD THIS for frontend to track
        ]);
        
        $updateStmt->close();
    } else {
        echo json_encode(["status" => "error", "message" => "Invalid username or password"]);
    }
    
    $stmt->close();
}

$conn->close();
?>