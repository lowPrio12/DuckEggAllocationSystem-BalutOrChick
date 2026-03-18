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

// Get user ID from POST
$user_id = $_POST['user_id'] ?? '';

if (empty($user_id)) {
    echo json_encode(['success' => false, 'message' => 'User ID is required']);
    exit;
}

if (!is_numeric($user_id)) {
    echo json_encode(['success' => false, 'message' => 'Invalid user ID']);
    exit;
}

// Prevent deleting yourself
if ($user_id == $_SESSION['user_id']) {
    echo json_encode(['success' => false, 'message' => 'You cannot delete your own account']);
    exit;
}

try {
    // Begin transaction
    $conn->beginTransaction();

    // Get user details for logging
    $stmt = $conn->prepare("SELECT username, user_role FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit;
    }

    $username = $user['username'];
    $user_role = $user['user_role'];

    // Delete user's activity logs first (foreign key constraint)
    $stmt = $conn->prepare("DELETE FROM user_activity_logs WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $logsDeleted = $stmt->rowCount();

    // Delete user's egg batches
    $stmt = $conn->prepare("DELETE FROM egg WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $batchesDeleted = $stmt->rowCount();

    // Delete the user
    $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ?");
    $result = $stmt->execute([$user_id]);
    $userDeleted = $stmt->rowCount();

    if ($result && $userDeleted > 0) {
        // Log the activity
        $action = "Deleted user: $username (ID: $user_id, Role: $user_role) - Removed $logsDeleted activity logs and $batchesDeleted egg batches";
        $logStmt = $conn->prepare("INSERT INTO user_activity_logs (user_id, action) VALUES (?, ?)");
        $logStmt->execute([$_SESSION['user_id'], $action]);

        // Commit transaction
        $conn->commit();

        echo json_encode([
            'success' => true,
            'message' => 'User deleted successfully',
            'details' => [
                'username' => $username,
                'user_id' => $user_id,
                'logs_deleted' => $logsDeleted,
                'batches_deleted' => $batchesDeleted
            ]
        ]);
        exit;
    } else {
        throw new Exception('Failed to delete user');
    }
} catch (PDOException $e) {
    // Rollback transaction on error
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    error_log("User deletion error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
    exit;
} catch (Exception $e) {
    // Rollback transaction on error
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    error_log("User deletion error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}
