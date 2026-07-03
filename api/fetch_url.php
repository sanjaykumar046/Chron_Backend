<?php
include 'apiMain.php';
$sql = "SELECT * FROM url_visits";
$result = $conn->query($sql);

if ($result) {
    $columns = [];
    while ($fieldinfo = $result->fetch_field()) {
        $columns[] = $fieldinfo->name;
    }

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    echo json_encode(["status" => "success", "columns" => $columns, "data" => $rows]);
} else {
    echo json_encode(["status" => "error", "message" => "Query failed"]);
}

$conn->close();
?>
