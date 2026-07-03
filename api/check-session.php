<?php
include 'apiMain.php';
include 'session-config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['EMPID'])) {
    echo json_encode([
        "status" => "error",
        "message" => "No active session",
        "expired" => true
    ]);
    exit();
}

echo json_encode([
    "status" => "success",
    "message" => "Session active",
    "timeRemaining" => 600 - (time() - $_SESSION['LAST_ACTIVITY'])
]);
?>