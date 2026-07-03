<?php
include 'apiMain.php';

$userid = isset($_GET['userid']) ? $_GET['userid'] : (isset($_POST['userid']) ? $_POST['userid'] : 'ALL');

// Prepare the SQL statement
$sql = "CALL EMP_STATUS(?)";
$stmt = $conn->prepare($sql);

if ($stmt) {
    // Bind the parameter
    $stmt->bind_param("s", $userid); // Assuming userid is a string. Change to "i" if it's an integer.

    // Execute the statement
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $data = array();  // Initialize an array to store the results

        while ($row = $result->fetch_assoc()) {
            $data[] = $row;  // Add each row to the array
        }
        echo json_encode($data);  // Convert the array to JSON and print it
        $result->free();  // Free result set
    } else {
        echo json_encode(array("error" => $stmt->error));  // Return error as JSON
    }
    
    $stmt->close();  // Close the statement
} else {
    echo json_encode(array("error" => $conn->error));  // Return error as JSON
}

// Close connection
$conn->close();
?>
