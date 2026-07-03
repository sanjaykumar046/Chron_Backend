<?php
include 'apiMain.php';

// Enable CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

// Get POST data
$data = json_decode(file_get_contents("php://input"), true);
$websiteName = $data['websiteName'] ?? null;
$fromType = $data['fromType'] ?? null;
$toType = $data['toType'] ?? null;

if ($websiteName && $fromType && $toType) {
    try {
        // Start transaction
        $conn->begin_transaction();

        // Convert type names to database values
        $typeMapping = [
            'PRODUCTIVE' => 'Y',
            'RESTRICTED' => 'N',
            'UNPRODUCTIVE' => 'U'
        ];

        $newTypeValue = $typeMapping[$toType] ?? 'U';
        $websiteTypeFixed = "WEBSITE";

        // Update existing record
        $updateStmt = $conn->prepare("UPDATE PRODUCTIVE_APPS_WEBSITE SET PRODUCTIVE_YN = ? WHERE APP_WEBSITE_URL = ?");
        $updateStmt->bind_param("ss", $newTypeValue, $websiteName);
        $updateStmt->execute();

        if ($updateStmt->affected_rows > 0) {
            // Commit and fetch updated record
            $conn->commit();
        } else {
            // Insert new record if update did not affect any row
            $insertStmt = $conn->prepare("INSERT INTO PRODUCTIVE_APPS_WEBSITE (APP_WEBSITE_URL, TYPE, PRODUCTIVE_YN) VALUES (?, ?, ?)");
            $insertStmt->bind_param("sss", $websiteName, $websiteTypeFixed, $newTypeValue);
            if ($insertStmt->execute()) {
                $conn->commit();
            } else {
                $conn->rollback();
                echo json_encode([
                    "status" => "error",
                    "message" => "Error creating new website record: " . $insertStmt->error
                ]);
                $insertStmt->close();
                exit();
            }
            $insertStmt->close();
        }

        $updateStmt->close();

        // Now fetch the updated row and return it
        $selectStmt = $conn->prepare("SELECT * FROM PRODUCTIVE_APPS_WEBSITE WHERE APP_WEBSITE_URL = ?");
        $selectStmt->bind_param("s", $websiteName);
        $selectStmt->execute();
        $result = $selectStmt->get_result();
        $websiteData = $result->fetch_assoc();
        $selectStmt->close();

        echo json_encode([
            "status" => "success",
            "message" => "Website moved successfully from $fromType to $toType",
            "website" => $websiteData
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode([
            "status" => "error",
            "message" => "Database error: " . $e->getMessage()
        ]);
    }
} else {
    echo json_encode([
        "status" => "error",
        "message" => "Invalid input. Required: websiteName, fromType, toType"
    ]);
}

// Close connection
$conn->close();
?>
