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
$edit_id = (int)($_POST['user_id'] ?? 0);
$username = trim($_POST['username'] ?? '');
$role = trim($_POST['role'] ?? '');
$password = $_POST['password'] ?? '';

$stmt = $conn->prepare("SELECT user_role FROM users WHERE user_id = ?");
$stmt->execute([$edit_id]);
$targetUser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$targetUser) {
    $response['message'] = 'User not found.';
    echo json_encode($response);
    exit;
}

if ($user_role !== 'admin') {
    if ($targetUser['user_role'] === 'admin') {
        $response['message'] = 'You cannot edit admin accounts.';
        echo json_encode($response);
        exit;
    }
    if ($role !== 'user') {
        $response['message'] = 'You can only assign regular user role.';
        echo json_encode($response);
        exit;
    }
    if ($targetUser['user_role'] === 'manager' && $edit_id != $user_id) {
        $response['message'] = 'You cannot edit other manager accounts.';
        echo json_encode($response);
        exit;
    }
}

if ($edit_id && strlen($username) >= 3) {
    if (!empty($password) && strlen($password) >= 6) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $upd = $conn->prepare("UPDATE users SET username=?, user_role=?, password=? WHERE user_id=?");
        $upd->execute([$username, $role, $hash, $edit_id]);
    } else {
        $upd = $conn->prepare("UPDATE users SET username=?, user_role=? WHERE user_id=?");
        $upd->execute([$username, $role, $edit_id]);
    }

    $actionLog = "Edited user: $username (ID: $edit_id)";
    $stmt = $conn->prepare("INSERT INTO user_activity_logs (user_id, action, log_date) VALUES (?, ?, NOW())");
    $stmt->execute([$user_id, $actionLog]);

    $response = ['success' => true, 'message' => 'User updated successfully.'];
} else {
    $response = ['success' => false, 'message' => 'Invalid data. Username must be at least 3 characters.'];
}

header('Content-Type: application/json');
echo json_encode($response);
?>