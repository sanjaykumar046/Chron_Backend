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

$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? intval($_GET['id']) : null;

switch ($method) {

    case 'GET':
        $result = $conn->query("SELECT * FROM AI_Rules ORDER BY created_at DESC");
        $rows   = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        echo json_encode(["success" => true, "data" => $rows]);
        break;

    case 'POST':
        $b          = json_decode(file_get_contents("php://input"), true);
        $rule_name  = $conn->real_escape_string(trim($b['rule_name']  ?? ''));
        $created_by = $conn->real_escape_string($b['created_by'] ?? 'System'); // ✅ NEW
        $updated_by = $conn->real_escape_string($b['updated_by'] ?? 'System'); // ✅ NEW

        if (!$rule_name) {
            http_response_code(400);
            echo json_encode(["success" => false, "error" => "rule_name required"]);
            break;
        }

        $conn->query("INSERT INTO AI_Rules (rule_name, created_by, updated_by)
                      VALUES ('$rule_name', '$created_by', '$updated_by')"); // ✅ NEW
        $newId = $conn->insert_id;
        $row   = $conn->query("SELECT * FROM AI_Rules WHERE id=$newId")->fetch_assoc();
        echo json_encode(["success" => true, "data" => $row]);
        break;

    case 'PUT':
        if (!$id) {
            http_response_code(400);
            echo json_encode(["success" => false, "error" => "id required"]);
            break;
        }
        $b          = json_decode(file_get_contents("php://input"), true);
        $rule_name  = $conn->real_escape_string(trim($b['rule_name']  ?? ''));
        $updated_by = $conn->real_escape_string($b['updated_by'] ?? 'System'); // ✅ NEW

        if (!$rule_name) {
            http_response_code(400);
            echo json_encode(["success" => false, "error" => "rule_name required"]);
            break;
        }

        $conn->query("UPDATE AI_Rules SET rule_name='$rule_name', updated_by='$updated_by' WHERE id=$id"); // ✅ NEW
        $row = $conn->query("SELECT * FROM AI_Rules WHERE id=$id")->fetch_assoc();
        echo json_encode(["success" => true, "data" => $row]);
        break;

    case 'DELETE':
        if (!$id) {
            http_response_code(400);
            echo json_encode(["success" => false, "error" => "id required"]);
            break;
        }
        $conn->query("DELETE FROM group_rule_access WHERE rule_id=$id");
        $conn->query("DELETE FROM AI_Rules WHERE id=$id");
        echo json_encode(["success" => true]);
        break;

    default:
        http_response_code(405);
        echo json_encode(["success" => false, "error" => "Method not allowed"]);
}

$conn->close();