<?php
include 'apiMain.php';

// Set timezone to IST
date_default_timezone_set('Asia/Kolkata');
$conn->query("SET time_zone = '+05:30'");

// **CONFIGURATION**
$MAX_SCHEDULES_PER_RUN = 20;
$DELAY_BETWEEN_EMAILS = 2;

// **CRITICAL: Use the full path that matches your API configuration**
$API_URL = 'https://demo.chronai.in/api/send_report_email.php';

// Get current IST date and time
$now = date('Y-m-d H:i:s');
echo "==========================================\n";
echo "Cron Job Started at: $now IST\n";
echo "==========================================\n";

// **FIRST: Reset any stuck schedules**
$timeout_sql = "UPDATE EMAIL_SCHEDULES 
                SET status = 'idle' 
                WHERE status = 'sending' 
                AND TIMESTAMPDIFF(MINUTE, last_sent, NOW()) >= 10";
$timeout_stmt = $conn->prepare($timeout_sql);
$timeout_stmt->execute();
$affected_rows = $timeout_stmt->affected_rows;
if ($affected_rows > 0) {
    echo "? Auto-reset $affected_rows stuck schedules\n";
}
$timeout_stmt->close();

// **DEBUG: Check what schedules exist and should be running**
$debug_sql = "SELECT id, report_type, next_send, status, is_active,
              CASE 
                  WHEN next_send <= NOW() THEN 'SHOULD RUN NOW'
                  ELSE CONCAT('Scheduled for ', TIMESTAMPDIFF(MINUTE, NOW(), next_send), ' minutes from now')
              END as should_run
              FROM EMAIL_SCHEDULES 
              WHERE is_active = 1 
              ORDER BY next_send ASC 
              LIMIT 10";
$debug_result = $conn->query($debug_sql);
echo "\n?? Active Schedules in Database:\n";
echo "   Current Server Time: $now\n\n";
while ($row = $debug_result->fetch_assoc()) {
    echo "   ID: {$row['id']}\n";
    echo "   Type: {$row['report_type']}\n";
    echo "   Next Send: {$row['next_send']}\n";
    echo "   Status: {$row['status']}\n";
    echo "   ? {$row['should_run']}\n";
    echo "   ---\n";
}
echo "\n";

// Find active schedules that need to be sent
$sql = "SELECT * FROM EMAIL_SCHEDULES
        WHERE is_active = 1
        AND status = 'idle'
        AND next_send <= ?
        ORDER BY next_send ASC
        LIMIT ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param('si', $now, $MAX_SCHEDULES_PER_RUN);
$stmt->execute();
$result = $stmt->get_result();

$schedules = [];
while ($row = $result->fetch_assoc()) {
    $schedules[] = $row;
}

echo "?? Found " . count($schedules) . " schedules to process NOW\n";

if (count($schedules) === 0) {
    echo "\n??  No schedules are due at this time\n";
    echo "   To debug, check if:\n";
    echo "   1. Schedule exists (see list above)\n";
    echo "   2. is_active = 1\n";
    echo "   3. status = 'idle'\n";
    echo "   4. next_send <= '$now'\n\n";
}

$processedCount = 0;
$errorCount = 0;

