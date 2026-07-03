<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header('Content-Type: application/json'); // Set the content type to JSON

try {
    // Database connection parameters
    // $host = "chron-db.cd6wkwiowv2u.ap-southeast-2.rds.amazonaws.com";
    // $dbname = "prod_ent1_tenant_0_demo";
    // $username = "admin";
    // $password = "wfxicVdxG71bjvdVhFN3";

    $servername = "localhost";
$username = "root";
$password = "Sanjaykumar@7";
$dbname = "prod_ent1_tenant_0_demo";

    // Define the DSN (Data Source Name)
    $dsn = "mysql:host=$host;dbname=$dbname;charset=utf8";

    // Create a PDO instance
    $pdo = new PDO($dsn, $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Prepare the SQL statement
    $stmt = $pdo->prepare("CALL PR_USER_TIMELINE_AGG_EX(:date, :empid, :empname, :team, :role, :department, :project, :userId)");

    // Bind the parameters
    $stmt->bindParam(':date', $date);
    $stmt->bindParam(':empid', $empid);
    $stmt->bindParam(':empname', $empname);
    $stmt->bindParam(':team', $team);
    $stmt->bindParam(':role', $role);
    $stmt->bindParam(':department', $department);
    $stmt->bindParam(':project', $project);
    $stmt->bindParam(':userId', $userId);
   

    // Retrieve data from the POST request
    $inputData = file_get_contents('php://input');
    parse_str($inputData, $parsedData);

    // Set filter parameters from incoming request, default to 'ALL' if not set
    $date = isset($parsedData['date']) ? $parsedData['date'] :date('Y-m-d');;
    $empid = isset($parsedData['EMPID']) ? $parsedData['EMPID'] : 'ALL';
    $empname = isset($parsedData['EMPNAME']) ? $parsedData['EMPNAME'] : 'ALL';
    $team = isset($parsedData['TEAM']) ? $parsedData['TEAM'] : 'ALL';
    $role = isset($parsedData['ROLE']) ? $parsedData['ROLE'] : 'ALL';
    $department = isset($parsedData['DEPARTMENT']) ? $parsedData['DEPARTMENT'] : 'ALL';
    $project = isset($parsedData['PROJECT']) ? $parsedData['PROJECT'] : 'ALL';
    $userId = isset($parsedData['userid']) ? $parsedData['userid'] : 'ALL';


    // Execute the statement
    $stmt->execute();

    // Fetch the data
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Output the results in JSON format
    if (empty($results)) {
        echo json_encode(["message" => "No data found."]);
    } else {
        echo json_encode($results, JSON_PRETTY_PRINT);
    }

} catch (PDOException $e) {
    // Output error in JSON format
    echo json_encode(["error" => $e->getMessage()]);
}

// Close the connection
$pdo = null;
?>
