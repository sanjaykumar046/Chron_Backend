<?php
header('Content-Type: application/json');
include 'Db1.php'; // however your other files connect

$sql = "SELECT id, 'AI Grouping' AS module,
               IF(created_at = updated_at, 'create', 'update') AS action,
               CONCAT('Group \"', group_name, '\" ', IF(created_at = updated_at, 'created', 'updated')) AS description,
               COALESCE(updated_by, created_by) AS done_by,
               COALESCE(updated_at, created_at) AS created_at
        FROM ai_groups
        UNION ALL
        SELECT id, 'AI Rules',
               IF(created_at = updated_at, 'create', 'update'),
               CONCAT('Rule \"', rule_name, '\" ', IF(created_at = updated_at, 'created', 'updated')),
               COALESCE(updated_by, created_by),
               COALESCE(updated_at, created_at)
        FROM ai_rules
        UNION ALL
        SELECT id, 'AI Topic Rules',
               IF(created_at = updated_at, 'create', 'update'),
               CONCAT('Allowed topic \"', topic_name, '\" ', IF(created_at = updated_at, 'created', 'updated')),
               COALESCE(updated_by, emp_name),
               COALESCE(updated_at, created_at)
        FROM ai_allowed_topics
        UNION ALL
        SELECT id, 'AI Topic Rules',
               IF(created_at = updated_at, 'create', 'update'),
               CONCAT('Blocked topic \"', topic_name, '\" ', IF(created_at = updated_at, 'created', 'updated')),
               COALESCE(updated_by, emp_name),
               COALESCE(updated_at, created_at)
        FROM ai_blocked_topics
        UNION ALL
        SELECT id, 'Data Access', 'update',
               CONCAT('Group \"', group_name, '\" rule access updated'),
               updated_by, updated_at
        FROM group_rule_access
        ORDER BY created_at DESC LIMIT 200";

$result = $conn->query($sql);
$data = [];
while ($row = $result->fetch_assoc()) $data[] = $row;

echo json_encode(['success' => true, 'data' => $data]);