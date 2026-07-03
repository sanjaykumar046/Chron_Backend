<?php
require_once 'db.php';

$method        = $_SERVER['REQUEST_METHOD'];
$department_id = isset($_GET['department_id']) ? intval($_GET['department_id']) : null;

switch ($method) {

    case 'GET':
        // Returns all rows, or filtered by department_id
        $sql    = $department_id ? "SELECT * FROM department_role_access WHERE department_id=$department_id"
                                 : "SELECT * FROM department_role_access";
        $result = $conn->query($sql);
        $rows   = [];
        while ($row = $result->fetch_assoc()) {
            $row['is_allowed'] = (bool)$row['is_allowed'];
            $rows[] = $row;
        }
        echo json_encode(["success" => true, "data" => $rows]);
        break;

    case 'POST':
        // Upsert: set role access for a department
        $b      = json_decode(file_get_contents("php://input"), true);
        $dep_id = intval($b['department_id'] ?? 0);
        $rol_id = intval($b['role_id']       ?? 0);
        $allow  = ($b['is_allowed'] ?? false) ? 1 : 0;

        if (!$dep_id || !$rol_id) {
            http_response_code(400);
            echo json_encode(["success"=>false,"error"=>"department_id and role_id required"]);
            break;
        }

        $exists = $conn->query("SELECT id FROM department_role_access WHERE department_id=$dep_id AND role_id=$rol_id")->num_rows > 0;
        if ($exists) {
            $conn->query("UPDATE department_role_access SET is_allowed=$allow WHERE department_id=$dep_id AND role_id=$rol_id");
        } else {
            $conn->query("INSERT INTO department_role_access (emp_id,emp_name,department_id,role_id,is_allowed)
                          VALUES ('SYS','System',$dep_id,$rol_id,$allow)");
        }
        $row = $conn->query("SELECT * FROM department_role_access WHERE department_id=$dep_id AND role_id=$rol_id")->fetch_assoc();
        $row['is_allowed'] = (bool)$row['is_allowed'];
        echo json_encode(["success" => true, "data" => $row]);
        break;

    default:
        http_response_code(405);
        echo json_encode(["success" => false, "error" => "Method not allowed"]);
}
$conn->close();