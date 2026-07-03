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
        $result = $conn->query("SELECT * FROM ai_groups ORDER BY created_at ASC");
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $row['can_edit']      = (bool)$row['can_edit'];
            $row['members_count'] = (int)$row['members_count'];
            $rows[] = $row;
        }
        echo json_encode(["success" => true, "data" => $rows]);
        break;

    case 'POST':
        $b          = json_decode(file_get_contents("php://input"), true);
        $group_name = $conn->real_escape_string($b['group_name'] ?? '');
        $scope      = $conn->real_escape_string($b['scope']      ?? '');
        $color      = $conn->real_escape_string($b['color']      ?? 'blue');
        $created_by = $conn->real_escape_string($b['created_by'] ?? 'System'); // ✅ NEW
        $updated_by = $conn->real_escape_string($b['updated_by'] ?? 'System'); // ✅ NEW

        if (!$group_name) {
            http_response_code(400);
            echo json_encode(["success" => false, "error" => "group_name required"]);
            break;
        }

        $conn->query("INSERT INTO ai_groups (group_name, scope, color, members_count, can_edit, created_by, updated_by)
                      VALUES ('$group_name', '$scope', '$color', 0, 1, '$created_by', '$updated_by')"); // ✅ NEW
        $newId = $conn->insert_id;
        $row   = $conn->query("SELECT * FROM ai_groups WHERE id=$newId")->fetch_assoc();
        $row['can_edit']      = (bool)$row['can_edit'];
        $row['members_count'] = (int)$row['members_count'];
        echo json_encode(["success" => true, "data" => $row]);
        break;

    case 'PUT':
        if (!$id) {
            http_response_code(400);
            echo json_encode(["success" => false, "error" => "id required"]);
            break;
        }
        $b      = json_decode(file_get_contents("php://input"), true);
        $fields = [];
        if (isset($b['group_name'])) $fields[] = "group_name='" . $conn->real_escape_string($b['group_name']) . "'";
        if (isset($b['scope']))      $fields[] = "scope='"      . $conn->real_escape_string($b['scope'])      . "'";
        if (isset($b['color']))      $fields[] = "color='"      . $conn->real_escape_string($b['color'])      . "'";
        if (isset($b['updated_by'])) $fields[] = "updated_by='" . $conn->real_escape_string($b['updated_by']) . "'"; // ✅ NEW

        if (empty($fields)) {
            http_response_code(400);
            echo json_encode(["success" => false, "error" => "nothing to update"]);
            break;
        }

        $conn->query("UPDATE ai_groups SET " . implode(",", $fields) . " WHERE id=$id");
        $row = $conn->query("SELECT * FROM ai_groups WHERE id=$id")->fetch_assoc();
        $row['can_edit']      = (bool)$row['can_edit'];
        $row['members_count'] = (int)$row['members_count'];
        echo json_encode(["success" => true, "data" => $row]);
        break;

    case 'DELETE':
        if (!$id) {
            http_response_code(400);
            echo json_encode(["success" => false, "error" => "id required"]);
            break;
        }
        $conn->query("DELETE FROM group_page_access WHERE group_id=$id");
        $conn->query("DELETE FROM group_data_access WHERE group_id=$id");
        $conn->query("DELETE FROM ai_groups WHERE id=$id");
        echo json_encode(["success" => true]);
        break;

    default:
        http_response_code(405);
        echo json_encode(["success" => false, "error" => "Method not allowed"]);
}

$conn->close();