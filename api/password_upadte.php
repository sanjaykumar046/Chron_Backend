<?php
include 'apiMain.php';
// Get POST data
$data = json_decode(file_get_contents("php://input"));

if (isset($data->empId) && isset($data->currentPassword) && isset($data->newPassword)) {
    $empId = $data->empId;
    $currentPassword = $data->currentPassword;
    $newPassword = $data->newPassword;

    // Check if the current password matches the stored password in the database
    $sql = "SELECT PASSWORD FROM EMP_DB WHERE EMPID = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $empId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        if ($row['PASSWORD'] === $currentPassword) {
            // Update password
            $updateSql = "UPDATE EMP_DB SET PASSWORD = ? WHERE EMPID = ?";
            $updateStmt = $conn->prepare($updateSql);
            $updateStmt->bind_param("ss", $newPassword, $empId);

            if ($updateStmt->execute()) {
                echo json_encode(['success' => true, 'message' => 'Password updated successfully.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to update password.']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Current password is incorrect.']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'User not found.']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid input data.']);
}

// Close connections
$stmt->close();
$conn->close();
?>
