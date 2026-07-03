<?php
include 'apiMain.php';

date_default_timezone_set('Asia/Kolkata');

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);

// -- Cache config --------------------------------------------------------------
define('CACHE_DIR', '/tmp/pms_insights_cache/');
define('CACHE_TTL', 900); // 15 minutes
define('CACHE_VERSION', 'gap_backend_v2');

$debug     = isset($_GET['debug'])      && $_GET['debug']      === '1';
$bustCache = isset($_GET['bust_cache']) && $_GET['bust_cache'] === '1';

set_error_handler(function($severity, $message, $file, $line) use ($debug) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $message, 'file' => $debug ? $file : null, 'line' => $debug ? $line : null]);
    exit;
});
set_exception_handler(function($e) use ($debug) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage(), 'file' => $debug ? $e->getFile() : null, 'line' => $debug ? $e->getLine() : null]);
    exit;
});
register_shutdown_function(function() use ($debug) {
    $error = error_get_last();
    if ($error) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => $error['message'] ?? 'Fatal error',
            'file'    => $debug ? ($error['file'] ?? null) : null,
            'line'    => $debug ? ($error['line'] ?? null) : null,
        ]);
    }
});

$REQUEST_DATA = [];
$raw     = file_get_contents('php://input');
$decoded = json_decode($raw, true);
if (is_array($decoded)) $REQUEST_DATA = $decoded;

function getParam($key, $default = null) {
    global $REQUEST_DATA;
    return $_GET[$key] ?? $_POST[$key] ?? $REQUEST_DATA[$key] ?? $default;
}

$startDate  = getParam('startDate', null);
$endDate    = getParam('endDate',   null);
$reportType = getParam('reportType', 'MONTHLY_EXPORT');
if (!$startDate || !$endDate) {
    $startDate = $endDate = date('Y-m-d');
}

$isCacheable = (
    $startDate === $endDate &&
    in_array(getParam('department', 'ALL'), ['ALL', '', null], true) &&
    in_array(getParam('role',       'ALL'), ['ALL', '', null], true) &&
    in_array(getParam('team',       'ALL'), ['ALL', '', null], true) &&
    in_array(getParam('project',    'ALL'), ['ALL', '', null], true) &&
    in_array(getParam('ids',        'ALL'), ['ALL', '', null], true)
);
$cacheFile = CACHE_DIR . CACHE_VERSION . '_insights_' . $endDate . '_' . getParam('userid', 'ALL') . '.json';

// -- 1. Serve from cache -------------------------------------------------------
if ($isCacheable && !$bustCache && file_exists($cacheFile)) {
    $age = time() - filemtime($cacheFile);
    if ($age < CACHE_TTL) {
        $data = json_decode(file_get_contents($cacheFile), true);
        if (is_array($data)) {
            $data['_cache'] = ['hit' => true, 'age_seconds' => $age, 'generated_at' => date('c', filemtime($cacheFile))];
            header('X-Cache: HIT');
            header('X-Cache-Age: ' . $age . 's');
            echo json_encode($data);
            $conn->close();
            exit;
        }
    }
}

// -- 2. Cache miss � compute live ----------------------------------------------
header('X-Cache: MISS');
set_time_limit(120);
ini_set('memory_limit', '256M');

function listToCsv($conn, $value) {
    if ($value === null) return 'ALL';
    if (is_string($value)) {
        $value = trim($value);
        if ($value === '' || strtoupper($value) === 'ALL') return 'ALL';
        $parts = explode(',', $value);
    } elseif (is_array($value)) {
        $parts = $value;
    } else {
        return 'ALL';
    }
    $clean = [];
    foreach ($parts as $item) {
        $item = trim((string)$item);
        if ($item !== '' && strtoupper($item) !== 'ALL')
            $clean[] = $conn->real_escape_string($item);
    }
    return empty($clean) ? 'ALL' : implode(',', $clean);
}

function timeToSeconds($t) {
    if (!$t) return 0;
    $p = explode(':', $t);
    return count($p) === 3 ? (int)$p[0] * 3600 + (int)$p[1] * 60 + (int)$p[2] : 0;
}

