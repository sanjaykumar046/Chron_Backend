<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

include 'apiMain.php';

$inputData = file_get_contents('php://input');
parse_str($inputData, $parsedData);

$date = isset($parsedData['date']) ? $parsedData['date'] : date('Y-m-d');
$enddate = isset($parsedData['enddate']) && !empty($parsedData['enddate']) ? $parsedData['enddate'] : $date;
$empid = isset($parsedData['EMPID']) ? $parsedData['EMPID'] : 'ALL';
$empname = isset($parsedData['EMPNAME']) ? $parsedData['EMPNAME'] : 'ALL';
$team = isset($parsedData['TEAMS']) ? $parsedData['TEAMS'] : 'ALL';
$role = isset($parsedData['ROLE']) ? $parsedData['ROLE'] : 'ALL';
$department = isset($parsedData['DEPARTMENT']) ? $parsedData['DEPARTMENT'] : 'ALL';
$project = isset($parsedData['PROJECTS']) ? $parsedData['PROJECTS'] : 'ALL';
$userid = isset($parsedData['userid']) ? $parsedData['userid'] : 'ALL';

$stmt = $conn->prepare("CALL PR_USER_TIMELINE(?, ?, ?, ?, ?, ?, ?, ?, ?)");
$stmt->bind_param("sssssssss", $date, $enddate, $empid, $empname, $department, $role, $team, $project, $userid);
$stmt->execute();
$result = $stmt->get_result();
$results = $result->fetch_all(MYSQLI_ASSOC);

if (empty($results)) {
    echo json_encode(["error" => "No data found."]);
} else {
    echo json_encode($results, JSON_PRETTY_PRINT);
}

$conn->close();
?>