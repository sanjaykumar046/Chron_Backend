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
$type   = isset($_GET['type']) ? $_GET['type'] : 'allowed'; // 'allowed' or 'blocked'
$id     = isset($_GET['id'])   ? intval($_GET['id']) : null;

$table = ($type === 'blocked') ? 'ai_blocked_topics' : 'ai_allowed_topics';

switch ($method) {

    case 'GET':
        $result = $conn->query("SELECT * FROM $table ORDER BY created_at ASC");
        $rows   = [];
        while ($row = $result->fetch_assoc()) {
            $row['is_active'] = (bool)$row['is_active'];
            $rows[] = $row;
        }
        echo json_encode(["success" => true, "data" => $rows]);
        break;

    case 'POST':
        $b          = json_decode(file_get_contents("php://input"), true);
        $topic_name = $conn->real_escape_string($b['topic_name'] ?? '');
        $is_active  = ($b['is_active'] ?? true) ? 1 : 0;
        $emp_id     = $conn->real_escape_string($b['emp_id']   ?? 'SYS');    // ✅ NEW
        $emp_name   = $conn->real_escape_string($b['emp_name'] ?? 'System'); // ✅ NEW

        if (!$topic_name) {
            http_response_code(400);
            echo json_encode(["success" => false, "error" => "topic_name required"]);
            break;
        }

        if ($type === 'blocked') {
            $reason = $conn->real_escape_string($b['reason'] ?? 'Custom rule');
            $conn->query("INSERT INTO ai_blocked_topics (emp_id, emp_name, topic_name, reason, is_active, created_by, updated_by)
              VALUES ('$emp_id', '$emp_name', '$topic_name', '$reason', $is_active, '$emp_name', '$emp_name')");// ✅ NEW
        } else {
            $role_name = $conn->real_escape_string($b['role_name'] ?? 'All');
            $conn->query("INSERT INTO ai_allowed_topics (emp_id, emp_name, topic_name, role_name, is_active, created_by, updated_by)
              VALUES ('$emp_id', '$emp_name', '$topic_name', '$role_name', $is_active, '$emp_name', '$emp_name')");// ✅ NEW
        }

        $newId = $conn->insert_id;
        $row   = $conn->query("SELECT * FROM $table WHERE id=$newId")->fetch_assoc();
        $row['is_active'] = (bool)$row['is_active'];
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
        if (isset($b['topic_name'])) $fields[] = "topic_name='" . $conn->real_escape_string($b['topic_name']) . "'";
        if (isset($b['is_active']))  $fields[] = "is_active="   . ($b['is_active'] ? 1 : 0);
        if (isset($b['emp_name']))   $fields[] = "emp_name='"   . $conn->real_escape_string($b['emp_name'])   . "'"; // ✅ NEW
        if (isset($b['emp_id']))     $fields[] = "emp_id='"     . $conn->real_escape_string($b['emp_id'])     . "'"; // ✅ NEW
        if ($type === 'blocked' && isset($b['reason']))    $fields[] = "reason='"    . $conn->real_escape_string($b['reason'])    . "'";
        if ($type === 'allowed' && isset($b['role_name'])) $fields[] = "role_name='" . $conn->real_escape_string($b['role_name']) . "'";

        if (empty($fields)) {
            http_response_code(400);
            echo json_encode(["success" => false, "error" => "nothing to update"]);
            break;
        }

        $conn->query("UPDATE $table SET " . implode(",", $fields) . " WHERE id=$id");
        $row = $conn->query("SELECT * FROM $table WHERE id=$id")->fetch_assoc();
        $row['is_active'] = (bool)$row['is_active'];
        echo json_encode(["success" => true, "data" => $row]);
        break;

    case 'DELETE':
        if (!$id) {
            http_response_code(400);
            echo json_encode(["success" => false, "error" => "id required"]);
            break;
        }
        $conn->query("DELETE FROM $table WHERE id=$id");
        echo json_encode(["success" => true]);
        break;

    default:
        http_response_code(405);
        echo json_encode(["success" => false, "error" => "Method not allowed"]);
}

$conn->close();