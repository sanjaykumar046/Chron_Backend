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
$id     = isset($_GET['id'])     ? intval($_GET['id'])                        : null;
$emp_id = isset($_GET['emp_id']) ? $conn->real_escape_string($_GET['emp_id']) : null;

switch ($method) {

    case 'GET':
        if ($emp_id) {
            // Get grouping for a specific employee
            $result = $conn->query("SELECT * FROM user_ai_grouping WHERE emp_id='$emp_id' LIMIT 1");
        } else {
            // Get all mappings
            $result = $conn->query("SELECT * FROM user_ai_grouping ORDER BY created_at DESC");
        }
        $rows = [];
        while ($row = $result->fetch_assoc()) $rows[] = $row;
        echo json_encode(["success" => true, "data" => $rows]);
        break;

    case 'POST':
        $b          = json_decode(file_get_contents("php://input"), true);
        $emp_id     = $conn->real_escape_string($b['emp_id']     ?? '');
        $emp_name   = $conn->real_escape_string($b['emp_name']   ?? '');
        $group_id   = intval($b['group_id']   ?? 0);
        $group_name = $conn->real_escape_string($b['group_name'] ?? '');
        $updated_by = $conn->real_escape_string($b['updated_by'] ?? 'System');

        if (!$emp_id || !$group_id) {
            http_response_code(400);
            echo json_encode(["success" => false, "error" => "emp_id and group_id required"]);
            break;
        }

        // Upsert — update if exists, insert if not
        $exists = $conn->query("SELECT id FROM user_ai_grouping WHERE emp_id='$emp_id'")->num_rows > 0;
        if ($exists) {
            $conn->query("UPDATE user_ai_grouping 
                          SET group_id=$group_id, group_name='$group_name', emp_name='$emp_name', updated_by='$updated_by'
                          WHERE emp_id='$emp_id'");
        } else {
            $conn->query("INSERT INTO user_ai_grouping (emp_id, emp_name, group_id, group_name, created_by, updated_by)
              VALUES ('$emp_id', '$emp_name', $group_id, '$group_name', '$updated_by', '$updated_by')");
        }

        $row = $conn->query("SELECT * FROM user_ai_grouping WHERE emp_id='$emp_id'")->fetch_assoc();
        echo json_encode(["success" => true, "data" => $row]);
        break;

    case 'DELETE':
        if (!$emp_id) {
            http_response_code(400);
            echo json_encode(["success" => false, "error" => "emp_id required"]);
            break;
        }
        $conn->query("DELETE FROM user_ai_grouping WHERE emp_id='$emp_id'");
        echo json_encode(["success" => true]);
        break;

    default:
        http_response_code(405);
        echo json_encode(["success" => false, "error" => "Method not allowed"]);
}

$conn->close();