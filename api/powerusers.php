<?php
include 'apiMain.php';

$startDate = $_GET['startDate'] ?? date('Y-m-d', strtotime('-7 days'));
$endDate = $_GET['endDate'] ?? date('Y-m-d');
$empid = $_GET['empid'] ?? 'ALL';
$empnames = $_GET['empnames'] ?? 'ALL';
$department = $_GET['department'] ?? 'ALL';
$role = $_GET['role'] ?? 'ALL';
$designations = $_GET['designations'] ?? 'ALL';
$project = $_GET['project'] ?? 'ALL';
$shift = $_GET['shift'] ?? 'ALL';
$team = $_GET['team'] ?? 'ALL';
$userid = $_GET['userid'] ?? 'admin';

$response = [
    'topProductiveUsers' => [],
    'bottomProductiveUsers' => [],
    'topProductiveMinutes' => [],
    'bottomProductiveMinutes' => [],
    'exceededBreakTime' => []
];

$sql = "CALL PR_EMPLOYEE_ACTIVITY_TOP_BTM('$startDate','$endDate','$empid','$empnames','$department','$role','$designations','$project','$shift','$team','$userid')";

if ($conn->multi_query($sql)) {
    $keys = ['topProductiveUsers', 'bottomProductiveUsers', 'topProductiveMinutes', 'bottomProductiveMinutes', 'exceededBreakTime'];
    $i = 0;

    do {
        if ($result = $conn->store_result()) {
            $response[$keys[$i]] = $result->fetch_all(MYSQLI_ASSOC);
            $result->free();
            $i++;
        }
    } while ($conn->more_results() && $conn->next_result());
}

$conn->close();
echo json_encode($response);
?>