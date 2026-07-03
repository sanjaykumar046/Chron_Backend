<?php
// org_chart.php
// Same pattern as fetch_employee_activity1.php

include 'apiMain.php'; // ? same include — handles CORS headers + $conn

// ? Same way other PHP files read userid
$userid = isset($_GET['userid']) ? trim($_GET['userid']) : NULL;

if (empty($userid)) {
    echo json_encode([
        'status'  => 'error',
        'message' => 'Employee ID is required.'
    ]);
    exit();
}

try {

    // --- STEP 1: Call stored procedure — last param is logged-in EMPID ---
    $all = 'ALL';
    $stmt = $conn->prepare("CALL prod_ent1_tenant_0_demo.PR_TBL_EMP_DB(?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param(
        "sssssssss",
        $all, $all, $all, $all, $all, $all, $all, $all,
        $userid
    );
    $stmt->execute();
    $result = $stmt->get_result();

    $allEmployees = [];
    while ($row = $result->fetch_assoc()) {
        if (!empty($row['profile_image'])) {
            $row['profile_image'] = base64_encode($row['profile_image']);
        } else {
            $row['profile_image'] = null;
        }
        $allEmployees[] = $row;
    }
    $stmt->close();

    // Free extra result sets from stored procedure
    while ($conn->more_results() && $conn->next_result()) {
        $extra = $conn->use_result();
        if ($extra) $extra->close();
    }

    if (empty($allEmployees)) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'No data found for this employee.'
        ]);
        exit();
    }

    // --- STEP 2: Index employees by EMPID ---
    $empMap = [];
    foreach ($allEmployees as $emp) {
        $empMap[$emp['EMPID']] = $emp;
    }

    // --- STEP 3: Find the logged-in employee ---
    $loggedIn = null;
    foreach ($allEmployees as $emp) {
        if (strtoupper(trim($emp['EMPID'])) === strtoupper(trim($userid))) {
            $loggedIn = $emp;
            break;
        }
    }

    // If not found in procedure result, fetch directly from table
    if (!$loggedIn) {
        $stmt2 = $conn->prepare(
            "SELECT EMPID, EMPNAME, REPORTING_1, EMAIL, DESIGNATION_CATEGORY, DEPARTMENT, profile_image
             FROM EMP_DB WHERE EMPID = ?"
        );
        $stmt2->bind_param("s", $userid);
        $stmt2->execute();
        $r2 = $stmt2->get_result();
        if ($r2->num_rows > 0) {
            $loggedIn = $r2->fetch_assoc();
            $loggedIn['profile_image'] = !empty($loggedIn['profile_image'])
                ? base64_encode($loggedIn['profile_image']) : null;
        }
        $stmt2->close();
    }

    if (!$loggedIn) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'Logged-in employee not found.'
        ]);
        exit();
    }

    // --- STEP 4: Find manager (who logged-in reports to) ---
    $manager = null;
    if (!empty($loggedIn['REPORTING_1'])) {
        $managerEmpId = $loggedIn['REPORTING_1'];

        if (isset($empMap[$managerEmpId])) {
            $manager = $empMap[$managerEmpId];
        } else {
            $stmt3 = $conn->prepare(
                "SELECT EMPID, EMPNAME, REPORTING_1, EMAIL, DESIGNATION_CATEGORY, DEPARTMENT, profile_image
                 FROM EMP_DB WHERE EMPID = ?"
            );
            $stmt3->bind_param("s", $managerEmpId);
            $stmt3->execute();
            $r3 = $stmt3->get_result();
            if ($r3->num_rows > 0) {
                $manager = $r3->fetch_assoc();
                $manager['profile_image'] = !empty($manager['profile_image'])
                    ? base64_encode($manager['profile_image']) : null;
            }
            $stmt3->close();
        }

        if ($manager) {
            $loggedIn['manager_name'] = $manager['EMPNAME'];
        }
    }

    // --- STEP 5: Find reportees (who reports to logged-in employee) ---
    $reportees = [];
    foreach ($allEmployees as $emp) {
        if (
            !empty($emp['REPORTING_1']) &&
            strtoupper(trim($emp['REPORTING_1'])) === strtoupper(trim($userid)) &&
            strtoupper(trim($emp['EMPID']))        !== strtoupper(trim($userid))
        ) {
            $emp['children'] = [];
            $reportees[] = $emp;
        }
    }

    $loggedIn['reportee_count'] = count($reportees);

    // --- STEP 6: Return final tree ---
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
    echo json_encode([
        'status'  => 'error',
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}

$conn->close();
?>