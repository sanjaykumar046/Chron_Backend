<?php
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
header('Content-Type: application/json');
date_default_timezone_set('UTC');
set_time_limit(120);

function finishAlertGeneration($conn, $status, $payload) {
    http_response_code($status);
    echo json_encode($payload);
    $conn->close();
    exit;
}

$managerEmpId = trim((string)($_GET['empid'] ?? ''));
if ($managerEmpId === '' || strtoupper($managerEmpId) === 'ALL') {
    finishAlertGeneration($conn, 400, [
        'success' => false,
        'message' => 'A logged-in manager EMPID is required to check that manager\'s reportees.',
    ]);
}
$escapedManagerEmpId = $conn->real_escape_string($managerEmpId);
$statusResult = $conn->query("CALL PMS_PRO.EMP_STATUS('{$escapedManagerEmpId}')");
if (!$statusResult) {
    finishAlertGeneration($conn, 500, [
        'success' => false,
        'message' => 'Could not read employee status.',
        'detail' => $conn->error,
    ]);
}

$statusRows = [];
while ($row = $statusResult->fetch_assoc()) {
    $statusRows[] = $row;
}
$statusResult->free();
while ($conn->more_results()) {
    if (!$conn->next_result()) break;
    $extra = $conn->use_result();
    if ($extra instanceof mysqli_result) $extra->free();
}

$employeeRows = [];
$employeeResult = $conn->query(
    "SELECT EMPID, EMPNAME, REPORTING_1
     FROM PMS_PRO.EMP_DB
     WHERE UPPER(COALESCE(ACTIVE_YN, 'Y')) = 'Y'"
);
if (!$employeeResult) {
    finishAlertGeneration($conn, 500, [
        'success' => false,
        'message' => 'Could not read employee reporting assignments.',
        'detail' => $conn->error,
    ]);
}
while ($employee = $employeeResult->fetch_assoc()) {
    $employeeRows[(string)$employee['EMPID']] = $employee;
}
$employeeResult->free();

$generated = 0;
$skipped = 0;
$now = time();
$insert = $conn->prepare(
    "INSERT INTO PMS_PRO.ALERTS_RT
       (ALERT_KEY, LEVEL, TITLE, MESSAGE, SCOPE, SOURCE, META, CREATED_AT)
     VALUES (?, 'medium', ?, ?, ?, 'rules', ?, UTC_TIMESTAMP())
     ON DUPLICATE KEY UPDATE
       LEVEL = VALUES(LEVEL), TITLE = VALUES(TITLE), MESSAGE = VALUES(MESSAGE),
       META = VALUES(META)"
);
if (!$insert) {
    finishAlertGeneration($conn, 500, [
        'success' => false,
        'message' => 'Could not prepare alert storage.',
        'detail' => $conn->error,
    ]);
}

foreach ($statusRows as $statusRow) {
    $empId = trim((string)($statusRow['EMPID'] ?? $statusRow['EmpID'] ?? ''));
    $status = strtoupper(trim((string)($statusRow['CURRENT_STATUS'] ?? '')));
    $lastSeen = trim((string)($statusRow['LAST_SEEN'] ?? ''));

    if ($empId === '' || $status !== 'INACTIVE' || $lastSeen === '' || !isset($employeeRows[$empId])) {
        $skipped++;
        continue;
    }

    $lastSeenTimestamp = strtotime($lastSeen);
    if ($lastSeenTimestamp === false || ($now - $lastSeenTimestamp) <= 300) {
        $skipped++;
        continue;
    }

    $employee = $employeeRows[$empId];
    $reportingManagerId = trim((string)($employee['REPORTING_1'] ?? ''));
    if ($reportingManagerId === '' || strcasecmp($reportingManagerId, $managerEmpId) !== 0) {
        $skipped++;
        continue;
    }

    $employeeName = trim((string)($employee['EMPNAME'] ?? ''));
    if ($employeeName === '') $employeeName = $empId;
    $scope = 'REPORTING_1:' . $reportingManagerId;
    $title = 'Employee inactive for more than 5 minutes';
    $message = $employeeName . ' (' . $empId . ') has had no recorded activity for more than 5 minutes.';
    $alertKey = 'idle5_' . substr(hash('sha256', $empId . '|' . gmdate('Y-m-d H:i:s', $lastSeenTimestamp)), 0, 40);
    $meta = json_encode([
        'category' => 'ops',
        'rule' => 'inactivity_over_5_minutes',
        'employee_id' => $empId,
        'employee_name' => $employeeName,
        'reporting_1' => $reportingManagerId,
        'last_seen' => gmdate('Y-m-d H:i:s', $lastSeenTimestamp),
        'inactive_minutes' => (int)floor(($now - $lastSeenTimestamp) / 60),
    ]);

    $insert->bind_param('sssss', $alertKey, $title, $message, $scope, $meta);
    if (!$insert->execute()) {
        $error = $insert->error;
        $insert->close();
        finishAlertGeneration($conn, 500, [
            'success' => false,
            'message' => 'Could not store an inactivity alert.',
            'detail' => $error,
        ]);
    }
    $generated++;
}

$insert->close();
finishAlertGeneration($conn, 200, [
    'success' => true,
    'rule' => 'inactivity_over_5_minutes',
    'status_scope' => $managerEmpId,
    'checked' => count($statusRows),
    'generated' => $generated,
    'skipped' => $skipped,
]);
?>
