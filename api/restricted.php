<?php
include 'apiMain.php';

// Enable CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

// Fetch data from PRODUCTIVE_APPS_WEBSITE table
$sql = "SELECT * FROM PRODUCTIVE_APPS_WEBSITE ORDER BY APP_WEBSITE_URL";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
    // Fetch all column names
    $columns = [];
    while ($fieldinfo = $result->fetch_field()) {
        $columns[] = $fieldinfo->name;
    }

    // Exclude the first three columns (keeping ID, URL, TYPE, PRODUCTIVE_YN)
    $columnsToReturn = array_slice($columns, 0); // Keep all columns for better data handling
    
    // Fetch data
    $data = [];
    while ($row = $result->fetch_assoc()) {
        // Ensure PRODUCTIVE_YN has a default value if null
        if (!isset($row['PRODUCTIVE_YN']) || $row['PRODUCTIVE_YN'] === null) {
            $row['PRODUCTIVE_YN'] = 'U'; // Default to Unproductive
        }
        
        // Add the row to data array
        $data[] = $row;
    }

    // Return response with better structure
    echo json_encode([
        "status" => "success",
        "columns" => $columnsToReturn,
        "data" => $data,
        "total_records" => count($data)
    ]);
} else {
    echo json_encode([
        "status" => "success",
        "message" => "No results found.",
        "columns" => [],
        "data" => [],
        "total_records" => 0
    ]);
}

// Close connection
$conn->close();
?>
