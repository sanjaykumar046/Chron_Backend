<?php
include 'apiMain.php';

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);

date_default_timezone_set('UTC');

// --- Helpers ------------------------------------------------------------------
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

function formatHourLabel($hour) {
    $h = (int)$hour % 12 ?: 12;
    return $h . ' ' . ((int)$hour >= 12 ? 'PM' : 'AM');
}

function fetchActivityFlat($conn, $startDate, $endDate, $filters) {
    $rows = [];
    $stmt = $conn->prepare("CALL PMS_PRO.PR_EMPLOYEE_ACTIVITY_FLAT(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) return $rows;
    $ids          = listToCsv($conn, $filters['ids']          ?? 'ALL');
    $names        = listToCsv($conn, $filters['names']        ?? 'ALL');
    $departments  = listToCsv($conn, $filters['department']   ?? 'ALL');
    $roles        = listToCsv($conn, $filters['role']         ?? 'ALL');
    $designations = listToCsv($conn, $filters['designations'] ?? 'ALL');
    $projects     = listToCsv($conn, $filters['project']      ?? 'ALL');
    $shifts       = listToCsv($conn, $filters['shift']        ?? 'ALL');
    $teams        = listToCsv($conn, $filters['team']         ?? 'ALL');
    $uid          = $filters['userid']     ?? 'ALL';
    $rtype        = $filters['reportType'] ?? 'MONTHLY_EXPORT';
    $stmt->bind_param('ssssssssssss', $startDate, $endDate, $ids, $names, $departments, $roles, $designations, $projects, $shifts, $teams, $uid, $rtype);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        if ($result) { while ($row = $result->fetch_assoc()) $rows[] = $row; $result->free(); }
    }
    $stmt->close();
    while ($conn->more_results() && $conn->next_result()) { $e = $conn->use_result(); if ($e instanceof mysqli_result) $e->free(); }
    return $rows;
}