function safePercent($num, $den) {
    return $den <= 0 ? 0 : round(($num / $den) * 100, 1);
}

function normalizeEmpId($empId) {
    return trim((string)$empId);
}

function getActivityLoggedEmpIds($rows) {
    $ids = [];
    foreach ($rows as $row) {
        $empId = normalizeEmpId($row['EmpID'] ?? $row['EMPID'] ?? '');
        if ($empId === '') continue;
        $loggedSeconds = timeToSeconds($row['TotalLoggedHours'] ?? '00:00:00');
        if ($loggedSeconds > 0) $ids[$empId] = true;
    }
    return array_keys($ids);
}

function getStatusLoggedEmpIds($rows) {
    $ids = [];
    $notLoggedStatuses = ['OFFLINE', 'NOT_LOGGED', 'NOT LOGGED', 'LOGGED_OUT', 'LOGGED OUT'];
    foreach ($rows as $row) {
        $empId = normalizeEmpId($row['EMPID'] ?? $row['EmpID'] ?? '');
        if ($empId === '') continue;
        $status = strtoupper(trim($row['CURRENT_STATUS'] ?? ''));
        if ($status !== '' && !in_array($status, $notLoggedStatuses, true)) {
            $ids[$empId] = true;
        }
    }
    return array_keys($ids);
}

function getMissingEmpIds($yesterdayIds, $todayIds) {
    $todaySet = [];
    foreach ($todayIds as $empId) {
        $empId = normalizeEmpId($empId);
        if ($empId !== '') $todaySet[$empId] = true;
    }

    $missing = [];
    foreach ($yesterdayIds as $empId) {
        $empId = normalizeEmpId($empId);
        if ($empId !== '' && !isset($todaySet[$empId])) {
            $missing[$empId] = true;
        }
    }
    return array_keys($missing);
}

function formatHourLabel($hour) {
    $h = (int)$hour % 12 ?: 12;
    return $h . ' ' . ((int)$hour >= 12 ? 'PM' : 'AM');
}

function fetchActivityFlat($conn, $startDate, $endDate, $filters) {
    $rows = [];
    $stmt = $conn->prepare("CALL PR_EMPLOYEE_ACTIVITY_FLAT(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) return $rows;
    $ids          = listToCsv($conn, $filters['ids']          ?? 'ALL');
    $names        = listToCsv($conn, $filters['names']        ?? 'ALL');
    $departments  = listToCsv($conn, $filters['department']   ?? 'ALL');
    $roles        = listToCsv($conn, $filters['role']         ?? 'ALL');
    $designations = listToCsv($conn, $filters['designations'] ?? 'ALL');
    $projects     = listToCsv($conn, $filters['project']      ?? 'ALL');
    $shifts       = listToCsv($conn, $filters['shift']        ?? 'ALL');
    $teams        = listToCsv($conn, $filters['team']         ?? 'ALL');
    $userid       = $filters['userid']     ?? 'ALL';
    $reportType   = $filters['reportType'] ?? 'MONTHLY_EXPORT';
    $stmt->bind_param(
        'ssssssssssss',
        $startDate, $endDate,
        $ids, $names, $departments, $roles, $designations,
        $projects, $shifts, $teams, $userid, $reportType
    );
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        if ($result) {
            while ($row = $result->fetch_assoc()) $rows[] = $row;
            $result->free();
        }
    }
    $stmt->close();
    while ($conn->more_results() && $conn->next_result()) {
        $extra = $conn->use_result();
        if ($extra instanceof mysqli_result) $extra->free();
    }
    return $rows;
}

