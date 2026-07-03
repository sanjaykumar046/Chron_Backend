<?php
error_reporting(0);
ini_set('display_errors', 0);

require_once 'Db1.php';

$method = $_SERVER['REQUEST_METHOD'];

// ── GET ──────────────────────────────────────────────────────
if ($method === 'GET') {
    $stmt = $conn->prepare("SELECT * FROM chat_approval WHERE group_id = 0");
    $stmt->execute();
    $result = $stmt->get_result();
    $row    = $result->fetch_assoc();

    if ($row) {
        $row['languages'] = $row['languages']
            ? json_decode($row['languages'], true)
            : ["English"];

        echo json_encode(["success" => true, "data" => $row]);
    } else {
        echo json_encode(["success" => false, "data" => null]);
    }

    $stmt->close();
}

// ── POST ─────────────────────────────────────────────────────
elseif ($method === 'POST') {
    $body = json_decode(file_get_contents("php://input"), true);

    $confidence_threshold = isset($body['confidence_threshold']) ? floatval($body['confidence_threshold']) : 0.75;
    $hallucination_guard  = isset($body['hallucination_guard'])  ? intval($body['hallucination_guard'])    : 1;
    $pii_filter           = isset($body['pii_filter'])           ? intval($body['pii_filter'])             : 1;
    $escalation_threshold = isset($body['escalation_threshold']) ? floatval($body['escalation_threshold']) : 0.85;
    $languages            = isset($body['languages'])
                                ? json_encode($body['languages'])
                                : json_encode(["English"]);

    // Check if record exists
    $check = $conn->prepare("SELECT id FROM chat_approval WHERE group_id = 0");
    $check->execute();
    $check->store_result();
    $exists = $check->num_rows > 0;
    $check->close();

    if ($exists) {
        // UPDATE
        $stmt = $conn->prepare("
            UPDATE chat_approval SET
                confidence_threshold = ?,
                hallucination_guard  = ?,
                pii_filter           = ?,
                escalation_threshold = ?,
                languages            = ?,
                updated_at           = CURRENT_TIMESTAMP
            WHERE group_id = 0
        ");
        $stmt->bind_param("diids",
            $confidence_threshold,
            $hallucination_guard,
            $pii_filter,
            $escalation_threshold,
            $languages
        );
    } else {
        // INSERT
        $stmt = $conn->prepare("
            INSERT INTO chat_approval 
                (group_id, confidence_threshold, hallucination_guard, pii_filter, escalation_threshold, languages)
            VALUES (0, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("diids",
            $confidence_threshold,
            $hallucination_guard,
            $pii_filter,
            $escalation_threshold,
            $languages
        );
    }

    if ($stmt->execute()) {
        echo json_encode(["success" => true, "message" => "Chat approval settings saved successfully"]);
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