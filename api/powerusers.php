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

// Maps the SP's Category value to the response key it belongs in
$categoryMap = [
    'TOP_PRODUCTIVE_USERS'    => 'topProductiveUsers',
    'BOTTOM_PRODUCTIVE_USERS' => 'bottomProductiveUsers',
    'TOP_BREAK_USERS'         => 'exceededBreakTime',
];

$sql = "CALL PR_EMPLOYEE_ACTIVITY_TOP_BTM('$startDate','$endDate','$empid','$empnames','$department','$role','$designations','$project','$shift','$team','$userid')";

if ($conn->multi_query($sql)) {
    do {
        if ($result = $conn->store_result()) {
            $rows = $result->fetch_all(MYSQLI_ASSOC);
            $result->free();

            foreach ($rows as $row) {
                $category = $row['Category'] ?? null;
                if ($category && isset($categoryMap[$category])) {
                    $response[$categoryMap[$category]][] = $row;
                }
            }
        }
    } while ($conn->more_results() && $conn->next_result());
} else {
    $response['error'] = true;
    $response['message'] = $conn->error;
}

$conn->close();
echo json_encode($response);
?>