// -- EMP_STATUS(userid) --------------------------------------------------------
// Pass current login userid � proc handles scope internally:
//   ADMIN      ? returns all employees
//   LEADERSHIP ? returns their reportees
//   EXECUTIVE  ? returns just themselves
// Returns columns: DATE, SHIFT, EMPID, EMPNAME, TEAM, DEPARTMENT, LAST_SEEN, CURRENT_STATUS
// CURRENT_STATUS = 'ACTIVE' means the employee is active right now
function fetchEmpStatus($conn, $userid) {
    $rows    = [];
    $escaped = $conn->real_escape_string($userid);
    $result  = $conn->query("CALL EMP_STATUS('{$escaped}')");
    if ($result) {
        while ($row = $result->fetch_assoc()) $rows[] = $row;
        $result->free();
    }
    while ($conn->more_results() && $conn->next_result()) {
        $extra = $conn->use_result();
        if ($extra instanceof mysqli_result) $extra->free();
    }
    return $rows;
}

function fetchTimelineAggregates($conn, $date, $filters) {
    $activeByHour = array_fill(0, 24, 0);
    $idleByHour   = array_fill(0, 24, 0);
    $stmt = $conn->prepare("CALL PR_USER_TIMELINE(?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) return [$activeByHour, $idleByHour];
    $empid      = listToCsv($conn, $filters['ids']        ?? 'ALL');
    $empname    = listToCsv($conn, $filters['names']      ?? 'ALL');
    $department = listToCsv($conn, $filters['department'] ?? 'ALL');
    $role       = listToCsv($conn, $filters['role']       ?? 'ALL');
    $team       = listToCsv($conn, $filters['team']       ?? 'ALL');
    $project    = listToCsv($conn, $filters['project']    ?? 'ALL');
    $userid     = $filters['userid'] ?? 'ALL';
    $stmt->bind_param('sssssssss', $date, $date, $empid, $empname, $department, $role, $team, $project, $userid);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $status = strtoupper(trim($row['ACTIVE_INACTIVE'] ?? ''));
                $from   = isset($row['FROM_TIME_UTC']) ? strtotime($row['FROM_TIME_UTC']) : null;
                $to     = isset($row['TO_TIME_UTC'])   ? strtotime($row['TO_TIME_UTC'])   : null;
                if (!$from || !$to || $to <= $from) continue;
                $cursor = $from;
                while ($cursor < $to) {
                    $hour     = (int)date('G', $cursor);
                    $nextHour = strtotime(date('Y-m-d H:00:00', $cursor) . ' +1 hour');
                    $segEnd   = min($to, $nextHour);
                    $minutes  = ($segEnd - $cursor) / 60;
                    if ($status === 'ACTIVE') $activeByHour[$hour] += $minutes;
                    else                      $idleByHour[$hour]   += $minutes;
                    $cursor = $segEnd;
                }
            }
            $result->free();
        }
    }
    $stmt->close();
    while ($conn->more_results() && $conn->next_result()) {
        $extra = $conn->use_result();
        if ($extra instanceof mysqli_result) $extra->free();
    }
    return [$activeByHour, $idleByHour];
}

