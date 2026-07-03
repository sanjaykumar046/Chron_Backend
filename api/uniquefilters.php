<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

include 'apiMain.php';

function getColumnNames($conn, $table) {
    $sql = "SHOW COLUMNS FROM $table";
    $queryResult = $conn->query($sql);
    $columns = [];
    
    if ($queryResult) {
        while ($row = $queryResult->fetch_assoc()) {
            $columns[] = $row['Field'];
        }
    }
    
    return $columns;
}

function getUniqueValues($conn, $table, $columns) {
    $result = [];
    
    foreach ($columns as $column) {
        $sql = "SELECT DISTINCT $column FROM $table";
        $queryResult = $conn->query($sql);
        
        if ($queryResult) {
            $values = [];
            while ($row = $queryResult->fetch_assoc()) {
                $values[] = $row[$column];
            }
            $result[$column] = $values;
        } else {
            $result[$column] = ['error' => "Query failed for column: $column"];
        }
    }

    return $result;
}

$columnsToFetch = [
    'EMPID', 'EMPNAME', 'ROLE', 'DEPARTMENT', 'TEAM', 'PROJECT', 'SHIFT'
];

$table = 'EMP_DB';

$allColumns = getColumnNames($conn, $table);
$uniqueValues = getUniqueValues($conn, $table, $columnsToFetch);

$response = [
    'columns' => $allColumns,
    'data' => $uniqueValues
];

echo json_encode($response);

$conn->close();
?>