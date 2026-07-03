<?php
include 'apiMain.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$empid = isset($_GET['EMPID']) ? trim($_GET['EMPID']) : '';

if (empty($empid)) {
    echo json_encode([
        'error' => 'EMPID parameter is required',
        'columns' => [],
        'data' => []
    ]);
    exit();
}

$stmt = $conn->prepare("CALL EMP_STATUS(?)");

if (!$stmt) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Prepare failed: ' . $conn->error,
        'columns' => [],
        'data' => []
    ]);
    exit();
}

$stmt->bind_param("s", $empid);
$stmt->execute();
$result = $stmt->get_result();

$data = [];
$columnNames = [];

while ($row = $result->fetch_assoc()) {
    if (empty($columnNames)) {
        $columnNames = array_keys($row);
    }
    $data[] = $row;
}

while ($conn->more_results()) {
    $conn->next_result();
}

$stmt->close();
$conn->close();

if (empty($data)) {
    echo json_encode([
        'columns' => [],
        'data' => [],
        'message' => 'No data found for the provided EMPID'
    ]);
} else {
    echo json_encode([
        'columns' => $columnNames,
        'data' => $data
    ]);
}
?>