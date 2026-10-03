<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

include 'apiMain.php';

$inputData = file_get_contents('php://input');
parse_str($inputData, $parsedData);

$date = $parsedData['date'] ?? date('Y-m-d');
$empid = $parsedData['EMPID'] ?? 'ALL';
$empname = $parsedData['EMPNAME'] ?? 'ALL';
$team = $parsedData['TEAM'] ?? 'ALL';
$role = $parsedData['ROLE'] ?? 'ALL';
$department = $parsedData['DEPARTMENT'] ?? 'ALL';
$project = $parsedData['PROJECT'] ?? 'ALL';
$userId = $parsedData['userid'] ?? 'ALL';

try {
    $stmt = $conn->prepare(
        'CALL PR_USER_TIMELINE_AGG(?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        throw new RuntimeException('Could not prepare timeline query: ' . $conn->error);
    }

    $stmt->bind_param(
        'ssssssss',
        $date,
        $empid,
        $empname,
        $team,
        $role,
        $department,
        $project,
        $userId
    );

    if (!$stmt->execute()) {
        throw new RuntimeException('Could not execute timeline query: ' . $stmt->error);
    }

    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    if ($result) $result->free();
    $stmt->close();

    while ($conn->more_results()) {
        if (!$conn->next_result()) break;
        $extra = $conn->use_result();
        if ($extra instanceof mysqli_result) $extra->free();
    }

    if (empty($rows)) {
        echo json_encode(['message' => 'No data found.']);
    } else {
        echo json_encode($rows, JSON_PRETTY_PRINT);
    }
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['error' => $error->getMessage()]);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>