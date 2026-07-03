<?php
    include 'apiMain.php';

    header('Content-Type: application/json'); // Always set proper content type

    $input = json_decode(file_get_contents('php://input'), true);

    // Required
    $empidArray = isset($input['EMPID']) ? $input['EMPID'] : null;

    if (!$empidArray || !is_array($empidArray) || empty($empidArray)) {
        echo json_encode(['error' => 'EMPID is required.']);
        exit();
    }

    $startDate = isset($input['startDate']) ? $input['startDate'] : date('Y-m-d');
    $endDate = isset($input['endDate']) ? $input['endDate'] : date('Y-m-d');

    // Optional filters with fallback to 'ALL'
    $ids = implode(',', $empidArray);
    $names = !empty($input['EMPNAME']) ? implode(',', $input['EMPNAME']) : 'ALL';
    $departments = !empty($input['DEPARTMENT']) ? implode(',', $input['DEPARTMENT']) : 'ALL';
    $roles = !empty($input['ROLE']) ? implode(',', $input['ROLE']) : 'ALL';
    $projects = !empty($input['PROJECT']) ? implode(',', $input['PROJECT']) : 'ALL';
    $shifts = !empty($input['SHIFT']) ? implode(',', $input['SHIFT']) : 'ALL';
    $teams = !empty($input['TEAM']) ? implode(',', $input['TEAM']) : 'ALL';
    $designations = !empty($input['DESIGNATION']) ? implode(',', $input['DESIGNATION']) : 'ALL';
    $userid = isset($input['userid']) ? $input['userid'] : 'ALL';

    $stmt = $conn->prepare("CALL PR_EMPLOYEE_ACTIVITY(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    if (!$stmt) {
        echo json_encode(['error' => 'Prepare failed: ' . $conn->error]);
        exit();
    }

    $stmt->bind_param(
        'sssssssssss',
        $startDate, $endDate, $ids, $names, $departments,
        $roles, $designations, $projects, $shifts, $teams, $userid
    );

    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $data = [];

        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }

        echo json_encode(['data' => $data ?: []]);
    } else {
        echo json_encode(['error' => 'Query failed: ' . $stmt->error]);
    }

    $stmt->close();
    $conn->close();
    ?>
