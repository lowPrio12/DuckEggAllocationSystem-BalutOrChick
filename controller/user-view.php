<?php
require_once '../model/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Only accept GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$user_role = $_SESSION['user_id'];
$user_id = $_SESSION['user_id'];
$selected_user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

$response = ['success' => false, 'data' => []];

// Fetch users based on role
if ($user_role === 'admin') {
    $stmt = $conn->prepare("
        SELECT u.user_id, u.username, u.user_role, u.created_at,
               COALESCE(SUM(e.balut_count), 0) AS total_balut,
               COALESCE(SUM(e.chick_count), 0) AS total_chicks,
               COALESCE(SUM(e.failed_count), 0) AS total_failed,
               COALESCE(COUNT(e.egg_id), 0) AS batch_count
        FROM users u
        LEFT JOIN egg e ON u.user_id = e.user_id
        GROUP BY u.user_id
        ORDER BY u.created_at DESC
    ");
    $stmt->execute();
    $response['data']['users'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $conn->prepare("
        SELECT u.user_id, u.username, u.user_role, u.created_at,
               COALESCE(SUM(e.balut_count), 0) AS total_balut,
               COALESCE(SUM(e.chick_count), 0) AS total_chicks,
               COALESCE(SUM(e.failed_count), 0) AS total_failed,
               COALESCE(COUNT(e.egg_id), 0) AS batch_count
        FROM users u
        LEFT JOIN egg e ON u.user_id = e.user_id
        WHERE u.user_role = 'user' OR u.user_id = ?
        GROUP BY u.user_id
        ORDER BY u.created_at DESC
    ");
    $stmt->execute([$user_id]);
    $response['data']['users'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch statistics
$response['data']['totalUsers'] = $conn->query("SELECT COUNT(*) FROM users")->fetchColumn();
$response['data']['totalBatches'] = $conn->query("SELECT COUNT(*) FROM egg")->fetchColumn();
$response['data']['totalEggs'] = $conn->query("SELECT SUM(total_egg) FROM egg")->fetchColumn() ?: 0;
$response['data']['totalChicks'] = $conn->query("SELECT SUM(chick_count) FROM egg")->fetchColumn() ?: 0;
$response['data']['totalBalut'] = $conn->query("SELECT SUM(balut_count) FROM egg")->fetchColumn() ?: 0;

// Fetch user egg records if specific user selected
if ($selected_user_id > 0) {
    $canView = false;
    if ($user_role === 'admin') {
        $canView = true;
    } else {
        $stmt = $conn->prepare("SELECT user_role FROM users WHERE user_id = ?");
        $stmt->execute([$selected_user_id]);
        $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($targetUser && ($targetUser['user_role'] === 'user' || $selected_user_id == $user_id)) {
            $canView = true;
        }
    }

    if ($canView) {
        $stmt = $conn->prepare("SELECT username, user_role, created_at FROM users WHERE user_id = ?");
        $stmt->execute([$selected_user_id]);
        $response['data']['selectedUser'] = $stmt->fetch(PDO::FETCH_ASSOC);

        $stmt = $conn->prepare("
            SELECT e.*, 
                   DATEDIFF(NOW(), e.date_started_incubation) as days_in_incubation
            FROM egg e
            WHERE e.user_id = ?
            ORDER BY e.date_started_incubation DESC
        ");
        $stmt->execute([$selected_user_id]);
        $response['data']['eggRecords'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $conn->prepare("
            SELECT l.*, u.username
            FROM user_activity_logs l
            LEFT JOIN users u ON l.user_id = u.user_id
            WHERE l.user_id = ?
            ORDER BY l.log_date DESC
            LIMIT 50
        ");
        $stmt->execute([$selected_user_id]);
        $response['data']['activities'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$response['success'] = true;
header('Content-Type: application/json');
echo json_encode($response);
?>