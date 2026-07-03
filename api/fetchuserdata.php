<?php
include 'apiMain.php';
// Get the username parameter
$username = isset($_GET['username']) ? $_GET['username'] : null;

// Check if username is provided
if (!$username) {
    echo json_encode(['error' => 'Username parameter is missing']);
    exit();
}

// Sanitize the username to prevent SQL injection
$username = $conn->real_escape_string($username);

// Prepare the SQL queries
$sql_data = "SELECT * FROM EmployeeActivity WHERE EmpID = '$username'";
$sql_aggregate_by_date = "
    SELECT
        DATE(Date) AS date,
        SEC_TO_TIME(AVG(TIME_TO_SEC(TotalLoggedHours))) AS average_logged_hours,
        SEC_TO_TIME(AVG(TIME_TO_SEC(TotalIdleHours))) AS average_idle_hours,
        SEC_TO_TIME(AVG(TIME_TO_SEC(TotalProductiveHours))) AS average_productive_hours,
        SEC_TO_TIME(AVG(TIME_TO_SEC(TOTAL_ON_SYSTEM))) AS average_time_on_system,
        SEC_TO_TIME(AVG(TIME_TO_SEC(AwayFromSystem))) AS average_time_away_from_system
    FROM EmployeeActivity
    WHERE EmpID = '$username'
    GROUP BY DATE(Date)
    ORDER BY DATE(Date)
";
$sql_aggregate_by_day = "
    SELECT
        DAYNAME(Date) AS day,
        SEC_TO_TIME(AVG(TIME_TO_SEC(TotalLoggedHours))) AS average_logged_hours,
        SEC_TO_TIME(AVG(TIME_TO_SEC(TotalIdleHours))) AS average_idle_hours,
        SEC_TO_TIME(AVG(TIME_TO_SEC(TotalProductiveHours))) AS average_productive_hours,
        SEC_TO_TIME(AVG(TIME_TO_SEC(TOTAL_ON_SYSTEM))) AS average_time_on_system,
        SEC_TO_TIME(AVG(TIME_TO_SEC(AwayFromSystem))) AS average_time_away_from_system
    FROM EmployeeActivity
    WHERE EmpID = '$username'
    GROUP BY DAYNAME(Date)
    ORDER BY FIELD(DAYNAME(Date), 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday')
";
$sql_totals = "
    SELECT
        SEC_TO_TIME(AVG(TIME_TO_SEC(TotalLoggedHours))) AS total_logged_hours,
        SEC_TO_TIME(AVG(TIME_TO_SEC(TotalIdleHours))) AS total_idle_hours,
        SEC_TO_TIME(AVG(TIME_TO_SEC(TotalProductiveHours))) AS total_productive_hours,
        SEC_TO_TIME(AVG(TIME_TO_SEC(TOTAL_ON_SYSTEM))) AS total_time_on_system,
        SEC_TO_TIME(AVG(TIME_TO_SEC(AwayFromSystem))) AS total_time_away_from_system
    FROM EmployeeActivity
    WHERE EmpID = '$username'
";

// Function to convert time in 'HH:MM:SS' format to 'H hours M minutes'
function formatToHoursMinutes($time) {
    if ($time === null || $time === '00:00:00') {
        return '0 minutes';
    }

    $parts = explode(':', $time);
    if (count($parts) !== 3) {
        return 'Invalid time format';
    }

    $hours = intval($parts[0]);
    $minutes = intval($parts[1]);
    $formatted = '';
    
    if ($hours > 0) {
        $formatted .= $hours . ' hour' . ($hours > 1 ? 's' : '');
    }
    if ($minutes > 0) {
        if ($hours > 0) {
            $formatted .= ' and ';
        }
        $formatted .= $minutes . ' minute' . ($minutes > 1 ? 's' : '');
    }
    
    return $formatted ? $formatted : '0 minutes';
}

// Initialize result variables
$result_data = $conn->query($sql_data);
$result_aggregate_by_date = $conn->query($sql_aggregate_by_date);
$result_aggregate_by_day = $conn->query($sql_aggregate_by_day);
$result_totals = $conn->query($sql_totals);

$data = [];
$columns = [];
$aggregate_by_date = [];
$aggregate_by_day = [];
$totals = [];

// Fetch data for detailed view
if ($result_data && $result_data->num_rows > 0) {
    // Get column names
    $fields = $result_data->fetch_fields();
    foreach ($fields as $field) {
        $columns[] = $field->name;
    }
    
    // Fetch rows
    while ($row = $result_data->fetch_assoc()) {
        $filtered_row = array_slice($row, 3);  // Remove the first three columns
        $data[] = $filtered_row;
    }
} else {
    $data[] = ['error' => 'No data found for the provided username'];
}

// Fetch aggregate by date data
if ($result_aggregate_by_date && $result_aggregate_by_date->num_rows > 0) {
    while ($row = $result_aggregate_by_date->fetch_assoc()) {
        $row['average_logged_hours'] = formatToHoursMinutes($row['average_logged_hours']);
        $row['average_idle_hours'] = formatToHoursMinutes($row['average_idle_hours']);
        $row['average_productive_hours'] = formatToHoursMinutes($row['average_productive_hours']);
        $row['average_time_on_system'] = formatToHoursMinutes($row['average_time_on_system']);
        $row['average_time_away_from_system'] = formatToHoursMinutes($row['average_time_away_from_system']);
        $aggregate_by_date[] = $row;
    }
} else {
    $aggregate_by_date[] = ['date' => 'NaN', 'average_logged_hours' => 'NaN', 'average_idle_hours' => 'NaN', 'average_productive_hours' => 'NaN', 'average_time_on_system' => 'NaN', 'average_time_away_from_system' => 'NaN'];
}

// Fetch aggregate by day data
if ($result_aggregate_by_day && $result_aggregate_by_day->num_rows > 0) {
    while ($row = $result_aggregate_by_day->fetch_assoc()) {
        $row['average_logged_hours'] = formatToHoursMinutes($row['average_logged_hours']);
        $row['average_idle_hours'] = formatToHoursMinutes($row['average_idle_hours']);
        $row['average_productive_hours'] = formatToHoursMinutes($row['average_productive_hours']);
        $row['average_time_on_system'] = formatToHoursMinutes($row['average_time_on_system']);
        $row['average_time_away_from_system'] = formatToHoursMinutes($row['average_time_away_from_system']);
        $aggregate_by_day[] = $row;
    }
} else {
    $aggregate_by_day[] = ['day' => 'NaN', 'average_logged_hours' => 'NaN', 'average_idle_hours' => 'NaN', 'average_productive_hours' => 'NaN', 'average_time_on_system' => 'NaN', 'average_time_away_from_system' => 'NaN'];
}

// Fetch totals
if ($result_totals && $result_totals->num_rows > 0) {
    $totals = $result_totals->fetch_assoc();
    foreach ($totals as &$value) {
        $value = formatToHoursMinutes($value);
    }
} else {
    $totals = [
        'total_logged_hours' => 'NaN',
        'total_idle_hours' => 'NaN',
        'total_productive_hours' => 'NaN',
        'total_time_on_system' => 'NaN',
        'total_time_away_from_system' => 'NaN'
    ];
}

// Prepare the final response
$response = [
    'receivedParameters' => [
        'username' => $username
    ],
    'data' => $data,
    'columns' => array_slice($columns, 3),  // Remove the first three columns
    'aggregateByDate' => $aggregate_by_date,
    'aggregateByDay' => $aggregate_by_day,
    'totals' => $totals
];

// Send the response as JSON
echo json_encode($response);

// Close the connection
$conn->close();
?>
