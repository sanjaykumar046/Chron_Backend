<?php

include 'apiMain.php';

// Get input parameters with default values
$endDate = isset($_GET['dateRange']['end']) ? $_GET['dateRange']['end'] : date('Y-m-d', strtotime('yesterday'));
$startDate = isset($_GET['dateRange']['start']) ? $_GET['dateRange']['start'] : date('Y-m-d', strtotime('yesterday - 6 days'));
$departments = isset($_GET['department']) ? $_GET['department'] : ['ALL'];
$roles = isset($_GET['role']) ? $_GET['role'] : ['ALL'];
$projects = isset($_GET['project']) ? $_GET['project'] : ['ALL'];
$shifts = isset($_GET['shift']) ? $_GET['shift'] : ['ALL'];
$teams = isset($_GET['team']) ? $_GET['team'] : ['ALL'];
$ids = isset($_GET['ids']) ? $_GET['ids'] : ['ALL'];
$names = isset($_GET['names']) ? $_GET['names'] : ['ALL'];
$designationCategory = isset($_GET['designationCategory']) ? $_GET['designationCategory'] : ['ALL']; // ? Added
$userId = isset($_GET['userId']) ? $_GET['userId'] : 'ADMIN'; // ? Added

// Convert arrays to comma-separated strings for stored procedure parameters
$departments = implode(",", array_map([$conn, 'real_escape_string'], $departments));
$roles = implode(",", array_map([$conn, 'real_escape_string'], $roles));
$projects = implode(",", array_map([$conn, 'real_escape_string'], $projects));
$shifts = implode(",", array_map([$conn, 'real_escape_string'], $shifts));
$teams = implode(",", array_map([$conn, 'real_escape_string'], $teams));
$ids = implode(",", array_map([$conn, 'real_escape_string'], $ids));
$names = implode(",", array_map([$conn, 'real_escape_string'], $names));
$designationCategory = implode(",", array_map([$conn, 'real_escape_string'], $designationCategory)); // ? Added

// ? Changed: Now using 11 parameters
if ($stmt = $conn->prepare("CALL PR_EMPLOYEE_ACTIVITY(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")) {
    // ? Changed: Now binding 11 parameters
    $stmt->bind_param('sssssssssss', 
        $startDate,           // P_FRM_DT
        $endDate,             // P_TO_DT
        $ids,                 // P_EMPID
        $names,               // P_EMPNAMES
        $departments,         // P_DEPT
        $roles,               // P_ROLE
        $designationCategory, // P_DESIGNATION_CATEGORY ? Parameter 7
        $projects,            // P_PROJECT
        $shifts,              // P_SHIFT
        $teams,               // P_TEAMS
        $userId               // P_USER_ID ? Parameter 11
    );
    
    $stmt->execute();
    $result_data = $stmt->get_result();

    $aggregate_data_by_project = [];
    $project_row_count = [];

    // Fetch and aggregate data
    while ($row = $result_data->fetch_assoc()) {
        $project = $row['Project'];

        if (!isset($aggregate_data_by_project[$project])) {
            $aggregate_data_by_project[$project] = [
                'TotalLoggedHours' => 0,
                'TotalIdleHours' => 0,
                'TotalProductiveHours' => 0,
                'TotalMeetings' => 0,
                'TotalBreaks' => 0,
                'TOTAL_ON_SYSTEM' => 0,
            ];
            $project_row_count[$project] = 0;
        }

        $project_row_count[$project]++;

        $aggregate_data_by_project[$project]['TotalLoggedHours'] += strtotime($row['TotalLoggedHours']) - strtotime('TODAY');
        $aggregate_data_by_project[$project]['TotalIdleHours'] += strtotime($row['TotalIdleHours']) - strtotime('TODAY');
        $aggregate_data_by_project[$project]['TotalProductiveHours'] += strtotime($row['TotalProductiveHours']) - strtotime('TODAY');
        $aggregate_data_by_project[$project]['TotalMeetings'] += strtotime($row['TotalMeetings']) - strtotime('TODAY');
        $aggregate_data_by_project[$project]['TotalBreaks'] += strtotime($row['TotalBreaks']) - strtotime('TODAY');
        $aggregate_data_by_project[$project]['TOTAL_ON_SYSTEM'] += strtotime($row['TOTAL_ON_SYSTEM']) - strtotime('TODAY');
    }

    $stmt->close();
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to prepare statement: ' . $conn->error]);
    exit;
}

// Calculate the average and format the results with decimal precision
foreach ($aggregate_data_by_project as $project => &$data) {
    if ($project_row_count[$project] > 0) {
        $data['TotalLoggedHours'] = round(($data['TotalLoggedHours'] / $project_row_count[$project]) / 3600, 2);
        $data['TotalIdleHours'] = round(($data['TotalIdleHours'] / $project_row_count[$project]) / 3600, 2);
        $data['TotalProductiveHours'] = round(($data['TotalProductiveHours'] / $project_row_count[$project]) / 3600, 2);
        $data['TotalMeetings'] = round(($data['TotalMeetings'] / $project_row_count[$project]) / 3600, 2);
        $data['TotalBreaks'] = round(($data['TotalBreaks'] / $project_row_count[$project]) / 3600, 2);
        $data['TOTAL_ON_SYSTEM'] = round(($data['TOTAL_ON_SYSTEM'] / $project_row_count[$project]) / 3600, 2);
    }
}

$response = ['data' => $aggregate_data_by_project];
echo json_encode($response);
$conn->close();
?>