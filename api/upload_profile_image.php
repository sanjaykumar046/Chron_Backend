<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

$EMPID = $_POST['EMPID'] ?? '';
$file  = $_FILES['profileImage'] ?? null;

if (!$EMPID || !$file) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

$uploadDir = __DIR__ . '/uploads/profiles/';
$fileName  = 'profile_' . $EMPID . '.jpg';
$filePath  = $uploadDir . $fileName;

if (move_uploaded_file($file['tmp_name'], $filePath)) {
    $imageUrl = 'http://13.206.51.164/api/uploads/profiles/' . $fileName;
    echo json_encode(['success' => true, 'imageUrl' => $imageUrl]);
} else {
    echo json_encode(['success' => false, 'error' => 'Failed to save image']);
}
?>