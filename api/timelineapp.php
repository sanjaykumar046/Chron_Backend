<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

include 'apiMain.php';

$date   = $_POST['date']   ?? date('Y-m-d');
$empid  = $_POST['EMPID']  ?? 'ALL';
$userId = $_POST['userid'] ?? 'ALL';

$stmt = $conn->prepare("CALL PR_APP_URL_USAGE_TIMELINE(?, ?, ?)");
$stmt->bind_param("sss", $date, $empid, $userId);
$stmt->execute();
$result = $stmt->get_result();
$rows = $result->fetch_all(MYSQLI_ASSOC);

if (empty($rows)) {
  echo json_encode(["error" => "No data found."]);
} else {
  echo json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

$conn->close();
?>