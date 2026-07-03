<?php
include 'apiMain.php';
// Function to fetch unique values from a column
function fetchUniqueValues($conn, $column) {
    $sql = "SELECT DISTINCT $column FROM EMP_DB";
    $result = $conn->query($sql);
    $values = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $values[] = $row[$column];
        }
        $result->free();
    } else {
        echo json_encode(array("error" => "Error fetching unique values: " . $conn->error));
        exit();
    }
    return $values;
}

// Function to extract columns from the result set
function extractColumns($result) {
    $columns = [];
    $meta = $result->fetch_fields();
    foreach ($meta as $field) {
        $columns[] = $field->name;
    }
    return $columns;
}

// Handle GET request for specific EMPID
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Get EMPID from URL
    if (isset($_GET['EMPID'])) {
        $empid = $conn->real_escape_string($_GET['EMPID']);
        
        // Query to fetch the specific employee's shift details
        $sql = "SELECT * FROM EMP_DB WHERE EMPID = '$empid'";
        $result = $conn->query($sql);

        if ($result) {
            if ($row = $result->fetch_assoc()) {
                echo json_encode($row);
            } else {
                echo json_encode(array("error" => "No data found for EMPID: $empid"));
            }
            $result->free();
        } else {
            echo json_encode(array("error" => $conn->error));
        }
    } else {
        // Fetch unique values if no EMPID is provided
        $uniqueValues = array(
            "uniqueEmpIds" => fetchUniqueValues($conn, 'EMPID'),
            "uniqueEmpNames" => fetchUniqueValues($conn, 'EMPNAME'),
            "uniqueRoles" => fetchUniqueValues($conn, 'ROLE'),
            "uniqueDepts" => fetchUniqueValues($conn, 'DEPARTMENT'),
            "uniqueProjects" => fetchUniqueValues($conn, 'Project'),
            "uniqueTeams" => fetchUniqueValues($conn, 'Team'),
            "uniqueShifts" => fetchUniqueValues($conn, 'Shift')
        );

        echo json_encode($uniqueValues);
    }
}

// Handle POST request
else if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    // Your existing POST logic here...

} 

// Handle PUT request
else if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    // Your existing PUT logic here...

}

// Close connection
$conn->close();
?>
