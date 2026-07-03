<?php
// ============================================================
// File  : session.php
// Table : EMP_LOGIN_CNT
// Actions: login | logout
//
// Session timeout is handled by FRONTEND (inactivity detection)
// Backend only:
//   login  ? Insert new row with LOGIN_TIME
//   logout ? Update LOGOUT_TIME + LOGOUT_REASON when frontend calls
//
// LOGOUT_REASON values:
//   'inactivity' ? session timed out due to no mouse/keyboard activity
//   'manual'     ? employee clicked logout button themselves
// ============================================================

include 'apiMain.php';

// -- CORS Headers (must be before any output) -----------------
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$action = isset($_GET['action']) ? trim($_GET['action']) : '';

switch ($action) {

    // ----------------------------------------------------------
    // LOGIN
    // Called right after employee clicks Login button
    // URL: session.php?action=login&empid=EMP001&username=John
    //
    // LocalStorage source (from LoginView.jsx):
    //   EMPID    ? localStorage.getItem("EMPID")
    //   USERNAME ? localStorage.getItem("empname").split(',')[0].trim()
    //
    // Inserts a new row into EMP_LOGIN_CNT with:
    //   LOGIN_TIME  ? current datetime
    //   LOGIN_DATE  ? current date
    //   LOGOUT_TIME ? NULL (filled on logout)
    //
    // Returns record_id ? stored in localStorage as 'session_record_id'
    //
    // NOTE: 'loginTime' is NOT returned anymore.
    //       Session timeout is based on INACTIVITY detected by frontend.
    //       NOT based on total time since login.
    // ----------------------------------------------------------
    case 'login':
        $empid    = isset($_GET['empid'])    ? $conn->real_escape_string(trim($_GET['empid']))    : '';
        $username = isset($_GET['username']) ? $conn->real_escape_string(trim($_GET['username'])) : '';

        if (empty($empid)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'empid is required']);
            exit();
        }

        $login_time = date('Y-m-d H:i:s'); // DATETIME ? LOGIN_TIME column
        $login_date = date('Y-m-d');        // DATE     ? LOGIN_DATE column

        $stmt = $conn->prepare("
            INSERT INTO EMP_LOGIN_CNT 
                (EMPID, USERNAME, LOGIN_TIME, LOGIN_DATE, LOGIN_COUNT)
            VALUES (?, ?, ?, ?, 1)
        ");
        $stmt->bind_param('ssss', $empid, $username, $login_time, $login_date);

        if ($stmt->execute()) {
            $record_id = $conn->insert_id;
            $stmt->close();
            $conn->close();
            echo json_encode([
                'success'   => true,
                'message'   => 'Login recorded successfully',
                'record_id' => $record_id  // Store as localStorage 'session_record_id'
                // NOTE: 'loginTime' removed — not needed anymore
            ]);
        } else {
            $error = $conn->error;
            $stmt->close();
            $conn->close();
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Insert failed: ' . $error]);
        }
        break;

    // ----------------------------------------------------------
    // LOGOUT
    // Called by frontend when:
    //   1. Employee inactive for 2 minutes (session timeout) ? logout_reason=inactivity
    //   2. Employee manually clicks Logout button            ? logout_reason=manual
    //
    // URL: session.php?action=logout&record_id=5&logout_reason=inactivity
    //
    // record_id     ? from localStorage 'session_record_id' (set during login)
    // logout_reason ? 'inactivity' | 'manual' (default: inactivity)
    //
    // Updates LOGOUT_TIME and LOGOUT_REASON for the matching row
    // Only updates if LOGOUT_TIME is still NULL (not already logged out)
    // ----------------------------------------------------------
    case 'logout':
        $record_id     = isset($_GET['record_id'])     ? intval($_GET['record_id'])                                    : 0;
        $logout_reason = isset($_GET['logout_reason']) ? $conn->real_escape_string(trim($_GET['logout_reason']))       : 'inactivity';

        // Validate logout_reason — only allow known values
        $allowed_reasons = ['inactivity', 'manual'];
        if (!in_array($logout_reason, $allowed_reasons)) {
            $logout_reason = 'inactivity'; // Default fallback
        }

        if ($record_id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid record_id']);
            exit();
        }

        // Safety check: ensure row exists and not already logged out
        $stmt = $conn->prepare("
            SELECT ID FROM EMP_LOGIN_CNT 
            WHERE ID = ? AND LOGOUT_TIME IS NULL
        ");
        $stmt->bind_param('i', $record_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            $stmt->close();
            $conn->close();
            echo json_encode(['success' => false, 'message' => 'Record not found or already logged out']);
            exit();
        }
        $stmt->close();

        $logout_time = date('Y-m-d H:i:s'); // DATETIME ? LOGOUT_TIME column

        // Update LOGOUT_TIME and LOGOUT_REASON
        $stmt = $conn->prepare("
            UPDATE EMP_LOGIN_CNT 
            SET LOGOUT_TIME = ?, LOGOUT_REASON = ?
            WHERE ID = ?
        ");
        $stmt->bind_param('ssi', $logout_time, $logout_reason, $record_id);

        if ($stmt->execute()) {
            $stmt->close();
            $conn->close();
            echo json_encode([
                'success'       => true,
                'message'       => 'Logout recorded successfully',
                'logout_time'   => $logout_time,
                'logout_reason' => $logout_reason
            ]);
        } else {
            $error = $conn->error;
            $stmt->close();
            $conn->close();
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Logout update failed: ' . $error]);
        }
        break;

    default:
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid action. Use: login | logout'
        ]);
}
?>