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

// Ensure that we are passing 9 parameters to match the stored procedure definition
$sql = "CALL PR_TBL_EMP_DB(?, ?, ?, ?, ?, ?, ?, ?, ?)";
$stmt = $conn->prepare($sql);

// Bind parameters: You should use the correct types for each parameter based on your database structure
$stmt->bind_param('sssssssss', $empId, $empName, $department, $role, $project, $team, $sysUserName, $activeYn);

// Execute the stored procedure
if (!$stmt->execute()) {
    die('Error executing query: ' . $stmt->error);
}

// Get the result from the stored procedure
$result = $stmt->get_result();

// Fetch all rows and column names
$columns = [];
$rows = [];
if ($result->num_rows > 0) {
    // Fetch column names
    while ($field = $result->fetch_field()) {
        $columns[] = $field->name;
    }

    // Fetch data
    while ($row = $result->fetch_assoc()) {
        // Remove "UPDATED_BY" field if it exists
        unset($row['UPDATED_BY']);
        $rows[] = $row;
    }
}

// Free the result set and close the statement
$result->free();
$stmt->close();

// Function to get unique values from a specific column
function getUniqueValues($conn, $columnName) {
    $query = "SELECT DISTINCT $columnName FROM EMP_DB"; 
    $result = $conn->query($query);
    $uniqueValues = [];
    while ($row = $result->fetch_assoc()) {
        $uniqueValues[] = $row[$columnName];
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

// Prepare response with columns, data, and unique values
$response = [
    'columns' => $columns,
    'data' => $rows,
    'uniqueValues' => array_merge(
        ['EMPID' => $empId === 'ALL' ? $uniqueValues['EMPID'] : explode(',', $empId)],
        ['EMPNAME' => $empName === 'ALL' ? $uniqueValues['EMPNAME'] : explode(',', $empName)],
        ['DEPARTMENT' => $department === 'ALL' ? $uniqueValues['DEPARTMENT'] : explode(',', $department)],
        ['ROLE' => $role === 'ALL' ? $uniqueValues['ROLE'] : explode(',', $role)],
        ['PROJECT' => $project === 'ALL' ? $uniqueValues['PROJECT'] : explode(',', $project)],
        ['TEAM' => $team === 'ALL' ? $uniqueValues['TEAM'] : explode(',', $team)],
        ['SYS_USER_NAME' => $sysUserName === 'ALL' ? $uniqueValues['SYS_USER_NAME'] : explode(',', $sysUserName)],
        ['ACTIVE_YN' => $activeYn === 'ALL' ? $uniqueValues['ACTIVE_YN'] : explode(',', $activeYn)]
    )
];

// Return data as JSON
echo json_encode($response);
?>
