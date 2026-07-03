<?php
// delete.php
include 'apiMain.php';

// Set content type to JSON
header('Content-Type: application/json');

// Handle CORS if needed
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Get the input data
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['websiteName']) || empty($input['websiteName'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing websiteName']);
    exit;
}

$websiteName = $input['websiteName'];

try {
    // Delete the website from the database
    $sql = "DELETE FROM PRODUCTIVE_APPS_WEBSITE WHERE APP_WEBSITE_URL = ?";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    $stmt->bind_param("s", $websiteName);
    
    if (!$stmt->execute()) {
        throw new Exception("Execute failed: " . $stmt->error);
    }
    
    $affectedRows = $stmt->affected_rows;
    
    if ($affectedRows > 0) {
        echo json_encode([
            'success' => true,
            'message' => 'Website deleted successfully',
            'affected_rows' => $affectedRows
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'No website found to delete'
        ]);
    }
    
    $stmt->close();
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}

$conn->close();
?>
