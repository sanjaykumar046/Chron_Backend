<?php
// fetch_attendance_calendar.php
include 'apiMain.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

/* --- DB credentials (from main.php) --- */
// $servername = "chron-db.cd6wkwiowv2u.ap-southeast-2.rds.amazonaws.com";
// $db_user    = "admin";
// $db_pass    = "wfxicVdxG71bjvdVhFN3";
// $dbname     = "prod_ent1_tenant_0_demo";

/* --- Read & validate params --- */
$from_date   = isset($_GET['from_date'])    ? trim($_GET['from_date'])    : '';
$to_date     = isset($_GET['to_date'])      ? trim($_GET['to_date'])      : '';
$target_date = isset($_GET['target_date'])  ? trim($_GET['target_date'])  : '';  // hardcoded yesterday from JSX
$empid       = isset($_GET['empid'])        ? trim($_GET['empid'])        : 'ALL';
$user_id     = isset($_GET['user_id'])      ? trim($_GET['user_id'])      : '';

/* use target_date (yesterday) as the single date passed to the procedure */
$proc_date = !empty($target_date) ? $target_date : date('Y-m-d', strtotime('-1 day'));

/* fallback: if from/to not provided, use whole month of target_date */
if (empty($from_date)) {
    $from_date = date('Y-m-01', strtotime($proc_date));
}
if (empty($to_date)) {
    $to_date = date('Y-m-t', strtotime($proc_date));
}

/* basic date format validation */
$dateRx = '/^\d{4}-\d{2}-\d{2}$/';
if (!preg_match($dateRx, $from_date) || !preg_match($dateRx, $to_date)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid date format. Use YYYY-MM-DD.']);
    $conn->close();
    exit();
}

/* --- Sanitise empid --- */
$empid_param = (empty($empid) || strtoupper($empid) === 'ALL') ? 'ALL' : $conn->real_escape_string($empid);

/* --- Procedure call -------------------------------------------------------
   Signature (image 2):
   CALL PR_GET_ATTENDANCE_REPORT(
       P_FRM_DT    DATE,
       P_TO_DT     DATE,
       P_EMPID     VARCHAR(255),
       P_EMPNAMES  VARCHAR(255),
       P_DEPT      VARCHAR(255),
       P_PROJECT   VARCHAR(255),
       P_TEAMS     VARCHAR(255),
       P_USER_ID   VARCHAR(255)
   )
   As specified: CALL PR_GET_ATTENDANCE_REPORT(?, ?, '?', 'ALL', 'ALL', 'ALL', 'ALL', ?)
   We pass:
       P_FRM_DT   = from_date  (month start)
       P_TO_DT    = to_date    (month end)
       P_EMPID    = empid_param
       P_EMPNAMES = 'ALL'
       P_DEPT     = 'ALL'
       P_PROJECT  = 'ALL'
       P_TEAMS    = 'ALL'
       P_USER_ID  = user_id
--------------------------------------------------------------------------- */

$stmt = $conn->prepare(
    "CALL PR_GET_ATTENDANCE_REPORT(?, ?, ?, 'ALL', 'ALL', 'ALL', 'ALL', ?)"
);

if (!$stmt) {
    echo json_encode([
        'status'  => 'error',
        'message' => 'Failed to prepare statement: ' . $conn->error,
    ]);
    $conn->close();
    exit();
}

$stmt->bind_param("ssss", $from_date, $to_date, $empid_param, $user_id);

if (!$stmt->execute()) {
    echo json_encode([
        'status'  => 'error',
        'message' => 'Procedure execution failed: ' . $stmt->error,
    ]);
    $stmt->close();
    $conn->close();
    exit();
}

$result = $stmt->get_result();

if (!$result) {
    /* some stored procedures use multi-result; try next result set */
    $stmt->next_result();
    $result = $stmt->get_result();
}

if (!$result) {
    echo json_encode([
        'status'  => 'error',
        'message' => 'No result set returned from procedure.',
    ]);
    $stmt->close();
    $conn->close();
    exit();
}

/* --- Build keyed response: { "YYYY-MM-DD": { status, shift_time, total_hours } } ---
   Exact columns from PR_GET_ATTENDANCE_REPORT result:
     ATTENDANCE_ID, WORK_DATE, EMPID, EMPNAME,
     SHIFT_NAME, EMP_SHIFT, ATTENDANCE_STATUS,
     TOTAL_LOGGED_HOURS, REGULARISATION_ATTEMPTS,
     UPDATE_TYPE, CREATED_BY, UPDATED_BY
--------------------------------------------------------------------------- */
$calendarData = [];
$rowCount     = 0;

while ($row = $result->fetch_assoc()) {
    $rowCount++;

    // WORK_DATE  ? calendar key
    $date = $row['WORK_DATE'] ?? null;

    // ATTENDANCE_STATUS e.g. "ABSENT", "WEEK OFF", "HALF DAY", "PRESENT"
    $attendance_status = isset($row['ATTENDANCE_STATUS']) ? strtoupper(trim($row['ATTENDANCE_STATUS'])) : '';

    // EMP_SHIFT  e.g. "11:00 - 23:30"  (displayed as shift time in cell)
    $shift_time = $row['EMP_SHIFT'] ?? $row['SHIFT_NAME'] ?? null;

    // TOTAL_LOGGED_HOURS � can be NULL for ABSENT / WEEK OFF days
    $total_hours_raw = $row['TOTAL_LOGGED_HOURS'] ?? null;

    // If TOTAL_LOGGED_HOURS is NULL or empty ? display status label only (00:00 shown in JSX)
    $total_hours = (!is_null($total_hours_raw) && $total_hours_raw !== '')
        ? $total_hours_raw
        : null;   // null signals JSX to skip hours and show status badge only

    if ($date) {
        $dateKey = date('Y-m-d', strtotime($date));
      $calendarData[$dateKey] = [
    'attendance_id'           => $row['ATTENDANCE_ID']           ?? null,  // ? ADD THIS
    'status'                  => $attendance_status,
    'shift_time'              => $shift_time,
    'total_hours'             => $total_hours,
    'shift_name'              => $row['SHIFT_NAME']              ?? null,
    'emp_shift'               => $row['EMP_SHIFT']               ?? null,  // ? ADD THIS TOO
    'empname'                 => $row['EMPNAME']                 ?? null,
    'regularisation_attempts' => $row['REGULARISATION_ATTEMPTS'] ?? 0,
    'update_type'             => $row['UPDATE_TYPE']             ?? null,
    'created_by'              => $row['CREATED_BY']              ?? null,
    'updated_by'              => $row['UPDATED_BY']              ?? null,
    'comments'                => $row['COMMENTS']                ?? null,
];
    }
}

$result->free();

/* close extra result sets (stored procedures often return multiple) */
while ($stmt->more_results()) {
    $stmt->next_result();
    if ($r = $stmt->get_result()) $r->free();
}

$stmt->close();
$conn->close();

/* --- Response --- */
if ($rowCount === 0) {
    echo json_encode([
        'status'  => 'no_match',
        'message' => 'No attendance records found for the selected period.',
        'data'    => (object)[],
    ]);
} else {
    echo json_encode([
        'status'     => 'success',
        'message'    => "Loaded {$rowCount} attendance records.",
        'from_date'  => $from_date,
        'to_date'    => $to_date,
        'empid'      => $empid_param,
        'data'       => $calendarData,
    ]);
}