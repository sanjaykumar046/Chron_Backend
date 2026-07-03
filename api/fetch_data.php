<?php
include 'apiMain.php';
$sql = "SELECT `key`, employeeId, name, position, department, status FROM employe_data";
$result = $conn->query($sql);

$data = array();

if ($result->num_rows > 0) {
    // Output data of each row
    while($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
} 

$conn->close();

header('Content-Type: application/json');
echo json_encode($data);
?>

