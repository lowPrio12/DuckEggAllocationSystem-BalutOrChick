<?php
require_once '../model/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'];

$response = ['success' => false, 'message' => ''];

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';
$role = trim($_POST['role'] ?? 'user');

// Role restrictions based on user role
if ($user_role !== 'admin') {
    if ($role !== 'user') {
        $response['message'] = 'You can only create regular user accounts.';
        echo json_encode($response);
        exit;
    }
}

$errors = [];
if (strlen($username) < 3) $errors[] = 'Username must be at least 3 characters.';
if (strlen($password) < 6) $errors[] = 'Password must be at least 6 characters.';

$chk = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
$chk->execute([$username]);
if ($chk->fetch()) $errors[] = 'Username already exists.';

if (empty($errors)) {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $ins = $conn->prepare("INSERT INTO users (username, password, user_role) VALUES (?, ?, ?)");
    $ins->execute([$username, $hash, $role]);

    $actionLog = "Created user: $username ($role)";
    $stmt = $conn->prepare("INSERT INTO user_activity_logs (user_id, action, log_date) VALUES (?, ?, NOW())");
    $stmt->execute([$user_id, $actionLog]);

    $response = ['success' => true, 'message' => 'User created successfully.'];
} else {
    $response = ['success' => false, 'message' => implode(' ', $errors)];
}

header('Content-Type: application/json');
echo json_encode($response);
?>