<?php
error_reporting(0);
ini_set('display_errors', 0);

require_once 'Db1.php';

$method = $_SERVER['REQUEST_METHOD'];

// ── GET ──────────────────────────────────────────────────────
if ($method === 'GET') {
    $group_id = isset($_GET['group_id']) ? intval($_GET['group_id']) : null;

    if ($group_id === null) {
        echo json_encode(["success" => false, "error" => "group_id is required"]);
        exit();
    }

    $stmt = $conn->prepare("SELECT * FROM chat_limitations WHERE group_id = ?");
    $stmt->bind_param("i", $group_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row    = $result->fetch_assoc();

    if ($row) {
        echo json_encode(["success" => true, "data" => $row]);
    } else {
        echo json_encode(["success" => false, "data" => null]);
    }

    $stmt->close();
}

// ── POST ─────────────────────────────────────────────────────
elseif ($method === 'POST') {
    $body = json_decode(file_get_contents("php://input"), true);

    $group_id            = isset($body['group_id'])            ? intval($body['group_id'])            : null;
    $group_name          = isset($body['group_name'])          ? trim($body['group_name'])            : '';
    $token_length        = isset($body['token_length'])        ? intval($body['token_length'])        : 500;
    $rate_limit_count    = isset($body['rate_limit_count'])    ? intval($body['rate_limit_count'])    : 100;
    $rate_limit_period   = isset($body['rate_limit_period'])   ? trim($body['rate_limit_period'])     : 'Day';
    $rate_limit_per_user = isset($body['rate_limit_per_user']) ? intval($body['rate_limit_per_user']) : 1;

    if ($group_id === null) {
        echo json_encode(["success" => false, "error" => "group_id is required"]);
        exit();
    }

    // Check if record exists
    $check = $conn->prepare("SELECT id FROM chat_limitations WHERE group_id = ?");
    $check->bind_param("i", $group_id);
    $check->execute();
    $check->store_result();
    $exists = $check->num_rows > 0;
    $check->close();

    if ($exists) {
        // UPDATE
        $stmt = $conn->prepare("
            UPDATE chat_limitations SET
                group_name          = ?,
                token_length        = ?,
                rate_limit_count    = ?,
                rate_limit_period   = ?,
                rate_limit_per_user = ?,
                updated_at          = CURRENT_TIMESTAMP
            WHERE group_id = ?
        ");
        $stmt->bind_param("siisis", 
            $group_name,
            $token_length,
            $rate_limit_count,
            $rate_limit_period,
            $rate_limit_per_user,
            $group_id
        );
    } else {
        // INSERT
        $stmt = $conn->prepare("
            INSERT INTO chat_limitations 
                (group_id, group_name, token_length, rate_limit_count, rate_limit_period, rate_limit_per_user)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("isiisi",
            $group_id,
            $group_name,
            $token_length,
            $rate_limit_count,
            $rate_limit_period,
            $rate_limit_per_user
        );
    }

    if ($stmt->execute()) {
        echo json_encode(["success" => true, "message" => "Chat limitations saved successfully"]);
    } else {
        echo json_encode(["success" => false, "error" => $stmt->error]);
    }

    $stmt->close();
}

else {
    echo json_encode(["success" => false, "error" => "Method not allowed"]);
}

$conn->close();
?>