<?php
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? intval($_GET['id']) : null;

switch ($method) {

    case 'GET':
        $result = $conn->query("SELECT * FROM roles ORDER BY created_at ASC");
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $row['can_edit'] = (bool)$row['can_edit'];
            $rows[] = $row;
        }
        echo json_encode(["success" => true, "data" => $rows]);
        break;

    case 'POST':
        $b         = json_decode(file_get_contents("php://input"), true);
        $role_name = $conn->real_escape_string($b['role_name'] ?? '');
        $scope     = $conn->real_escape_string($b['scope']     ?? '');
        $color     = $conn->real_escape_string($b['color']     ?? 'blue');
        $can_edit  = 1;

        if (!$role_name) { http_response_code(400); echo json_encode(["success"=>false,"error"=>"role_name required"]); break; }

        $conn->query("INSERT INTO roles (emp_id, emp_name, role_name, scope, color, members_count, can_edit)
                      VALUES ('SYS','System','$role_name','$scope','$color',0,$can_edit)");
        $newId = $conn->insert_id;
        $row   = $conn->query("SELECT * FROM roles WHERE id=$newId")->fetch_assoc();
        $row['can_edit'] = (bool)$row['can_edit'];
        echo json_encode(["success" => true, "data" => $row]);
        break;

    case 'PUT':
        if (!$id) { http_response_code(400); echo json_encode(["success"=>false,"error"=>"id required"]); break; }
        $b      = json_decode(file_get_contents("php://input"), true);
        $fields = [];
        if (isset($b['role_name'])) $fields[] = "role_name='" . $conn->real_escape_string($b['role_name']) . "'";
        if (isset($b['scope']))     $fields[] = "scope='"     . $conn->real_escape_string($b['scope'])     . "'";
        if (empty($fields))         { http_response_code(400); echo json_encode(["success"=>false,"error"=>"nothing to update"]); break; }

        $conn->query("UPDATE roles SET " . implode(",", $fields) . " WHERE id=$id");
        $row = $conn->query("SELECT * FROM roles WHERE id=$id")->fetch_assoc();
        $row['can_edit'] = (bool)$row['can_edit'];
        echo json_encode(["success" => true, "data" => $row]);
        break;

    case 'DELETE':
        if (!$id) { http_response_code(400); echo json_encode(["success"=>false,"error"=>"id required"]); break; }
        $conn->query("DELETE FROM role_permissions WHERE role_id=$id");
        $conn->query("DELETE FROM roles WHERE id=$id");
        echo json_encode(["success" => true]);
        break;

    default:
        http_response_code(405);
        echo json_encode(["success" => false, "error" => "Method not allowed"]);
}
$conn->close();