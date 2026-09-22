<?php

include 'apiMain.php';

// Function to generate a random token
function generateToken($length = 32) {
    return bin2hex(random_bytes($length));
}

// Function to hash password using SHA-1 (matching C# sha1encrypt implementation)
function hashPassword($password) {
    // Compute SHA-1 hash to match database
    $hash = hash('sha1', $password, false);
    // Returns lowercase hex string (40 characters)
    return strtolower($hash);
}

// Function to get the request body as JSON
function getRequestBody() {
    $body = file_get_contents("php://input");
    return json_decode($body, true);
}

// User login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'login') {
    $data = getRequestBody();
    
    if (!isset($data['EMPID']) || !isset($data['PASSWORD'])) {
        echo json_encode(['error' => 'EMPID and PASSWORD are required.']);
        exit;
    }

    $EMPID = $data['EMPID'];
    $PASSWORD = $data['PASSWORD'];

    // Check if the user exists
    $sql = "SELECT EMPNAME, PASSWORD, EMAIL, SYS_USER_NAME, ROLE, DESIGNATION_CATEGORY, DEPARTMENT, TEAM, PROJECT, REGION, ACCESS_ROLE, ACTIVE_YN FROM EMP_DB WHERE EMPID = ? AND Active_YN = 'Y'";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $EMPID);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    if ($user) {
        // Hash the input password using SHA-1 (matching database)
        $hashedPassword = hashPassword($PASSWORD);
        $storedPassword = strtolower($user['PASSWORD']); // Ensure lowercase comparison
        
        // DEBUG: Log the comparison (REMOVE THIS IN PRODUCTION)
        error_log("Input Password: " . $PASSWORD);
        error_log("Generated Hash: " . $hashedPassword);
        error_log("Stored Hash: " . $storedPassword);
        error_log("Match: " . ($hashedPassword === $storedPassword ? 'YES' : 'NO'));
        
        // Compare hashed passwords
        if ($hashedPassword === $storedPassword) {
            // Generate token
            $token = generateToken();
            $sql = "UPDATE EMP_DB SET token = ? WHERE EMPID = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ss", $token, $EMPID);
            $stmt->execute();

            // Execute the recursive query to get employee hierarchy and shift type
            $query = "
WITH RECURSIVE employee_hierarchy AS (
    SELECT EMPID
    FROM EMP_DB
    WHERE EMPID = ? AND UPPER(ACCESS_ROLE) = UPPER('LEADERSHIP')
    
    UNION ALL
    
    SELECT e.EMPID
    FROM EMP_DB e
    JOIN employee_hierarchy eh ON e.REPORTING_1 = eh.EMPID OR e.REPORTING_2 = eh.EMPID
)

SELECT e.*, s.SHIFTTYPE
FROM EMP_DB e
LEFT JOIN TBL_SHIFT s ON e.EMPID = s.EMPID
WHERE 
    (EXISTS (SELECT 1 FROM EMP_DB WHERE EMPID = ? AND UPPER(ACCESS_ROLE) IN (UPPER('ADMIN'), UPPER('SUPER_ADMIN'), UPPER('EXECUTIVE'))))
    OR 
    (e.EMPID IN (SELECT EMPID FROM employee_hierarchy) OR e.EMPID = ?)
    AND e.ACTIVE_YN = 'Y';
        ";

            $stmt = $conn->prepare($query);
            $stmt->bind_param("sss", $EMPID, $EMPID, $EMPID);
            $stmt->execute();
            $hierarchyResult = $stmt->get_result();

            $employees = [];
            while ($row = $hierarchyResult->fetch_assoc()) {
                $employees[] = $row;
            }

            // Prepare individual arrays
            $aempid = [];
            $aempname = [];
            $adepartment = [];
            $arole = [];
            $ateam = [];
            $aproject = [];
            $ashift = [];
            $adesignationcategory = [];
            $aactivenyn = [];

            // Populate individual arrays with data from employees
            foreach ($employees as $employee) {
                $aempid[] = $employee['EMPID'];
                $aempname[] = $employee['EMPNAME'];
                $adepartment[] = $employee['DEPARTMENT'];
                $arole[] = $employee['ROLE'];
                $ateam[] = $employee['TEAM'];
                $aproject[] = $employee['PROJECT'];
                $ashift[] = $employee['SHIFTTYPE'];
                $adesignationcategory[] = $employee['DESIGNATION_CATEGORY'];
                $aactivenyn[] = $employee['ACTIVE_YN'];
            }

            // Remove duplicates and re-index
            $aempid = array_values(array_unique($aempid));
            $aempname = array_values(array_unique($aempname));
            $adepartment = array_values(array_unique($adepartment));
            $arole = array_values(array_unique($arole));
            $ateam = array_values(array_unique($ateam));
            $aproject = array_values(array_unique($aproject));
            $ashift = array_values(array_unique($ashift));
            $adesignationcategory = array_values(array_unique($adesignationcategory));
            $aactivenyn = array_values(array_filter($aactivenyn, function($value) {
                return $value === 'Y';
            }));

            // Respond with the structured data
            $response = [
                'token' => $token,
                'EMPID' => $EMPID,
                'EMPNAME' => $user['EMPNAME'],
                'EMAIL' => $user['EMAIL'],
                'ACCESS_ROLE' => $user['ACCESS_ROLE'],
                'REGION' => $user['REGION'],
                'PROJECT' => $user['PROJECT'],
                'TEAM' => $user['TEAM'],
                'DEPARTMENT' => $user['DEPARTMENT'],
                'DESIGNATION_CATEGORY' => $user['DESIGNATION_CATEGORY'],
                'ROLE' => $user['ROLE'],
                'SYS_USER_NAME' => $user['SYS_USER_NAME'],
                'PASSWORD' => $user['PASSWORD'],
                'ACTIVE_YN' => $user['ACTIVE_YN'],
                'empid' => $aempid,
                'empname' => $aempname,
                'department' => $adepartment,
                'team' => $ateam,
                'role' => $arole,
                'project' => $aproject,
                'shift' => $ashift,
                'designationcategory' => $adesignationcategory,
                'activenyn' => $aactivenyn,
            ];

            echo json_encode($response);
        } else {
            echo json_encode(['error' => 'Invalid EMPID or PASSWORD.']);
        }
    } else {
        echo json_encode(['error' => 'INACTIVE USER: Login Restricted.']);
    }
    exit;
}

$conn->close();
?>