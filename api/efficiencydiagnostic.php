<?php
include 'apiMain.php';

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$userid = isset($_GET['userid']) ? $_GET['userid'] : NULL;
$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

$response = [
    'success' => false,
    'date' => $date,
    'userid' => $userid,
    'sample_rows' => [],
    'field_analysis' => []
];

try {
    $departments = 'ALL';
    $roles = 'ALL';
    $projects = 'ALL';
    $shifts = 'ALL';
    $teams = 'ALL';
    $ids = 'ALL';
    $names = 'ALL';
    $designations = 'ALL';
    $reportType = 'GROUP_REPORT';
    
    if ($stmt = $conn->prepare("CALL PR_EMPLOYEE_ACTIVITY_FLAT(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")) {
        $stmt->bind_param('ssssssssssss', $date, $date, $ids, $names, $departments, $roles, $designations, $projects, $shifts, $teams, $userid, $reportType);
        
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            
            if ($result) {
                $rowCount = 0;
                $allFields = [];
                
                while ($row = $result->fetch_assoc()) {
                    // Store first 3 rows as samples
                    if ($rowCount < 3) {
                        $response['sample_rows'][] = $row;
                    }
                    
                    // Collect all field names
                    foreach (array_keys($row) as $fieldName) {
                        if (!isset($allFields[$fieldName])) {
                            $allFields[$fieldName] = [
                                'field_name' => $fieldName,
                                'sample_values' => [],
                                'non_empty_count' => 0,
                                'empty_count' => 0
                            ];
                        }
                        
                        $value = $row[$fieldName];
                        if (!empty($value) && $value !== '00:00:00' && $value !== '0') {
                            $allFields[$fieldName]['non_empty_count']++;
                            if (count($allFields[$fieldName]['sample_values']) < 3) {
                                $allFields[$fieldName]['sample_values'][] = $value;
                            }
                        } else {
                            $allFields[$fieldName]['empty_count']++;
                        }
                    }
                    
                    $rowCount++;
                }
                
                $result->free();
                
                $response['total_rows'] = $rowCount;
                $response['field_analysis'] = array_values($allFields);
                $response['success'] = true;
                
                // Identify likely time fields
                $timeFields = [];
                foreach ($allFields as $field) {
                    if ($field['non_empty_count'] > 0) {
                        foreach ($field['sample_values'] as $sampleValue) {
                            if (preg_match('/^\d{1,2}:\d{2}:\d{2}$/', $sampleValue)) {
                                $timeFields[] = $field['field_name'];
                                break;
                            }
                        }
                    }
                }
                $response['identified_time_fields'] = $timeFields;
            }
        }
        $stmt->close();
    }
    
} catch (Exception $e) {
    $response['error'] = $e->getMessage();
}

$conn->close();
echo json_encode($response, JSON_PRETTY_PRINT);
?>