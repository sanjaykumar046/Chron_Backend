<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header('Content-Type: application/json');

include 'apiMain.php';

try {
    // Prepare the SQL statement
    $stmt = $conn->prepare("CALL PR_EMPLOYEE_ACTIVITY(?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        throw new Exception("Preparation failed: " . $conn->error);
    }

    // Default parameters to fetch all data
    $startDate = '2024-08-18'; // or '2024-08-18' based on your stored procedure
    $endDate = '2024-09-19';   // or '2024-09-19'
    $departments = 'ALL'; // NULL to fetch all departments
    $roles = 'ALL';      // NULL to fetch all roles
    $projects = 'ALL';   // NULL to fetch all projects
    $shifts = 'ALL';     // NULL to fetch all shifts
    $teams = 'ALL';      // NULL to fetch all teams

    // Bind parameters
    $stmt->bind_param('sssssss', $startDate, $endDate, $departments, $roles, $projects, $shifts, $teams);

    // Execute the statement
    $stmt->execute();

    // Fetch results
    $result = $stmt->get_result();
    $data = [];
    
    // Fetch all rows as an associative array
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }

    // Close the statement and connection
    $stmt->close();
    $conn->close();

    // Return the data as JSON
    echo json_encode($data);

} catch (Exception $e) {
    // Handle exceptions
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
}
?>