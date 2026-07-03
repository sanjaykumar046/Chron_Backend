<?php
require_once 'vendor/autoload.php'; // composer require google/apiclient

define('DRIVE_FOLDER_ID', 'your_google_drive_folder_id_here');
define('SERVICE_ACCOUNT_JSON', __DIR__ . '/service-account.json');

function uploadToGoogleDrive($file, $EMPID) {
    $client = new Google\Client();
    $client->setAuthConfig(SERVICE_ACCOUNT_JSON);
    $client->addScope(Google\Service\Drive::DRIVE);

    $driveService = new Google\Service\Drive($client);

    $fileName   = 'profile_' . $EMPID . '_' . time() . '.jpg';
    $mimeType   = $file['type'];
    $tmpPath    = $file['tmp_name'];

    $fileMetadata = new Google\Service\Drive\DriveFile([
        'name'    => $fileName,
        'parents' => [DRIVE_FOLDER_ID]
    ]);

    $content  = file_get_contents($tmpPath);
    $uploaded = $driveService->files->create($fileMetadata, [
        'data'       => $content,
        'mimeType'   => $mimeType,
        'uploadType' => 'multipart',
        'fields'     => 'id'
    ]);

    $fileId = $uploaded->id;

    // Make public
    $permission = new Google\Service\Drive\Permission([
        'type' => 'anyone',
        'role' => 'reader'
    ]);
    $driveService->permissions->create($fileId, $permission);

    return "https://drive.google.com/uc?export=view&id={$fileId}";
}
?>
