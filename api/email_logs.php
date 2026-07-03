<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Path to the cron log file
$logFilePath = '/var/www/html/api/cron.log';

// Function to parse the log file and extract relevant information
function parseEmailLogs($logFilePath) {
    $logs = [];

    if (!file_exists($logFilePath)) {
        return $logs;
    }

    // Read the last 7 days of logs (approximately)
    $fileContent = shell_exec("tail -n 5000 " . escapeshellarg($logFilePath));

    if (empty($fileContent)) {
        return $logs;
    }

    $lines = explode("\n", $fileContent);
    $currentLog = null;

    foreach ($lines as $line) {
        $line = trim($line);

        if (empty($line)) {
            continue;
        }

        // Check if this is a "Processing Schedule ID" line (start of a new log entry)
        if (preg_match('/Processing Schedule ID:\s*(\d+)/', $line, $matches)) {
            // Save the previous log if it exists
            if ($currentLog !== null) {
                $logs[] = $currentLog;
            }

            // Start a new log entry
            $currentLog = [
                'id' => count($logs) + 1,
                'schedule_id' => $matches[1],
                'report_type' => null,
                'userid' => null,
                'scheduled_time' => null,
                'status' => 'unknown',
                'processed_at' => date('Y-m-d H:i:s')
            ];
        }

        // Extract report type
        if ($currentLog && preg_match('/Report Type:\s*(.+)/', $line, $matches)) {
            $currentLog['report_type'] = trim($matches[1]);
        }

        // Extract scheduled time
        if ($currentLog && preg_match('/Scheduled Time:\s*(.+)/', $line, $matches)) {
            $currentLog['scheduled_time'] = trim($matches[1]);
        }

        // Extract userid
        if ($currentLog && preg_match('/Using userid:\s*(.+)/', $line, $matches)) {
            $currentLog['userid'] = trim($matches[1]);
        }

        // Extract status
        if ($currentLog && preg_match('/Status set to \'(\w+)\' for schedule ID/', $line, $matches)) {
            $currentLog['status'] = $matches[1];
        }

        // Check for success
        if ($currentLog && strpos($line, 'Email job triggered successfully') !== false) {
            $currentLog['status'] = 'success';
        }

        // Check for failure indicators
        if ($currentLog && (strpos($line, 'Error:') !== false || strpos($line, 'Failed') !== false)) {
            $currentLog['status'] = 'failed';
        }

        // Check for pending status
        if ($currentLog && strpos($line, 'Status: \'pending\'') !== false) {
            $currentLog['status'] = 'pending';
        }

        // Check for processing status
        if ($currentLog && (strpos($line, 'running in background') !== false || strpos($line, 'processing') !== false)) {
            $currentLog['status'] = 'processing';
        }
    }

    // Add the last log entry if it exists
    if ($currentLog !== null) {
        $logs[] = $currentLog;
    }

    // Filter logs from the last 7 days
    $sevenDaysAgo = strtotime('-7 days');
    $filteredLogs = array_filter($logs, function($log) use ($sevenDaysAgo) {
        if ($log['scheduled_time']) {
            $logTime = strtotime($log['scheduled_time']);
            return $logTime >= $sevenDaysAgo;
        }
        return true; // Keep logs without a scheduled time
    });

    // Reverse to show most recent first
    return array_reverse(array_values($filteredLogs));
}

// Handle GET requests
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';

    if ($action === 'get_logs') {
        try {
            $logs = parseEmailLogs($logFilePath);

            echo json_encode([
                'success' => true,
                'data' => $logs,
                'message' => 'Logs retrieved successfully'
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Error reading logs: ' . $e->getMessage()
            ]);
        }
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid action'
        ]);
    }
    exit;
}

// Invalid request method
echo json_encode([
    'success' => false,
    'message' => 'Invalid request method'
]);
exit;
?>
