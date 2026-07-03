<?php

include 'apiMain.php';

// Function to generate a random token
function generateToken($length = 32) {
    return bin2hex(random_bytes($length));
}

// Function to get the request body as JSON
function getRequestBody() {
    $body = file_get_contents("php://input");
    return json_decode($body, true);
}

// User registration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'register') {
    $data = getRequestBody();
    
    if (!isset($data['name']) || !isset($data['email']) || !isset($data['password'])) {
        echo json_encode(['error' => 'Name, email, and password are required.']);
        exit;
    }
    
    $name = $data['name'];
    $email = $data['email'];
    $password = $data['password']; // Store as plain text

    // Check if the user already exists
    $sql = "SELECT * FROM AdminLogin WHERE email = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        echo json_encode(['error' => 'Email already exists.']);
        exit;
    }

    // Insert the new user into the database
    $sql = "INSERT INTO AdminLogin (name, email, password) VALUES (?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sss", $name, $email, $password);
    
    if ($stmt->execute()) {
        $token = generateToken();
        $userId = $stmt->insert_id; 
        $sql = "UPDATE AdminLogin SET token = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("si", $token, $userId);
        $stmt->execute();

        echo json_encode([
            'message' => 'User registered successfully.',
            'token' => $token,
            'name' => $name,
            'email' => $email
        ]);
    } else {
        echo json_encode(['error' => 'Registration failed.']);
    }
    exit;
}

// User login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'login') {
    $data = getRequestBody();
    
    if (!isset($data['email']) || !isset($data['password'])) {
        echo json_encode(['error' => 'Email and password are required.']);
        exit;
    }

    $email = $data['email'];
    $password = $data['password'];

    $sql = "SELECT * FROM AdminLogin WHERE email = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    if ($user) {
        // Direct comparison for plain text password
        if ($password === $user['password']) {
            $token = generateToken();
            $sql = "UPDATE AdminLogin SET token = ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("si", $token, $user['id']);
            $stmt->execute();

            echo json_encode(['token' => $token, 'name' => $user['name'], 'email' => $user['email']]);
        } else {
            echo json_encode(['error' => 'Invalid email or password.']);
        }
    } else {
        echo json_encode(['error' => 'Invalid email or password.']);
    }
    exit;
}


// Middleware to check the token
function checkToken($conn, $token) {
    $sql = "SELECT * FROM AdminLogin WHERE token = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $token);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

// GET user data
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_user') {
    $token = $_GET['token'];
    $user = checkToken($conn, $token);

    if ($user) {
        echo json_encode(['name' => $user['name'], 'email' => $user['email']]);
    } else {
        echo json_encode(['error' => 'Unauthorized.']);
    }
    exit;
}

// PUT user data
if ($_SERVER['REQUEST_METHOD'] === 'PUT' && isset($_GET['action']) && $_GET['action'] === 'update_user') {
    $token = $_GET['token'];
    $data = getRequestBody();
    
    if (!isset($data['name']) || !isset($data['email'])) {
        echo json_encode(['error' => 'Name and email are required.']);
        exit;
    }

    $name = $data['name'];
    $email = $data['email'];

    $user = checkToken($conn, $token);

    if ($user) {
        $sql = "UPDATE AdminLogin SET name = ?, email = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssi", $name, $email, $user['id']);
        if ($stmt->execute()) {
            echo json_encode(['message' => 'User updated successfully.']);
        } else {
            echo json_encode(['error' => 'Update failed.']);
        }
    } else {
        echo json_encode(['error' => 'Unauthorized.']);
    }
    exit;
}

$conn->close();
?>
