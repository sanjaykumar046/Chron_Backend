<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

include 'apiMain.php';

try {
    // Check if connection exists
    if (!$conn) {
        throw new Exception('Database connection failed');
    }
    
    // Query to get distinct designation categories from EMP_DB table
    $sql = "SELECT DISTINCT DESIGNATION_CATEGORY FROM EMP_DB WHERE DESIGNATION_CATEGORY IS NOT NULL AND DESIGNATION_CATEGORY != '' ORDER BY DESIGNATION_CATEGORY ASC";
    
    $result = $conn->query($sql);
    
    if ($result) {
        $designations = [];
        
        while ($row = $result->fetch_assoc()) {
            $designations[] = $row['DESIGNATION_CATEGORY'];
        }
        
        // Prepare success response
        $response = [
            'success' => true,
            'data' => $designations,
            'count' => count($designations),
            'message' => 'Designation categories fetched successfully'
        ];
        
        http_response_code(200);
        echo json_encode($response);
    } else {
        // Query execution failed
        $response = [
            'success' => false,
            'data' => [],
            'message' => 'Failed to fetch designation categories: ' . $conn->error
        ];
        
        http_response_code(500);
        echo json_encode($response);
    }
    
} catch (Exception $e) {
    // Handle exceptions
    $response = [
        'success' => false,
        'data' => [],
        'message' => 'Error: ' . $e->getMessage()
    ];
    
    http_response_code(500);
    echo json_encode($response);
}

// Close database connection
if ($conn) {
    $conn->close();
}
?>