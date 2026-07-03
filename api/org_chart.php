<?php
// org_chart.php
include 'apiMain.php';

$raw = file_get_contents('php://input');
$body = json_decode($raw, true) ?? [];
$userid = isset($_GET['userid']) ? trim($_GET['userid']) 
        : (isset($_POST['userid']) ? trim($_POST['userid'])
        : (isset($body['userid']) ? trim($body['userid']) : NULL));

if (empty($userid)) {
    echo json_encode(['status' => 'error', 'message' => 'Employee ID is required.']);
    exit();
}

try {

    // --- STEP 1: Get ALL employees from EMP_DB ---
    $stmt = $conn->prepare(
        "SELECT EMPID, EMPNAME, REPORTING_1, EMAIL, DESIGNATION_CATEGORY, DEPARTMENT
         FROM EMP_DB"
    );
    $stmt->execute();
    $result = $stmt->get_result();

    $allEmployees = [];
    while ($row = $result->fetch_assoc()) {
        $allEmployees[] = [
            'EMPID'                => $row['EMPID']                ?? '',
            'EMPNAME'              => $row['EMPNAME']              ?? '',
            'REPORTING_1'          => $row['REPORTING_1']          ?? '',
            'EMAIL'                => $row['EMAIL']                ?? '',
            'DESIGNATION_CATEGORY' => $row['DESIGNATION_CATEGORY'] ?? '',
            'DEPARTMENT'           => $row['DEPARTMENT']           ?? '',
            'profile_image'        => null,
        ];
    }
    $stmt->close();

    if (empty($allEmployees)) {
        echo json_encode(['status' => 'error', 'message' => 'No employee data found.']);
        exit();
    }

    // --- STEP 2: Index by EMPID ---
    $empMap = [];
    foreach ($allEmployees as $emp) {
        $empMap[strtoupper(trim($emp['EMPID']))] = $emp;
    }

    // --- STEP 3: Find logged-in employee ---
    $loggedIn = $empMap[strtoupper(trim($userid))] ?? null;

    if (!$loggedIn) {
        echo json_encode(['status' => 'error', 'message' => 'Employee not found: ' . $userid]);
        exit();
    }

    // --- STEP 4: Find manager ---
    $manager = null;
    if (!empty($loggedIn['REPORTING_1'])) {
        $managerKey = strtoupper(trim($loggedIn['REPORTING_1']));
        $manager    = $empMap[$managerKey] ?? null;
        if ($manager) {
            $loggedIn['manager_name'] = $manager['EMPNAME'];
        }
    }

    // --- STEP 5: Find reportees ---
    $reportees = [];
    foreach ($allEmployees as $emp) {
        if (
            !empty($emp['REPORTING_1']) &&
            strtoupper(trim($emp['REPORTING_1'])) === strtoupper(trim($userid)) &&
            strtoupper(trim($emp['EMPID']))        !== strtoupper(trim($userid))
        ) {
            $reportees[] = $emp;
        }
    }

    $loggedIn['reportee_count'] = count($reportees);

    // --- STEP 6: Return ---
    echo json_encode([
        'status' => 'success',
        'tree'   => [
            'manager'   => $manager,
            'loggedIn'  => $loggedIn,
            'reportees' => $reportees,
        ],
        'total' => count($allEmployees),
    ]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Server error: ' . $e->getMessage()]);
}

$conn->close();
?>