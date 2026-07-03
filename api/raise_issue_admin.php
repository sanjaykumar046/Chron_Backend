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

// --- GET ALL ISSUES ---------------------------------------
if ($action === 'get_all_issues') {
    $result = $conn->query("
        SELECT i.*, m.menu_name, p.page_name 
        FROM issues i
        LEFT JOIN menus m ON i.menu_id = m.menu_id
        LEFT JOIN pages  p ON i.page_id = p.page_id
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

    $stmt = $conn->prepare("
        UPDATE issues 
        SET status = ?, updated_by = ?, updated_on = NOW()
        WHERE issue_id = ?
    ");
    $stmt->bind_param("ssi", $status, $updated_by, $issue_id);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => true, 'message' => 'Status updated!']);
}

else {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

$conn->close();
?>