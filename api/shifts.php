<?php
include 'apiMain.php';

// Set content type to JSON
header('Content-Type: application/json');

// Function to extract columns from the result set
function extractColumns($result) {
    $columns = [];
    $meta = $result->fetch_fields();
    foreach ($meta as $field) {
        $columns[] = $field->name;
    }
    return $columns;
}

// Handle GET request for unassigned employees
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'unassigned') {
    $userid = isset($_GET['userid']) ? $_GET['userid'] : 'ADMIN';
    $userid = $conn->real_escape_string($userid);

    // Call the stored procedure to get employees without shifts
    $proc_call = "CALL PR_EMP_MISMATCH_SHIFT_VS_EMPDB('$userid')";

    if ($result = $conn->query($proc_call)) {
        $data = [];
        $columns = extractColumns($result);

        // Fetch rows
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }

        echo json_encode(array(
            "columns" => $columns,
            "data" => $data
        ));
    } else {
        echo json_encode(array("error" => $conn->error));
    }
}

// Handle POST request for assigned shifts
else if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    // Extract parameters from the input data
    $empid = isset($data['empId']) ? $data['empId'] : 'ALL';
    $empname = isset($data['empName']) ? $data['empName'] : 'ALL';
    $startDate = isset($data['startDate']) ? $data['startDate'] : date('Y-m-d');
    $endDate = isset($data['endDate']) ? $data['endDate'] : date('Y-m-d');
    $roles = isset($data['role']) ? $data['role'] : 'ALL';
    $project = isset($data['project']) ? $data['project'] : 'ALL';
    $team = isset($data['team']) ? $data['team'] : 'ALL';
    $dept = isset($data['dept']) ? $data['dept'] : 'ALL';
    $userid = isset($data['userid']) ? $data['userid'] : 'ALL';

    // Escape all parameters
    $empid = $conn->real_escape_string($empid);
    $empname = $conn->real_escape_string($empname);
    $startDate = $conn->real_escape_string($startDate);
    $endDate = $conn->real_escape_string($endDate);
    $roles = $conn->real_escape_string($roles);
    $project = $conn->real_escape_string($project);
    $team = $conn->real_escape_string($team);
    $dept = $conn->real_escape_string($dept);
    $userid = $conn->real_escape_string($userid);

    // Build the query to call the stored procedure for assigned shifts
    $proc_call = "CALL PR_TBL_SHIFT('$startDate', '$endDate', '$empid', '$empname', '$dept', '$roles', '$project', '$team', '$userid')";

    // Execute the stored procedure
    if ($result = $conn->query($proc_call)) {
        $data = [];
        $columns = extractColumns($result);

        // Fetch rows
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }

        echo json_encode(array(
            "columns" => $columns,
            "data" => $data
        ));
    } else {
        echo json_encode(array("error" => $conn->error));
    }
}

// Handle PUT request for updating shifts
else if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    try {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        // Log the received data for debugging
        error_log("Received PUT data: " . print_r($data, true));

        // Check if JSON decoding was successful
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo json_encode(array("error" => "Invalid JSON data: " . json_last_error_msg()));
            exit;
        }

        // Check if data is null or empty
        if (!$data || !is_array($data)) {
            echo json_encode(array("error" => "No data received or invalid data format"));
            exit;
        }

        // Extract parameters from the input data with better validation
        $required_fields = ['EMPID', 'EMPNAME', 'SYS_USER_NAME', 'SHIFTTYPE', 'SHIFT_START_TIME', 'SHIFT_END_TIME', 'SHIFTSTART_DT', 'SHIFTEND_DT', 'TIME_ZONE', 'WEEKOFF', 'COMMENTS', 'UPDATED_BY'];
        $missing_fields = [];

        foreach ($required_fields as $field) {
            if (!isset($data[$field])) {
                $missing_fields[] = $field;
            }
        }

        if (!empty($missing_fields)) {
            echo json_encode(array("error" => "Missing required fields: " . implode(', ', $missing_fields)));
            exit;
        }

        // Escape all parameters
        $EMPID = $conn->real_escape_string($data['EMPID']);
        $EMPNAME = $conn->real_escape_string($data['EMPNAME']);
        $SYS_USER_NAME = $conn->real_escape_string($data['SYS_USER_NAME']);
        $SHIFTTYPE = $conn->real_escape_string($data['SHIFTTYPE']);
        $SHIFT_START_TIME = $conn->real_escape_string($data['SHIFT_START_TIME']);
        $SHIFT_END_TIME = $conn->real_escape_string($data['SHIFT_END_TIME']);
        $SHIFTSTART_DT = $conn->real_escape_string($data['SHIFTSTART_DT']);
        $SHIFTEND_DT = $conn->real_escape_string($data['SHIFTEND_DT']);
        $TIME_ZONE = $conn->real_escape_string($data['TIME_ZONE']);
        
        // Handle WEEKOFF - can be array or string
        // If it's an array, join with comma; if string, use as is
        $WEEKOFF = is_array($data['WEEKOFF']) 
            ? $conn->real_escape_string(implode(',', $data['WEEKOFF']))
            : $conn->real_escape_string($data['WEEKOFF']);
        
        $COMMENTS = $conn->real_escape_string($data['COMMENTS']);
        $UPDATED_BY = $conn->real_escape_string($data['UPDATED_BY']);

        // Log the SQL that will be executed
        $sql = "CALL PR_SHIFT_UPDATE('$EMPID', '$EMPNAME', '$SYS_USER_NAME', '$SHIFTTYPE', '$SHIFT_START_TIME', '$SHIFT_END_TIME', '$SHIFTSTART_DT', '$SHIFTEND_DT', '$TIME_ZONE', '$WEEKOFF', '$COMMENTS', '$UPDATED_BY')";
        error_log("Executing SQL: " . $sql);

        // Execute the stored procedure
        if ($conn->query($sql)) {
            echo json_encode(array("status" => "success", "message" => "Data updated successfully."));
        } else {
            $error_msg = $conn->error;
            error_log("MySQL Error: " . $error_msg);
            echo json_encode(array("error" => "Database error: " . $error_msg));
        }
    } catch (Exception $e) {
        error_log("Exception in PUT request: " . $e->getMessage());
        echo json_encode(array("error" => "Server error: " . $e->getMessage()));
    }
}
else {
    echo json_encode(array("error" => "Method not allowed"));
}

// Close connection
$conn->close();