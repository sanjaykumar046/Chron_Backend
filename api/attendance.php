<?php
include 'apiMain.php';

class AttendanceAPI {
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
    
   public function getAttendance($data) {
    $startDate = $data['startDate'] ?? date('Y-m-d');
    $endDate = $data['endDate'] ?? date('Y-m-d');
    $userId = $data['userId'] ?? 'ALL';
    $role = $data['role'] ?? 'EXECUTIVE';

    // Admin/Leadership/Executive should see ALL employees, not just their own EMPID
    $empIdParam = in_array($role, ['ADMIN', 'LEADERSHIP', 'EXECUTIVE']) ? 'ALL' : $userId;
    
    try {
       $sql = "CALL PR_GET_ATTENDANCE_REPORT(
    ?, ?, ?, 'ALL', 'ALL', 'ALL', 'ALL', ?
);";

$stmt = $this->conn->prepare($sql);

if (!$stmt) {
    $this->sendResponse(false, 'Failed to prepare statement: ' . $this->conn->error);
}

$stmt->bind_param(
    'ssss',
    $startDate,
    $endDate,
    $empIdParam,   // P_EMPID
    $userId        // P_USER_ID
);
            
            if (!$stmt->execute()) {
                $this->sendResponse(false, 'Failed to execute query: ' . $stmt->error);
            } 
            
            $result = $stmt->get_result();
            $records = [];
            
            while ($row = $result->fetch_assoc()) {
                $records[] = [
                    'attendanceId' => $row['ATTENDANCE_ID'] ?? '',
                    'date' => $row['WORK_DATE'] ?? ($row['ATTENDANCE_DATE'] ?? ''),
                    'empId' => $row['EMPID'] ?? '',
                    'name' => $row['EMPNAME'] ?? ($row['NAME'] ?? ''),
                    'shift' => $row['SHIFT_NAME'] ?? '',
                    'shiftName' => $row['SHIFT_NAME'] ?? '',
                    'empShift' => $row['EMP_SHIFT'] ?? '',
                    'shiftTime' => $row['EMP_SHIFT'] ?? '',
                    'status' => $row['ATTENDANCE_STATUS'] ?? '',
                    'attendanceStatus' => $row['ATTENDANCE_STATUS'] ?? '',
                    'workTime' => $row['TOTAL_LOGGED_HOURS'] ?? '',
                    'regularisationAttempts' => $row['REGULARISATION_ATTEMPTS'] ?? 0,
                    'regularisationStatus' => $row['REGULARISATION_STATUS'] ?? '',
                    'requestedAttendanceStatus' => $row['REQUESTED_ATTENDANCE_STATUS'] ?? '',
                    'createdDt' => $row['CREATED_DT'] ?? null,
                    'updatedDt' => $row['UPDATED_DT'] ?? null
                ];
            }
            
            $stmt->close();
            $this->conn->next_result();
            
            $this->sendResponse(true, 'Attendance data retrieved successfully', $records);
            
        } catch (Exception $e) {
            $this->sendResponse(false, 'Error: ' . $e->getMessage());
        }
    }
    
    public function disputeAttendance($data) {
        $attendanceId = $data['attendanceId'] ?? null;
        $empId = $data['empId'] ?? null;
        $workDate = $data['workDate'] ?? null;
        $shiftName = $data['shiftName'] ?? null;
        $empShift = $data['empShift'] ?? null;
        $requestedAttendanceStatus = $data['requestedAttendanceStatus'] ?? null;
        $updatedBy = $data['updatedBy'] ?? 'SYSTEM';
        
        if (!$attendanceId) {
            $this->sendResponse(false, 'Attendance ID is required');
        }
        
        if (!$shiftName || !$empShift || !$requestedAttendanceStatus) {
            $this->sendResponse(false, 'All fields are required');
        }
        
        try {
            // Check current status
            $checkSql = "SELECT REGULARISATION_STATUS FROM ATTENDANCE_REPORT WHERE ATTENDANCE_ID = ?";
            $checkStmt = $this->conn->prepare($checkSql);
            $checkStmt->bind_param('i', $attendanceId);
            $checkStmt->execute();
            $result = $checkStmt->get_result();
            $row = $result->fetch_assoc();
            $checkStmt->close();
            
            if ($row && $row['REGULARISATION_STATUS'] === 'DISPUTED') {
                $this->sendResponse(false, 'A dispute is already pending for this record. Please wait for it to be processed before submitting a new dispute.');
            }
            
            // Update to DISPUTED status
            $sql = "UPDATE ATTENDANCE_REPORT 
                    SET SHIFT_NAME = ?, 
                        EMP_SHIFT = ?, 
                        REQUESTED_ATTENDANCE_STATUS = ?,
                        REGULARISATION_STATUS = 'DISPUTED',
                        UPDATE_TYPE = 'dispute_raised',
                        UPDATED_BY = ?,
                        UPDATED_DT = UTC_TIMESTAMP()
                    WHERE ATTENDANCE_ID = ?";
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                $this->sendResponse(false, 'Failed to prepare statement: ' . $this->conn->error);
            }
            
            $stmt->bind_param('ssssi', $shiftName, $empShift, $requestedAttendanceStatus, $updatedBy, $attendanceId);
            
            if (!$stmt->execute()) {
                $this->sendResponse(false, 'Failed to submit dispute: ' . $stmt->error);
            }
            
            $affectedRows = $stmt->affected_rows;
            $stmt->close();
            
            if ($affectedRows > 0) {
                $this->sendResponse(true, 'Dispute submitted successfully', [
                    'affectedRows' => $affectedRows
                ]);
            } else {
                $this->sendResponse(false, 'No records updated');
            }
            
        } catch (Exception $e) {
            $this->sendResponse(false, 'Error: ' . $e->getMessage());
        }
    }
    
    public function overrideAttendance($data) {
        $attendanceId = $data['attendanceId'] ?? null;
        $empId = $data['empId'] ?? null;
        $workDate = $data['workDate'] ?? null;
        $shiftName = $data['shiftName'] ?? null;
        $empShift = $data['empShift'] ?? null;
        $requestedAttendanceStatus = $data['requestedAttendanceStatus'] ?? null;
        $updatedBy = $data['updatedBy'] ?? 'SYSTEM';
        
        if (!$attendanceId) {
            $this->sendResponse(false, 'Attendance ID is required');
        }
        
        if (!$shiftName || !$empShift || !$requestedAttendanceStatus) {
            $this->sendResponse(false, 'All fields are required');
        }
        
        try {
            // Direct override with OVERRIDDEN status
            $sql = "UPDATE ATTENDANCE_REPORT 
                    SET SHIFT_NAME = ?, 
                        EMP_SHIFT = ?, 
                        ATTENDANCE_STATUS = ?,
                        REGULARISATION_STATUS = 'OVERRIDDEN',
                        REQUESTED_ATTENDANCE_STATUS = ?,
                        UPDATE_TYPE = 'override_by_manager',
                        UPDATED_BY = ?,
                        UPDATED_DT = UTC_TIMESTAMP()
                    WHERE ATTENDANCE_ID = ?";
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                $this->sendResponse(false, 'Failed to prepare statement: ' . $this->conn->error);
            }
            
            $stmt->bind_param('sssssi', $shiftName, $empShift, $requestedAttendanceStatus, 
                             $requestedAttendanceStatus, $updatedBy, $attendanceId);
            
            if (!$stmt->execute()) {
                $this->sendResponse(false, 'Failed to override attendance: ' . $stmt->error);
            }
            
            $affectedRows = $stmt->affected_rows;
            $stmt->close();
            
            if ($affectedRows > 0) {
                $this->sendResponse(true, 'Attendance overridden successfully', [
                    'affectedRows' => $affectedRows,
                    'type' => 'override'
                ]);
            } else {
                $this->sendResponse(false, 'No records updated');
            }
            
        } catch (Exception $e) {
            $this->sendResponse(false, 'Error: ' . $e->getMessage());
        }
    }
    
    public function getPendingDisputes($data) {
        $userId = $data['userId'] ?? 'ALL';
        $role = $data['role'] ?? 'EXECUTIVE';
        
        try {
            $sql = "SELECT 
                        ar.ATTENDANCE_ID as attendanceId,
                        ar.EMPID as empId,
                        ar.EMPNAME as name,
                        ar.WORK_DATE as workDate,
                        ar.ATTENDANCE_STATUS as currentStatus,
                        ar.REQUESTED_ATTENDANCE_STATUS as requestedStatus,
                        ar.SHIFT_NAME as shiftName,
                        ar.EMP_SHIFT as empShift,
                        ar.REGULARISATION_STATUS as regularisationStatus,
                        ar.UPDATED_DT as requestedDate
                    FROM ATTENDANCE_REPORT ar
                    WHERE ar.REGULARISATION_STATUS = 'DISPUTED'";
            
            // For LEADERSHIP role, we can't filter by team as we don't have leadership info
            // ADMIN sees all disputes, LEADERSHIP will also see all for now
            // The stored procedure should handle the filtering based on userId and role
            
            $sql .= " ORDER BY ar.UPDATED_DT DESC";
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                $this->sendResponse(false, 'Failed to prepare statement: ' . $this->conn->error);
            }
            
            if (!$stmt->execute()) {
                $this->sendResponse(false, 'Failed to execute query: ' . $stmt->error);
            }
            
            $result = $stmt->get_result();
            $records = [];
            
            while ($row = $result->fetch_assoc()) {
                $records[] = $row;
            }
            
            $stmt->close();
            
            $this->sendResponse(true, 'Pending disputes retrieved successfully', $records);
            
        } catch (Exception $e) {
            $this->sendResponse(false, 'Error: ' . $e->getMessage());
        }
    }
    
    public function approveDispute($data) {
        $attendanceId = $data['attendanceId'] ?? null;
        $approvedBy = $data['approvedBy'] ?? 'SYSTEM';
        
        if (!$attendanceId) {
            $this->sendResponse(false, 'Attendance ID is required');
        }
        
        try {
            // Get requested status
            $getSql = "SELECT REQUESTED_ATTENDANCE_STATUS FROM ATTENDANCE_REPORT WHERE ATTENDANCE_ID = ?";
            $getStmt = $this->conn->prepare($getSql);
            $getStmt->bind_param('i', $attendanceId);
            $getStmt->execute();
            $result = $getStmt->get_result();
            $row = $result->fetch_assoc();
            $requestedStatus = $row['REQUESTED_ATTENDANCE_STATUS'] ?? null;
            $getStmt->close();
            
            if (!$requestedStatus) {
                $this->sendResponse(false, 'No requested status found');
            }
            
            // Update to APPROVED status
            $sql = "UPDATE ATTENDANCE_REPORT 
                    SET ATTENDANCE_STATUS = ?,
                        REGULARISATION_STATUS = 'APPROVED',
                        UPDATE_TYPE = 'dispute_approved',
                        UPDATED_BY = ?,
                        UPDATED_DT = UTC_TIMESTAMP()
                    WHERE ATTENDANCE_ID = ?";
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                $this->sendResponse(false, 'Failed to prepare statement: ' . $this->conn->error);
            }
            
            $stmt->bind_param('ssi', $requestedStatus, $approvedBy, $attendanceId);
            
            if (!$stmt->execute()) {
                $this->sendResponse(false, 'Failed to approve dispute: ' . $stmt->error);
            }
            
            $affectedRows = $stmt->affected_rows;
            $stmt->close();
            
            if ($affectedRows > 0) {
                $this->sendResponse(true, 'Dispute approved successfully', [
                    'affectedRows' => $affectedRows
                ]);
            } else {
                $this->sendResponse(false, 'No records updated');
            }
            
        } catch (Exception $e) {
            $this->sendResponse(false, 'Error: ' . $e->getMessage());
        }
    }
    
    public function rejectDispute($data) {
        $attendanceId = $data['attendanceId'] ?? null;
        $approvedBy = $data['approvedBy'] ?? 'SYSTEM';
        $comments = $data['comments'] ?? null;
        
        if (!$attendanceId) {
            $this->sendResponse(false, 'Attendance ID is required');
        }
        
        if (!$comments || trim($comments) === '') {
            $this->sendResponse(false, 'Rejection reason is required');
        }
        
        try {
            // Update to REJECTED status
            $sql = "UPDATE ATTENDANCE_REPORT 
                    SET REGULARISATION_STATUS = 'REJECTED',
                        REQUESTED_ATTENDANCE_STATUS = NULL,
                        COMMENTS = ?,
                        UPDATE_TYPE = 'dispute_rejected',
                        UPDATED_BY = ?,
                        UPDATED_DT = UTC_TIMESTAMP()
                    WHERE ATTENDANCE_ID = ?";
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                $this->sendResponse(false, 'Failed to prepare statement: ' . $this->conn->error);
            }
            
            $stmt->bind_param('ssi', $comments, $approvedBy, $attendanceId);
            
            if (!$stmt->execute()) {
                $this->sendResponse(false, 'Failed to reject dispute: ' . $stmt->error);
            }
            
            $affectedRows = $stmt->affected_rows;
            $stmt->close();
            
            if ($affectedRows > 0) {
                $this->sendResponse(true, 'Dispute rejected successfully', [
                    'affectedRows' => $affectedRows
                ]);
            } else {
                $this->sendResponse(false, 'No records updated');
            }
            
        } catch (Exception $e) {
            $this->sendResponse(false, 'Error: ' . $e->getMessage());
        }
    }
    
    public function withdrawDispute($data) {
        $attendanceId = $data['attendanceId'] ?? null;
        $empId = $data['empId'] ?? null;
        
        if (!$attendanceId || !$empId) {
            $this->sendResponse(false, 'Attendance ID and Employee ID are required');
        }
        
        try {
            // Verify ownership
            $verifySql = "SELECT EMPID FROM ATTENDANCE_REPORT WHERE ATTENDANCE_ID = ?";
            $verifyStmt = $this->conn->prepare($verifySql);
            $verifyStmt->bind_param('i', $attendanceId);
            $verifyStmt->execute();
            $result = $verifyStmt->get_result();
            $row = $result->fetch_assoc();
            $verifyStmt->close();
            
            if (!$row || $row['EMPID'] !== $empId) {
                $this->sendResponse(false, 'Unauthorized to withdraw this dispute');
            }
            
            // Update to WITHDRAWN status
            $sql = "UPDATE ATTENDANCE_REPORT 
                    SET REGULARISATION_STATUS = 'WITHDRAWN',
                        REQUESTED_ATTENDANCE_STATUS = NULL,
                        UPDATE_TYPE = 'dispute_withdrawn',
                        UPDATED_BY = ?,
                        UPDATED_DT = UTC_TIMESTAMP()
                    WHERE ATTENDANCE_ID = ? AND REGULARISATION_STATUS = 'DISPUTED'";
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                $this->sendResponse(false, 'Failed to prepare statement: ' . $this->conn->error);
            }
            
            $stmt->bind_param('si', $empId, $attendanceId);
            
            if (!$stmt->execute()) {
                $this->sendResponse(false, 'Failed to withdraw dispute: ' . $stmt->error);
            }
            
            $affectedRows = $stmt->affected_rows;
            $stmt->close();
            
            if ($affectedRows > 0) {
                $this->sendResponse(true, 'Dispute withdrawn successfully', [
                    'affectedRows' => $affectedRows
                ]);
            } else {
                $this->sendResponse(false, 'No pending dispute found to withdraw');
            }
            
        } catch (Exception $e) {
            $this->sendResponse(false, 'Error: ' . $e->getMessage());
        }
    }
    
    public function checkEmployeePendingDisputes($data) {
        $empId = $data['empId'] ?? null;
        
        if (!$empId) {
            $this->sendResponse(false, 'Employee ID is required');
        }
        
        try {
            $sql = "SELECT 
                        COUNT(*) as pendingCount,
                        GROUP_CONCAT(WORK_DATE ORDER BY WORK_DATE SEPARATOR ', ') as pendingDates
                    FROM ATTENDANCE_REPORT 
                    WHERE EMPID = ? 
                    AND REGULARISATION_STATUS = 'DISPUTED'";
            
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
            
            $pendingCount = $row['pendingCount'] ?? 0;
            $pendingDates = $row['pendingDates'] ? explode(', ', $row['pendingDates']) : [];
            
            $this->sendResponse(true, 'Check completed', [
                'hasPending' => $pendingCount > 0,
                'pendingCount' => $pendingCount,
                'pendingDates' => $pendingDates
            ]);
            
        } catch (Exception $e) {
            $this->sendResponse(false, 'Error: ' . $e->getMessage());
        }
    }
    
    public function getShiftList() {
        try {
            $sql = "SELECT DISTINCT ShiftName FROM SHIFT_CODE_MAPPING ORDER BY ShiftName";
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                $this->sendResponse(false, 'Failed to prepare statement: ' . $this->conn->error);
            }
            
            if (!$stmt->execute()) {
                $this->sendResponse(false, 'Failed to execute query: ' . $stmt->error);
            }
            
            $result = $stmt->get_result();
            $shifts = [];
            
            while ($row = $result->fetch_assoc()) {
                $shifts[] = [
                    'shiftName' => $row['ShiftName']
                ];
            }
            
            $stmt->close();
            
            $this->sendResponse(true, 'Shift list retrieved successfully', $shifts);
            
        } catch (Exception $e) {
            $this->sendResponse(false, 'Error: ' . $e->getMessage());
        }
    }
    
    public function handleRequest($data) {
        $action = $data['action'] ?? 'get_attendance';
        
        switch ($action) {
            case 'get_attendance':
                $this->getAttendance($data);
                break;
                
            case 'dispute_attendance':
                $this->disputeAttendance($data);
                break;
                
            case 'override_attendance':
                $this->overrideAttendance($data);
                break;
                
            case 'get_pending_disputes':
                $this->getPendingDisputes($data);
                break;
                
            case 'approve_dispute':
                $this->approveDispute($data);
                break;
                
            case 'reject_dispute':
                $this->rejectDispute($data);
                break;
                
            case 'withdraw_dispute':
                $this->withdrawDispute($data);
                break;
                
            case 'check_employee_pending_disputes':
                $this->checkEmployeePendingDisputes($data);
                break;
                
            case 'get_shift_list':
                $this->getShiftList();
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
    
    $api = new AttendanceAPI($conn);
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