<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

// ✅ Handle preflight here so every PHP file that includes this is covered
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// $servername = "pmsglobal.cel8gekqgbno.us-east-1.rds.amazonaws.com";
// $username = "admin";
// $password = "wfxicVdxG71bjvdVhFN3";
// $dbname = "PMS_PRO";

$servername = "localhost";
$username   = "root";
$password   = "Sanjaykumar@7";
$dbname     = "governanace_for_chron";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    echo json_encode(['error' => 'connection failed: ' . $conn->connect_error]);
    exit;
}
?>