<?php
include 'Db.php';

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$input  = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

// --- GET TEAM ISSUES (employees who report to L1) --------
if ($action === 'get_l1_issues') {
    $manager_id = $conn->real_escape_string($input['manager_id']);

    // Step 1: Find all employees reporting to this L1
    $stmt = $conn->prepare("
        SELECT EMPID FROM EMP_DB 
        WHERE REPORTING_1 = ?
    ");
    $stmt->bind_param("s", $manager_id);
    $stmt->execute();
    $reportees = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($reportees)) {
        echo json_encode(['success' => true, 'data' => []]);
        exit();
    }

    // Step 2: Get issues raised by those employees
    $ids = array_map(fn($r) => "'" . $r['EMPID'] . "'", $reportees);
    $inClause = implode(',', $ids);

    $result = $conn->query("
        SELECT i.*, m.menu_name, p.page_name 
        FROM issues i
        LEFT JOIN menus m ON i.menu_id = m.menu_id
        LEFT JOIN pages  p ON i.page_id = p.page_id
        WHERE i.raised_by IN ($inClause)
        ORDER BY i.created_on DESC
    ");
    $issues = $result->fetch_all(MYSQLI_ASSOC);
    echo json_encode(['success' => true, 'data' => $issues]);
}

// --- UPDATE STATUS ----------------------------------------
else if ($action === 'update_status') {
    $issue_id    = intval($input['issue_id']);
    $status      = $conn->real_escape_string($input['status']);
    $updated_by  = $conn->real_escape_string($input['updated_by']);
    $assigned_to = $conn->real_escape_string($input['assigned_to'] ?? '');

    $stmt = $conn->prepare("
        UPDATE issues 
        SET status = ?, updated_by = ?, assigned_to = ?, updated_on = NOW()
        WHERE issue_id = ?
    ");
    $stmt->bind_param("sssi", $status, $updated_by, $assigned_to, $issue_id);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => true, 'message' => 'Status updated!']);
}

else {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

$conn->close();
?>