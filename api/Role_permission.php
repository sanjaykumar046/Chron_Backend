<?php
require_once 'db.php';
$method  = $_SERVER['REQUEST_METHOD'];
$role_id = isset($_GET['role_id']) ? intval($_GET['role_id']) : null;
switch ($method) {
    case 'GET':
        $sql    = $role_id ? "SELECT * FROM role_permissions WHERE role_id=$role_id LIMIT 1"
                           : "SELECT * FROM role_permissions";
        $result = $conn->query($sql);
        $rows   = [];
        while ($row = $result->fetch_assoc()) {
            $row['attendance_access'] = (bool)$row['attendance_access'];
            $row['activity_access']   = (bool)$row['activity_access'];
            $row['leave_access']      = (bool)$row['leave_access'];
            $row['website_access']    = (bool)$row['website_access'];
            $rows[] = $row;
        }
        echo json_encode(["success" => true, "data" => $role_id ? ($rows[0] ?? null) : $rows]);
        break;
    case 'POST':
        $b          = json_decode(file_get_contents("php://input"), true);
        $r_id       = intval($b['role_id'] ?? 0);
        $attendance = ($b['attendance_access'] ?? false) ? 1 : 0;
        $activity   = ($b['activity_access']   ?? false) ? 1 : 0;
        $leave      = ($b['leave_access']      ?? false) ? 1 : 0;
        $website    = ($b['website_access']    ?? false) ? 1 : 0;
        if (!$r_id) { http_response_code(400); echo json_encode(["success"=>false,"error"=>"role_id required"]); break; }
        $exists = $conn->query("SELECT id FROM role_permissions WHERE role_id=$r_id")->num_rows > 0;
        if ($exists) {
            $conn->query("UPDATE role_permissions SET attendance_access=$attendance, activity_access=$activity,
                          leave_access=$leave, website_access=$website WHERE role_id=$r_id");
        } else {
            $conn->query("INSERT INTO role_permissions (emp_id,emp_name,role_id,attendance_access,activity_access,leave_access,website_access)
                          VALUES ('SYS','System',$r_id,$attendance,$activity,$leave,$website)");
        }
        $row = $conn->query("SELECT * FROM role_permissions WHERE role_id=$r_id")->fetch_assoc();
        $row['attendance_access'] = (bool)$row['attendance_access'];
        $row['activity_access']   = (bool)$row['activity_access'];
        $row['leave_access']      = (bool)$row['leave_access'];
        $row['website_access']    = (bool)$row['website_access'];
        echo json_encode(["success" => true, "data" => $row]);
        break;
    case 'PUT':
        if (!$role_id) { http_response_code(400); echo json_encode(["success"=>false,"error"=>"role_id required"]); break; }
        $b      = json_decode(file_get_contents("php://input"), true);
        $fields = [];
        if (array_key_exists('attendance_access', $b)) $fields[] = "attendance_access=" . ($b['attendance_access'] ? 1 : 0);
        if (array_key_exists('activity_access',   $b)) $fields[] = "activity_access="   . ($b['activity_access']   ? 1 : 0);
        if (array_key_exists('leave_access',      $b)) $fields[] = "leave_access="      . ($b['leave_access']      ? 1 : 0);
        if (array_key_exists('website_access',    $b)) $fields[] = "website_access="    . ($b['website_access']    ? 1 : 0);
        if (empty($fields)) { http_response_code(400); echo json_encode(["success"=>false,"error"=>"nothing to update"]); break; }
        $conn->query("UPDATE role_permissions SET " . implode(",", $fields) . " WHERE role_id=$role_id");
        $row = $conn->query("SELECT * FROM role_permissions WHERE role_id=$role_id")->fetch_assoc();
        $row['attendance_access'] = (bool)$row['attendance_access'];
        $row['activity_access']   = (bool)$row['activity_access'];
        $row['leave_access']      = (bool)$row['leave_access'];
        $row['website_access']    = (bool)$row['website_access'];
        echo json_encode(["success" => true, "data" => $row]);
        break;
    default:
        http_response_code(405);
        echo json_encode(["success" => false, "error" => "Method not allowed"]);
}
$conn->close();