<?php

// ✅ MUST BE FIRST — catches PHP fatal errors and returns JSON instead of HTML
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR])) {
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode(['error' => 'connection failed: ' . $error['message']]);
        exit;
    }
});

error_reporting(0);
ini_set('display_errors', 0);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, PUT, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

$servername = "pmsglobal.cel8gekqgbno.us-east-1.rds.amazonaws.com";
$username = "admin";
$password = "wfxicVdxG71bjvdVhFN3";
$dbname = "PMS_PRO";

// $servername = "localhost";
// $username   = "root";
// $password   = "Sanjaykumar@7";
// $dbname     = "prod_ent1_tenant_0_demo";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection — returns JSON so React can parse and trigger fallback
if ($conn->connect_error) {
    echo json_encode(['error' => 'connection failed: ' . $conn->connect_error]);
    exit;
}
?>