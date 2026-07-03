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

switch ($method) {

    case 'GET':
        $sql = "
            SELECT 
                g.id,
                g.group_name,
                g.scope,
                g.color,
                COALESCE(d.retention_days, 90)   AS retention_days,
                COALESCE(d.created_by, 'System') AS created_by,
                COALESCE(d.updated_by, 'System') AS updated_by,
                (
                    SELECT COUNT(*) 
                    FROM group_rule_access gra 
                    WHERE gra.group_id = g.id 
                    AND CAST(gra.is_allowed AS UNSIGNED) = 1
                ) AS allowed_count
            FROM ai_groups g
            LEFT JOIN group_data_access d ON d.group_id = g.id
            ORDER BY g.created_at ASC
        ";

        $result = $conn->query($sql);
        $rows   = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                "id"             => (int)$row['id'],
                "group_name"     => $row['group_name'],
                "scope"          => $row['scope'],
                "color"          => $row['color'],
                "allowed_count"  => (int)$row['allowed_count'],
                "retention_days" => (int)$row['retention_days'],
                "created_by"     => $row['created_by'],
                "updated_by"     => $row['updated_by'],
            ];
        }

        echo json_encode(["success" => true, "data" => $rows]);
        break;

    case 'PUT':
        $b          = json_decode(file_get_contents("php://input"), true);
        $g_id       = intval($b['group_id']      ?? 0);
        $retention  = intval($b['retention_days'] ?? 90);
        $updated_by = $conn->real_escape_string($b['updated_by']  ?? 'System');
        $group_name = $conn->real_escape_string($b['group_name']  ?? '');

        if (!$g_id) {
            http_response_code(400);
            echo json_encode(["success" => false, "error" => "group_id required"]);
            break;
        }

        $exists = $conn->query("SELECT id FROM group_data_access WHERE group_id=$g_id LIMIT 1")->num_rows > 0;

        if ($exists) {
            $conn->query("
                UPDATE group_data_access 
                SET retention_days = $retention,
                    group_name     = '$group_name',
                    updated_by     = '$updated_by',
                    updated_at     = NOW()
                WHERE group_id = $g_id
            ");
        } else {
            // is_allowed column was dropped — do NOT include it
            $conn->query("
                INSERT INTO group_data_access 
                    (group_id, group_name, data_type, retention_days, created_by, updated_by)
                VALUES 
                    ($g_id, '$group_name', 'general', $retention, '$updated_by', '$updated_by')
            ");
        }

        echo json_encode(["success" => true]);
        break;

    default:
        http_response_code(405);
        echo json_encode(["success" => false, "error" => "Method not allowed"]);
}

$conn->close();