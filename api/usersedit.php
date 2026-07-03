<?php
include 'apiMain.php';

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// GET: Fetch user data
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!isset($_GET['EMPID'])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'EMPID is required']);
        exit;
    }

    $empid = $_GET['EMPID'];

    $stmt = $conn->prepare("SELECT * FROM EMP_DB WHERE EMPID = ?");
    $stmt->bind_param("s", $empid);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Employee not found']);
    } else {
        $row = $result->fetch_assoc();

        echo json_encode([
            'status' => 'success',
            'data' => [
                'EMPID' => $row['EMPID'] ?? '',
                'EMPNAME' => $row['EMPNAME'] ?? '',
                'EMAIL' => $row['EMAIL'] ?? '',
                'SYS_USER_NAME' => $row['SYS_USER_NAME'] ?? '',
                'ROLE' => $row['ROLE'] ?? '',
                'DESIGNATIONCATEGORY' => $row['DESIGNATION_CATEGORY'] ?? '',
                'REPORTING1' => $row['REPORTING_1'] ?? '',
                'REPORTING2' => $row['REPORTING_2'] ?? '',
                'DEPARTMENT' => $row['DEPARTMENT'] ?? '',
                'TEAM' => $row['TEAM'] ?? '',
                'PROJECT' => $row['PROJECT'] ?? '',
                'SHIFT' => $row['SHIFT'] ?? '',
                'ALLOTED_BREAK' => $row['ALLOTED_BREAK'] ?? '',
                'REQUIRED_PRODUCTIVE_HRS' => $row['REQUIRED_PRODUCTIVE_HRS'] ?? '',
                'ACTIVE_YN' => $row['ACTIVE_YN'] ?? '',
                'HOLIDAYCOUNTRY' => $row['HOLIDAY_COUNTRY'] ?? '',
                'REGION' => $row['REGION'] ?? '',
                'ACCESS_ROLE' => $row['ACCESS_ROLE'] ?? '',
                'UPDATED_BY' => $row['UPDATED_BY'] ?? ''
            ]
        ]);
    }

    $stmt->close();
    $conn->close();
    exit;
}

// PUT: Update user data
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $input = json_decode(file_get_contents("php://input"), true);

    if (!$input) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid JSON body']);
        exit;
    }

    $requiredFields = [
        'EMPID', 'EMPNAME', 'EMAIL', 'SYS_USER_NAME', 'ROLE',
        'DESIGNATIONCATEGORY', 'REPORTING1', 'REPORTING2', 'DEPARTMENT',
        'TEAM', 'PROJECT', 'SHIFT', 'ALLOTED_BREAK', 'REQUIRED_PRODUCTIVE_HRS',
        'ACTIVE_YN', 'HOLIDAYCOUNTRY', 'REGION', 'ACCESS_ROLE', 'UPDATED_BY'
    ];

    foreach ($requiredFields as $field) {
        if (!isset($input[$field])) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => "Missing field: $field"]);
            exit;
        }
    }

    $stmt = $conn->prepare("UPDATE EMP_DB SET
        EMPNAME = ?, EMAIL = ?, SYS_USER_NAME = ?, ROLE = ?, DESIGNATION_CATEGORY = ?,
        REPORTING_1 = ?, REPORTING_2 = ?, DEPARTMENT = ?, TEAM = ?, PROJECT = ?, SHIFT = ?,
        ALLOTED_BREAK = ?, REQUIRED_PRODUCTIVE_HRS = ?, ACTIVE_YN = ?, HOLIDAY_COUNTRY = ?,
        REGION = ?, ACCESS_ROLE = ?, UPDATED_BY = ? WHERE EMPID = ?");

    $stmt->bind_param(
        "sssssssssssssssssss",
        $input['EMPNAME'],
        $input['EMAIL'],
        $input['SYS_USER_NAME'],
        $input['ROLE'],
        $input['DESIGNATIONCATEGORY'],
        $input['REPORTING1'],
        $input['REPORTING2'],
        $input['DEPARTMENT'],
        $input['TEAM'],
        $input['PROJECT'],
        $input['SHIFT'],
        $input['ALLOTED_BREAK'],
        $input['REQUIRED_PRODUCTIVE_HRS'],
        $input['ACTIVE_YN'],
        $input['HOLIDAYCOUNTRY'],
        $input['REGION'],
        $input['ACCESS_ROLE'],
        $input['UPDATED_BY'],
        $input['EMPID']
    );

    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'Employee updated successfully']);
    } else {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Database update failed']);
    }

    $stmt->close();
    $conn->close();
    exit;
}

// If method not matched
http_response_code(405);
echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
?>
