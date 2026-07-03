<?php
include 'Db.php';
error_reporting(0);
ini_set('display_errors', 0);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, PUT, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}


$input  = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

// --- GET MENUS -------------------------------------------
if ($action === 'get_menus') {
    $result = $conn->query("SELECT menu_id, menu_name FROM menus ORDER BY menu_id");
    $menus = $result->fetch_all(MYSQLI_ASSOC);
    echo json_encode(['success' => true, 'data' => $menus]);
}

// --- GET PAGES BY MENU -----------------------------------
else if ($action === 'get_pages') {
    $menu_id = intval($input['menu_id']);
    $stmt = $conn->prepare("SELECT page_id, page_name FROM pages WHERE menu_id = ?");
    $stmt->bind_param("i", $menu_id);
    $stmt->execute();
    $pages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    echo json_encode(['success' => true, 'data' => $pages]);
}

// --- GET CLIENTS -----------------------------------------
else if ($action === 'get_clients') {
    $result = $conn->query("SELECT master_id, client_name FROM tenant_master WHERE status = 'ACTIVE' ORDER BY client_name");
    $clients = $result->fetch_all(MYSQLI_ASSOC);
    echo json_encode(['success' => true, 'data' => $clients]);
}

// --- SUBMIT ISSUE -----------------------------------------
else if ($action === 'submit_issue') {
    $menu_id       = intval($input['menu_id']);
    $page_id       = intval($input['page_id']);
    $issue_title   = $conn->real_escape_string($input['issue_title']);
    $description   = $conn->real_escape_string($input['description']);
    $priority      = $conn->real_escape_string($input['priority']);
    $impact_users  = intval($input['impact_users']);
    $client_type   = $conn->real_escape_string($input['client_type']);
    $client_id     = $client_type === 'All Clients' ? null : intval($input['client_id']);
    $raised_by     = $conn->real_escape_string($input['raised_by']);
    $raised_by_name = $conn->real_escape_string($input['raised_by_name']);

    $stmt = $conn->prepare("
        INSERT INTO issues 
        (menu_id, page_id, issue_title, description, priority, impact_users, client_type, client_id, raised_by, raised_by_name)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("iisssiisss", $menu_id, $page_id, $issue_title, $description, $priority, $impact_users, $client_type, $client_id, $raised_by, $raised_by_name);
    $stmt->execute();
    echo json_encode(['success' => true, 'message' => 'Issue submitted successfully!']);
}

else {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

$conn->close();
?>