function fetchTimelineAggregates($conn, $date, $filters) {
    $activeByHour = array_fill(0, 24, 0);
    $idleByHour   = array_fill(0, 24, 0);
    $stmt = $conn->prepare("CALL PMS_PRO.PR_USER_TIMELINE(?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) return [$activeByHour, $idleByHour];
    $empid  = listToCsv($conn, $filters['ids']        ?? 'ALL');
    $empname= listToCsv($conn, $filters['names']      ?? 'ALL');
    $dept   = listToCsv($conn, $filters['department'] ?? 'ALL');
    $role   = listToCsv($conn, $filters['role']       ?? 'ALL');
    $team   = listToCsv($conn, $filters['team']       ?? 'ALL');
    $proj   = listToCsv($conn, $filters['project']    ?? 'ALL');
    $uid    = $filters['userid'] ?? 'ALL';
    $stmt->bind_param('sssssssss', $date, $date, $empid, $empname, $dept, $role, $team, $proj, $uid);
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
    while ($conn->more_results() && $conn->next_result()) { $e = $conn->use_result(); if ($e instanceof mysqli_result) $e->free(); }
    return [$activeByHour, $idleByHour];
}

function fetchEmpStatus($conn, $userid) {
    $rows    = [];
    $escaped = $conn->real_escape_string($userid);
    $result  = $conn->query("CALL PMS_PRO.EMP_STATUS('{$escaped}')");
    if ($result) { while ($row = $result->fetch_assoc()) $rows[] = $row; $result->free(); }
    while ($conn->more_results() && $conn->next_result()) { $e = $conn->use_result(); if ($e instanceof mysqli_result) $e->free(); }
    return $rows;
}

/**
 * Insert or upsert an alert into ALERTS_RT.
 * scope maps to BRD roles: 'CEO', 'MANAGER', 'TEAM_LEADER', 'EMPLOYEE', 'ALL'
 */
function insertAlert($conn, $key, $level, $title, $message, $meta = [], $scope = 'ALL') {
    $metaJson = $meta ? json_encode($meta) : null;
    $stmt = $conn->prepare(
        "INSERT INTO ALERTS_RT (ALERT_KEY, LEVEL, TITLE, MESSAGE, SCOPE, SOURCE, META, CREATED_AT)
         VALUES (?, ?, ?, ?, ?, 'rules', ?, NOW())
         ON DUPLICATE KEY UPDATE
           LEVEL = VALUES(LEVEL),
           TITLE = VALUES(TITLE),
           MESSAGE = VALUES(MESSAGE),
           META = VALUES(META),
           CREATED_AT = NOW()"
    );
    if (!$stmt) return false;
    $stmt->bind_param('ssssss', $key, $level, $title, $message, $scope, $metaJson);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

// --- Date setup ---------------------------------------------------------------
$today    = date('Y-m-d');
$yest     = date('Y-m-d', strtotime('-1 day'));
$day2     = date('Y-m-d', strtotime('-2 days'));
$day3     = date('Y-m-d', strtotime('-3 days'));
$weekAgo  = date('Y-m-d', strtotime('-7 days'));

$baseFilters = [
    'department'   => 'ALL',
    'role'         => 'ALL',
    'project'      => 'ALL',
    'shift'        => 'ALL',
    'team'         => 'ALL',
    'ids'          => 'ALL',
    'names'        => 'ALL',
    'designations' => 'ALL',
    'userid'       => 'ALL',
    'reportType'   => 'MONTHLY_EXPORT',
];

$inserted = 0;

// --- Fetch activity data -------------------------------------------------------
$activityToday = fetchActivityFlat($conn, $today, $today, $baseFilters);
$activityYest  = fetchActivityFlat($conn, $yest,  $yest,  $baseFilters);
$activityDay2  = fetchActivityFlat($conn, $day2,  $day2,  $baseFilters);
$activityDay3  = fetchActivityFlat($conn, $day3,  $day3,  $baseFilters);
$activityWeek  = fetchActivityFlat($conn, $weekAgo, $today, $baseFilters);

// --- Build per-user stats for today -------------------------------------------
$userStatsToday = [];  // empId => [prod, idle, logged, team, dept, name, keyboard, mouse]
foreach ($activityToday as $row) {
    $emp  = $row['EmpID'] ?? $row['EMPID'] ?? null;
    if (!$emp) continue;
    if (!isset($userStatsToday[$emp])) {
        $userStatsToday[$emp] = [
            'prod' => 0, 'idle' => 0, 'logged' => 0,
            'team' => $row['Team']  ?? $row['TEAM']  ?? 'Unassigned',
            'dept' => $row['Department'] ?? $row['DEPARTMENT'] ?? 'Unknown',
            'name' => $row['EmpName'] ?? $row['EMPNAME'] ?? $emp,
            'keyboard' => (int)($row['KeyboardEvents'] ?? $row['KEYBOARD_EVENTS'] ?? 0),
            'mouse'    => (int)($row['MouseEvents']    ?? $row['MOUSE_EVENTS']    ?? 0),
            'login_time'  => $row['LoginTime']  ?? $row['LOGIN_TIME']  ?? null,
            'shift_start' => $row['ShiftStart'] ?? $row['SHIFT_START'] ?? null,
        ];
    }
    $userStatsToday[$emp]['prod']   += timeToSeconds($row['TotalProductiveHours'] ?? '00:00:00');
    $userStatsToday[$emp]['idle']   += timeToSeconds($row['TotalIdleHours']       ?? '00:00:00')
                                     + timeToSeconds($row['AwayFromSystem']       ?? '00:00:00');
    $userStatsToday[$emp]['logged'] += timeToSeconds($row['TotalLoggedHours']     ?? '00:00:00');
}

// --- Build weekly average productive seconds per user -------------------------
$weeklyProdByUser = [];
foreach ($activityWeek as $row) {
    $emp  = $row['EmpID'] ?? $row['EMPID'] ?? null;
    if (!$emp) continue;
    if (!isset($weeklyProdByUser[$emp])) $weeklyProdByUser[$emp] = ['total' => 0, 'days' => 0];
    $p = timeToSeconds($row['TotalProductiveHours'] ?? '00:00:00');
    if ($p > 0) {
        $weeklyProdByUser[$emp]['total'] += $p;
        $weeklyProdByUser[$emp]['days']++;
    }
}
$weeklyAvgProd = [];
foreach ($weeklyProdByUser as $emp => $v) {
    $weeklyAvgProd[$emp] = $v['days'] > 0 ? $v['total'] / $v['days'] : 0;
}

// --- Org-level aggregates -----------------------------------------------------
$prodToday   = array_sum(array_column($userStatsToday, 'prod'));
$idleToday   = array_sum(array_column($userStatsToday, 'idle'));
$loggedToday = array_sum(array_column($userStatsToday, 'logged'));
$totalToday  = $prodToday + $idleToday;
$idlePercent = safePercent($idleToday, max($totalToday, 1));

$prodYest = array_sum(array_map(
    fn($r) => timeToSeconds($r['TotalProductiveHours'] ?? '00:00:00'), $activityYest
));
$prodChange = $prodYest > 0 ? round((($prodToday - $prodYest) / $prodYest) * 100, 1) : 0;

// --- EMP_STATUS — active right now --------------------------------------------
$empStatusRows     = fetchEmpStatus($conn, 'ALL');
$activeSystemUsers = 0;
$empStatusMap      = [];  // empId => CURRENT_STATUS
foreach ($empStatusRows as $row) {
    $emp    = $row['EMPID'] ?? $row['EmpID'] ?? null;
    $status = strtoupper(trim($row['CURRENT_STATUS'] ?? ''));
    if (!$emp) continue;
    $empStatusMap[$emp] = $status;
    if ($status === 'ACTIVE') $activeSystemUsers++;
}
$totalEmpScope = count($empStatusMap);

// --- HRMS roster -------------------------------------------------------------
$hrmsUsers   = 0;
$hrmsEmpIds  = [];
$roleExempt  = 0;
$noAccess    = 0;
$empRes = $conn->query("SELECT EMPID, ROLE, SYS_USER_NAME, ACTIVE_YN FROM EMP_DB");
if ($empRes) {
    while ($row = $empRes->fetch_assoc()) {
        $hrmsUsers++;
        $hrmsEmpIds[$row['EMPID']] = true;
        $r = strtoupper(trim($row['ROLE'] ?? ''));
        if ($r === 'ADMIN' || $r === 'LEADERSHIP') $roleExempt++;
        if (strtoupper(trim($row['ACTIVE_YN'] ?? 'Y')) === 'Y' && trim($row['SYS_USER_NAME'] ?? '') === '')
            $noAccess++;
    }
    $empRes->free();
}

// Shadow users: in EMP_STATUS scope but not in HRMS
$vendorShadow = 0;
foreach ($empStatusMap as $empId => $_) {
    if (!isset($hrmsEmpIds[$empId])) $vendorShadow++;
}

// On leave today
$onLeave = 0;
$leaveStmt = $conn->prepare(
    "SELECT COUNT(DISTINCT EMP_ID) AS cnt FROM LEAVE_TRACKER
     WHERE STATUS = 'APPROVED' AND FROM_DATE <= ? AND TO_DATE >= ?"
);
if ($leaveStmt) {
    $leaveStmt->bind_param('ss', $today, $today);
    if ($leaveStmt->execute()) {
        $res = $leaveStmt->get_result();
        if ($res && ($row = $res->fetch_assoc())) $onLeave = (int)$row['cnt'];
        if ($res) $res->free();
    }
    $leaveStmt->close();
}

// --- BRD §6.2: Activity Score per user ---------------------------------------
// activity_score = (keyboard_events + mouse_events) / active_time_minutes
$activityScores = [];
foreach ($userStatsToday as $emp => $u) {
    $activeMinutes = $u['prod'] / 60;
    if ($activeMinutes > 0) {
        $score = ($u['keyboard'] + $u['mouse']) / $activeMinutes;
    } else {
        $score = 0;
    }
    // Classify: High = 20 events/min, Medium = 5, Low > 0, Zero = 0
    if ($score >= 20)     $classification = 'high';
    elseif ($score >= 5)  $classification = 'medium';
    elseif ($score > 0)   $classification = 'low';
    else                  $classification = 'zero';

    $activityScores[$emp] = [
        'score'          => round($score, 2),
        'classification' => $classification,
        'team'           => $u['team'],
        'dept'           => $u['dept'],
        'name'           => $u['name'],
    ];
}

// Org-level average activity score
$allScores    = array_column($activityScores, 'score');
$avgScoreToday = count($allScores) > 0 ? round(array_sum($allScores) / count($allScores), 2) : 0;

// Weekly average activity score (use yesterday data as proxy, or build from week)
$weeklyScoreData = [];
foreach ($activityWeek as $row) {
    $kb = (int)($row['KeyboardEvents'] ?? $row['KEYBOARD_EVENTS'] ?? 0);
    $ms = (int)($row['MouseEvents']    ?? $row['MOUSE_EVENTS']    ?? 0);
    $prod = timeToSeconds($row['TotalProductiveHours'] ?? '00:00:00') / 60;
    if ($prod > 0) $weeklyScoreData[] = ($kb + $ms) / $prod;
}
$weeklyAvgScore = count($weeklyScoreData) > 0 ? array_sum($weeklyScoreData) / count($weeklyScoreData) : 0;

// --- BRD §6.3: App Adoption Rate ---------------------------------------------
$appAdoption = [];
// Guard missing tables in some environments
$hasAppUsage = $conn->query("SHOW TABLES LIKE 'APP_USAGE_TODAY'");
$hasUserApp  = $conn->query("SHOW TABLES LIKE 'USER_APP_ACTIVITY'");
$hasAssign   = $conn->query("SHOW TABLES LIKE 'EMP_APP_ASSIGNMENT'");
$canQueryApp = $hasAppUsage && $hasAppUsage->num_rows > 0
            && $hasUserApp  && $hasUserApp->num_rows  > 0
            && $hasAssign   && $hasAssign->num_rows   > 0;

if ($hasAppUsage) $hasAppUsage->free();
if ($hasUserApp)  $hasUserApp->free();
if ($hasAssign)   $hasAssign->free();

if ($canQueryApp) {
    $appRes = $conn->query(
        "SELECT A.APP_NAME, COUNT(DISTINCT UA.EMP_ID) AS active_users, COUNT(DISTINCT E.EMPID) AS assigned_users
         FROM APP_USAGE_TODAY A
         LEFT JOIN USER_APP_ACTIVITY UA ON UA.APP_NAME = A.APP_NAME AND DATE(UA.USED_AT) = UTC_DATE()
         LEFT JOIN EMP_APP_ASSIGNMENT E ON E.APP_NAME = A.APP_NAME
         GROUP BY A.APP_NAME"
    );
    if ($appRes) {
        while ($row = $appRes->fetch_assoc()) {
            $rate = safePercent((int)$row['active_users'], max((int)$row['assigned_users'], 1));
            $appAdoption[] = [
                'app'           => $row['APP_NAME'],
                'active_users'  => (int)$row['active_users'],
                'assigned_users'=> (int)$row['assigned_users'],
                'adoption_rate' => $rate,
            ];
        }
        $appRes->free();
    }
}
usort($appAdoption, fn($a, $b) => $a['adoption_rate'] <=> $b['adoption_rate']);

// --- Timeline for peak/idle windows ------------------------------------------
[$activeByHour, $idleByHour] = fetchTimelineAggregates($conn, $today, $baseFilters);

$startHour = 8; $endHour = 17;
$bestActive = $bestIdle = -1;
$peakWindow = ['start' => 10, 'end' => 13];
$idleWindow = ['start' => 15, 'end' => 17];
for ($h = $startHour; $h <= $endHour - 2; $h++) {
    $sumA = $activeByHour[$h] + $activeByHour[$h+1] + $activeByHour[$h+2];
    $sumI = $idleByHour[$h]   + $idleByHour[$h+1]   + $idleByHour[$h+2];
    if ($sumA > $bestActive) { $bestActive = $sumA; $peakWindow = ['start' => $h, 'end' => $h+3]; }
    if ($sumI > $bestIdle)   { $bestIdle   = $sumI; $idleWindow = ['start' => $h, 'end' => $h+3]; }
}

$pre       = ($activeByHour[14] ?? 0) + ($activeByHour[15] ?? 0) + ($activeByHour[16] ?? 0);
$post      = ($activeByHour[17] ?? 0) + ($activeByHour[18] ?? 0) + ($activeByHour[19] ?? 0);
$after5Drop = $pre > 0 ? round((($post - $pre) / $pre) * 100, 1) : 0;

// --- Team-level stats ---------------------------------------------------------
$teamStats = [];
foreach ($userStatsToday as $emp => $u) {
    $team = $u['team'];
    if (!isset($teamStats[$team])) {
        $teamStats[$team] = ['prod' => 0, 'idle' => 0, 'logged' => 0, 'members' => [], 'inactive_count' => 0];
    }
    $teamStats[$team]['prod']    += $u['prod'];
    $teamStats[$team]['idle']    += $u['idle'];
    $teamStats[$team]['logged']  += $u['logged'];
    $teamStats[$team]['members'][$emp] = true;
    if ($u['prod'] === 0 && $u['idle'] === 0) $teamStats[$team]['inactive_count']++;
}

// Top 2 workload concentration
$productiveByUser = [];
foreach ($userStatsToday as $emp => $u) {
    $productiveByUser[$emp] = $u['prod'];
}
arsort($productiveByUser);
$top2 = array_slice(array_values($productiveByUser), 0, 2);
$top2Share = safePercent(array_sum($top2), max($prodToday, 1));

// Team adoption
$teamCounts = [];
foreach ($activityToday as $row) {
    $team = $row['Team'] ?? $row['TEAM'] ?? 'Unassigned';
    $emp  = $row['EmpID'] ?? $row['EMPID'] ?? null;
    if (!$emp) continue;
    if (!isset($teamCounts[$team])) $teamCounts[$team] = ['active' => [], 'total' => 0];
    $teamCounts[$team]['active'][$emp] = true;
}
$empTotals = $conn->query("SELECT TEAM, COUNT(*) AS cnt FROM EMP_DB GROUP BY TEAM");
if ($empTotals) {
    while ($row = $empTotals->fetch_assoc()) {
        $team = $row['TEAM'] ?? 'Unassigned';
        if (!isset($teamCounts[$team])) $teamCounts[$team] = ['active' => [], 'total' => 0];
        $teamCounts[$team]['total'] = (int)$row['cnt'];
    }
    $empTotals->free();
}
$lowestTeam = 'N/A'; $lowestAdoption = 100;
foreach ($teamCounts as $team => $v) {
    $adopt = safePercent(count($v['active']), max($v['total'], 1));
    if ($adopt < $lowestAdoption) { $lowestAdoption = $adopt; $lowestTeam = $team; }
}

// Underutilised team
$underTeam = ['team' => 'N/A', 'idlePercent' => 0, 'utilization' => 0];
foreach ($teamStats as $team => $stat) {
    $idlePct = safePercent($stat['idle'], max($stat['logged'], 1));
    if ($idlePct > $underTeam['idlePercent']) {
        $underTeam = ['team' => $team, 'idlePercent' => $idlePct, 'utilization' => round(100 - $idlePct, 1)];
    }
}

// Consecutive 3-day inactivity
$empDayActivity = [];
foreach ([$activityYest, $activityDay2, $activityDay3] as $dayRows) {
    foreach ($dayRows as $row) {
        $emp  = $row['EmpID'] ?? $row['EMPID'] ?? null;
        if (!$emp) continue;
        $p = timeToSeconds($row['TotalProductiveHours'] ?? '00:00:00');
        $i = timeToSeconds($row['TotalIdleHours']       ?? '00:00:00')
           + timeToSeconds($row['AwayFromSystem']        ?? '00:00:00');
        if (!isset($empDayActivity[$emp])) $empDayActivity[$emp] = 0;
        if ($p > 0 || $i > 0) $empDayActivity[$emp]++;
    }
}
$consecutive3DayInactive = 0;
foreach ($empDayActivity as $emp => $activeDays) {
    if ($activeDays === 0) $consecutive3DayInactive++;
}

// Visibility rate (BRD §6.1, §8)
$hrmsInactive   = max(0, $totalEmpScope - $activeSystemUsers);
$visibilityRate = $hrmsUsers > 0 ? round($activeSystemUsers / $hrmsUsers, 3) : 0;

// --- Now shift to current time for real-time per-user checks -----------------
$currentHour   = (int)date('G');
$isShiftHours  = ($currentHour >= 9 && $currentHour < 18);
$nowTimestamp  = time();

// -----------------------------------------------------------------------------
//  ¦¦¦¦¦¦+¦¦¦¦¦¦¦+ ¦¦¦¦¦¦+     ¦¦¦¦¦+ ¦¦+     ¦¦¦¦¦¦¦+¦¦¦¦¦¦+ ¦¦¦¦¦¦¦¦+¦¦¦¦¦¦¦+
// ¦¦+----+¦¦+----+¦¦+---¦¦+   ¦¦+--¦¦+¦¦¦     ¦¦+----+¦¦+--¦¦++--¦¦+--+¦¦+----+
// ¦¦¦     ¦¦¦¦¦+  ¦¦¦   ¦¦¦   ¦¦¦¦¦¦¦¦¦¦¦     ¦¦¦¦¦+  ¦¦¦¦¦¦++   ¦¦¦   ¦¦¦¦¦¦¦+
// ¦¦¦     ¦¦+--+  ¦¦¦   ¦¦¦   ¦¦+--¦¦¦¦¦¦     ¦¦+--+  ¦¦+--¦¦+   ¦¦¦   +----¦¦¦
// +¦¦¦¦¦¦+¦¦¦¦¦¦¦++¦¦¦¦¦¦++   ¦¦¦  ¦¦¦¦¦¦¦¦¦¦+¦¦¦¦¦¦¦+¦¦¦  ¦¦¦   ¦¦¦   ¦¦¦¦¦¦¦¦
//  +-----++------+ +-----+    +-+  +-++------++------++-+  +-+   +-+   +------+
// -----------------------------------------------------------------------------

$notifications = [];

// ---------------------------------------------------------------
// BRD §8 — CEO Alerts (scope = 'CEO')
// ---------------------------------------------------------------

// §8.1 Workforce Visibility Alert — VISIBILITY_RATE < 0.60
$visLevel = $visibilityRate < 0.50 ? 'high' : ($visibilityRate < 0.60 ? 'medium' : 'low');
if ($visibilityRate < 0.60) {
    $notifications[] = [
        'key'      => "ceo_visibility_{$today}",
        'level'    => $visLevel,
        'title'    => 'Workforce visibility alert',
        'message'  => 'Active workforce dropped below 60% of HRMS headcount. '
                    . round($visibilityRate * 100, 1) . '% visibility ('
                    . $activeSystemUsers . ' of ' . $hrmsUsers . ' employees active).',
        'scope'    => 'CEO',
        'category' => 'gap',
    ];
} else {
    $notifications[] = [
        'key'      => "ceo_visibility_{$today}",
        'level'    => 'low',
        'title'    => 'Workforce visibility healthy',
        'message'  => round($visibilityRate * 100, 1) . '% of HRMS employees are active in monitored systems today.',
        'scope'    => 'CEO',
        'category' => 'gap',
    ];
}

// §8.2 High Idle Workforce — idle_users / active_users > 0.25
$activeCount = count(array_filter($userStatsToday, fn($u) => $u['prod'] > 0));
$idleCount   = count(array_filter($userStatsToday, fn($u) => $u['prod'] === 0 && $u['idle'] > 0));
$idleRatio   = $activeCount > 0 ? round($idleCount / $activeCount, 3) : 0;
$idleRatioLevel = $idleRatio > 0.40 ? 'high' : ($idleRatio > 0.25 ? 'medium' : 'low');
if ($idleRatio > 0.25) {
    $notifications[] = [
        'key'      => "ceo_high_idle_{$today}",
        'level'    => $idleRatioLevel,
        'title'    => 'High idle workforce detected',
        'message'  => 'More than 25% of active workforce is idle. '
                    . $idleCount . ' idle vs ' . $activeCount . ' active users ('
                    . round($idleRatio * 100, 1) . '% idle ratio).',
        'scope'    => 'CEO',
        'category' => 'ops',
    ];
} else {
    $notifications[] = [
        'key'      => "ceo_high_idle_{$today}",
        'level'    => 'low',
        'title'    => 'Idle workforce within threshold',
        'message'  => 'Idle ratio at ' . round($idleRatio * 100, 1) . '% — within the 25% threshold.',
        'scope'    => 'CEO',
        'category' => 'ops',
    ];
}

// §8.3 Shadow Workforce — SYSTEM_USERS not in HRMS
if ($vendorShadow > 0) {
    $shadowLevel = $vendorShadow >= 50 ? 'high' : ($vendorShadow >= 10 ? 'medium' : 'low');
    $notifications[] = [
        'key'      => "ceo_shadow_{$today}",
        'level'    => $shadowLevel,
        'title'    => 'Shadow workforce detected',
        'message'  => $vendorShadow . ' users are active in systems but not mapped in HRMS. '
                    . 'Verify vendor/contractor accounts and update HRMS roster.',
        'scope'    => 'CEO',
        'category' => 'gap',
    ];
}

// CEO: general SLA / productivity signal
$slaLevel = $prodChange <= -15 ? 'high' : ($prodChange <= -5 ? 'medium' : 'low');
$notifications[] = [
    'key'      => "ceo_productivity_{$today}",
    'level'    => $slaLevel,
    'title'    => $prodChange < 0
        ? 'Productivity decline — executive attention needed'
        : 'Productivity on track',
    'message'  => $prodChange < 0
        ? 'Organisation-wide productivity dropped ' . abs($prodChange) . '% vs yesterday. Review team-level reports.'
        : 'Productivity improved ' . abs($prodChange) . '% vs yesterday.',
    'scope'    => 'CEO',
    'category' => 'risk',
];

// ---------------------------------------------------------------
// BRD §9 — Manager Alerts (scope = 'MANAGER')
// ---------------------------------------------------------------

// §9.1 Team Inactivity — inactive_users > 3 AND within shift hours
if ($isShiftHours) {
    foreach ($teamStats as $team => $stat) {
        $inactiveInTeam = $stat['inactive_count'];
        if ($inactiveInTeam > 3) {
            $teamLevel = $inactiveInTeam > 8 ? 'high' : 'medium';
            $teamKey   = preg_replace('/[^a-z0-9_]/', '_', strtolower($team));
            $notifications[] = [
                'key'      => "mgr_team_inactive_{$teamKey}_{$today}",
                'level'    => $teamLevel,
                'title'    => "Team inactivity alert — {$team}",
                'message'  => $inactiveInTeam . ' users in ' . $team
                            . ' are inactive during active shift hours. Investigate attendance or system access.',
                'scope'    => 'MANAGER',
                'category' => 'ops',
            ];
        }
    }
}

// §9.2 Productivity Drop — today_activity_score < weekly_avg * 0.80
if ($weeklyAvgScore > 0 && $avgScoreToday < $weeklyAvgScore * 0.80) {
    $dropPct = round((1 - $avgScoreToday / $weeklyAvgScore) * 100, 1);
    $notifications[] = [
        'key'      => "mgr_prod_drop_{$today}",
        'level'    => $dropPct >= 30 ? 'high' : 'medium',
        'title'    => 'Team productivity dropped significantly',
        'message'  => 'Average activity score is ' . $avgScoreToday . ' events/min today vs weekly average '
                    . round($weeklyAvgScore, 2) . ' (' . $dropPct . '% below threshold). Review team engagement.',
        'scope'    => 'MANAGER',
        'category' => 'risk',
    ];
} else {
    $notifications[] = [
        'key'      => "mgr_prod_drop_{$today}",
        'level'    => 'low',
        'title'    => 'Productivity within weekly average',
        'message'  => 'Activity score ' . $avgScoreToday . ' events/min is within the acceptable weekly range.',
        'scope'    => 'MANAGER',
        'category' => 'risk',
    ];
}

// §9.3 System Access Missing — employees assigned to app but not logged in
if ($noAccess > 0) {
    $notifications[] = [
        'key'      => "mgr_no_access_{$today}",
        'level'    => $noAccess >= 20 ? 'high' : 'medium',
        'title'    => 'Users missing required application access',
        'message'  => $noAccess . ' employees are assigned to systems but have no system username configured. '
                    . 'Verify access provisioning in HRMS.',
        'scope'    => 'MANAGER',
        'category' => 'gap',
    ];
} else {
    $notifications[] = [
        'key'      => "mgr_no_access_{$today}",
        'level'    => 'low',
        'title'    => 'System access — all clear',
        'message'  => 'All HRMS employees have system access configured.',
        'scope'    => 'MANAGER',
        'category' => 'gap',
    ];
}

// Manager: app adoption alert
if (!empty($appAdoption)) {
    $lowestApp = $appAdoption[0];
    if ($lowestApp['adoption_rate'] < 50) {
        $notifications[] = [
            'key'      => "mgr_app_adoption_{$today}",
            'level'    => $lowestApp['adoption_rate'] < 30 ? 'high' : 'medium',
            'title'    => "Low application adoption — {$lowestApp['app']}",
            'message'  => $lowestApp['app'] . ' adoption is only ' . $lowestApp['adoption_rate']
                        . '% (' . $lowestApp['active_users'] . ' of ' . $lowestApp['assigned_users'] . ' assigned users active).',
            'scope'    => 'MANAGER',
            'category' => 'ops',
        ];
    }
}

// Manager: workload concentration
$workloadLevel = $top2Share >= 60 ? 'high' : ($top2Share >= 45 ? 'medium' : 'low');
if ($top2Share > 0) {
    $notifications[] = [
        'key'      => "mgr_workload_{$today}",
        'level'    => $workloadLevel,
        'title'    => 'Workload imbalance detected',
        'message'  => 'Top 2 users completed ' . $top2Share . '% of productive output. '
                    . 'Redistribute tasks to reduce burnout risk.',
        'scope'    => 'MANAGER',
        'category' => 'risk',
    ];
}

// Manager: underutilised team
if ($underTeam['team'] !== 'N/A' && $underTeam['idlePercent'] >= 30) {
    $underLevel = $underTeam['idlePercent'] >= 45 ? 'high' : 'medium';
    $notifications[] = [
        'key'      => "mgr_under_team_{$today}",
        'level'    => $underLevel,
        'title'    => "Underutilised team — {$underTeam['team']}",
        'message'  => $underTeam['team'] . ' has ' . $underTeam['idlePercent'] . '% idle capacity '
                    . '(utilisation ' . $underTeam['utilization'] . '%). Consider rebalancing workload.',
        'scope'    => 'MANAGER',
        'category' => 'risk',
    ];
}

// ---------------------------------------------------------------
// BRD §10 — Team Leader Alerts (scope = 'TEAM_LEADER')
// ---------------------------------------------------------------

// §10.1 Long Idle User — last_activity > 20 minutes
// Check LAST_SEEN from EMP_STATUS for users currently idle
$longIdleUsers = [];
foreach ($empStatusRows as $row) {
    $emp     = $row['EMPID'] ?? $row['EmpID'] ?? null;
    $status  = strtoupper(trim($row['CURRENT_STATUS'] ?? ''));
    $lastSeen = $row['LAST_SEEN'] ?? null;
    if (!$emp || $status === 'ACTIVE') continue;  // skip active users
    if ($lastSeen) {
        $lastSeenTs  = strtotime($lastSeen);
        $idleMinutes = ($nowTimestamp - $lastSeenTs) / 60;
        if ($idleMinutes >= 20 && $idleMinutes < 480) {  // 20min+ and under 8 hours
            $longIdleUsers[] = [
                'emp'          => $emp,
                'name'         => $row['EMPNAME'] ?? $row['EmpName'] ?? $emp,
                'team'         => $row['TEAM']    ?? $row['Team']    ?? 'Unassigned',
                'idle_minutes' => (int)$idleMinutes,
            ];
        }
    }
}
if (!empty($longIdleUsers)) {
    // Group by team
    $idleByTeam = [];
    foreach ($longIdleUsers as $u) {
        $idleByTeam[$u['team']][] = $u;
    }
    foreach ($idleByTeam as $team => $users) {
        $teamKey = preg_replace('/[^a-z0-9_]/', '_', strtolower($team));
        $names   = implode(', ', array_slice(array_column($users, 'name'), 0, 3));
        if (count($users) > 3) $names .= ' +' . (count($users) - 3) . ' more';
        $maxIdle = max(array_column($users, 'idle_minutes'));
        $notifications[] = [
            'key'      => "tl_long_idle_{$teamKey}_{$today}",
            'level'    => $maxIdle >= 60 ? 'high' : 'medium',
            'title'    => 'User inactive for extended period — ' . $team,
            'message'  => count($users) . ' user(s) inactive for 20+ minutes: ' . $names
                        . '. Longest: ' . $maxIdle . ' min.',
            'scope'    => 'TEAM_LEADER',
            'category' => 'ops',
        ];
    }
} else {
    $notifications[] = [
        'key'      => "tl_long_idle_{$today}",
        'level'    => 'low',
        'title'    => 'No extended idle users',
        'message'  => 'All users have shown activity within the last 20 minutes.',
        'scope'    => 'TEAM_LEADER',
        'category' => 'ops',
    ];
}

// §10.2 Late Login — login_time > shift_start + 10 minutes
$lateLogins = [];
foreach ($userStatsToday as $emp => $u) {
    if (!$u['login_time'] || !$u['shift_start']) continue;
    $loginTs      = strtotime(date('Y-m-d') . ' ' . $u['login_time']);
    $shiftStartTs = strtotime(date('Y-m-d') . ' ' . $u['shift_start']);
    if (!$loginTs || !$shiftStartTs) continue;
    $diffMin = ($loginTs - $shiftStartTs) / 60;
    if ($diffMin > 10) {
        $lateLogins[] = [
            'emp'     => $emp,
            'name'    => $u['name'],
            'team'    => $u['team'],
            'late_min' => (int)$diffMin,
        ];
    }
}
if (!empty($lateLogins)) {
    $lateByTeam = [];
    foreach ($lateLogins as $l) {
        $lateByTeam[$l['team']][] = $l;
    }
    foreach ($lateByTeam as $team => $users) {
        $teamKey = preg_replace('/[^a-z0-9_]/', '_', strtolower($team));
        $names   = implode(', ', array_slice(array_column($users, 'name'), 0, 3));
        if (count($users) > 3) $names .= ' +' . (count($users) - 3) . ' more';
        $notifications[] = [
            'key'      => "tl_late_login_{$teamKey}_{$today}",
            'level'    => count($users) >= 5 ? 'high' : 'medium',
            'title'    => 'Late login detected — ' . $team,
            'message'  => count($users) . ' user(s) logged in more than 10 minutes after shift start: ' . $names . '.',
            'scope'    => 'TEAM_LEADER',
            'category' => 'ops',
        ];
    }
} else {
    $notifications[] = [
        'key'      => "tl_late_login_{$today}",
        'level'    => 'low',
        'title'    => 'All users logged in on time',
        'message'  => 'No late logins detected today.',
        'scope'    => 'TEAM_LEADER',
        'category' => 'ops',
    ];
}

// §10.3 Application Usage Drop — usage_today < weekly_avg * 0.70
// Using prodToday vs weekly average as proxy for usage drop
$weeklyAvgProdTotal = 0; $weeklyDayCount = 0;
foreach ([$activityYest, $activityDay2, $activityDay3] as $dayRows) {
    $dayProd = array_sum(array_map(fn($r) => timeToSeconds($r['TotalProductiveHours'] ?? '00:00:00'), $dayRows));
    if ($dayProd > 0) { $weeklyAvgProdTotal += $dayProd; $weeklyDayCount++; }
}
$weeklyAvgProdTotalVal = $weeklyDayCount > 0 ? $weeklyAvgProdTotal / $weeklyDayCount : 0;
$appUsageDrop = $weeklyAvgProdTotalVal > 0
    ? round((($prodToday - $weeklyAvgProdTotalVal) / $weeklyAvgProdTotalVal) * 100, 1)
    : 0;

if ($weeklyAvgProdTotalVal > 0 && $prodToday < $weeklyAvgProdTotalVal * 0.70) {
    $dropPct = abs($appUsageDrop);
    $notifications[] = [
        'key'      => "tl_usage_drop_{$today}",
        'level'    => $dropPct >= 40 ? 'high' : 'medium',
        'title'    => 'Application usage significantly lower today',
        'message'  => 'System usage is ' . $dropPct . '% below the 3-day average. '
                    . 'Check for access issues, system outages, or attendance gaps.',
        'scope'    => 'TEAM_LEADER',
        'category' => 'ops',
    ];
} else {
    $notifications[] = [
        'key'      => "tl_usage_drop_{$today}",
        'level'    => 'low',
        'title'    => 'Application usage within normal range',
        'message'  => 'Usage is ' . ($appUsageDrop >= 0 ? '+' : '') . $appUsageDrop . '% vs 3-day average.',
        'scope'    => 'TEAM_LEADER',
        'category' => 'ops',
    ];
}

// ---------------------------------------------------------------
// BRD §11 — Employee Alerts (scope = 'EMPLOYEE')
// ---------------------------------------------------------------

// §11.1 Idle Reminder — no activity > 15 minutes
// Generate per-employee idle reminders for currently idle users
$idleReminderCount = 0;
foreach ($empStatusRows as $row) {
    $emp     = $row['EMPID'] ?? $row['EmpID'] ?? null;
    $status  = strtoupper(trim($row['CURRENT_STATUS'] ?? ''));
    $lastSeen = $row['LAST_SEEN'] ?? null;
    if (!$emp || $status === 'ACTIVE') continue;
    if ($lastSeen) {
        $idleMinutes = ($nowTimestamp - strtotime($lastSeen)) / 60;
        if ($idleMinutes >= 15 && $idleMinutes < 60) {
            $empKey = preg_replace('/[^a-z0-9_]/', '_', strtolower($emp));
            $notifications[] = [
                'key'      => "emp_idle_reminder_{$empKey}_{$today}",
                'level'    => 'medium',
                'title'    => 'Idle reminder',
                'message'  => 'You have been inactive for ' . (int)$idleMinutes . ' minutes. '
                            . 'Resume activity to keep your productivity score up.',
                'scope'    => "EMPLOYEE:{$emp}",
                'category' => 'hr',
            ];
            $idleReminderCount++;
        }
    }
}

// §11.2 Productivity Insight — personalised peak window per employee
// For employees with sufficient data, emit a personal insight
foreach ($userStatsToday as $emp => $u) {
    if ($u['prod'] < 1800) continue;  // skip if less than 30 minutes productive
    $empKey   = preg_replace('/[^a-z0-9_]/', '_', strtolower($emp));
    $weeklyAvg = $weeklyAvgProd[$emp] ?? 0;
    $prodToday_emp = $u['prod'];
    $peakMsg   = 'You are most productive between '
               . formatHourLabel($peakWindow['start']) . ' and '
               . formatHourLabel($peakWindow['end']) . '.';
    $compMsg   = $weeklyAvg > 0
        ? ($prodToday_emp >= $weeklyAvg * 0.9
            ? ' Your productivity today is on track with your weekly average.'
            : ' Your productivity today is below your usual level.')
        : '';
    $notifications[] = [
        'key'      => "emp_insight_{$empKey}_{$today}",
        'level'    => 'low',
        'title'    => 'Your productivity insight',
        'message'  => $peakMsg . $compMsg,
        'scope'    => "EMPLOYEE:{$emp}",
        'category' => 'hr',
    ];
}

// ---------------------------------------------------------------
// BRD §7 / General operational alerts (scope = 'ALL')
// ---------------------------------------------------------------

// General idle
$idleLevelGen = $idlePercent >= 18 ? 'high' : ($idlePercent >= 10 ? 'medium' : 'low');
$notifications[] = [
    'key'      => "ops_idle_{$today}",
    'level'    => $idleLevelGen,
    'title'    => 'Active vs idle workforce',
    'message'  => $idlePercent . '% of logged-in time is idle today. '
                . $idleCount . ' users idle, ' . $activeCount . ' active.',
    'scope'    => 'ALL',
    'category' => 'ops',
];

// Peak productivity window
$notifications[] = [
    'key'      => "ops_peak_{$today}",
    'level'    => 'low',
    'title'    => 'Peak productivity window',
    'message'  => 'Team productivity peaks between '
                . formatHourLabel($peakWindow['start']) . ' – '
                . formatHourLabel($peakWindow['end']) . '. Schedule critical tasks in this window.',
    'scope'    => 'ALL',
    'category' => 'ops',
];

// General SLA risk
$slaLevelGen = $prodChange <= -12 ? 'high' : ($prodChange <= -5 ? 'medium' : 'low');
$notifications[] = [
    'key'      => "risk_sla_{$today}",
    'level'    => $slaLevelGen,
    'title'    => 'Potential SLA risk',
    'message'  => $prodChange < 0
        ? 'Task completion dropped ' . abs($prodChange) . '% vs yesterday.'
        : 'Productivity stable vs yesterday (+' . abs($prodChange) . '%).',
    'scope'    => 'ALL',
    'category' => 'risk',
];

// 3-day consecutive inactivity
if ($consecutive3DayInactive > 0) {
    $hrLevel = $consecutive3DayInactive >= 10 ? 'high' : 'medium';
    $notifications[] = [
        'key'      => "hr_inactive_3day_{$today}",
        'level'    => $hrLevel,
        'title'    => 'Consecutive inactivity detected',
        'message'  => $consecutive3DayInactive . ' employees have been inactive for 3 consecutive days. HR review recommended.',
        'scope'    => 'ALL',
        'category' => 'hr',
    ];
}

// Leave impact
if ($onLeave > 0) {
    $leaveLevel = $onLeave >= 25 ? 'high' : ($onLeave >= 10 ? 'medium' : 'low');
    $notifications[] = [
        'key'      => "hr_leave_{$today}",
        'level'    => $leaveLevel,
        'title'    => 'Leave impact',
        'message'  => $onLeave . ' employees on approved leave today.',
        'scope'    => 'ALL',
        'category' => 'hr',
    ];
}

// HRMS gap — untracked
if ($hrmsInactive > 0) {
    $gapLevel = $hrmsInactive >= 300 ? 'high' : 'medium';
    $notifications[] = [
        'key'      => "gap_hrms_inactive_{$today}",
        'level'    => $gapLevel,
        'title'    => 'HRMS vs system gap',
        'message'  => $hrmsInactive . ' HRMS users are not active in the system today.',
        'scope'    => 'ALL',
        'category' => 'gap',
    ];
}

// After-5 PM drop
if ($after5Drop <= -10) {
    $dropAbs = abs($after5Drop);
    $after5Level = $dropAbs >= 18 ? 'high' : 'medium';
    $notifications[] = [
        'key'      => "ops_after5_{$today}",
        'level'    => $after5Level,
        'title'    => 'Productivity drops after 5 PM',
        'message'  => 'Active time drops ' . $dropAbs . '% after 5 PM compared to 2–4 PM.',
        'scope'    => 'ALL',
        'category' => 'ops',
    ];
}

// Activity score summary
$lowActivityUsers = count(array_filter($activityScores, fn($s) => $s['classification'] === 'low' || $s['classification'] === 'zero'));
if ($lowActivityUsers > 0) {
    $actLevel = $lowActivityUsers >= 20 ? 'high' : ($lowActivityUsers >= 8 ? 'medium' : 'low');
    $notifications[] = [
        'key'      => "ops_activity_score_{$today}",
        'level'    => $actLevel,
        'title'    => 'Low activity score users',
        'message'  => $lowActivityUsers . ' users have low or zero activity scores today (avg score: '
                    . $avgScoreToday . ' events/min).',
        'scope'    => 'ALL',
        'category' => 'ops',
    ];
}

// --- Persist all notifications ------------------------------------------------
$sharedMeta = [
    'date'    => $today,
    'metrics' => [
        'visibilityRate'   => $visibilityRate,
        'idlePercent'      => $idlePercent,
        'idleRatio'        => $idleRatio,
        'prodChange'       => $prodChange,
        'top2Share'        => $top2Share,
        'avgScoreToday'    => $avgScoreToday,
        'weeklyAvgScore'   => round($weeklyAvgScore, 2),
        'lowestAdoption'   => $lowestAdoption,
        'after5Drop'       => $after5Drop,
        'longIdleUsers'    => count($longIdleUsers),
        'lateLogins'       => count($lateLogins),
        'idleRemindersSent'=> $idleReminderCount,
    ],
];

foreach ($notifications as $n) {
    $meta = array_merge($sharedMeta, ['category' => $n['category']]);
    if (insertAlert($conn, $n['key'], $n['level'], $n['title'], $n['message'], $meta, $n['scope'])) {
        $inserted++;
    }
}

// Cleanup rows older than today (UTC)
$conn->query("DELETE FROM ALERTS_RT WHERE DATE(CREATED_AT) < UTC_DATE()");

echo json_encode([
    'success'  => true,
    'inserted' => $inserted,
    'metrics'  => $sharedMeta['metrics'],
    'breakdown' => [
        'ceo_alerts'           => 4,
        'manager_alerts'       => count(array_filter($notifications, fn($n) => $n['scope'] === 'MANAGER')),
        'team_leader_alerts'   => count(array_filter($notifications, fn($n) => $n['scope'] === 'TEAM_LEADER')),
        'employee_alerts'      => count(array_filter($notifications, fn($n) => str_starts_with($n['scope'], 'EMPLOYEE'))),
        'general_alerts'       => count(array_filter($notifications, fn($n) => $n['scope'] === 'ALL')),
    ],
]);

$conn->close();
?>
