<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Database configuration
require_once 'apiMain.php';

try {
    // Fetch all active employees with valid email addresses
    $sql = "SELECT 
                EMPID, 
                EMPNAME, 
                EMAIL, 
                DESIGNATION_CATEGORY,
                DEPARTMENT,
                ROLE
            FROM EMP_DB 
            WHERE ACTIVE_YN = 'Y' 
            AND EMAIL IS NOT NULL 
            AND EMAIL != ''
            AND EMAIL LIKE '%@%'
            ORDER BY EMPNAME ASC";
    
    $result = $conn->query($sql);
    
    if ($result === false) {
        throw new Exception("Query failed: " . $conn->error);
    }
    
    $activeUsers = [];
    while ($row = $result->fetch_assoc()) {
        $activeUsers[] = $row;
    }
    
    if (count($activeUsers) > 0) {
        echo json_encode([
            'success' => true,
            'message' => 'Active emails fetched successfully',
            'data' => $activeUsers,
            'count' => count($activeUsers)
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'message' => 'No active users found',
            'data' => [],
            'count' => 0
        ]);
    }
    
} catch (Exception $e) {
    error_log("Error in get_active_emails.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage(),
        'data' => []
    ]);
} finally {
    // Close connection if it exists
    if (isset($conn) && $conn) {
        $conn->close();
    }
}
?>