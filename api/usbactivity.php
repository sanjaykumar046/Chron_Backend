<?php
include 'apiMain.php';

// Function to handle the incoming request and call the stored procedure
function handleRequest() {
    // Get raw POST data
    $jsonData = file_get_contents("php://input");
    
    // Decode the incoming JSON into a PHP array
    $data = json_decode($jsonData, true);
    
    // Check if the required fields are available in the received data
    if (!isset($data['userId']) || !isset($data['filters'])) {
        // Respond with an error if the expected data is not present
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Missing userId or filters data']);
        exit();
    }

    // Extract filters from the received data
    $userId = $data['userId'];
    $filters = $data['filters'];
    
    // Default to today's date if not provided
    $today = date('Y-m-d'); // Get today's date in YYYY-MM-DD format
    
    // Extract individual filters, defaulting to 'ALL' if not provided or if empty
    $startDate = isset($filters['dateRange'][0]) ? $filters['dateRange'][0] : $today;
    $endDate = isset($filters['dateRange'][1]) ? $filters['dateRange'][1] : $today;

    // Check each filter array, if empty set to 'ALL'
    $empId = isset($filters['empId']) && !empty($filters['empId']) ? implode(',', $filters['empId']) : 'ALL';
    $empName = isset($filters['empName']) && !empty($filters['empName']) ? implode(',', $filters['empName']) : 'ALL';
    $dept = isset($filters['dept']) && !empty($filters['dept']) ? implode(',', $filters['dept']) : 'ALL';
    $role = isset($filters['role']) && !empty($filters['role']) ? implode(',', $filters['role']) : 'ALL';
    $project = isset($filters['project']) && !empty($filters['project']) ? implode(',', $filters['project']) : 'ALL';
    $team = isset($filters['team']) && !empty($filters['team']) ? implode(',', $filters['team']) : 'ALL';

    // Prepare the SQL call to the stored procedure
    $query = "CALL PR_USB_ACTIVITY('$startDate', '$endDate', '$empId', '$empName', '$dept', '$role', '$project', '$team', '$userId')";

    // Call the stored procedure and fetch the result
    $result = executeQuery($query);

    // Check if the result is valid and return the appropriate response
    if ($result) {
        // Assuming the result is a list of rows, format it as JSON
        $response = [];
        while ($row = $result->fetch_assoc()) {
            $response[] = $row;
        }

        // Send back the fetched data as JSON
        header('Content-Type: application/json');
        echo json_encode($response);
    } else {
        // If the query fails, return an error message
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Error executing stored procedure']);
    }
}

// Helper function to execute the query (this will use the connection defined in apiMain.php)
function executeQuery($query) {
    // Assuming you have a MySQLi connection in your apiMain.php file
    global $conn;
    
    // Execute the query and return the result
    return $conn->query($query);
}

// Call the function to handle the request
handleRequest();
?>
