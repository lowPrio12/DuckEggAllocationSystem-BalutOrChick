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
$del_id = (int)($_POST['user_id'] ?? 0);

$stmt = $conn->prepare("SELECT username, user_role FROM users WHERE user_id = ?");
$stmt->execute([$del_id]);
$targetUser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$targetUser) {
    $response['message'] = 'User not found.';
    echo json_encode($response);
    exit;
}

if ($user_role !== 'admin') {
    if ($targetUser['user_role'] === 'admin') {
        $response['message'] = 'You cannot delete admin accounts.';
        echo json_encode($response);
        exit;
    }
    if ($targetUser['user_role'] === 'manager' && $del_id != $user_id) {
        $response['message'] = 'You cannot delete other manager accounts.';
        echo json_encode($response);
        exit;
    }
}

if ($del_id == $user_id) {
    $response['message'] = 'You cannot delete your own account.';
    echo json_encode($response);
    exit;
}

if ($del_id) {
    $stmt = $conn->prepare("DELETE FROM user_activity_logs WHERE user_id = ?");
    $stmt->execute([$del_id]);
    $stmt = $conn->prepare("DELETE FROM egg WHERE user_id = ?");
    $stmt->execute([$del_id]);
    $del = $conn->prepare("DELETE FROM users WHERE user_id=?");
    $del->execute([$del_id]);

    $actionLog = "Deleted user: {$targetUser['username']} (ID: $del_id, Role: {$targetUser['user_role']})";
    $stmt = $conn->prepare("INSERT INTO user_activity_logs (user_id, action, log_date) VALUES (?, ?, NOW())");
    $stmt->execute([$user_id, $actionLog]);

    $response = ['success' => true, 'message' => 'User deleted successfully.'];
} else {
    $response = ['success' => false, 'message' => 'Invalid user ID.'];
}

header('Content-Type: application/json');
echo json_encode($response);
?>