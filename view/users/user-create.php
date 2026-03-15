<?php
require_once '../../model/config.php';

// Set header for JSON response
header('Content-Type: application/json');

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Check if request method is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

// Check if it's an AJAX request
if (
    !isset($_SERVER['HTTP_X_REQUESTED_WITH']) ||
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest'
) {
    echo json_encode(['success' => false, 'message' => 'Invalid request type']);
    exit;
}

// Validate required fields
$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';
$user_role = $_POST['user_role'] ?? '';

$errors = [];

// Validate username
if (empty($username)) {
    $errors[] = 'Username is required';
} elseif (strlen($username) < 3) {
    $errors[] = 'Username must be at least 3 characters';
} elseif (strlen($username) > 50) {
    $errors[] = 'Username must be less than 50 characters';
} elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
    $errors[] = 'Username can only contain letters, numbers, and underscores';
}

// Validate password
if (empty($password)) {
    $errors[] = 'Password is required';
} elseif (strlen($password) < 6) {
    $errors[] = 'Password must be at least 6 characters';
}

// Validate role
$valid_roles = ['admin', 'manager', 'user'];
if (!in_array($user_role, $valid_roles)) {
    $errors[] = 'Invalid user role';
}

// If there are validation errors, return them
if (!empty($errors)) {
    echo json_encode(['success' => false, 'errors' => $errors]);
    exit;
}

try {
    // Check if username already exists
    $stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $userExists = $stmt->fetchColumn();

    if ($userExists > 0) {
        echo json_encode(['success' => false, 'message' => 'Username already exists']);
        exit;
    }

    // Hash the password
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    // Insert new user
    $stmt = $conn->prepare("
        INSERT INTO users (username, password, user_role, created_at) 
        VALUES (?, ?, ?, NOW())
    ");

    $result = $stmt->execute([$username, $hashed_password, $user_role]);

    if ($result) {
        $newUserId = $conn->lastInsertId();

        // Log the activity
        $action = "Created new user: $username (Role: $user_role)";
        $logStmt = $conn->prepare("INSERT INTO user_activity_logs (user_id, action) VALUES (?, ?)");
        $logStmt->execute([$_SESSION['user_id'], $action]);

        // Fetch the newly created user for response
        $stmt = $conn->prepare("
            SELECT u.*, 
                   (SELECT COUNT(*) FROM egg WHERE user_id = u.user_id) as batch_count,
                   (SELECT COUNT(*) FROM user_activity_logs WHERE user_id = u.user_id) as activity_count
            FROM users u 
            WHERE u.user_id = ?
        ");
        $stmt->execute([$newUserId]);
        $newUser = $stmt->fetch(PDO::FETCH_ASSOC);

        // Remove password from response
        unset($newUser['password']);

        echo json_encode([
            'success' => true,
            'message' => 'User created successfully',
            'user' => $newUser
        ]);
        exit;
    } else {
        throw new Exception('Failed to create user');
    }
} catch (PDOException $e) {
    // Log error for debugging
    error_log("User creation error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
    exit;
} catch (Exception $e) {
    // Log error for debugging
    error_log("User creation error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}
