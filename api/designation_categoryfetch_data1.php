<?php
include 'apiMain.php';

// Prepare the query to fetch unique DESIGNATION_CATEGORY values
$sql = "SELECT DISTINCT DESIGNATION_CATEGORY FROM EMP_DB";

// Execute the query
$result = $conn->query($sql);

// Initialize an array to hold the unique values
$designationCategories = [];

// Fetch the results
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $designationCategories[] = $row['DESIGNATION_CATEGORY'];
    }
}

// Close the connection
$conn->close();

// Prepare the response
$response = [
    'DESIGNATION_CATEGORY' => $designationCategories
];

// Return the response as JSON
echo json_encode($response);
?>
