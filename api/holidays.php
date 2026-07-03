<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Include database connection
require_once 'apiMain.php';

// Handle sample download before database connection
if (isset($_GET['action']) && $_GET['action'] === 'download_sample') {
    downloadSample();
    exit;
}

// Use the connection from apiMain.php
if (!isset($conn)) {
    echo json_encode(['success' => false, 'message' => 'Database connection not available']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

switch ($action) {
    case 'get_holidays':
        getHolidays($conn, $input);
        break;
    
    case 'upload_csv':
        uploadCSV($conn);
        break;
    
    case 'download_sample':
        downloadSample();
        break;
    
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        break;
}

function getHolidays($conn, $input) {
    try {
        $fromDate = $input['from_date'] ?? null;
        $toDate = $input['to_date'] ?? null;
        $shift = $input['shift'] ?? 'ALL';
        
        // Call stored procedure using mysqli
        $sql = "CALL PR_GET_HOLIDAY_MASTER(?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sss", $fromDate, $toDate, $shift);
        $stmt->execute();
        
        $result = $stmt->get_result();
        $holidays = [];
        
        while ($row = $result->fetch_assoc()) {
            $holidays[] = $row;
        }
        
        $stmt->close();
        
        echo json_encode([
            'success' => true,
            'data' => $holidays
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error fetching holidays: ' . $e->getMessage()
        ]);
    }
}

function uploadCSV($conn) {
    try {
        if (!isset($_FILES['csv_file'])) {
            echo json_encode(['success' => false, 'message' => 'No file uploaded']);
            return;
        }

        $file = $_FILES['csv_file'];
        
        // Validate file type
        $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($fileExt !== 'csv') {
            echo json_encode(['success' => false, 'message' => 'Only CSV files are allowed']);
            return;
        }

        // Read CSV file
        $handle = fopen($file['tmp_name'], 'r');
        if ($handle === false) {
            echo json_encode(['success' => false, 'message' => 'Failed to open CSV file']);
            return;
        }

        // Skip header row
        fgetcsv($handle);
        
        $successCount = 0;
        $errorCount = 0;
        $errors = [];

        $conn->begin_transaction();

        while (($data = fgetcsv($handle)) !== false) {
            if (count($data) < 4) {
                $errorCount++;
                $errors[] = "Row skipped: insufficient columns";
                continue;
            }

            $holidayDate = trim($data[0]);
            $holidayName = trim($data[1]);
            $holidayType = trim($data[2]);
            $description = trim($data[3]);
            $shift = isset($data[4]) ? trim($data[4]) : 'ALL';

            // Validate date format
            $dateObj = DateTime::createFromFormat('Y-m-d', $holidayDate);
            if (!$dateObj || $dateObj->format('Y-m-d') !== $holidayDate) {
                $errorCount++;
                $errors[] = "Invalid date format for: $holidayName";
                continue;
            }

            try {
                // Insert into holiday table
                $sql = "INSERT INTO holiday_master (holiday_date, holiday_name, holiday_type, description, shift_name, created_at) 
                        VALUES (?, ?, ?, ?, ?, NOW())
                        ON DUPLICATE KEY UPDATE 
                        holiday_name = VALUES(holiday_name),
                        holiday_type = VALUES(holiday_type),
                        description = VALUES(description),
                        shift_name = VALUES(shift_name)";
                
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("sssss", $holidayDate, $holidayName, $holidayType, $description, $shift);
                $stmt->execute();
                $stmt->close();
                $successCount++;
            } catch (Exception $e) {
                $errorCount++;
                $errors[] = "Error inserting $holidayName: " . $e->getMessage();
            }
        }

        fclose($handle);
        $conn->commit();

        echo json_encode([
            'success' => true,
            'message' => "Upload completed. Success: $successCount, Errors: $errorCount",
            'details' => [
                'success_count' => $successCount,
                'error_count' => $errorCount,
                'errors' => $errors
            ]
        ]);

    } catch (Exception $e) {
        if ($conn->connect_errno === 0) {
            $conn->rollback();
        }
        echo json_encode([
            'success' => false,
            'message' => 'Upload failed: ' . $e->getMessage()
        ]);
    }
}

function downloadSample() {
    $filename = 'holiday_sample.csv';
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    
    // Header
    fputcsv($output, ['holiday_date', 'holiday_name', 'holiday_type', 'description', 'shift_name']);
    
    // Sample data
    fputcsv($output, ['2026-01-26', 'Republic Day', 'Public', 'National Holiday', 'ALL']);
    fputcsv($output, ['2026-03-14', 'Holi', 'Public', 'Festival of Colors', 'ALL']);
    fputcsv($output, ['2026-08-15', 'Independence Day', 'Public', 'National Holiday', 'ALL']);
    fputcsv($output, ['2026-10-02', 'Gandhi Jayanti', 'Public', 'National Holiday', 'ALL']);
    fputcsv($output, ['2026-12-25', 'Christmas', 'Public', 'Christmas Day', 'ALL']);
    
    fclose($output);
    exit;
}
?>