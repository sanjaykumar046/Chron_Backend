<?php

include 'apiMain.php';
// Date range parameters
$endDate = isset($_GET['endDate']) ? $_GET['endDate'] : date('Y-m-d', strtotime('yesterday'));
$startDate = isset($_GET['startDate']) ? $_GET['startDate'] : date('Y-m-d', strtotime('yesterday - 6 days'));

$aggregate_data_by_team = [];

// Update this query according to your actual column names
if ($stmt = $conn->prepare("SELECT Team, 
    SUM(TotalLoggedHours) AS TotalLoggedHours, 
    SUM(TotalIdleHours) AS TotalIdleHours, 
    SUM(TotalProductiveHours) AS TotalProductiveHours, 
    SUM(TOTAL_ON_SYSTEM) AS TOTAL_ON_SYSTEM, 
    SUM(TotalMeetings) AS TotalMeetings, 
    SUM(TotalBreaks) AS TotalBreaks, 
    COUNT(*) AS count 
FROM EMP_DB 
WHERE Date BETWEEN ? AND ? 
GROUP BY Team")) {
    
    $stmt->bind_param('ss', $startDate, $endDate);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $team = $row['Team'];
        $aggregate_data_by_team[$team] = [
            'TotalLoggedHours' => $row['TotalLoggedHours'],
            'TotalIdleHours' => $row['TotalIdleHours'],
            'TotalProductiveHours' => $row['TotalProductiveHours'],
            'TOTAL_ON_SYSTEM' => $row['TOTAL_ON_SYSTEM'],
            'TotalMeetings' => $row['TotalMeetings'],
            'TotalBreaks' => $row['TotalBreaks'],
            'count' => $row['count'],
        ];
    }

    $stmt->close();
} else {
    die("Failed to prepare statement.");
}

// Return JSON response
echo json_encode($aggregate_data_by_team);

// Close the connection
$conn->close();