try {
    $userid = getParam('userid', 'ALL');

    $filters = [
        'department'   => getParam('department',   'ALL'),
        'role'         => getParam('role',         'ALL'),
        'project'      => getParam('project',      'ALL'),
        'shift'        => getParam('shift',        'ALL'),
        'team'         => getParam('team',         'ALL'),
        'ids'          => getParam('ids',          'ALL'),
        'names'        => getParam('names',        'ALL'),
        'designations' => getParam('designations', 'ALL'),
        'userid'       => $userid,
        'reportType'   => $reportType,
    ];

    $periodEnd  = $endDate;
    $yesterday  = date('Y-m-d', strtotime($periodEnd . ' -1 day'));
    $dayBefore  = date('Y-m-d', strtotime($periodEnd . ' -2 day'));
    $rangeStart = strtotime($dayBefore) < strtotime($startDate) ? $dayBefore : $startDate;

    // -- Activity data (scoped to userid � for ops/hr insights) ---------------
    $activityRange = fetchActivityFlat($conn, $rangeStart, $endDate, $filters);

    $activityByDate = [];
    $deptStats      = [];
    $deptIdleLogged = [];
    $monthly        = [];

    foreach ($activityRange as $row) {
        $rowDate = $row['Date'] ?? $row['DATE'] ?? null;
        $dept    = $row['Department'] ?? $row['DEPARTMENT'] ?? 'Unknown';
        $prod    = timeToSeconds($row['TotalProductiveHours'] ?? '00:00:00');
        $idle    = timeToSeconds($row['TotalIdleHours']       ?? '00:00:00')
                 + timeToSeconds($row['AwayFromSystem']       ?? '00:00:00');
        $logged  = timeToSeconds($row['TotalLoggedHours']     ?? '00:00:00');

        if ($rowDate) $activityByDate[$rowDate][] = $row;

        if ($rowDate && $rowDate >= $startDate && $rowDate <= $endDate) {
            if (!isset($deptStats[$dept]))      $deptStats[$dept]      = ['productive' => 0, 'count' => 0];
            if (!isset($deptIdleLogged[$dept])) $deptIdleLogged[$dept] = ['idle' => 0, 'logged' => 0];
            $deptStats[$dept]['productive']        += $prod;
            $deptStats[$dept]['count']             += 1;
            $deptIdleLogged[$dept]['idle']          += $idle;
            $deptIdleLogged[$dept]['logged']        += $logged;
            $monthKey = substr($rowDate, 0, 7);
            if (!isset($monthly[$monthKey])) $monthly[$monthKey] = ['productive' => 0, 'count' => 0];
            $monthly[$monthKey]['productive'] += $prod;
            $monthly[$monthKey]['count']      += 1;
        }
    }

    $activityToday     = $activityByDate[$periodEnd] ?? [];
    $activityYesterday = $activityByDate[$yesterday] ?? [];
    $activityDayBefore = $activityByDate[$dayBefore] ?? [];

    // -- Scoped user stats (for ops/hr sections) -------------------------------
    $users = []; $teamStats = [];
    foreach ($activityToday as $row) {
        $emp  = $row['EmpID'] ?? $row['EMPID'] ?? null;
        $team = $row['Team']  ?? $row['TEAM']  ?? 'Unassigned';
        if (!$emp) continue;
        $prod   = timeToSeconds($row['TotalProductiveHours'] ?? '00:00:00');
        $idle   = timeToSeconds($row['TotalIdleHours']       ?? '00:00:00')
                + timeToSeconds($row['AwayFromSystem']       ?? '00:00:00');
        $logged = timeToSeconds($row['TotalLoggedHours']     ?? '00:00:00');
        if (!isset($users[$emp])) $users[$emp] = ['productive' => 0, 'idle' => 0, 'logged' => 0, 'name' => $row['EmpName'] ?? $row['EMPNAME'] ?? ''];
        $users[$emp]['productive'] += $prod;
        $users[$emp]['idle']       += $idle;
        $users[$emp]['logged']     += $logged;
        if (!isset($teamStats[$team])) $teamStats[$team] = ['idle' => 0, 'logged' => 0];
        $teamStats[$team]['idle']   += $idle;
        $teamStats[$team]['logged'] += $logged;
    }

    $totalUsers = count($users);
    $activeUsers = $idleUsers = $totalProductiveSeconds = $totalIdleSeconds = 0;
    foreach ($users as $u) {
        $totalProductiveSeconds += $u['productive'];
        $totalIdleSeconds       += $u['idle'];
        if      ($u['productive'] > 0) $activeUsers++;
        elseif  ($u['idle']       > 0) $idleUsers++;
    }
    $totalActivitySeconds = $totalProductiveSeconds + $totalIdleSeconds;
    $idlePercent   = safePercent($totalIdleSeconds, max($totalActivitySeconds, 1));
    $activePercent = $totalActivitySeconds > 0 ? round(100 - $idlePercent, 1) : 0;

    // -- GAP: EMP_STATUS(userid) � use CURRENT_STATUS column ------------------
    // Proc returns: DATE, SHIFT, EMPID, EMPNAME, TEAM, DEPARTMENT, LAST_SEEN, CURRENT_STATUS
    // CURRENT_STATUS = 'ACTIVE'  ? employee is currently active
    // CURRENT_STATUS = anything else (IDLE, OFFLINE, etc.) ? not active right now
    $empStatusRows     = fetchEmpStatus($conn, $userid);
    $activeSystemUsers = 0;  // count of CURRENT_STATUS = 'ACTIVE'
    $empStatusEmpIds   = []; // all empids returned by proc

    foreach ($empStatusRows as $row) {
        $emp    = $row['EMPID']          ?? $row['EmpID']   ?? null;
        $status = strtoupper(trim($row['CURRENT_STATUS']    ?? ''));
        if (!$emp) continue;
        $empStatusEmpIds[$emp] = true;
        // Count as active only if CURRENT_STATUS is exactly 'ACTIVE'
        if ($status === 'ACTIVE') {
            $activeSystemUsers++;
        }
    }

    // Total employees in this login's scope (all rows proc returned)
    $totalEmpScope = count($empStatusEmpIds);

    $todayActivityLoggedEmpIds     = getActivityLoggedEmpIds($activityToday);
    $yesterdayActivityLoggedEmpIds = getActivityLoggedEmpIds($activityYesterday);
    $statusLoggedEmpIds            = getStatusLoggedEmpIds($empStatusRows);
    $todayLoggedEmpIds             = $periodEnd === date('Y-m-d') ? $statusLoggedEmpIds : $todayActivityLoggedEmpIds;
    $missingLoggedEmpIds           = getMissingEmpIds($yesterdayActivityLoggedEmpIds, $todayLoggedEmpIds);
    $pmsUsersToday                 = count($todayLoggedEmpIds);
    $pmsUsersYesterday             = count($yesterdayActivityLoggedEmpIds);
    $hrmsInactive                  = count($missingLoggedEmpIds);

    // -- Timeline aggregates ---------------------------------------------------
    [$activeByHour, $idleByHour] = fetchTimelineAggregates($conn, $periodEnd, $filters);

    $startHour = 8; $endHour = 17;
    $bestActive = $bestIdle = -1;
    $peakWindow = ['start' => 10, 'end' => 13, 'peak' => 0];
    $idleWindow = ['start' => 15, 'end' => 17, 'peak' => 0];
    for ($h = $startHour; $h <= $endHour - 2; $h++) {
        $sumA = $activeByHour[$h] + $activeByHour[$h+1] + $activeByHour[$h+2];
        $sumI = $idleByHour[$h]   + $idleByHour[$h+1]   + $idleByHour[$h+2];
        if ($sumA > $bestActive) { $bestActive = $sumA; $peakWindow = ['start' => $h, 'end' => $h+3, 'peak' => $sumA]; }
        if ($sumI > $bestIdle)   { $bestIdle   = $sumI; $idleWindow = ['start' => $h, 'end' => $h+3, 'peak' => $sumI]; }
    }

    $hourLabels = $activeSeries = $idleSeries = [];
    for ($h = $startHour; $h <= $endHour; $h++) {
        $hourLabels[]   = formatHourLabel($h);
        $activeSeries[] = round($activeByHour[$h], 1);
        $idleSeries[]   = round($idleByHour[$h],   1);
    }

    // -- Workload --------------------------------------------------------------
    $productiveByUser = [];
    foreach ($users as $empId => $u) {
        $productiveByUser[] = ['empId' => $empId, 'name' => $u['name'], 'productive' => $u['productive']];
    }
    usort($productiveByUser, fn($a, $b) => $b['productive'] <=> $a['productive']);
    $totalProductive = array_sum(array_column($productiveByUser, 'productive'));
    $topTwoShare = safePercent(
        array_sum(array_column(array_slice($productiveByUser, 0, 2), 'productive')),
        max($totalProductive, 1)
    );

    $underTeam = ['team' => 'N/A', 'idlePercent' => 0, 'utilization' => 0];
    foreach ($teamStats as $team => $stat) {
        $idlePct = safePercent($stat['idle'], max($stat['logged'], 1));
        if ($idlePct > $underTeam['idlePercent']) {
            $underTeam = ['team' => $team, 'idlePercent' => $idlePct, 'utilization' => round(100 - $idlePct, 1)];
        }
    }

    $prodToday   = array_sum(array_map(fn($r) => timeToSeconds($r['TotalProductiveHours'] ?? '00:00:00'), $activityToday));
    $prodYest    = array_sum(array_map(fn($r) => timeToSeconds($r['TotalProductiveHours'] ?? '00:00:00'), $activityYesterday));
    $prodDB      = array_sum(array_map(fn($r) => timeToSeconds($r['TotalProductiveHours'] ?? '00:00:00'), $activityDayBefore));
    $prodChange  = $prodYest > 0 ? round((($prodToday - $prodYest) / $prodYest) * 100, 1) : 0;
    $dropPercent = $prodDB   > 0 ? round((($prodYest  - $prodDB)   / $prodDB)   * 100, 1) : 0;

    // -- HR: engagement trend --------------------------------------------------
    ksort($monthly);
    $engagementTrend = [];
    foreach ($monthly as $mk => $v) {
        $engagementTrend[] = [
            'month' => date('M', strtotime($mk . '-01')),
            'value' => round(($v['count'] > 0 ? $v['productive'] / $v['count'] : 0) / 3600, 2),
        ];
    }
    $engagementTrend = array_slice($engagementTrend, -6);

    // -- HR: department comparison ---------------------------------------------
    $deptAverages = []; $companyTotal = $companyCount = 0;
    foreach ($deptStats as $dept => $v) {
        $deptAverages[] = ['dept' => $dept, 'avg' => $v['count'] > 0 ? $v['productive'] / $v['count'] : 0];
        $companyTotal  += $v['productive'];
        $companyCount  += $v['count'];
    }
    usort($deptAverages, fn($a, $b) => $b['avg'] <=> $a['avg']);
    $companyAvg     = $companyCount > 0 ? round(($companyTotal / $companyCount) / 3600, 2) : 0;
    $deptComparison = array_map(fn($d) => ['dept' => $d['dept'], 'value' => round($d['avg'] / 3600, 2)], $deptAverages);

    // -- HR: leave correlation -------------------------------------------------
    $leaveCorrelation = [];
    $leaveStmt = $conn->prepare("SELECT LT.EMP_ID, E.DEPARTMENT FROM LEAVE_TRACKER LT LEFT JOIN EMP_DB E ON LT.EMP_ID = E.EMPID WHERE LT.STATUS = 'APPROVED' AND LT.FROM_DATE <= ? AND LT.TO_DATE >= ?");
    if ($leaveStmt) {
        $leaveStmt->bind_param('ss', $endDate, $startDate);
        if ($leaveStmt->execute()) {
            $result      = $leaveStmt->get_result();
            $leaveCounts = [];
            while ($row = $result->fetch_assoc()) {
                $dept = $row['DEPARTMENT'] ?? 'Unknown';
                $leaveCounts[$dept] = ($leaveCounts[$dept] ?? 0) + 1;
            }
            $result->free();
            foreach ($deptStats as $dept => $v) {
                $il = $deptIdleLogged[$dept] ?? ['idle' => 0, 'logged' => 0];
                $leaveCorrelation[] = [
                    'dept'  => $dept,
                    'idle'  => safePercent($il['idle'], max($il['logged'], 1)),
                    'leave' => $leaveCounts[$dept] ?? 0,
                ];
            }
            usort($leaveCorrelation, fn($a, $b) => $b['leave'] <=> $a['leave']);
        }
        $leaveStmt->close();
    }

    // -- HRMS roster (scoped by EMP_STATUS(userid)) ----------------------------
    $hrmsUsers  = $roleExempt = $noAccess = 0;
    $hrmsEmpIds = [];
    $empRes = $conn->query("SELECT EMPID, ROLE, SYS_USER_NAME, ACTIVE_YN FROM EMP_DB");
    if ($empRes) {
        while ($row = $empRes->fetch_assoc()) {
            $empId = normalizeEmpId($row['EMPID'] ?? '');
            if ($empId === '') continue;

            if (!empty($empStatusEmpIds) && !isset($empStatusEmpIds[$empId])) {
                continue;
            }

            $hrmsUsers++;
            $hrmsEmpIds[$empId] = true;
            $role = strtoupper(trim($row['ROLE'] ?? ''));
            if ($role === 'ADMIN' || $role === 'LEADERSHIP') $roleExempt++;
            if (strtoupper(trim($row['ACTIVE_YN'] ?? 'Y')) === 'Y' && trim($row['SYS_USER_NAME'] ?? '') === '') $noAccess++;
        }
        $empRes->free();
    }

    // -- On leave count --------------------------------------------------------
    $onLeave        = 0;
    $leaveCountStmt = $conn->prepare("SELECT COUNT(DISTINCT EMP_ID) AS cnt FROM LEAVE_TRACKER WHERE STATUS = 'APPROVED' AND FROM_DATE <= ? AND TO_DATE >= ?");
    if ($leaveCountStmt) {
        $leaveCountStmt->bind_param('ss', $periodEnd, $periodEnd);
        if ($leaveCountStmt->execute()) {
            $res = $leaveCountStmt->get_result();
            if ($res && ($row = $res->fetch_assoc())) $onLeave = (int)$row['cnt'];
            if ($res) $res->free();
        }
        $leaveCountStmt->close();
    }

    // -- Vendor/shadow: in EMP_STATUS scope but not in HRMS EMP_DB ------------
    $vendorShadow = 0;
    foreach ($empStatusEmpIds as $empId => $_) {
        if (!isset($hrmsEmpIds[$empId])) $vendorShadow++;
    }

    $visibilityRate = $hrmsUsers > 0 ? round(($pmsUsersToday / $hrmsUsers) * 100, 1) : 0;

    // -- Team adoption ---------------------------------------------------------
    $teamCounts = [];
    foreach ($activityToday as $row) {
        $team = $row['Team'] ?? $row['TEAM'] ?? 'Unassigned';
        $emp  = $row['EmpID'] ?? $row['EMPID'] ?? null;
        if (!$emp) continue;
        if (!isset($teamCounts[$team])) $teamCounts[$team] = ['active' => [], 'total' => 0];
        $teamCounts[$team]['active'][$emp] = true;
    }
    $empTotals = $conn->query("SELECT EMPID, TEAM FROM EMP_DB");
    if ($empTotals) {
        while ($row = $empTotals->fetch_assoc()) {
            $empId = normalizeEmpId($row['EMPID'] ?? '');
            if ($empId === '') continue;
            if (!empty($empStatusEmpIds) && !isset($empStatusEmpIds[$empId])) {
                continue;
            }

            $team = $row['TEAM'] ?? 'Unassigned';
            if (!isset($teamCounts[$team])) $teamCounts[$team] = ['active' => [], 'total' => 0];
            $teamCounts[$team]['total'] += 1;
        }
        $empTotals->free();
    }
    $teamAdoption = [];
    foreach ($teamCounts as $team => $v) {
        $teamAdoption[] = ['team' => $team, 'adoption' => safePercent(count($v['active']), max($v['total'], 1))];
    }
    usort($teamAdoption, fn($a, $b) => $a['adoption'] <=> $b['adoption']);

    $lowestTeam     = $teamAdoption[0]['team']     ?? 'N/A';
    $lowestAdoption = $teamAdoption[0]['adoption'] ?? 0;

    // -- Build response --------------------------------------------------------
    $response = [
        'success'     => true,
        'generatedAt' => date('c'),
        'period'      => ['start' => $startDate, 'end' => $endDate],
        'summary' => [
            'activePercent' => $activePercent,
            'idlePercent'   => $idlePercent,
            'activeUsers'   => $activeUsers,
            'idleUsers'     => $idleUsers,
            'totalUsers'    => $totalUsers,
            'productiveSeconds' => $totalProductiveSeconds,
            'idleSeconds'       => $totalIdleSeconds,
            'activitySeconds'   => $totalActivitySeconds,
            'peakWindow'    => [
                'start'     => formatHourLabel($peakWindow['start']),
                'end'       => formatHourLabel($peakWindow['end']),
                'peakValue' => round($bestActive, 1),
            ],
            'idleWindow' => [
                'start'     => formatHourLabel($idleWindow['start']),
                'end'       => formatHourLabel($idleWindow['end']),
                'peakValue' => round($bestIdle, 1),
            ],
            'hourLabels'   => $hourLabels,
            'activeSeries' => $activeSeries,
            'idleSeries'   => $idleSeries,
        ],
        'ops' => [
            'workload' => [
                'topUsers'    => array_map(fn($u) => [
                    'name'  => $u['name'] ?: $u['empId'],
                    'value' => round($u['productive'] / 60, 1),
                    'share' => safePercent($u['productive'], max($totalProductive, 1)),
                ], $productiveByUser),
                'topTwoShare' => $topTwoShare,
            ],
            'underutilized' => $underTeam,
            'slaRisk'       => ['changePercent' => $prodChange],
        ],
        'hr' => [
            'engagementTrend'      => $engagementTrend,
            'departmentComparison' => ['companyAvg' => $companyAvg, 'departments' => $deptComparison],
            'leaveCorrelation'     => $leaveCorrelation,
        ],
        // GAP: sourced entirely from EMP_STATUS(userid) + EMP_DB � no hardcoding
        'gap' => [
            'hrmsUsers'         => $hrmsUsers,           // total in EMP_DB
            'activeSystemUsers' => $activeSystemUsers,   // CURRENT_STATUS = 'ACTIVE' count
            'pmsUsersToday'     => $pmsUsersToday,
            'pmsUsersYesterday' => $pmsUsersYesterday,
            'visibilityRate'    => $visibilityRate,
            'totalEmpScope'     => $totalEmpScope,       // total rows EMP_STATUS returned
            'onLeave'           => $onLeave,
            'roleExempt'        => $roleExempt,
            'noAccess'          => $noAccess,
            'vendorShadow'      => $vendorShadow,
            'hrmsInactive'      => $hrmsInactive,
            'usageGapCount'     => $hrmsInactive,
            'missingLoggedEmpIds' => $missingLoggedEmpIds,
            'gapLabel'          => 'Users active yesterday but not logged in today',
        ],
        // Fields for alerts cache
        '_prodTotal'    => $totalProductiveSeconds,
        '_idleTotal'    => $totalIdleSeconds,
        '_activeEmpIds' => array_keys($users),

        'queries' => [
            [
                'query'  => 'Why did measured productive time drop yesterday?',
                'answer' => $dropPercent < 0
                    ? 'Measured productive time dropped ' . abs($dropPercent) . '% vs the day before, driven by reduced active system time.'
                    : 'Measured productive time improved ' . $dropPercent . '% vs the day before.',
                'trend'  => [round($prodDB / 3600, 2), round($prodYest / 3600, 2), round($prodToday / 3600, 2)],
            ],
            [
                'query'  => 'Which team has the lowest adoption?',
                'answer' => 'Lowest adoption is ' . $lowestTeam . ' at ' . $lowestAdoption . '%.',
                'trend'  => array_slice(array_column($teamAdoption, 'adoption'), 0, 5),
            ],
            [
                'query'  => 'How many users were active yesterday but not logged in today?',
                'answer' => $hrmsInactive . ' users were active yesterday but are not logged in today.',
                'trend'  => [$pmsUsersYesterday, $pmsUsersToday, $hrmsInactive],
            ],
        ],
    ];

    // -- 3. Write cache --------------------------------------------------------
    if ($isCacheable) {
        if (!is_dir(CACHE_DIR)) mkdir(CACHE_DIR, 0755, true);
        file_put_contents($cacheFile, json_encode($response), LOCK_EX);
    }

    $response['_cache'] = ['hit' => false, 'generated_at' => date('c')];
    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'file'    => $debug ? $e->getFile() : null,
        'line'    => $debug ? $e->getLine() : null,
    ]);
}

$conn->close();
?>
