<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

include 'apiMain.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

try {
    // Get and decode JSON input
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON input']);
        exit;
    }

    // Extract and sanitize inputs (matching your JS payload)
    $empid = trim($data['empid'] ?? '');
    $empname = trim($data['empname'] ?? '');
    $email = trim($data['email'] ?? '');
    $sysUserName = trim($data['sysUserName'] ?? '');
    $role = trim($data['role'] ?? '');
    $designationcategory = trim($data['designationcategory'] ?? '');
    $reporting1 = trim($data['reporting1'] ?? '');
    $reporting2 = trim($data['reporting2'] ?? '');
    $department = trim($data['department'] ?? '');
    $team = trim($data['team'] ?? '');
    $project = trim($data['project'] ?? '');
    $shift = trim($data['shift'] ?? '');
    $allotedBreak = trim($data['allotedBreak'] ?? '');
    $weekOff = trim($data['weekOff'] ?? ''); // Added from JS
    $requiredproductivehrs = trim($data['requiredproductivehrs'] ?? '');
    $activeYn = strtoupper(trim($data['activeYn'] ?? 'N')) === 'Y' ? 'Y' : 'N';
    $holidayCountry = trim($data['holidayCountry'] ?? '');
    $region = trim($data['region'] ?? '');
    $updatedBy = trim($data['updatedBy'] ?? '');
    $accessRole = trim($data['accessRole'] ?? '');
    $uploadType = 'FORM'; // ? Set to FORM for form submissions

    // Basic validation
    $missingFields = [];
    if (empty($empid)) $missingFields[] = 'empid';
    if (empty($empname)) $missingFields[] = 'empname';
    if (empty($email)) $missingFields[] = 'email';
    if (empty($sysUserName)) $missingFields[] = 'sysUserName';

    if (!empty($missingFields)) {
        http_response_code(400);
        echo json_encode([
            'success' => false, 
            'error' => 'Missing required fields: ' . implode(', ', $missingFields)
        ]);
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid email format.']);
        exit;
    }

    // Check database connection
    if (!isset($conn) || $conn->connect_error) {
        throw new Exception('Database connection failed');
    }

    // Check duplicate EMAIL
    $checkEmail = $conn->prepare("SELECT 1 FROM EMP_DB WHERE EMAIL = ?");
    if (!$checkEmail) {
        throw new Exception('Email check prepare failed: ' . $conn->error);
    }
    $checkEmail->bind_param("s", $email);
    $checkEmail->execute();
    $checkEmail->store_result();
    if ($checkEmail->num_rows > 0) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'Email already exists.']);
        $checkEmail->close();
        exit;
    }
    $checkEmail->close();

    // Check duplicate SYS_USER_NAME
    $checkSysUser = $conn->prepare("SELECT 1 FROM EMP_DB WHERE SYS_USER_NAME = ?");
    if (!$checkSysUser) {
        throw new Exception('SysUser check prepare failed: ' . $conn->error);
    }
    $checkSysUser->bind_param("s", $sysUserName);
    $checkSysUser->execute();
    $checkSysUser->store_result();
    if ($checkSysUser->num_rows > 0) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'System Username already exists.']);
        $checkSysUser->close();
        exit;
    }
    $checkSysUser->close();

    // Check duplicate EMPID
    $checkEmpid = $conn->prepare("SELECT 1 FROM EMP_DB WHERE EMPID = ?");
    if (!$checkEmpid) {
        throw new Exception('EMPID check prepare failed: ' . $conn->error);
    }
    $checkEmpid->bind_param("s", $empid);
    $checkEmpid->execute();
    $checkEmpid->store_result();
    if ($checkEmpid->num_rows > 0) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'EMPID already exists.']);
        $checkEmpid->close();
        exit;
    }
    $checkEmpid->close();

    // Generate default password using EMPID (stored as plain text)
    $defaultPassword = hash('sha1', 'PMS@321');


    // Check if WEEK_OFF column exists in database
    $columnCheck = $conn->query("SHOW COLUMNS FROM EMP_DB LIKE 'WEEK_OFF'");
    $hasWeekOffColumn = ($columnCheck && $columnCheck->num_rows > 0);

    // Prepare INSERT statement based on available columns
    if ($hasWeekOffColumn) {
        // Include WEEK_OFF if column exists
        $stmt = $conn->prepare("
            INSERT INTO EMP_DB (
                EMPID, EMPNAME, EMAIL, SYS_USER_NAME, ROLE, DESIGNATION_CATEGORY, 
                REPORTING_1, REPORTING_2, DEPARTMENT, TEAM, PROJECT, SHIFT, 
                ALLOTED_BREAK, WEEK_OFF, REQUIRED_PRODUCTIVE_HRS, ACTIVE_YN,
                HOLIDAY_COUNTRY, REGION, UPDATED_BY, PASSWORD, ACCESS_ROLE, 
                UPLOAD_TYPE, CREATE_DT, UPDATED_DT
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()
            )
        ");

        if (!$stmt) {
            throw new Exception('Statement prepare failed: ' . $conn->error);
        }

        $stmt->bind_param(
            "ssssssssssssssssssssss",
            $empid, 
            $empname, 
            $email, 
            $sysUserName, 
            $role, 
            $designationcategory, 
            $reporting1,
            $reporting2, 
            $department, 
            $team, 
            $project, 
            $shift, 
            $allotedBreak,
            $weekOff,
            $requiredproductivehrs,
            $activeYn, 
            $holidayCountry, 
            $region, 
            $updatedBy, 
            $defaultPassword,
            $accessRole,
            $uploadType
        );
    } else {
        // Exclude WEEK_OFF if column doesn't exist
        $stmt = $conn->prepare("
            INSERT INTO EMP_DB (
                EMPID, EMPNAME, EMAIL, SYS_USER_NAME, ROLE, DESIGNATION_CATEGORY, 
                REPORTING_1, REPORTING_2, DEPARTMENT, TEAM, PROJECT, SHIFT, 
                ALLOTED_BREAK, REQUIRED_PRODUCTIVE_HRS, ACTIVE_YN,
                HOLIDAY_COUNTRY, REGION, UPDATED_BY, PASSWORD, ACCESS_ROLE, 
                UPLOAD_TYPE, CREATE_DT, UPDATED_DT
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()
            )
        ");

        if (!$stmt) {
            throw new Exception('Statement prepare failed: ' . $conn->error);
        }

        $stmt->bind_param(
            "sssssssssssssssssssss",
            $empid, 
            $empname, 
            $email, 
            $sysUserName, 
            $role, 
            $designationcategory, 
            $reporting1,
            $reporting2, 
            $department, 
            $team, 
            $project, 
            $shift, 
            $allotedBreak,
            $requiredproductivehrs,
            $activeYn, 
            $holidayCountry, 
            $region, 
            $updatedBy, 
            $defaultPassword,
            $accessRole,
            $uploadType
        );
    }

    if ($stmt->execute()) {
        http_response_code(200);
        echo json_encode([
            'success' => true, 
            'message' => 'Employee added successfully.',
            'empid' => $empid
        ]);
    } else {
        throw new Exception('Execute failed: ' . $stmt->error);
    }

    $stmt->close();
    $conn->close();

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => 'Server error: ' . $e->getMessage()
    ]);
    error_log('UsersAdd Error: ' . $e->getMessage());
}
?>