foreach ($schedules as $schedule) {
    echo "\n------------------------------------------\n";
    echo "?? Processing Schedule ID: {$schedule['id']}\n";
    echo "   Report Type: {$schedule['report_type']}\n";
    echo "   Scheduled Time: {$schedule['next_send']}\n";
    echo "   Recipient Type: {$schedule['recipient_type']}\n";
    echo "   Recipients: {$schedule['recipients']}\n";
    
    $userid = $schedule['created_by'] ?? 'ALL';
    echo "   Using userid: $userid\n";
    
    // Set status to 'sending' IMMEDIATELY to prevent duplicate triggers
    $updateSql = "UPDATE EMAIL_SCHEDULES SET status = 'sending', last_sent = NOW() WHERE id = ?";
    $updateStmt = $conn->prepare($updateSql);
    $updateStmt->bind_param('i', $schedule['id']);
    $updateStmt->execute();
    $updateStmt->close();
    
    echo "   ? Status set to 'sending'\n";
    
    // Call send_report_email.php
    echo "   ?? Calling: $API_URL\n";
    
    $ch = curl_init($API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'schedule_id' => $schedule['id'],
            'test_mode' => false,
            'userid' => $userid
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 120,  // 2 minutes for batch email processing
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_NOSIGNAL => 1,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_VERBOSE => true  // Enable verbose output for debugging
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    
    if ($response === false) {
        echo "   ? CURL ERROR: $curlError\n";
        echo "   HTTP Code: $httpCode\n";
        
        // Reset status to 'idle' so it can retry
        $resetSql = "UPDATE EMAIL_SCHEDULES SET status = 'idle' WHERE id = ?";
        $resetStmt = $conn->prepare($resetSql);
        $resetStmt->bind_param('i', $schedule['id']);
        $resetStmt->execute();
        $resetStmt->close();
        
        echo "   ??  Status reset to 'idle' for retry\n";
        
        $errorCount++;
    } else {
        echo "   ? HTTP Code: $httpCode\n";
        
        // Decode response to check success
        $responseData = json_decode($response, true);
        
        if ($responseData && isset($responseData['success'])) {
            if ($responseData['success']) {
                echo "   ? Email job completed successfully\n";
                if (isset($responseData['batch_info'])) {
                    $batchInfo = $responseData['batch_info'];
                    echo "   ?? Batch Progress:\n";
                    echo "      - Processed in this batch: {$batchInfo['processed_in_batch']}\n";
                    echo "      - Successful: {$batchInfo['successful_in_batch']}\n";
                    echo "      - Failed: {$batchInfo['failed_in_batch']}\n";
                    echo "      - Total sent so far: {$batchInfo['total_sent_so_far']}/{$batchInfo['total_recipients']}\n";
                    echo "      - Remaining: {$batchInfo['remaining']}\n";
                    echo "      - Complete: " . ($batchInfo['is_complete'] ? 'YES' : 'NO') . "\n";
                }
                $processedCount++;
            } else {
                echo "   ??  Email job reported failure\n";
                echo "   Error: " . ($responseData['message'] ?? 'Unknown error') . "\n";
                
                // Log the failure but don't reset - send_report_email.php handles status
                $errorCount++;
            }
        } else {
            echo "   ??  Unexpected response format\n";
            echo "   Response Preview: " . substr($response, 0, 300) . "\n";
            
            // Try to reset status since response was unexpected
            $resetSql = "UPDATE EMAIL_SCHEDULES SET status = 'idle' WHERE id = ?";
            $resetStmt = $conn->prepare($resetSql);
            $resetStmt->bind_param('i', $schedule['id']);
            $resetStmt->execute();
            $resetStmt->close();
            
            $errorCount++;
        }
    }
    
    curl_close($ch);
    
    // Wait before processing next schedule
    if ($DELAY_BETWEEN_EMAILS > 0) {
        echo "   ? Waiting {$DELAY_BETWEEN_EMAILS}s before next schedule...\n";
        sleep($DELAY_BETWEEN_EMAILS);
    }
}

// Check remaining schedules that are due
$sql = "SELECT COUNT(*) as pending FROM EMAIL_SCHEDULES
        WHERE is_active = 1 AND status = 'idle' AND next_send <= ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param('s', $now);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$remainingSchedules = $row['pending'];

// Check upcoming schedules (next 24 hours)
$tomorrow = date('Y-m-d H:i:s', strtotime('+24 hours'));
$sql = "SELECT COUNT(*) as upcoming FROM EMAIL_SCHEDULES
        WHERE is_active = 1 AND next_send > ? AND next_send <= ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param('ss', $now, $tomorrow);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$upcomingSchedules = $row['upcoming'];

echo "\n==========================================\n";
echo "?? Cron Job Summary\n";
echo "==========================================\n";
echo "Completed at: " . date('Y-m-d H:i:s') . " IST\n";
echo "? Successfully Processed: $processedCount\n";
echo "? Errors: $errorCount\n";
echo "? Still Due (remaining): $remainingSchedules\n";
echo "?? Upcoming (next 24h): $upcomingSchedules\n";
echo "==========================================\n";

$conn->close();
?>