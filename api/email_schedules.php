<?php
include 'apiMain.php';
header('Content-Type: application/json');

// Set timezone to IST
date_default_timezone_set('Asia/Kolkata');

$data = json_decode(file_get_contents('php://input'), true);
$action = $data['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'list':
        $userid = $_GET['userid'] ?? '';
        $sql = "SELECT * FROM EMAIL_SCHEDULES ORDER BY created_at DESC";
        $result = $conn->query($sql);
        
        $schedules = [];
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $schedules[] = $row;
            }
        }
        
        echo json_encode(['success' => true, 'data' => $schedules]);
        break;
        
    case 'create':
        $report_type = $data['report_type'];
        $frequency_days = $data['frequency_days'];
        $send_time = $data['send_time'];
        $recipient_type = $data['recipient_type'];
        $recipients = $data['recipients'];
        $created_by = $data['created_by'];
        $target_empid = $data['target_empid'] ?? $created_by;
        $recipient_config = $data['recipient_config'] ?? null;

        // DEBUG: Log what we received
        error_log("CREATE SCHEDULE - Received data: created_by=$created_by, target_empid from request=" . ($data['target_empid'] ?? 'NOT SET') . ", recipient_config=" . ($recipient_config ?? 'NULL'));

        // Check for duplicates - same report_type, frequency, and overlapping recipients
        if ($recipient_type === 'email') {
            $recipientArray = array_map('trim', explode(',', $recipients));
            foreach ($recipientArray as $email) {
                $checkSql = "SELECT id FROM EMAIL_SCHEDULES
                            WHERE report_type = ?
                            AND frequency_days = ?
                            AND is_active = 1
                            AND FIND_IN_SET(?, REPLACE(recipients, ' ', ''))";
                $checkStmt = $conn->prepare($checkSql);
                $checkStmt->bind_param('sis', $report_type, $frequency_days, $email);
                $checkStmt->execute();
                $result = $checkStmt->get_result();

                if ($result->num_rows > 0) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'A schedule already exists for this email (' . $email . ') with the same report type and frequency. Please edit the existing schedule instead.',
                        'isDuplicate' => true
                    ]);
                    exit;
                }
            }
        }

        // Calculate next send time in IST
        $currentDate = date('Y-m-d');
        $currentTime = date('H:i:s');

        // If send_time has already passed today, schedule for tomorrow
        if ($send_time <= $currentTime) {
            $next_send = date('Y-m-d H:i:s', strtotime('+1 day ' . $send_time));
        } else {
            $next_send = $currentDate . ' ' . $send_time;
        }

        $sql = "INSERT INTO EMAIL_SCHEDULES (report_type, frequency_days, send_time, recipient_type, recipients, target_empid, recipient_config, created_by, is_active, next_send, status, batch_offset, batch_size)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, 'idle', 0, 50)";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param('sisssssss', $report_type, $frequency_days, $send_time, $recipient_type, $recipients, $target_empid, $recipient_config, $created_by, $next_send);

        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Schedule created successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to create schedule: ' . $stmt->error]);
        }
        break;
        
    case 'update':
        $schedule_id = $data['schedule_id'];
        $report_type = $data['report_type'];
        $frequency_days = $data['frequency_days'];
        $send_time = $data['send_time'];
        $recipient_type = $data['recipient_type'];
        $recipients = $data['recipients'];
        $target_empid = $data['target_empid'] ?? null;
        $recipient_config = $data['recipient_config'] ?? null;

        // Recalculate next send time in IST
        $currentDate = date('Y-m-d');
        $currentTime = date('H:i:s');

        if ($send_time <= $currentTime) {
            $next_send = date('Y-m-d H:i:s', strtotime('+1 day ' . $send_time));
        } else {
            $next_send = $currentDate . ' ' . $send_time;
        }

        $sql = "UPDATE EMAIL_SCHEDULES
                SET report_type = ?, frequency_days = ?, send_time = ?, recipient_type = ?, recipients = ?, target_empid = ?, recipient_config = ?, next_send = ?
                WHERE id = ?";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param('sissssssi', $report_type, $frequency_days, $send_time, $recipient_type, $recipients, $target_empid, $recipient_config, $next_send, $schedule_id);

        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Schedule updated successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update schedule: ' . $stmt->error]);
        }
        break;
        
    case 'delete':
        $schedule_id = $data['schedule_id'];
        
        $sql = "DELETE FROM EMAIL_SCHEDULES WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $schedule_id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Schedule deleted successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to delete schedule: ' . $stmt->error]);
        }
        break;
        
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

$conn->close();
?>