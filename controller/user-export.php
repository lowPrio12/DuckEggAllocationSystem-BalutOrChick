<?php
require_once '../model/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

$user_role = $_SESSION['user_role'];
$user_id = $_SESSION['user_id'];

// Helper: format date/time properly
function formatDateTime($datetime)
{
    if (!$datetime) return 'Never';
    $timestamp = strtotime($datetime);
    return date('M j, Y g:i A', $timestamp);
}

// Handle export requests
if (isset($_GET['export']) && isset($_GET['user_id'])) {
    $export_type = $_GET['export'];
    $export_user_id = (int)$_GET['user_id'];

    // Check permission for export
    $canExport = false;
    if ($user_role === 'admin') {
        $canExport = true;
    } else {
        $stmt = $conn->prepare("SELECT user_role FROM users WHERE user_id = ?");
        $stmt->execute([$export_user_id]);
        $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($targetUser && ($targetUser['user_role'] === 'user' || $export_user_id == $user_id)) {
            $canExport = true;
        }
    }

    if ($canExport && $export_user_id > 0) {
        // Get user data
        $stmt = $conn->prepare("SELECT username, user_role, created_at FROM users WHERE user_id = ?");
        $stmt->execute([$export_user_id]);
        $userData = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($export_type === 'egg_records') {
            // Export egg records
            $stmt = $conn->prepare("
                SELECT batch_number, total_egg, status, date_started_incubation, 
                       balut_count, chick_count, failed_count,
                       DATEDIFF(NOW(), date_started_incubation) as days_in_incubation
                FROM egg 
                WHERE user_id = ? 
                ORDER BY date_started_incubation DESC
            ");
            $stmt->execute([$export_user_id]);
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Set headers for CSV download
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $userData['username'] . '_egg_records_' . date('Y-m-d') . '.csv"');

            $output = fopen('php://output', 'w');
            fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($output, ['Batch Number', 'Total Eggs', 'Balut Count', 'Chick Count', 'Failed Count', 'Status', 'Date Started', 'Days in Incubation']);

            foreach ($records as $record) {
                fputcsv($output, [
                    $record['batch_number'],
                    $record['total_egg'],
                    $record['balut_count'],
                    $record['chick_count'],
                    $record['failed_count'],
                    $record['status'],
                    date('Y-m-d', strtotime($record['date_started_incubation'])),
                    $record['days_in_incubation']
                ]);
            }
            fclose($output);
            exit;
        } elseif ($export_type === 'activity_logs') {
            // Export activity logs
            $stmt = $conn->prepare("
                SELECT action, log_date 
                FROM user_activity_logs 
                WHERE user_id = ? 
                ORDER BY log_date DESC
            ");
            $stmt->execute([$export_user_id]);
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $userData['username'] . '_activity_logs_' . date('Y-m-d') . '.csv"');

            $output = fopen('php://output', 'w');
            fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($output, ['Action', 'Date & Time']);

            foreach ($records as $record) {
                fputcsv($output, [
                    $record['action'],
                    formatDateTime($record['log_date'])
                ]);
            }
            fclose($output);
            exit;
        }
    }
}

// If no valid export, redirect back
header("Location: ../view/users/user-management.php");
exit;
?>