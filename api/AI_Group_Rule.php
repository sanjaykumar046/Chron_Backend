<?php
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

include 'Db1.php';

$method   = $_SERVER['REQUEST_METHOD'];
$group_id = isset($_GET['group_id']) ? intval($_GET['group_id']) : null;

switch ($method) {

    case 'GET':
        // Returns all rules with is_allowed status for the given group
        if (!$group_id) {
            http_response_code(400);
            echo json_encode(["success" => false, "error" => "group_id required"]);
            break;
        }

        $rulesResult = $conn->query("SELECT * FROM AI_Rules ORDER BY created_at DESC");
        $rows = [];
        while ($rule = $rulesResult->fetch_assoc()) {
            $rid    = (int)$rule['id'];
            $access = $conn->query("SELECT is_allowed FROM group_rule_access WHERE group_id=$group_id AND rule_id=$rid")->fetch_assoc();
            $rows[] = [
                "id"         => $rid,
                "rule_name"  => $rule['rule_name'],
                "created_at" => $rule['created_at'],
                "updated_at" => $rule['updated_at'],
                "is_allowed" => $access ? (bool)$access['is_allowed'] : false,
            ];
        }

        echo json_encode(["success" => true, "data" => $rows]);
        break;

    case 'POST':
        // Toggle rule access for a group
        $b          = json_decode(file_get_contents("php://input"), true);
        $g_id       = intval($b['group_id']  ?? 0);
        $r_id       = intval($b['rule_id']   ?? 0);
        $is_allowed = ($b['is_allowed'] ?? false) ? 1 : 0;

        if (!$g_id || !$r_id) {
            http_response_code(400);
            echo json_encode(["success" => false, "error" => "group_id and rule_id required"]);
            break;
        }

$g_name = $conn->real_escape_string($b['group_name'] ?? '');
$exists = $conn->query("SELECT id FROM group_rule_access WHERE group_id=$g_id AND rule_id=$r_id")->num_rows > 0;
if ($exists) {
    $conn->query("UPDATE group_rule_access SET is_allowed=$is_allowed, group_name='$g_name' WHERE group_id=$g_id AND rule_id=$r_id");
} else {
    $conn->query("INSERT INTO group_rule_access (group_id, group_name, rule_id, is_allowed) VALUES ($g_id, '$g_name', $r_id, $is_allowed)");
}

        echo json_encode(["success" => true, "data" => [
            "group_id"   => $g_id,
            "rule_id"    => $r_id,
            "is_allowed" => (bool)$is_allowed,
        ]]);
        break;

    default:
        http_response_code(405);
        echo json_encode(["success" => false, "error" => "Method not allowed"]);
}

$conn->close();