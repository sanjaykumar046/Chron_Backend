<?php
// Endpoint-level CORS runs before the DB include, so DB failures retain CORS.
header('Access-Control-Allow-Origin: *');
header('Vary: Origin');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Max-Age: 86400');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

include 'apiMain.php';

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

function normalizeAccessRole($role) {
    $role = strtoupper(trim((string)$role));
    return match ($role) {
        'ADMIN' => 'CEO',
        'LEADERSHIP' => 'MANAGER',
        default => $role !== '' ? $role : 'ALL',
    };
}

function alertsTableExists($conn) {
    $result = $conn->query("SHOW TABLES LIKE 'ALERTS_RT'");
    if (!$result) {
        return false;
    }

    $exists = $result->num_rows > 0;
    $result->free();
    return $exists;
}

function sendSse($event, $payload) {
    echo "event: {$event}\n";
    echo 'data: ' . json_encode($payload) . "\n\n";
    @ob_flush();
    @flush();
}

$limit = isset($_GET['limit']) ? max(1, min(100, (int)$_GET['limit'])) : 20;
$empid = isset($_GET['empid']) ? trim($_GET['empid']) : '';
$accessRole = normalizeAccessRole($_GET['access_role'] ?? 'ALL');

sendSse('hello', [
    'success' => true,
    'access_role' => $accessRole,
    'empid' => $empid,
]);

if (!alertsTableExists($conn)) {
    sendSse('heartbeat', [
        'success' => true,
        'count' => 0,
        'warning' => 'ALERTS_RT table is not available yet.',
    ]);
    $conn->close();
    exit;
}

$hasRecipient = $empid !== '' && strtoupper($empid) !== 'ALL';
$scopeClause = $hasRecipient
    ? "SCOPE = 'REPORTING_1:" . $conn->real_escape_string($empid) . "'"
    : '1 = 0';
$sql = "SELECT ID, LEVEL, TITLE, MESSAGE, SCOPE, SOURCE, META, CREATED_AT
        FROM ALERTS_RT
        WHERE {$scopeClause}
        ORDER BY CREATED_AT DESC, ID DESC
        LIMIT {$limit}";

$result = $conn->query($sql);

if (!$result) {
    sendSse('error', [
        'success' => false,
        'message' => 'Query failed: ' . $conn->error,
    ]);
    $conn->close();
    exit;
}

$rows = [];
while ($row = $result->fetch_assoc()) {
    $row['META'] = $row['META'] ? json_decode($row['META'], true) : null;
    $rows[] = $row;
}
$result->free();

if (count($rows) > 0) {
    sendSse('snapshot', [
        'success' => true,
        'count' => count($rows),
        'data' => $rows,
    ]);

    foreach ($rows as $row) {
        sendSse('alert', $row);
    }
} else {
    sendSse('heartbeat', [
        'success' => true,
        'count' => 0,
        'data' => [],
    ]);
}

$conn->close();
?>
