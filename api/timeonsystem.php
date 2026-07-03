<?php
include 'apiMain.php';

$from_date = isset($_GET['from_date']) ? trim($_GET['from_date']) : '';
$to_date   = isset($_GET['to_date'])   ? trim($_GET['to_date'])   : '';
$empid     = isset($_GET['empid'])     ? trim($_GET['empid'])     : 'ALL';
$user_id   = isset($_GET['user_id'])   ? trim($_GET['user_id'])   : '';

$dateRx = '/^\d{4}-\d{2}-\d{2}$/';
if (empty($from_date) || empty($to_date) || !preg_match($dateRx, $from_date) || !preg_match($dateRx, $to_date)) {
    $year      = date('Y');
$from_date = $year . '-01-01';
$to_date   = $year . '-01-30';
}

$empid_param = (empty($empid) || strtoupper($empid) === 'ALL') ? 'ALL' : $conn->real_escape_string($empid);

$stmt = $conn->prepare("CALL PR_GET_ATTENDANCE_REPORT(?, ?, ?, 'ALL', 'ALL', 'ALL', 'ALL', ?)");
if (!$stmt) {
    echo json_encode(['status' => 'error', 'message' => 'Prepare failed: ' . $conn->error]);
    exit();
}

$stmt->bind_param("ssss", $from_date, $to_date, $empid_param, $user_id);
if (!$stmt->execute()) {
    echo json_encode(['status' => 'error', 'message' => 'Execution failed: ' . $stmt->error]);
    $stmt->close(); $conn->close(); exit();
}

$result = $stmt->get_result();
if (!$result) { $stmt->next_result(); $result = $stmt->get_result(); }
if (!$result) {
    echo json_encode(['status' => 'error', 'message' => 'No result from procedure.']);
    $stmt->close(); $conn->close(); exit();
}

$employees = [];
$dates_set = [];

while ($row = $result->fetch_assoc()) {
    $eid   = $row['EMPID']   ?? 'UNKNOWN';
    $ename = $row['EMPNAME'] ?? 'Unknown';
    $date  = isset($row['WORK_DATE']) ? date('Y-m-d', strtotime($row['WORK_DATE'])) : null;
    $hours_raw = $row['TOTAL_LOGGED_HOURS'] ?? '00:00:00';
    $status    = strtoupper(trim($row['ATTENDANCE_STATUS'] ?? ''));

    if (!$date) continue;

    $dates_set[$date] = true;

    if (!isset($employees[$eid])) {
        $employees[$eid] = ['empid' => $eid, 'empname' => $ename, 'days' => []];
    }

    // Convert HH:MM:SS to decimal hours
    $parts = explode(':', $hours_raw);
    $decimal = round(intval($parts[0]) + intval($parts[1] ?? 0) / 60, 2);

    $employees[$eid]['days'][$date] = [
        'hours'   => $decimal,
        'display' => number_format($decimal, 2),
        'status'  => $status,
    ];
}

$result->free();
while ($stmt->more_results()) { $stmt->next_result(); if ($r = $stmt->get_result()) $r->free(); }
$stmt->close();
$conn->close();

// Sort dates
$dates = array_keys($dates_set);
sort($dates);

$output = array_values($employees);

if (empty($output)) {
    echo json_encode(['status' => 'no_match', 'message' => 'No records found.', 'dates' => [], 'employees' => []]);
} else {
    echo json_encode(['status' => 'success', 'dates' => $dates, 'employees' => $output]);
}
?>