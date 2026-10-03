<?php
// Return only the logged-in employee, their manager, and their direct
// reportees. Avoid loading the entire EMP_DB table into PHP.
include 'apiMain.php';

$raw = file_get_contents('php://input');
$body = json_decode($raw, true) ?? [];
$userid = isset($_GET['userid']) ? trim($_GET['userid'])
        : (isset($_POST['userid']) ? trim($_POST['userid'])
        : (isset($body['userid']) ? trim($body['userid']) : ''));

if ($userid === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Employee ID is required.']);
    $conn->close();
    exit;
}

// Lightweight RBAC lookup: answer whether one employee reports directly to
// the requested manager without constructing the full org chart.
if (isset($_GET['candidate_id'])) {
    $candidateId = trim((string)$_GET['candidate_id']);
    if ($candidateId === '') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Candidate employee ID is required.']);
        $conn->close();
        exit;
    }

    try {
        $stmt = $conn->prepare(
            'SELECT 1 FROM EMP_DB WHERE EMPID = ? AND REPORTING_1 = ? LIMIT 1'
        );
        if (!$stmt) {
            throw new RuntimeException('Could not prepare reportee check: ' . $conn->error);
        }

        $stmt->bind_param('ss', $candidateId, $userid);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('Could not check reportee relationship: ' . $error);
        }

        $stmt->store_result();
        $isReportee = $stmt->num_rows > 0;
        $stmt->close();

        echo json_encode([
            'status' => 'success',
            'manager_id' => $userid,
            'candidate_id' => $candidateId,
            'is_reportee' => $isReportee,
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Reportee check failed.']);
    } finally {
        if ($conn instanceof mysqli) {
            $conn->close();
        }
    }
    exit;
}

/** Fetch one employee by EMPID, with the fields used by the org chart. */
function getOrgChartEmployee(mysqli $conn, string $empId): ?array {
    $stmt = $conn->prepare(
        "SELECT EMPID, EMPNAME, REPORTING_1, EMAIL, DESIGNATION_CATEGORY, DEPARTMENT
         FROM EMP_DB
         WHERE EMPID = ?
         LIMIT 1"
    );
    if (!$stmt) {
        throw new RuntimeException('Could not prepare employee lookup: ' . $conn->error);
    }

    $stmt->bind_param('s', $empId);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Could not load employee: ' . $error);
    }

    $result = $stmt->get_result();
    $employee = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if ($employee) {
        $employee['profile_image'] = null;
    }

    return $employee ?: null;
}

try {
    $loggedIn = getOrgChartEmployee($conn, $userid);
    if (!$loggedIn) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Employee not found: ' . $userid]);
        $conn->close();
        exit;
    }

    $loggedIn['EMPID'] = $loggedIn['EMPID'] ?? '';
    $loggedIn['EMPNAME'] = $loggedIn['EMPNAME'] ?? '';
    $loggedIn['REPORTING_1'] = $loggedIn['REPORTING_1'] ?? '';
    $loggedIn['EMAIL'] = $loggedIn['EMAIL'] ?? '';
    $loggedIn['DESIGNATION_CATEGORY'] = $loggedIn['DESIGNATION_CATEGORY'] ?? '';
    $loggedIn['DEPARTMENT'] = $loggedIn['DEPARTMENT'] ?? '';

    $manager = null;
    $managerId = trim((string)$loggedIn['REPORTING_1']);
    if ($managerId !== '') {
        $manager = getOrgChartEmployee($conn, $managerId);
        if ($manager) {
            $loggedIn['manager_name'] = $manager['EMPNAME'] ?? '';
        }
    }

    $stmt = $conn->prepare(
        "SELECT EMPID, EMPNAME, REPORTING_1, EMAIL, DESIGNATION_CATEGORY, DEPARTMENT
         FROM EMP_DB
         WHERE REPORTING_1 = ? AND EMPID <> ?
         ORDER BY EMPNAME"
    );
    if (!$stmt) {
        throw new RuntimeException('Could not prepare reportee lookup: ' . $conn->error);
    }

    $stmt->bind_param('ss', $userid, $userid);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Could not load reportees: ' . $error);
    }

    $result = $stmt->get_result();
    $reportees = [];
    while ($row = $result->fetch_assoc()) {
        $row['profile_image'] = null;
        $row['children'] = [];
        $reportees[] = $row;
    }
    $stmt->close();

    $loggedIn['reportee_count'] = count($reportees);

    $countResult = $conn->query('SELECT COUNT(*) AS total FROM EMP_DB');
    $total = $countResult ? (int)$countResult->fetch_assoc()['total'] : null;
    if ($countResult) {
        $countResult->free();
    }

    echo json_encode([
        'status' => 'success',
        'tree' => [
            'manager' => $manager,
            'loggedIn' => $loggedIn,
            'reportees' => $reportees,
        ],
        'total' => $total,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Server error: ' . $e->getMessage(),
    ]);
} finally {
    if ($conn instanceof mysqli) {
        $conn->close();
    }
}
?>