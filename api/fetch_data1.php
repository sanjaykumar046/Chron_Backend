<?php
include 'apiMain.php';

// Get data from GET request with default values
$empId = isset($_GET['EMPID']) ? htmlspecialchars($_GET['EMPID']) : 'ALL';
$empName = isset($_GET['EMPNAME']) ? htmlspecialchars($_GET['EMPNAME']) : 'ALL';
$department = isset($_GET['DEPARTMENT']) ? htmlspecialchars($_GET['DEPARTMENT']) : 'ALL';
$role = isset($_GET['ROLE']) ? htmlspecialchars($_GET['ROLE']) : 'ALL';
$project = isset($_GET['PROJECT']) ? htmlspecialchars($_GET['PROJECT']) : 'ALL';
$team = isset($_GET['TEAM']) ? htmlspecialchars($_GET['TEAM']) : 'ALL';
$sysUserName = isset($_GET['SYS_USER_NAME']) ? htmlspecialchars($_GET['SYS_USER_NAME']) : 'ALL';
$activeYn = isset($_GET['ACTIVE_YN']) ? htmlspecialchars($_GET['ACTIVE_YN']) : 'ALL';
$userId = isset($_GET['userid']) ? htmlspecialchars($_GET['userid']) : 'ALL';

// Build dynamic WHERE clause
$whereConditions = [];
$params = [];
$types = '';

if ($empId !== 'ALL') {
    $whereConditions[] = "EMPID = ?";
    $params[] = $empId;
    $types .= 's';
}

if ($empName !== 'ALL') {
    $whereConditions[] = "EMPNAME = ?";
    $params[] = $empName;
    $types .= 's';
}

if ($department !== 'ALL') {
    $whereConditions[] = "DEPARTMENT = ?";
    $params[] = $department;
    $types .= 's';
}

if ($role !== 'ALL') {
    $whereConditions[] = "ROLE = ?";
    $params[] = $role;
    $types .= 's';
}

if ($project !== 'ALL') {
    $whereConditions[] = "PROJECT = ?";
    $params[] = $project;
    $types .= 's';
}

if ($team !== 'ALL') {
    $whereConditions[] = "TEAM = ?";
    $params[] = $team;
    $types .= 's';
}

if ($sysUserName !== 'ALL') {
    $whereConditions[] = "SYS_USER_NAME = ?";
    $params[] = $sysUserName;
    $types .= 's';
}

if ($activeYn !== 'ALL') {
    $whereConditions[] = "ACTIVE_YN = ?";
    $params[] = $activeYn;
    $types .= 's';
}

// Build the final query
$sql = "SELECT e.*, uag.group_name AS AI_GROUPING 
        FROM EMP_DB e
        LEFT JOIN governanace_for_chron.user_ai_grouping uag 
            ON e.EMPID = uag.emp_id";
if (!empty($whereConditions)) {
    $sql .= " WHERE " . implode(' AND ', $whereConditions);
}

// Prepare and execute the query
$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();

// Get the result
$result = $stmt->get_result();

// Fetch all rows and column names
$columns = [];
$rows = [];
if ($result && $result->num_rows > 0) {
    // Fetch column names
    while ($field = $result->fetch_field()) {
        $columns[] = $field->name;
    }

    // Reset result pointer
    $result->data_seek(0);

    // Fetch data
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
}

// Close the result set and statement
if ($result) {
    $result->free();
}
$stmt->close();

// Calculate summary counts
$totalEmployees = count($rows);
$activeEmployees = 0;
$inactiveEmployees = 0;

foreach ($rows as $row) {
    if (isset($row['ACTIVE_YN'])) {
        $activeValue = trim($row['ACTIVE_YN']);
        if ($activeValue === 'Y') {
            $activeEmployees++;
        } else if ($activeValue === 'N') {
            $inactiveEmployees++;
        }
    }
}

// Function to get unique values from a specific column
function getUniqueValues($conn, $columnName) {
    $query = "SELECT DISTINCT $columnName FROM EMP_DB WHERE $columnName IS NOT NULL AND $columnName != ''"; 
    $result = $conn->query($query);
    $uniqueValues = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $uniqueValues[] = $row[$columnName];
        }
        $result->free();
    }
    return $uniqueValues;
}

// Fetch unique values for each field
$uniqueValues = [
    'EMPID' => getUniqueValues($conn, 'EMPID'),
    'EMPNAME' => getUniqueValues($conn, 'EMPNAME'),
    'DEPARTMENT' => getUniqueValues($conn, 'DEPARTMENT'),
    'ROLE' => getUniqueValues($conn, 'ROLE'),
    'PROJECT' => getUniqueValues($conn, 'PROJECT'),
    'TEAM' => getUniqueValues($conn, 'TEAM'),
    'SYS_USER_NAME' => getUniqueValues($conn, 'SYS_USER_NAME'),
    'ACTIVE_YN' => getUniqueValues($conn, 'ACTIVE_YN')
];

// Close the connection
$conn->close();

// Prepare response
$response = [
    'columns' => $columns,
    'data' => $rows,
    'uniqueValues' => [
        'EMPID' => $empId === 'ALL' ? $uniqueValues['EMPID'] : explode(',', $empId),
        'EMPNAME' => $empName === 'ALL' ? $uniqueValues['EMPNAME'] : explode(',', $empName),
        'DEPARTMENT' => $department === 'ALL' ? $uniqueValues['DEPARTMENT'] : explode(',', $department),
        'ROLE' => $role === 'ALL' ? $uniqueValues['ROLE'] : explode(',', $role),
        'PROJECT' => $project === 'ALL' ? $uniqueValues['PROJECT'] : explode(',', $project),
        'TEAM' => $team === 'ALL' ? $uniqueValues['TEAM'] : explode(',', $team),
        'SYS_USER_NAME' => $sysUserName === 'ALL' ? $uniqueValues['SYS_USER_NAME'] : explode(',', $sysUserName),
        'ACTIVE_YN' => $activeYn === 'ALL' ? $uniqueValues['ACTIVE_YN'] : explode(',', $activeYn)
    ],
    'summary' => [
        'totalEmployees' => $totalEmployees,
        'activeEmployees' => $activeEmployees,
        'inactiveEmployees' => $inactiveEmployees
    ]
];

// Set proper headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Return data as JSON
echo json_encode($response);
?>