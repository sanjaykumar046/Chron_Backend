<?php
include 'apiMain.php';
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ✅ Dynamically allow trusted origins
$allowed_origins = [
    'https://demo.chronai.in',
    'http://44.211.232.251',
    'http://localhost:3001',
    'https://demo.chronai.in'
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array($origin, $allowed_origins)) {
    header("Access-Control-Allow-Origin: $origin");
}

header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json; charset=UTF-8");

// ✅ Handle preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ✅ Get and validate JSON input
$data = file_get_contents("php://input");
$employees = json_decode($data, true);

if (!is_array($employees) || empty($employees)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Invalid or empty data"]);
    exit;
}

// ✅ Arrays to track validation errors
$duplicateErrors = [];
$validationErrors = [];
$validEmployees = [];

// ✅ Check for duplicates BEFORE inserting
foreach ($employees as $index => $employee) {
    $empid = trim($employee['EMPID'] ?? '');
    $email = trim($employee['EMAIL'] ?? '');
    $sys_user_name = trim($employee['Sys_user_name'] ?? '');
    
    $rowNumber = $index + 2; // +2 because row 1 is header, array starts at 0
    
    // Check required fields
    if (empty($empid)) {
        $validationErrors[] = "Row $rowNumber: EMPID is required";
        continue;
    }
    if (empty($email)) {
        $validationErrors[] = "Row $rowNumber: EMAIL is required";
        continue;
    }
    if (empty($sys_user_name)) {
        $validationErrors[] = "Row $rowNumber: System Username is required";
        continue;
    }
    
    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $validationErrors[] = "Row $rowNumber: Invalid email format ($email)";
        continue;
    }
    
    // Check for duplicate EMPID in database
    $checkEmpid = $conn->prepare("SELECT EMPID FROM EMP_DB WHERE EMPID = ?");
    $checkEmpid->bind_param("s", $empid);
    $checkEmpid->execute();
    $checkEmpid->store_result();
    if ($checkEmpid->num_rows > 0) {
        $duplicateErrors[] = "Row $rowNumber: EMPID '$empid' already exists in database";
        $checkEmpid->close();
        continue;
    }
    $checkEmpid->close();
    
    // Check for duplicate EMAIL in database
    $checkEmail = $conn->prepare("SELECT EMAIL FROM EMP_DB WHERE EMAIL = ?");
    $checkEmail->bind_param("s", $email);
    $checkEmail->execute();
    $checkEmail->store_result();
    if ($checkEmail->num_rows > 0) {
        $duplicateErrors[] = "Row $rowNumber: Email '$email' already exists in database";
        $checkEmail->close();
        continue;
    }
    $checkEmail->close();
    
    // Check for duplicate SYS_USER_NAME in database
    $checkSysUser = $conn->prepare("SELECT SYS_USER_NAME FROM EMP_DB WHERE SYS_USER_NAME = ?");
    $checkSysUser->bind_param("s", $sys_user_name);
    $checkSysUser->execute();
    $checkSysUser->store_result();
    if ($checkSysUser->num_rows > 0) {
        $duplicateErrors[] = "Row $rowNumber: System Username '$sys_user_name' already exists in database";
        $checkSysUser->close();
        continue;
    }
    $checkSysUser->close();
    
    // If all validations pass, add to valid employees
    $validEmployees[] = $employee;
}

// ✅ If there are any errors, return them without inserting anything
if (!empty($duplicateErrors) || !empty($validationErrors)) {
    $allErrors = array_merge($validationErrors, $duplicateErrors);
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Validation failed. Please fix the following errors:",
        "errors" => $allErrors,
        "total_rows" => count($employees),
        "failed_rows" => count($allErrors),
        "valid_rows" => count($validEmployees)
    ]);
    exit;
}

// ✅ If all validations pass, proceed with insertion
$stmt = $conn->prepare("
    INSERT INTO EMP_DB (
        EMPID, EMPNAME, EMAIL, sys_user_name, ROLE, 
        REPORTING_1, REPORTING_2, DEPARTMENT, DESIGNATION_CATEGORY, REQUIRED_PRODUCTIVE_HRS, 
        TEAM, PROJECT, SHIFT, ALLOTED_BREAK, ACTIVE_YN, 
        HOLIDAY_COUNTRY, REGION, UPDATED_BY, PASSWORD, ACCESS_ROLE, UPLOAD_TYPE
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");

if (!$stmt) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Failed to prepare SQL: " . $conn->error]);
    exit;
}

// ✅ Bind parameters
$stmt->bind_param(
    "sssssssssssssssssssss",
    $empid, $empname, $email, $sys_user_name, $role,
    $reporting_1, $reporting_2, $department, $des_cat, $req_pro,
    $team, $project, $shift, $alloted_break, $active_yn,
    $holiday_country, $region, $updated_by, $password, $access_role, $upload_type
);

// ✅ Execute for each valid employee
$success_count = 0;
$insert_errors = [];

foreach ($validEmployees as $index => $employee) {
    $empid = $employee['EMPID'] ?? '';
    $empname = $employee['EMPNAME'] ?? '';
    $email = $employee['EMAIL'] ?? '';
    $sys_user_name = $employee['Sys_user_name'] ?? '';
    $role = $employee['ROLE'] ?? '';
    $reporting_1 = $employee['REPORTING_1'] ?? '';
    $reporting_2 = $employee['REPORTING_2'] ?? '';
    $department = $employee['DEPARTMENT'] ?? '';
    $des_cat = $employee['DESIGNATION_CATEGORY'] ?? '';
    $req_pro = $employee['REQUIRED_PRODUCTIVE_HRS'] ?? '';
    $team = $employee['TEAM'] ?? '';
    $project = $employee['PROJECT'] ?? '';
    $shift = $employee['SHIFT'] ?? '';
    $alloted_break = $employee['ALLOTED_BREAK'] ?? '';
    $active_yn = $employee['ACTIVE_YN'] ?? 'N';
    $holiday_country = $employee['HOLIDAY_COUNTRY'] ?? '';
    $region = $employee['REGION'] ?? '';
    $updated_by = $employee['UPDATED_BY'] ?? '';
    $password = $employee['PASSWORD'] ?? '';
    $access_role = $employee['ACCESS_ROLE'] ?? '';
    $upload_type = $employee['UPLOAD_TYPE'] ?? 'UPLOADER_FILE';

    if (!$stmt->execute()) {
        $insert_errors[] = "Failed to insert EMPID '$empid': " . $stmt->error;
    } else {
        $success_count++;
    }
}

$stmt->close();
$conn->close();

// ✅ Success response with details
if (!empty($insert_errors)) {
    http_response_code(207); // Multi-Status
    echo json_encode([
        "success" => true,
        "message" => "Data uploaded with some errors",
        "records_processed" => $success_count,
        "total_records" => count($validEmployees),
        "errors" => $insert_errors
    ]);
} else {
    http_response_code(200);
    echo json_encode([
        "success" => true,
        "message" => "All data uploaded successfully",
        "records_processed" => $success_count,
        "total_records" => count($validEmployees)
    ]);
}
?>