<?php
// 
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);  // exits BEFORE include � no DB connection opened
}
include 'apiMain.php';

class NotificationAPI {
    private $conn;
    
    public function __construct($connection) {
        $this->conn = $connection;
    }
    
    private function sendResponse($success, $message, $data = null) {
        echo json_encode([
            'success' => $success,
            'message' => $message,
            'data' => $data
        ]);
        exit;
    }
    
    // Fetch notifications for a user
    public function getNotifications($data) {
        $empId = $data['empId'] ?? null;
        
        if (!$empId) {
            $this->sendResponse(false, 'Employee ID is required');
        }
        
        try {
            $sql = "SELECT * FROM VW_NOTIFICATIONS WHERE RECIVER = ? ORDER BY UPDATED_DATE DESC LIMIT 50";
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                $this->sendResponse(false, 'Failed to prepare statement: ' . $this->conn->error);
            }
            
            $stmt->bind_param('s', $empId);
            
            if (!$stmt->execute()) {
                $this->sendResponse(false, 'Failed to execute query: ' . $stmt->error);
            }
            
            $result = $stmt->get_result();
            $notifications = [];
            
            while ($row = $result->fetch_assoc()) {
                $notifications[] = [
                    'empId' => $row['EMPID'] ?? '',
                    'empName' => $row['EMPNAME'] ?? '',
                    'status' => $row['STATUS'] ?? '',
                    'date' => $row['DATE'] ?? '',
                    'updatedDate' => $row['UPDATED_DATE'] ?? '',
                    'timeAgo' => $row['time_ago'] ?? '',
                    'notificationType' => $row['NOTIFICATION_TYPE'] ?? '',
                    'sender' => $row['SENDER'] ?? '',
                    'senderName' => $row['SENDER_NAME'] ?? '',
                    'receiver' => $row['RECIVER'] ?? '',
                    'receiverName' => $row['RECIVER_NAME'] ?? '',
                    'isRead' => $row['IS_READ'] ?? 0,
                    'message' => $row['MESSAGE'] ?? ''
                ];
            }
            
            $stmt->close();
            
            $this->sendResponse(true, 'Notifications retrieved successfully', $notifications);
            
        } catch (Exception $e) {
            $this->sendResponse(false, 'Error: ' . $e->getMessage());
        }
    }
    
    // Get unread notification count
    public function getUnreadCount($data) {
        $empId = $data['empId'] ?? null;
        
        if (!$empId) {
            $this->sendResponse(false, 'Employee ID is required');
        }
        
        try {
            $sql = "SELECT COUNT(*) as unread_count FROM VW_NOTIFICATIONS WHERE RECIVER = ? AND IS_READ = 0";
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                $this->sendResponse(false, 'Failed to prepare statement: ' . $this->conn->error);
            }
            
            $stmt->bind_param('s', $empId);
            
            if (!$stmt->execute()) {
                $this->sendResponse(false, 'Failed to execute query: ' . $stmt->error);
            }
            
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();
            
            $this->sendResponse(true, 'Unread count retrieved successfully', [
                'unreadCount' => (int)$row['unread_count']
            ]);
            
        } catch (Exception $e) {
            $this->sendResponse(false, 'Error: ' . $e->getMessage());
        }
    }
    
    // Mark notification as read
    public function markAsRead($data) {
        $empId = $data['empId'] ?? null;
        $notificationType = $data['notificationType'] ?? null;
        $sourceDate = $data['referenceDate'] ?? null;
        $sourceEmpId = $data['sourceEmpId'] ?? null;
        $sourceUpdatedAt = $data['sourceUpdatedAt'] ?? null;
        
        if (!$empId || !$notificationType || !$sourceDate || !$sourceEmpId || !$sourceUpdatedAt) {
            $this->sendResponse(false, 'Missing required fields');
        }
        
        try {
            // According to the documentation, the unique key is:
            // (RECIVER_EMPID, NOTIFICATION_TYPE, SOURCE_DATE, SOURCE_EMPID)
            $sql = "INSERT INTO NOTIFICATION_READ_STATUS 
                    (RECIVER_EMPID, NOTIFICATION_TYPE, SOURCE_DATE, SOURCE_EMPID, SOURCE_UPDATED_AT, IS_READ, READ_AT)
                    VALUES (?, ?, ?, ?, ?, 1, NOW())
                    ON DUPLICATE KEY UPDATE
                    IS_READ = 1,
                    READ_AT = NOW()";
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                $this->sendResponse(false, 'Prepare failed: ' . $this->conn->error);
            }
            
            $stmt->bind_param('sssss', $empId, $notificationType, $sourceDate, $sourceEmpId, $sourceUpdatedAt);
            
            if (!$stmt->execute()) {
                $this->sendResponse(false, 'Execute failed: ' . $stmt->error);
            }
            
            $stmt->close();
            
            $this->sendResponse(true, 'Notification marked as read');
            
        } catch (Exception $e) {
            $this->sendResponse(false, 'Exception: ' . $e->getMessage());
        }
    }
    
    // Mark all notifications as read
    public function markAllAsRead($data) {
        $empId = $data['empId'] ?? null;
        
        if (!$empId) {
            $this->sendResponse(false, 'Employee ID is required');
        }
        
        try {
            // Use SELECT * to get all unread notifications
            $sql = "SELECT * FROM VW_NOTIFICATIONS WHERE RECIVER = ? AND IS_READ = 0";
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                $this->sendResponse(false, 'Failed to prepare statement: ' . $this->conn->error);
            }
            
            $stmt->bind_param('s', $empId);
            
            if (!$stmt->execute()) {
                $this->sendResponse(false, 'Failed to execute query: ' . $stmt->error);
            }
            
            $result = $stmt->get_result();
            $notifications = [];
            
            while ($row = $result->fetch_assoc()) {
                $notifications[] = [
                    'type' => $row['NOTIFICATION_TYPE'] ?? '',
                    'date' => $row['DATE'] ?? '',
                    'sender' => $row['SENDER'] ?? '',
                    'updated' => $row['UPDATED_DATE'] ?? ''
                ];
            }
            
            $stmt->close();
            
            // Mark each as read
            $count = 0;
            $errors = [];
            
            if (count($notifications) > 0) {
                $insertSql = "INSERT INTO NOTIFICATION_READ_STATUS
                              (RECIVER_EMPID, NOTIFICATION_TYPE, SOURCE_DATE, SOURCE_EMPID, SOURCE_UPDATED_AT, IS_READ, READ_AT)
                              VALUES (?, ?, ?, ?, ?, 1, NOW())
                              ON DUPLICATE KEY UPDATE
                              IS_READ = 1,
                              READ_AT = NOW()";
                
                $insertStmt = $this->conn->prepare($insertSql);
                if (!$insertStmt) {
                    $this->sendResponse(false, 'Failed to prepare insert: ' . $this->conn->error);
                }
                
                foreach ($notifications as $notif) {
                    if (!$notif['type'] || !$notif['date'] || !$notif['sender'] || !$notif['updated']) {
                        $errors[] = 'Missing required fields in notification';
                        continue;
                    }
                    
                    $insertStmt->bind_param('sssss', 
                        $empId, 
                        $notif['type'], 
                        $notif['date'], 
                        $notif['sender'],
                        $notif['updated']
                    );
                    
                    if ($insertStmt->execute()) {
                        $count++;
                    } else {
                        $errors[] = $insertStmt->error;
                    }
                }
                
                $insertStmt->close();
            }
            
            $message = $count . ' notifications marked as read';
            if (count($errors) > 0) {
                $message .= ' (' . count($errors) . ' errors)';
            }
            
            $this->sendResponse(true, $message, [
                'count' => $count,
                'total' => count($notifications),
                'errors' => $errors
            ]);
            
        } catch (Exception $e) {
            $this->sendResponse(false, 'Error: ' . $e->getMessage());
        }
    }
    
    public function handleRequest($data) {
        $action = $data['action'] ?? '';
        
        switch ($action) {
            case 'get_notifications':
                $this->getNotifications($data);
                break;
                
            case 'get_unread_count':
                $this->getUnreadCount($data);
                break;
                
            case 'mark_as_read':
                $this->markAsRead($data);
                break;
                
            case 'mark_all_as_read':
                $this->markAllAsRead($data);
                break;
                
            default:
                $this->sendResponse(false, 'Invalid action');
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid JSON input',
            'data' => null
        ]);
        exit;
    }
    
    $api = new NotificationAPI($conn);
    $api->handleRequest($input);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method. Only POST is allowed.',
        'data' => null
    ]);
}

$conn->close();
?>