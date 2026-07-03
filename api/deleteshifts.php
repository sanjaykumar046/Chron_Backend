<?php

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Max-Age: 3600');
header('Content-Type: application/json');


if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

include 'apiMain.php';


if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {

    if (isset($_GET['EMPID']) && isset($_GET['SHIFTSTART_DT']) && isset($_GET['SHIFTEND_DT'])) {
        $empid = $conn->real_escape_string($_GET['EMPID']);
        $shiftStartDt = $conn->real_escape_string($_GET['SHIFTSTART_DT']);
        $shiftEndDt = $conn->real_escape_string($_GET['SHIFTEND_DT']);

        $sql = "DELETE FROM TBL_SHIFT 
                WHERE EMPID = '$empid' 
                AND SHIFTSTART_DT = '$shiftStartDt' 
                AND SHIFTEND_DT = '$shiftEndDt'";
        
        if ($conn->query($sql) === TRUE) {
            if ($conn->affected_rows > 0) {
                echo json_encode(array(
                    "status" => "success", 
                    "message" => "Shift deleted successfully.",
                    "deleted_rows" => $conn->affected_rows
                ));
            } else {
                echo json_encode(array(
                    "status" => "error", 
                    "message" => "No shift found matching the criteria.",
                    "sql_debug" => $sql
                ));
            }
        } else {
            echo json_encode(array(
                "status" => "error", 
                "error" => "Error deleting shift: " . $conn->error
            ));
        }
    } else {
        $provided = array(
            'EMPID' => isset($_GET['EMPID']) ? $_GET['EMPID'] : 'missing',
            'SHIFTSTART_DT' => isset($_GET['SHIFTSTART_DT']) ? $_GET['SHIFTSTART_DT'] : 'missing',
            'SHIFTEND_DT' => isset($_GET['SHIFTEND_DT']) ? $_GET['SHIFTEND_DT'] : 'missing'
        );
        
        echo json_encode(array(
            "status" => "error", 
            "error" => "Missing required parameters. Need EMPID, SHIFTSTART_DT, and SHIFTEND_DT.",
            "provided_parameters" => $provided
        ));
    }
} else {
    echo json_encode(array(
        "status" => "error", 
        "error" => "Method not allowed. Use DELETE method.",
        "received_method" => $_SERVER['REQUEST_METHOD']
    ));
}


$conn->close();
?>