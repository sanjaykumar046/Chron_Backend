<?php
// leave.php
include 'apiMain.php';

class LeaveAPI {
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
    
    public function getLeaveApplications($data) {
        // ? FIX: Allow empty dates to fetch ALL records, not just today's
        $startDate = $data['startDate'] ?? null;
        $endDate = $data['endDate'] ?? null;
        $empId = $data['empId'] ?? 'ALL';
        $leaveType = $data['leaveType'] ?? 'ALL';
        $status = $data['status'] ?? 'ALL';
        $userId = $data['userId'] ?? null;
        $role = $data['role'] ?? 'EXECUTIVE';
        
        try {
            $sql = "SELECT 
                        LEAVE_ID,
                        EMP_ID,
                        EMP_NAME,
                        LEAVE_TYPE,
                        FROM_DATE,
                        TO_DATE,
                        TOTAL_DAYS,
                        REASON,
                        STATUS,
                        LEAVE_APPLIED_BY,
                        APPROVE_REJECT_BEFORE,
                        APPROVED_REJECTED_BY,
                        APPROVED_REJECTED_AT,
                        COMMENTS,
                        CREATED_AT
                    FROM LEAVE_TRACKER
                    WHERE 1=1"; // Always true, allows dynamic conditions
            
            $params = [];
            $types = '';
            
            // ? FIX: Only add date filter if dates are provided
            if ($startDate && $endDate) {
                // Show leaves that overlap with the date range
                $sql .= " AND (FROM_DATE <= ? AND TO_DATE >= ?)";
                $params[] = $endDate;
                $params[] = $startDate;
                $types .= 'ss';
            }
            
            if ($empId !== 'ALL') {
                $sql .= " AND EMP_ID = ?";
                $params[] = $empId;
                $types .= 's';
            }
            
            if ($leaveType !== 'ALL') {
                $sql .= " AND LEAVE_TYPE = ?";
                $params[] = $leaveType;
                $types .= 's';
            }
            
            if ($status !== 'ALL') {
                $sql .= " AND STATUS = ?";
                $params[] = $status;
                $types .= 's';
            }
            
            // ? Optional: Add user-based filtering based on role
            if ($userId && $role !== 'ADMIN') {
                // Regular users can only see their own leaves
                $sql .= " AND (EMP_ID = ? OR LEAVE_APPLIED_BY = ?)";
                $params[] = $userId;
                $params[] = $userId;
                $types .= 'ss';
            }
            
            $sql .= " ORDER BY CREATED_AT DESC";
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                $this->sendResponse(false, 'Failed to prepare statement: ' . $this->conn->error);
            }
            
            // Only bind if there are parameters
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            
            if (!$stmt->execute()) {
                $this->sendResponse(false, 'Failed to execute query: ' . $stmt->error);
            }
            
            $result = $stmt->get_result();
            $records = [];
            
            while ($row = $result->fetch_assoc()) {
                $records[] = [
                    'leaveId' => $row['LEAVE_ID'] ?? '',
                    'empId' => $row['EMP_ID'] ?? '',
                    'empName' => $row['EMP_NAME'] ?? '',
                    'leaveType' => $row['LEAVE_TYPE'] ?? '',
                    'fromDate' => $row['FROM_DATE'] ?? '',
                    'toDate' => $row['TO_DATE'] ?? '',
                    'totalDays' => $row['TOTAL_DAYS'] ?? '',
                    'reason' => $row['REASON'] ?? '',
                    'status' => $row['STATUS'] ?? '',
                    'leaveAppliedBy' => $row['LEAVE_APPLIED_BY'] ?? '',
                    'approveRejectBefore' => $row['APPROVE_REJECT_BEFORE'] ?? '',
                    'approvedRejectedBy' => $row['APPROVED_REJECTED_BY'] ?? '',
                    'approvedRejectedAt' => $row['APPROVED_REJECTED_AT'] ?? '',
                    'comments' => $row['COMMENTS'] ?? '',
                    'createdAt' => $row['CREATED_AT'] ?? ''
                ];
            }
            
            $stmt->close();
            
            $this->sendResponse(true, 'Leave applications retrieved successfully', $records);
            
        } catch (Exception $e) {
            $this->sendResponse(false, 'Error: ' . $e->getMessage());
        }
    }
    
    public function applyLeave($data) {
    $empId = $data['empId'] ?? null;
    $empName = $data['empName'] ?? null;
    $leaveType = $data['leaveType'] ?? null;
    $fromDate = $data['fromDate'] ?? null;
    $toDate = $data['toDate'] ?? null;
    $totalDays = $data['totalDays'] ?? null;
    $reason = $data['reason'] ?? '';
    
    if (!$empId || !$empName || !$leaveType || !$fromDate || !$toDate || !$totalDays) {
        $this->sendResponse(false, 'All fields are required');
    }
    
    try {
        // Check for overlapping leaves
        $overlapSql = "SELECT COUNT(*) AS overlap_count
                       FROM LEAVE_TRACKER 
                       WHERE EMP_ID = ?
                       AND STATUS IN ('PENDING APPROVAL', 'APPROVED')
                       AND (
                           ? <= TO_DATE
                           AND ? >= FROM_DATE
                       )";
        
        $overlapStmt = $this->conn->prepare($overlapSql);
        if (!$overlapStmt) {
            $this->sendResponse(false, 'Failed to prepare overlap check: ' . $this->conn->error);
        }
        
        $overlapStmt->bind_param('sss', $empId, $fromDate, $toDate);
        
        if (!$overlapStmt->execute()) {
            $this->sendResponse(false, 'Failed to check overlap: ' . $overlapStmt->error);
        }
        
        $overlapResult = $overlapStmt->get_result();
        $overlapRow = $overlapResult->fetch_assoc();
        $overlapStmt->close();
        
        if ($overlapRow['overlap_count'] > 0) {
            $this->sendResponse(false, 'Leave dates overlap with an existing leave application. Please check your existing leaves and choose different dates.');
        }
        
        // Calculate APPROVE_REJECT_BEFORE (TO_DATE + 18:29:00 IST)
        $approveRejectBefore = $toDate . ' 18:29:00';
        
        // Insert new leave application
        $sql = "INSERT INTO LEAVE_TRACKER 
                (CREATED_AT, UPDATED_AT, EMP_ID, EMP_NAME, LEAVE_TYPE, FROM_DATE, TO_DATE, 
                 TOTAL_DAYS, REASON, STATUS, LEAVE_APPLIED_BY, APPROVE_REJECT_BEFORE)
                VALUES 
                (NOW(), NOW(), ?, ?, ?, ?, ?, ?, ?, 'PENDING APPROVAL', ?, ?)";
        
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            $this->sendResponse(false, 'Failed to prepare statement: ' . $this->conn->error);
        }
        
        $stmt->bind_param('sssssdsss', $empId, $empName, $leaveType, $fromDate, $toDate, 
                         $totalDays, $reason, $empId, $approveRejectBefore);
        
        if (!$stmt->execute()) {
            $this->sendResponse(false, 'Failed to apply leave: ' . $stmt->error);
        }
        
        $stmt->close();
        
        $this->sendResponse(true, 'Leave application submitted successfully', [
            'leaveId' => $this->conn->insert_id
        ]);
        
    } catch (Exception $e) {
        $this->sendResponse(false, 'Error: ' . $e->getMessage());
    }
}
    
    public function updateLeaveStatus($data) {
    $leaveId = $data['leaveId'] ?? null;
    $status = $data['status'] ?? null;
    $updatedBy = $data['updatedBy'] ?? null;
    $comments = $data['comments'] ?? null;
    
    if (!$leaveId || !$status || !$updatedBy) {
        $this->sendResponse(false, 'Leave ID, status, and updater are required');
    }
    
    if (!in_array($status, ['APPROVED', 'REJECTED'])) {
        $this->sendResponse(false, 'Invalid status. Must be APPROVED or REJECTED');
    }
    
    try {
        // If rejecting, comments are required
        if ($status === 'REJECTED' && empty($comments)) {
            $this->sendResponse(false, 'Rejection reason is required');
        }
        
        // Build SQL based on whether comments exist
        if (!empty($comments)) {
            $sql = "UPDATE LEAVE_TRACKER 
                    SET STATUS = ?, 
                        APPROVED_REJECTED_BY = ?, 
                        APPROVED_REJECTED_AT = NOW(),
                        UPDATED_AT = NOW(),
                        COMMENTS = ?
                    WHERE LEAVE_ID = ?";
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                $this->sendResponse(false, 'Failed to prepare statement: ' . $this->conn->error);
            }
            
            $stmt->bind_param('sssi', $status, $updatedBy, $comments, $leaveId);
        } else {
            $sql = "UPDATE LEAVE_TRACKER 
                    SET STATUS = ?, 
                        APPROVED_REJECTED_BY = ?, 
                        APPROVED_REJECTED_AT = NOW(),
                        UPDATED_AT = NOW()
                    WHERE LEAVE_ID = ?";
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                $this->sendResponse(false, 'Failed to prepare statement: ' . $this->conn->error);
            }
            
            $stmt->bind_param('ssi', $status, $updatedBy, $leaveId);
        }
        
        if (!$stmt->execute()) {
            $this->sendResponse(false, 'Failed to update leave status: ' . $stmt->error);
        }
        
        $affectedRows = $stmt->affected_rows;
        $stmt->close();
        
        if ($affectedRows > 0) {
            $statusMessage = $status === 'APPROVED' ? 'approved' : 'rejected';
            $this->sendResponse(true, "Leave application {$statusMessage} successfully");
        } else {
            $this->sendResponse(false, 'No records updated');
        }
        
    } catch (Exception $e) {
        $this->sendResponse(false, 'Error: ' . $e->getMessage());
    }
}
    
    public function withdrawLeave($data) {
        $leaveId = $data['leaveId'] ?? null;
        $empId = $data['empId'] ?? null;
        
        if (!$leaveId || !$empId) {
            $this->sendResponse(false, 'Leave ID and Employee ID are required');
        }
        
        try {
            // First check if the leave exists and belongs to the employee
            $checkSql = "SELECT LEAVE_ID, EMP_ID, STATUS FROM LEAVE_TRACKER 
                        WHERE LEAVE_ID = ? AND EMP_ID = ?";
            $checkStmt = $this->conn->prepare($checkSql);
            
            if (!$checkStmt) {
                $this->sendResponse(false, 'Failed to prepare check statement: ' . $this->conn->error);
            }
            
            $checkStmt->bind_param('is', $leaveId, $empId);
            
            if (!$checkStmt->execute()) {
                $this->sendResponse(false, 'Failed to check leave: ' . $checkStmt->error);
            }
            
            $result = $checkStmt->get_result();
            $leave = $result->fetch_assoc();
            $checkStmt->close();
            
            if (!$leave) {
                $this->sendResponse(false, 'Leave application not found or you do not have permission to withdraw it');
            }
            
            if ($leave['STATUS'] !== 'PENDING APPROVAL') {
                $this->sendResponse(false, 'Cannot withdraw. Leave is already ' . strtolower($leave['STATUS']));
            }
            
            // Now perform the withdrawal
            $sql = "UPDATE LEAVE_TRACKER 
                    SET STATUS = 'WITHDRAWN', 
                        APPROVED_REJECTED_BY = ?,
                        APPROVED_REJECTED_AT = NOW(),
                        UPDATED_AT = NOW(),
                        COMMENTS = 'Withdrawn by employee'
                    WHERE LEAVE_ID = ?";
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                $this->sendResponse(false, 'Failed to prepare statement: ' . $this->conn->error);
            }
            
            $stmt->bind_param('si', $empId, $leaveId);
            
            if (!$stmt->execute()) {
                $this->sendResponse(false, 'Failed to withdraw leave: ' . $stmt->error);
            }
            
            $affectedRows = $stmt->affected_rows;
            $stmt->close();
            
            if ($affectedRows > 0) {
                $this->sendResponse(true, 'Leave application withdrawn successfully');
            } else {
                $this->sendResponse(false, 'Failed to withdraw leave. Please try again.');
            }
            
        } catch (Exception $e) {
            $this->sendResponse(false, 'Error: ' . $e->getMessage());
        }
    }
    
    public function handleRequest($data) {
        $action = $data['action'] ?? '';
        
        switch ($action) {
            case 'get_leave_applications':
                $this->getLeaveApplications($data);
                break;
                
            case 'apply_leave':
                $this->applyLeave($data);
                break;
                
            case 'update_leave_status':
                $this->updateLeaveStatus($data);
                break;
                
            case 'withdraw_leave':
                $this->withdrawLeave($data);
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
    
    $api = new LeaveAPI($conn);
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