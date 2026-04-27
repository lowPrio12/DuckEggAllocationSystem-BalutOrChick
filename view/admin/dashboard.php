<?php
require '../../model/config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../../index.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'];

// Helper: format date/time properly
function formatDateTime($datetime)
{
    if (!$datetime) return 'Never';
    $timestamp = strtotime($datetime);
    return date('M j, Y g:i A', $timestamp);
}

// Helper: time ago
function timeAgo($datetime)
{
    if (!$datetime) return 'Never';
    $now = new DateTime();
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    if ($diff->y > 0) return $diff->y . ' year' . ($diff->y > 1 ? 's' : '') . ' ago';
    elseif ($diff->m > 0) return $diff->m . ' month' . ($diff->m > 1 ? 's' : '') . ' ago';
    elseif ($diff->d > 0) return $diff->d . ' day' . ($diff->d > 1 ? 's' : '') . ' ago';
    elseif ($diff->h > 0) return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
    elseif ($diff->i > 0) return $diff->i . ' minute' . ($diff->i > 1 ? 's' : '') . ' ago';
    else return 'Just now';
}

// Log access (once per session)
if (!isset($_SESSION['admin_dashboard_logged'])) {
    $stmt = $conn->prepare("INSERT INTO user_activity_logs (user_id, action, log_date) VALUES (?, ?, NOW())");
    $stmt->execute([$user_id, "Admin accessed dashboard"]);
    $_SESSION['admin_dashboard_logged'] = true;
}

// Get active tab from URL parameter
$activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'overview';
$validTabs = ['overview', 'analytics', 'reports'];
if (!in_array($activeTab, $validTabs)) {
    $activeTab = 'overview';
}

// ── REPORT HANDLING ─────────────────────────────────────────────────────────
$reportType = isset($_GET['report']) ? $_GET['report'] : 'userSummary';
$startDate = isset($_GET['start']) ? $_GET['start'] : date('Y-m-01');
$endDate = isset($_GET['end']) ? $_GET['end'] : date('Y-m-d');
$reportData = [];
$reportColumns = [];

// Handle CSV Export
if (isset($_GET['export_csv']) && isset($_GET['report_type'])) {
    $exportType = $_GET['report_type'];
    $exportStart = $_GET['start'] ?? date('Y-m-01');
    $exportEnd = $_GET['end'] ?? date('Y-m-d');
    $exportData = [];
    $exportHeaders = [];

    switch ($exportType) {
        case 'userSummary':
            $stmt = $conn->prepare("
                SELECT u.user_id, u.username, u.user_role, DATE(u.created_at) as created_date,
                       COALESCE(COUNT(DISTINCT e.egg_id), 0) as total_batches,
                       COALESCE(SUM(e.balut_count), 0) as total_balut,
                       COALESCE(SUM(e.chick_count), 0) as total_chicks,
                       COALESCE(SUM(e.failed_count), 0) as total_failed
                FROM users u
                LEFT JOIN egg e ON u.user_id = e.user_id AND DATE(e.date_started_incubation) BETWEEN ? AND ?
                GROUP BY u.user_id
                ORDER BY u.created_at DESC
            ");
            $stmt->execute([$exportStart, $exportEnd]);
            $exportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $exportHeaders = ['User ID', 'Username', 'Role', 'Created Date', 'Total Batches', 'Total Balut', 'Total Chicks', 'Total Failed'];
            $filename = "user_summary_{$exportStart}_to_{$exportEnd}.csv";
            break;

        case 'batchProduction':
            $stmt = $conn->prepare("
                SELECT e.batch_number, u.username, e.total_egg, e.balut_count, e.chick_count, e.failed_count,
                       e.status, DATE(e.date_started_incubation) as start_date
                FROM egg e
                JOIN users u ON e.user_id = u.user_id
                WHERE DATE(e.date_started_incubation) BETWEEN ? AND ?
                ORDER BY e.date_started_incubation DESC
            ");
            $stmt->execute([$exportStart, $exportEnd]);
            $exportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $exportHeaders = ['Batch Number', 'User', 'Total Eggs', 'Balut', 'Chicks', 'Failed', 'Status', 'Start Date'];
            $filename = "batch_production_{$exportStart}_to_{$exportEnd}.csv";
            break;

        case 'dailyEggLogs':
            $stmt = $conn->prepare("
                SELECT e.batch_number, u.username, d.day_number, d.balut_count, d.chick_count, d.failed_count, DATE(d.created_at) as log_date
                FROM egg_daily_logs d
                JOIN egg e ON d.egg_id = e.egg_id
                JOIN users u ON e.user_id = u.user_id
                WHERE DATE(d.created_at) BETWEEN ? AND ?
                ORDER BY d.created_at DESC
            ");
            $stmt->execute([$exportStart, $exportEnd]);
            $exportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $exportHeaders = ['Batch Number', 'User', 'Day', 'Daily Balut', 'Daily Chicks', 'Daily Failed', 'Log Date'];
            $filename = "daily_egg_logs_{$exportStart}_to_{$exportEnd}.csv";
            break;

        case 'managerPerformance':
            $stmt = $conn->prepare("
                SELECT u.username, DATE(u.created_at) as created_date,
                       (SELECT COUNT(*) FROM users WHERE user_role = 'user' AND created_by = u.user_id) as managed_users,
                       (SELECT COUNT(*) FROM user_activity_logs WHERE user_id = u.user_id AND log_date BETWEEN ? AND ?) as recent_actions
                FROM users u
                WHERE u.user_role = 'manager'
                ORDER BY u.created_at DESC
            ");
            $stmt->execute([$exportStart, $exportEnd]);
            $exportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $exportHeaders = ['Manager Name', 'Created Date', 'Managed Users', 'Recent Actions'];
            $filename = "manager_performance_{$exportStart}_to_{$exportEnd}.csv";
            break;

        case 'userActivityLogs':
            $stmt = $conn->prepare("
                SELECT u.username, u.user_role, l.action, DATE(l.log_date) as log_date, TIME(l.log_date) as log_time
                FROM user_activity_logs l
                JOIN users u ON l.user_id = u.user_id
                WHERE DATE(l.log_date) BETWEEN ? AND ?
                ORDER BY l.log_date DESC
            ");
            $stmt->execute([$exportStart, $exportEnd]);
            $exportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $exportHeaders = ['Username', 'Role', 'Action', 'Date', 'Time'];
            $filename = "user_activity_logs_{$exportStart}_to_{$exportEnd}.csv";
            break;

        case 'failedEggAnalysis':
            $stmt = $conn->prepare("
                SELECT u.username, e.batch_number, e.failed_count, e.total_egg,
                       ROUND((e.failed_count / e.total_egg) * 100, 2) as fail_rate,
                       DATE(e.date_started_incubation) as start_date
                FROM egg e
                JOIN users u ON e.user_id = u.user_id
                WHERE e.failed_count > 0 AND DATE(e.date_started_incubation) BETWEEN ? AND ?
                ORDER BY e.failed_count DESC
            ");
            $stmt->execute([$exportStart, $exportEnd]);
            $exportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $exportHeaders = ['User', 'Batch Number', 'Failed Eggs', 'Total Eggs', 'Failure Rate %', 'Start Date'];
            $filename = "failed_egg_analysis_{$exportStart}_to_{$exportEnd}.csv";
            break;

        case 'monthlySummary':
            $stmt = $conn->prepare("
                SELECT DATE_FORMAT(e.date_started_incubation, '%Y-%m') as month,
                       COUNT(DISTINCT e.egg_id) as total_batches,
                       SUM(e.total_egg) as total_eggs,
                       SUM(e.balut_count) as total_balut,
                       SUM(e.chick_count) as total_chicks,
                       SUM(e.failed_count) as total_failed,
                       ROUND((SUM(e.balut_count) + SUM(e.chick_count)) / NULLIF(SUM(e.total_egg), 0) * 100, 2) as success_rate
                FROM egg e
                WHERE DATE(e.date_started_incubation) BETWEEN ? AND ?
                GROUP BY DATE_FORMAT(e.date_started_incubation, '%Y-%m')
                ORDER BY month DESC
            ");
            $stmt->execute([$exportStart, $exportEnd]);
            $exportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $exportHeaders = ['Month', 'Total Batches', 'Total Eggs', 'Total Balut', 'Total Chicks', 'Total Failed', 'Success Rate %'];
            $filename = "monthly_summary_{$exportStart}_to_{$exportEnd}.csv";
            break;

        case 'roleDistribution':
            $stmt = $conn->prepare("
                SELECT user_role as role, COUNT(*) as count
                FROM users
                GROUP BY user_role
            ");
            $stmt->execute();
            $exportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $exportHeaders = ['Role', 'Count'];
            $filename = "role_distribution.csv";
            break;
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($output, $exportHeaders);
    foreach ($exportData as $row) {
        fputcsv($output, array_values($row));
    }
    fclose($output);
    exit;
}

// Generate Report Data based on type
switch ($reportType) {
    case 'userSummary':
        $stmt = $conn->prepare("
            SELECT u.username, u.user_role, DATE(u.created_at) as created_date,
                   COALESCE(COUNT(DISTINCT e.egg_id), 0) as total_batches,
                   COALESCE(SUM(e.balut_count), 0) as total_balut,
                   COALESCE(SUM(e.chick_count), 0) as total_chicks,
                   COALESCE(SUM(e.failed_count), 0) as total_failed
            FROM users u
            LEFT JOIN egg e ON u.user_id = e.user_id AND DATE(e.date_started_incubation) BETWEEN ? AND ?
            GROUP BY u.user_id
            ORDER BY u.created_at DESC
        ");
        $stmt->execute([$startDate, $endDate]);
        $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $reportColumns = ['Username', 'Role', 'Created Date', 'Total Batches', 'Total Balut', 'Total Chicks', 'Total Failed'];
        $reportTitle = 'User Summary Report';
        break;

    case 'batchProduction':
        $stmt = $conn->prepare("
            SELECT e.batch_number, u.username, e.total_egg, e.balut_count, e.chick_count, e.failed_count,
                   e.status, DATE(e.date_started_incubation) as start_date
            FROM egg e
            JOIN users u ON e.user_id = u.user_id
            WHERE DATE(e.date_started_incubation) BETWEEN ? AND ?
            ORDER BY e.date_started_incubation DESC
        ");
        $stmt->execute([$startDate, $endDate]);
        $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $reportColumns = ['Batch Number', 'Assigned User', 'Total Eggs', 'Balut', 'Chicks', 'Failed', 'Status', 'Start Date'];
        $reportTitle = 'Batch Production Report';
        break;

    case 'dailyEggLogs':
        $stmt = $conn->prepare("
            SELECT e.batch_number, u.username, d.day_number, d.balut_count, d.chick_count, d.failed_count, DATE(d.created_at) as log_date
            FROM egg_daily_logs d
            JOIN egg e ON d.egg_id = e.egg_id
            JOIN users u ON e.user_id = u.user_id
            WHERE DATE(d.created_at) BETWEEN ? AND ?
            ORDER BY d.created_at DESC
        ");
        $stmt->execute([$startDate, $endDate]);
        $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $reportColumns = ['Batch Number', 'User', 'Day Number', 'Daily Balut', 'Daily Chicks', 'Daily Failed', 'Log Date'];
        $reportTitle = 'Daily Egg Logs Report';
        break;

    case 'managerPerformance':
        // Fixed: Removed non-existent 'created_by' column
        // Shows manager activity and system oversight
        $stmt = $conn->prepare("
            SELECT 
                u.username,
                DATE(u.created_at) as created_date,
                COUNT(DISTINCT al.log_id) as total_actions,
                COUNT(DISTINCT DATE(al.log_date)) as active_days,
                MAX(DATE(al.log_date)) as last_active
            FROM users u
            LEFT JOIN user_activity_logs al ON u.user_id = al.user_id 
                AND DATE(al.log_date) BETWEEN ? AND ?
            WHERE u.user_role = 'manager'
            GROUP BY u.user_id
            ORDER BY total_actions DESC
        ");
        $stmt->execute([$startDate, $endDate]);
        $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $reportColumns = ['Manager Name', 'Created Date', 'Actions Performed', 'Active Days', 'Last Active'];
        $reportTitle = 'Manager Performance Report';
        break;

    case 'userActivityLogs':
        $stmt = $conn->prepare("
            SELECT u.username, u.user_role, l.action, DATE(l.log_date) as log_date, TIME(l.log_date) as log_time
            FROM user_activity_logs l
            JOIN users u ON l.user_id = u.user_id
            WHERE DATE(l.log_date) BETWEEN ? AND ?
            ORDER BY l.log_date DESC
            LIMIT 5000
        ");
        $stmt->execute([$startDate, $endDate]);
        $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $reportColumns = ['Username', 'Role', 'Action', 'Date', 'Time'];
        $reportTitle = 'User Activity Logs Report';
        break;

    case 'failedEggAnalysis':
        $stmt = $conn->prepare("
            SELECT u.username, e.batch_number, e.failed_count, e.total_egg,
                   ROUND((e.failed_count / e.total_egg) * 100, 2) as fail_rate,
                   DATE(e.date_started_incubation) as start_date
            FROM egg e
            JOIN users u ON e.user_id = u.user_id
            WHERE e.failed_count > 0 AND DATE(e.date_started_incubation) BETWEEN ? AND ?
            ORDER BY e.failed_count DESC
        ");
        $stmt->execute([$startDate, $endDate]);
        $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $reportColumns = ['User', 'Batch Number', 'Failed Eggs', 'Total Eggs', 'Failure Rate %', 'Start Date'];
        $reportTitle = 'Failed Egg Analysis Report';
        break;

    case 'monthlySummary':
        $stmt = $conn->prepare("
            SELECT DATE_FORMAT(e.date_started_incubation, '%Y-%m') as month,
                   COUNT(DISTINCT e.egg_id) as total_batches,
                   SUM(e.total_egg) as total_eggs,
                   SUM(e.balut_count) as total_balut,
                   SUM(e.chick_count) as total_chicks,
                   SUM(e.failed_count) as total_failed,
                   ROUND((SUM(e.balut_count) + SUM(e.chick_count)) / NULLIF(SUM(e.total_egg), 0) * 100, 2) as success_rate
            FROM egg e
            WHERE DATE(e.date_started_incubation) BETWEEN ? AND ?
            GROUP BY DATE_FORMAT(e.date_started_incubation, '%Y-%m')
            ORDER BY month DESC
        ");
        $stmt->execute([$startDate, $endDate]);
        $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $reportColumns = ['Month', 'Total Batches', 'Total Eggs', 'Total Balut', 'Total Chicks', 'Total Failed', 'Success Rate %'];
        $reportTitle = 'Monthly Production Summary';
        break;

    case 'roleDistribution':
        $stmt = $conn->prepare("
            SELECT user_role as role, COUNT(*) as count
            FROM users
            GROUP BY user_role
        ");
        $stmt->execute();
        $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $reportColumns = ['Role', 'Count'];
        $reportTitle = 'Role Distribution Report';
        break;
}

// ── STATISTICS for Overview ─────────────────────────────────────────────────
$totalUsers = $conn->query("SELECT COUNT(*) FROM users")->fetchColumn();
$totalAdmins = $conn->query("SELECT COUNT(*) FROM users WHERE user_role='admin'")->fetchColumn();
$totalManagers = $conn->query("SELECT COUNT(*) FROM users WHERE user_role='manager'")->fetchColumn();
$totalRegularUsers = $conn->query("SELECT COUNT(*) FROM users WHERE user_role='user'")->fetchColumn();
$totalBatches = $conn->query("SELECT COUNT(*) FROM egg")->fetchColumn();
$totalEggs = $conn->query("SELECT SUM(total_egg) FROM egg")->fetchColumn() ?: 0;
$totalChicks = $conn->query("SELECT SUM(chick_count) FROM egg")->fetchColumn() ?: 0;
$totalBalut = $conn->query("SELECT SUM(balut_count) FROM egg")->fetchColumn() ?: 0;
$totalFailed = $conn->query("SELECT SUM(failed_count) FROM egg")->fetchColumn() ?: 0;
$successRate = $totalEggs > 0 ? round((($totalChicks + $totalBalut) / $totalEggs) * 100, 1) : 0;

// Recent batches
$stmt = $conn->prepare("
    SELECT e.*, u.username,
           DATEDIFF(NOW(), e.date_started_incubation) as days_in_incubation
    FROM egg e
    JOIN users u ON e.user_id = u.user_id
    ORDER BY e.date_started_incubation DESC
    LIMIT 5
");
$stmt->execute();
$recentBatches = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Activity logs
$stmt = $conn->prepare("
    SELECT l.*, u.username
    FROM user_activity_logs l
    LEFT JOIN users u ON l.user_id = u.user_id
    WHERE l.log_date IS NOT NULL
    ORDER BY l.log_date DESC
    LIMIT 10
");
$stmt->execute();
$activityLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Analytics data
$dates = [];
$activityTrend = [];
for ($i = 13; $i >= 0; $i--) {
    $dates[] = date('Y-m-d', strtotime("-$i days"));
}

$stmt = $conn->prepare("
    SELECT DATE(log_date) as date, COUNT(*) as count
    FROM user_activity_logs
    WHERE log_date >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
    GROUP BY DATE(log_date)
");
$stmt->execute();
$dailyStats = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $dailyStats[$row['date']] = (int)$row['count'];
}
foreach ($dates as $date) {
    $activityTrend[] = isset($dailyStats[$date]) ? $dailyStats[$date] : 0;
}

$hourlyActivity = array_fill(0, 24, 0);
$stmt = $conn->prepare("
    SELECT HOUR(log_date) as hour, COUNT(*) as count
    FROM user_activity_logs
    WHERE log_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY HOUR(log_date)
");
$stmt->execute();
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $hourlyActivity[(int)$row['hour']] = (int)$row['count'];
}

$incubatingBatches = $conn->query("SELECT COUNT(*) FROM egg WHERE status='incubating'")->fetchColumn();
$completeBatches = $conn->query("SELECT COUNT(*) FROM egg WHERE status='complete'")->fetchColumn();

$formattedDates = array_map(function ($date) {
    return date('M d', strtotime($date));
}, $dates);

// Handle AJAX activity refresh
if (isset($_GET['get_activity_ajax'])) {
    $stmt = $conn->prepare("
        SELECT l.log_date, u.username, l.action 
        FROM user_activity_logs l 
        LEFT JOIN users u ON l.user_id = u.user_id 
        WHERE l.log_date IS NOT NULL 
        ORDER BY l.log_date DESC 
        LIMIT 10
    ");
    $stmt->execute();
    $freshLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $logsData = [];
    foreach ($freshLogs as $log) {
        $logsData[] = [
            'formatted_date' => formatDateTime($log['log_date']),
            'time_ago' => timeAgo($log['log_date']),
            'username' => $log['username'] ?? 'System',
            'action' => $log['action']
        ];
    }
    echo json_encode(['logs' => $logsData]);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Admin Dashboard | EggFlow</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: #f1f5f9;
            color: #1e293b;
            font-size: 13px;
            overflow-x: hidden;
        }

        .dashboard {
            display: flex;
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* Sidebar */
        .sidebar {
            width: 240px;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: white;
            transition: all 0.3s ease;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 1000;
        }

        .sidebar-header {
            padding: 1.2rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .sidebar-header h2 {
            font-size: 1.1rem;
            font-weight: 600;
        }

        .sidebar-header p {
            font-size: 0.65rem;
            opacity: 0.7;
            margin-top: 0.25rem;
        }

        .sidebar-menu {
            list-style: none;
            padding: 0.75rem 0;
        }

        .sidebar-menu li {
            margin: 0.15rem 0;
        }

        .sidebar-menu li a {
            display: flex;
            align-items: center;
            gap: 0.7rem;
            padding: 0.6rem 1.2rem;
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            transition: all 0.2s;
            font-size: 0.85rem;
            cursor: pointer;
        }

        .sidebar-menu li.active a,
        .sidebar-menu a:hover {
            background: rgba(16, 185, 129, 0.2);
            color: #10b981;
        }

        .mobile-menu-btn {
            display: none;
            position: fixed;
            top: 0.75rem;
            left: 0.75rem;
            z-index: 1001;
            background: #10b981;
            color: white;
            border: none;
            padding: 0.5rem 0.7rem;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1rem;
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
        }

        .main-content {
            flex: 1;
            margin-left: 240px;
            padding: 1rem;
            transition: margin-left 0.3s ease;
            width: calc(100% - 240px);
            overflow-x: auto;
        }

        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            flex-wrap: wrap;
            gap: 0.75rem;
        }

        .welcome-text h1 {
            font-size: 1.3rem;
            font-weight: 600;
            color: #0f172a;
        }

        .welcome-text p {
            font-size: 0.75rem;
            color: #64748b;
            margin-top: 0.2rem;
        }

        .date-badge {
            background: white;
            padding: 0.4rem 0.9rem;
            border-radius: 10px;
            font-size: 0.75rem;
            font-weight: 500;
            color: #1e293b;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
        }

        .tab-section {
            display: none;
        }

        .tab-section.active {
            display: block;
            animation: fadeIn 0.2s ease;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(5px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 0.75rem;
            margin-bottom: 1rem;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 0.75rem;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
            transition: all 0.2s;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .stat-card:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }

        .stat-info h3 {
            font-size: 0.65rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #64748b;
            margin-bottom: 0.35rem;
        }

        .stat-info p {
            font-size: 1.2rem;
            font-weight: 700;
            color: #0f172a;
        }

        .stat-icon {
            width: 32px;
            height: 32px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
        }

        .stat-card:nth-child(1) .stat-icon {
            background: rgba(16, 185, 129, 0.12);
            color: #10b981;
        }

        .stat-card:nth-child(2) .stat-icon {
            background: rgba(59, 130, 246, 0.12);
            color: #3b82f6;
        }

        .stat-card:nth-child(3) .stat-icon {
            background: rgba(245, 158, 11, 0.12);
            color: #f59e0b;
        }

        .stat-card:nth-child(4) .stat-icon {
            background: rgba(139, 92, 246, 0.12);
            color: #8b5cf6;
        }

        .stat-card:nth-child(5) .stat-icon {
            background: rgba(236, 72, 153, 0.12);
            color: #ec4899;
        }

        .stat-card:nth-child(6) .stat-icon {
            background: rgba(239, 68, 68, 0.12);
            color: #ef4444;
        }

        .chart-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 0.75rem;
            margin-bottom: 1rem;
        }

        .chart-card {
            background: white;
            border-radius: 12px;
            padding: 0.85rem;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
        }

        .chart-card h3 {
            font-size: 0.8rem;
            font-weight: 600;
            margin-bottom: 0.65rem;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }

        .chart-card canvas {
            max-height: 220px;
            width: 100% !important;
        }

        .table-container {
            background: white;
            border-radius: 12px;
            padding: 0.85rem;
            margin-bottom: 1rem;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
            width: 100%;
            overflow-x: auto;
        }

        .table-scroll-wrapper {
            width: 100%;
            overflow-x: auto;
            overflow-y: visible;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: thin;
        }

        .table-scroll-wrapper::-webkit-scrollbar {
            height: 6px;
        }

        .activity-scroll-wrapper {
            max-height: 400px;
            overflow-y: auto;
            overflow-x: auto;
            scrollbar-width: thin;
        }

        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.85rem;
            flex-wrap: wrap;
            gap: 0.6rem;
        }

        .table-header h3 {
            font-size: 0.9rem;
            font-weight: 600;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }

        .data-table {
            width: 100%;
            font-size: 0.75rem;
            border-collapse: collapse;
            min-width: 500px;
        }

        .data-table th {
            text-align: left;
            padding: 0.6rem 0.5rem;
            font-size: 0.7rem;
            font-weight: 600;
            color: #64748b;
            border-bottom: 2px solid #e2e8f0;
            background: white;
        }

        .data-table td {
            padding: 0.5rem 0.5rem;
            border-bottom: 1px solid #f1f5f9;
        }

        /* Report Controls */
        .report-controls {
            background: white;
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1rem;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            align-items: flex-end;
        }

        .report-controls .form-group {
            flex: 1;
            min-width: 140px;
        }

        .report-controls label {
            font-size: 0.7rem;
            font-weight: 600;
            color: #64748b;
            display: block;
            margin-bottom: 0.3rem;
        }

        .report-controls select,
        .report-controls input[type="date"] {
            width: 100%;
            padding: 0.5rem 0.7rem;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 0.75rem;
            color: #334155;
            background: white;
        }

        .btn {
            padding: 0.5rem 1rem;
            font-size: 0.75rem;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: #10b981;
            color: white;
        }

        .btn-primary:hover {
            background: #059669;
        }

        .btn-success {
            background: #059669;
            color: white;
        }

        .btn-outline {
            background: white;
            border: 1px solid #e2e8f0;
            color: #334155;
        }

        .btn-outline:hover {
            background: #f1f5f9;
        }

        .role-badge {
            display: inline-block;
            padding: 0.2rem 0.55rem;
            border-radius: 999px;
            font-size: 0.65rem;
            font-weight: 600;
        }

        .role-badge.admin {
            background: #fee2e2;
            color: #991b1b;
        }

        .role-badge.manager {
            background: #fed7aa;
            color: #92400e;
        }

        .role-badge.user {
            background: #dcfce7;
            color: #166534;
        }

        .badge {
            padding: 0.2rem 0.55rem;
            border-radius: 999px;
            font-size: 0.65rem;
            font-weight: 600;
        }

        .badge-success {
            background: #dcfce7;
            color: #166534;
        }

        .badge-warning {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-info {
            background: #dbeafe;
            color: #1e40af;
        }

        .badge-danger {
            background: #fee2e2;
            color: #991b1b;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .avatar {
            width: 28px;
            height: 28px;
            border-radius: 8px;
            background: #10b981;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .activity-time {
            font-size: 0.7rem;
            color: #64748b;
            white-space: nowrap;
        }

        .toast {
            position: fixed;
            bottom: 1.5rem;
            right: 1.5rem;
            z-index: 9999;
            padding: 0.6rem 1rem;
            border-radius: 10px;
            color: white;
            font-size: 0.8rem;
            font-weight: 500;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            display: none;
            align-items: center;
            gap: 0.5rem;
        }

        .toast.show {
            display: flex;
        }

        .toast.success {
            background: #10b981;
        }

        .toast.error {
            background: #ef4444;
        }

        /* Print Styles */
        @media print {

            .sidebar,
            .mobile-menu-btn,
            .sidebar-overlay,
            .top-bar,
            .stats-grid,
            .chart-row,
            .report-controls .btn,
            .table-container:not(#print-area),
            #print-area .table-header .btn,
            .date-badge,
            .tab-section:not(.active) {
                display: none !important;
            }

            .main-content {
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
            }

            #print-area {
                display: block !important;
                margin: 0;
                padding: 0.5in;
            }

            #print-area .table-container {
                box-shadow: none;
                padding: 0;
                margin: 0;
            }

            #print-area .data-table {
                font-size: 10pt;
                border-collapse: collapse;
                width: 100%;
            }

            #print-area .data-table th,
            #print-area .data-table td {
                border: 1px solid #ddd;
                padding: 8px;
            }

            #print-area .data-table th {
                background-color: #f2f2f2;
            }

            .report-header {
                text-align: center;
                margin-bottom: 20px;
            }

            .report-header h2 {
                font-size: 18pt;
                margin-bottom: 5px;
            }

            .report-header p {
                font-size: 10pt;
                color: #666;
            }
        }

        @media (max-width: 768px) {
            .mobile-menu-btn {
                display: block;
            }

            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.open {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
                padding: 0.85rem;
                padding-top: 3.5rem;
                width: 100%;
            }

            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
            }

            .chart-row {
                grid-template-columns: 1fr;
            }

            .report-controls {
                flex-direction: column;
                align-items: stretch;
            }

            .report-controls .form-group {
                min-width: auto;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .table-header {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>

<body>
    <button class="mobile-menu-btn" id="mobileMenuBtn">
        <i class="fas fa-bars"></i>
    </button>
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="closeMobileMenu()"></div>

    <div class="dashboard">
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h2><i class="fas fa-chart-line"></i> EggFlow</h2>
                <p>Admin Panel</p>
            </div>
            <ul class="sidebar-menu">
                <li class="<?= $activeTab == 'overview' ? 'active' : '' ?>" data-tab="overview">
                    <a onclick="switchTab('overview')"><i class="fas fa-tachometer-alt"></i> Overview</a>
                </li>
                <li>
                    <a href="../users/user-management.php"><i class="fas fa-users"></i> User Management</a>
                </li>
                <li class="<?= $activeTab == 'analytics' ? 'active' : '' ?>" data-tab="analytics">
                    <a onclick="switchTab('analytics')"><i class="fas fa-chart-line"></i> Analytics</a>
                </li>
                <li class="<?= $activeTab == 'reports' ? 'active' : '' ?>" data-tab="reports">
                    <a onclick="switchTab('reports')"><i class="fas fa-file-alt"></i> Reports</a>
                </li>
                <li>
                    <a href="../../controller/auth/signout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
                </li>
            </ul>
        </aside>

        <main class="main-content">
            <div class="top-bar">
                <div class="welcome-text">
                    <h1>Admin Dashboard</h1>
                    <p id="page-subtitle"><?= $activeTab == 'overview' ? 'System overview & key metrics' : ($activeTab == 'analytics' ? 'System analytics & insights' : 'Generate, export & print reports') ?></p>
                </div>
                <div class="date-badge">
                    <i class="far fa-calendar-alt"></i> <?= date('M d, Y') ?>
                </div>
            </div>

            <!-- ═══════════════ OVERVIEW TAB ═══════════════ -->
            <div id="overview-section" class="tab-section <?= $activeTab == 'overview' ? 'active' : '' ?>">
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-info">
                            <h3>Total Users</h3>
                            <p><?= number_format($totalUsers) ?></p>
                        </div>
                        <div class="stat-icon"><i class="fas fa-users"></i></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-info">
                            <h3>Total Admins</h3>
                            <p><?= number_format($totalAdmins) ?></p>
                        </div>
                        <div class="stat-icon"><i class="fas fa-user-shield"></i></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-info">
                            <h3>Total Managers</h3>
                            <p><?= number_format($totalManagers) ?></p>
                        </div>
                        <div class="stat-icon"><i class="fas fa-user-tie"></i></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-info">
                            <h3>Regular Users</h3>
                            <p><?= number_format($totalRegularUsers) ?></p>
                        </div>
                        <div class="stat-icon"><i class="fas fa-user"></i></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-info">
                            <h3>Total Batches</h3>
                            <p><?= number_format($totalBatches) ?></p>
                        </div>
                        <div class="stat-icon"><i class="fas fa-egg"></i></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-info">
                            <h3>Success Rate</h3>
                            <p><?= $successRate ?>%</p>
                        </div>
                        <div class="stat-icon"><i class="fas fa-percentage"></i></div>
                    </div>
                </div>

                <div class="table-container">
                    <div class="table-header">
                        <h3><i class="fas fa-egg"></i> Recent Egg Batches</h3>
                    </div>
                    <div class="table-scroll-wrapper">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Batch #</th>
                                    <th>User</th>
                                    <th>Total Eggs</th>
                                    <th>Chicks</th>
                                    <th>Balut</th>
                                    <th>Status</th>
                                    <th>Days</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recentBatches)): ?>
                                    <tr>
                                        <td colspan="7" style="text-align:center">No batches found</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($recentBatches as $batch): ?>
                                        <tr>
                                            <td><strong>#<?= htmlspecialchars($batch['batch_number'] ?? $batch['egg_id']) ?></strong></td>
                                            <td><?= htmlspecialchars($batch['username']) ?></td>
                                            <td><?= number_format($batch['total_egg']) ?></td>
                                            <td><?= number_format($batch['chick_count']) ?></td>
                                            <td><?= number_format($batch['balut_count']) ?></td>
                                            <td><span class="badge <?= $batch['status'] == 'incubating' ? 'badge-warning' : 'badge-success' ?>"><?= ucfirst($batch['status']) ?></span></td>
                                            <td><?= $batch['days_in_incubation'] ?? 0 ?> days</td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="table-container">
                    <div class="table-header">
                        <h3><i class="fas fa-history"></i> Recent Activity</h3>
                        <button class="btn btn-outline" onclick="exportActivityCSV()"><i class="fas fa-download"></i> Export All Logs</button>
                    </div>
                    <div class="activity-scroll-wrapper">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Date & Time</th>
                                    <th>User</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody id="activityLogsBody">
                                <?php if ($activityLogs): ?>
                                    <?php foreach ($activityLogs as $log): ?>
                                        <tr>
                                            <td class="activity-time"><i class="far fa-clock"></i> <?= formatDateTime($log['log_date']) ?> <small>(<?= timeAgo($log['log_date']) ?>)</small></td>
                                            <td><?= htmlspecialchars($log['username'] ?? 'System') ?></td>
                                            <td><?= htmlspecialchars($log['action']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="3" style="text-align:center">No activity logs found</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ═══════════════ ANALYTICS TAB ═══════════════ -->
            <div id="analytics-section" class="tab-section <?= $activeTab == 'analytics' ? 'active' : '' ?>">
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-info">
                            <h3>Total Eggs</h3>
                            <p><?= number_format($totalEggs) ?></p>
                        </div>
                        <div class="stat-icon"><i class="fas fa-layer-group"></i></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-info">
                            <h3>Total Chicks</h3>
                            <p><?= number_format($totalChicks) ?></p>
                        </div>
                        <div class="stat-icon"><i class="fas fa-hat-wizard"></i></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-info">
                            <h3>Total Balut</h3>
                            <p><?= number_format($totalBalut) ?></p>
                        </div>
                        <div class="stat-icon"><i class="fas fa-drumstick-bite"></i></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-info">
                            <h3>Total Failed</h3>
                            <p><?= number_format($totalFailed) ?></p>
                        </div>
                        <div class="stat-icon"><i class="fas fa-times-circle"></i></div>
                    </div>
                </div>

                <div class="chart-row">
                    <div class="chart-card">
                        <h3><i class="fas fa-chart-line" style="color:#10b981;"></i> Daily Activity Trend</h3><canvas id="dailyTrendChart"></canvas>
                    </div>
                    <div class="chart-card">
                        <h3><i class="fas fa-chart-bar" style="color:#3b82f6;"></i> Activity by Hour</h3><canvas id="hourlyChart"></canvas>
                    </div>
                </div>

                <div class="chart-row">
                    <div class="chart-card">
                        <h3><i class="fas fa-chart-pie" style="color:#f59e0b;"></i> Batch Status Distribution</h3><canvas id="batchStatusChart"></canvas>
                    </div>
                    <div class="chart-card">
                        <h3><i class="fas fa-chart-pie" style="color:#8b5cf6;"></i> User Role Distribution</h3><canvas id="userRoleChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- ═══════════════ REPORTS TAB - FULL PROFESSIONAL REPORTING ═══════════════ -->
            <div id="reports-section" class="tab-section <?= $activeTab == 'reports' ? 'active' : '' ?>">
                <!-- Report Controls -->
                <div class="report-controls">
                    <div class="form-group">
                        <label><i class="fas fa-chart-simple"></i> Report Type</label>
                        <select id="reportType">
                            <option value="userSummary" <?= $reportType == 'userSummary' ? 'selected' : '' ?>>1. User Summary Report</option>
                            <option value="batchProduction" <?= $reportType == 'batchProduction' ? 'selected' : '' ?>>2. Batch Production Report</option>
                            <option value="dailyEggLogs" <?= $reportType == 'dailyEggLogs' ? 'selected' : '' ?>>3. Daily Egg Logs Report</option>
                            <option value="managerPerformance" <?= $reportType == 'managerPerformance' ? 'selected' : '' ?>>4. Manager Performance Report</option>
                            <option value="userActivityLogs" <?= $reportType == 'userActivityLogs' ? 'selected' : '' ?>>5. User Activity Logs Report</option>
                            <option value="failedEggAnalysis" <?= $reportType == 'failedEggAnalysis' ? 'selected' : '' ?>>6. Failed Egg Analysis Report</option>
                            <option value="monthlySummary" <?= $reportType == 'monthlySummary' ? 'selected' : '' ?>>7. Monthly Production Summary</option>
                            <option value="roleDistribution" <?= $reportType == 'roleDistribution' ? 'selected' : '' ?>>8. Role Distribution Report</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-calendar-alt"></i> Start Date</label>
                        <input type="date" id="startDate" value="<?= $startDate ?>">
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-calendar-alt"></i> End Date</label>
                        <input type="date" id="endDate" value="<?= $endDate ?>">
                    </div>
                    <button class="btn btn-primary" onclick="generateReport()">
                        <i class="fas fa-chart-bar"></i> Generate Report
                    </button>
                    <button class="btn btn-outline" onclick="exportReportCSV()">
                        <i class="fas fa-file-csv"></i> Export CSV
                    </button>
                    <button class="btn btn-outline" onclick="printReport()">
                        <i class="fas fa-print"></i> Print Report
                    </button>
                </div>

                <!-- Report Preview Area (Printable) -->
                <div id="print-area">
                    <div class="table-container">
                        <div class="table-header">
                            <h3 id="reportTitle"><i class="fas fa-chart-line"></i> <?= $reportTitle ?? 'Report Preview' ?></h3>
                            <span id="reportDateRange" style="font-size:0.7rem; color:#64748b;">
                                <?php if ($reportType != 'roleDistribution'): ?>
                                    <i class="far fa-calendar"></i> <?= date('M d, Y', strtotime($startDate)) ?> - <?= date('M d, Y', strtotime($endDate)) ?>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="table-scroll-wrapper" id="reportContent">
                            <?php if ($reportData && count($reportData) > 0): ?>
                                <table class="data-table" id="reportTable">
                                    <thead>
                                        <tr>
                                            <?php foreach ($reportColumns as $col): ?>
                                                <th><?= htmlspecialchars($col) ?></th>
                                            <?php endforeach; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($reportData as $row): ?>
                                            <tr>
                                                <?php foreach (array_values($row) as $value): ?>
                                                    <td><?= htmlspecialchars($value ?? '0') ?></td>
                                                <?php endforeach; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <?php if ($reportType == 'monthlySummary' && count($reportData) > 0): ?>
                                    <div style="margin-top:1rem; padding:0.75rem; background:#f0fdf4; border-radius:8px; text-align:center;">
                                        <strong>Overall Success Rate: </strong>
                                        <?php
                                        $totalSuccess = 0;
                                        $totalMonths = 0;
                                        foreach ($reportData as $row) {
                                            $totalSuccess += floatval($row['success_rate']);
                                            $totalMonths++;
                                        }
                                        $avgSuccess = $totalMonths > 0 ? round($totalSuccess / $totalMonths, 1) : 0;
                                        ?>
                                        <?= $avgSuccess ?>% average across <?= $totalMonths ?> month(s)
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div style="text-align:center; padding: 3rem; color:#94a3b8;">
                                    <i class="fas fa-chart-simple" style="font-size: 2rem; margin-bottom: 0.5rem; display: block;"></i>
                                    Select a report type and click Generate Report
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <div id="toast" class="toast"><i class="fas fa-check-circle"></i> <span id="toastMsg"></span></div>

    <script>
        // Chart data
        const chartData = {
            dates: <?= json_encode($formattedDates) ?>,
            activityTrend: <?= json_encode($activityTrend) ?>,
            hourlyActivity: <?= json_encode(array_values($hourlyActivity)) ?>,
            incubating: <?= $incubatingBatches ?>,
            complete: <?= $completeBatches ?>,
            adminCount: <?= $totalAdmins ?>,
            managerCount: <?= $totalManagers ?>,
            userCount: <?= $totalRegularUsers ?>
        };

        let dailyChart, hourlyChart, batchStatusChart, userRoleChart;

        function initCharts() {
            const dailyCtx = document.getElementById('dailyTrendChart')?.getContext('2d');
            if (dailyCtx) {
                dailyChart = new Chart(dailyCtx, {
                    type: 'line',
                    data: {
                        labels: chartData.dates,
                        datasets: [{
                            label: 'Activities',
                            data: chartData.activityTrend,
                            borderColor: '#10b981',
                            backgroundColor: 'rgba(16, 185, 129, 0.1)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        scales: {
                            y: {
                                beginAtZero: true
                            }
                        }
                    }
                });
            }

            const hourlyCtx = document.getElementById('hourlyChart')?.getContext('2d');
            if (hourlyCtx) {
                const hourLabels = Array.from({
                    length: 24
                }, (_, i) => `${String(i).padStart(2, '0')}:00`);
                hourlyChart = new Chart(hourlyCtx, {
                    type: 'bar',
                    data: {
                        labels: hourLabels,
                        datasets: [{
                            label: 'Activities',
                            data: chartData.hourlyActivity,
                            backgroundColor: '#10b981',
                            borderRadius: 4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        scales: {
                            y: {
                                beginAtZero: true
                            }
                        }
                    }
                });
            }

            const batchCtx = document.getElementById('batchStatusChart')?.getContext('2d');
            if (batchCtx) {
                batchStatusChart = new Chart(batchCtx, {
                    type: 'doughnut',
                    data: {
                        labels: ['Incubating', 'Complete'],
                        datasets: [{
                            data: [chartData.incubating, chartData.complete],
                            backgroundColor: ['#f59e0b', '#10b981']
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        plugins: {
                            legend: {
                                position: 'bottom'
                            }
                        }
                    }
                });
            }

            const roleCtx = document.getElementById('userRoleChart')?.getContext('2d');
            if (roleCtx) {
                userRoleChart = new Chart(roleCtx, {
                    type: 'pie',
                    data: {
                        labels: ['Admin', 'Manager', 'User'],
                        datasets: [{
                            data: [chartData.adminCount, chartData.managerCount, chartData.userCount],
                            backgroundColor: ['#ef4444', '#f59e0b', '#10b981']
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        plugins: {
                            legend: {
                                position: 'bottom'
                            }
                        }
                    }
                });
            }
        }

        function switchTab(tabName) {
            const url = new URL(window.location.href);
            url.searchParams.set('tab', tabName);
            window.history.pushState({}, '', url);

            document.querySelectorAll('.sidebar-menu li').forEach(li => li.classList.remove('active'));
            document.querySelector(`.sidebar-menu li[data-tab="${tabName}"]`)?.classList.add('active');

            document.querySelectorAll('.tab-section').forEach(section => section.classList.remove('active'));
            document.getElementById(`${tabName}-section`).classList.add('active');

            const subtitles = {
                overview: 'System overview & key metrics',
                analytics: 'System analytics & insights',
                reports: 'Generate, export & print reports'
            };
            document.getElementById('page-subtitle').textContent = subtitles[tabName];

            if (tabName === 'analytics') {
                setTimeout(() => {
                    if (dailyChart) dailyChart.resize();
                    if (hourlyChart) hourlyChart.resize();
                    if (batchStatusChart) batchStatusChart.resize();
                    if (userRoleChart) userRoleChart.resize();
                }, 100);
            }
            if (window.innerWidth <= 768) closeMobileMenu();
        }

        function generateReport() {
            const reportType = document.getElementById('reportType').value;
            const startDate = document.getElementById('startDate').value;
            const endDate = document.getElementById('endDate').value;
            window.location.href = `?tab=reports&report=${reportType}&start=${startDate}&end=${endDate}`;
        }

        function exportReportCSV() {
            const reportType = document.getElementById('reportType').value;
            const startDate = document.getElementById('startDate').value;
            const endDate = document.getElementById('endDate').value;
            window.location.href = `?export_csv=1&report_type=${reportType}&start=${startDate}&end=${endDate}`;
            showToast('Exporting report...', 'success');
        }

        function printReport() {
            const reportTitle = document.getElementById('reportTitle')?.innerText || 'System Report';
            const reportDateRange = document.getElementById('reportDateRange')?.innerText || '';

            const printContent = document.getElementById('print-area').innerHTML;
            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>${reportTitle}</title>
                    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
                    <style>
                        * { margin: 0; padding: 0; box-sizing: border-box; }
                        body { font-family: 'Inter', sans-serif; padding: 0.5in; background: white; }
                        .report-header { text-align: center; margin-bottom: 20px; }
                        .report-header h2 { font-size: 18pt; margin-bottom: 5px; color: #0f172a; }
                        .report-header p { font-size: 10pt; color: #64748b; }
                        .data-table { width: 100%; font-size: 9pt; border-collapse: collapse; margin-top: 15px; }
                        .data-table th { background: #f1f5f9; padding: 8px; text-align: left; border: 1px solid #e2e8f0; }
                        .data-table td { padding: 6px 8px; border: 1px solid #e2e8f0; }
                        .summary-box { margin-top: 20px; padding: 10px; background: #f0fdf4; border-radius: 8px; text-align: center; }
                        @media print {
                            body { padding: 0; }
                            .no-print { display: none; }
                        }
                    </style>
                </head>
                <body>
                    <div class="report-header">
                        <h2>${reportTitle.replace(/<[^>]*>/g, '')}</h2>
                        <p>Generated on: ${new Date().toLocaleString()} | ${reportDateRange}</p>
                    </div>
                    ${printContent}
                </body>
                </html>
            `);
            printWindow.document.close();
            printWindow.print();
            printWindow.onafterprint = () => printWindow.close();
            showToast('Preparing print...', 'success');
        }

        function exportActivityCSV() {
            window.location.href = '?export_activity=csv';
            showToast('Exporting activity logs...', 'success');
        }

        function showToast(msg, type = 'success') {
            const toast = document.getElementById('toast');
            document.getElementById('toastMsg').textContent = msg;
            toast.className = `toast show ${type}`;
            setTimeout(() => toast.classList.remove('show'), 3000);
        }

        function toggleMobileMenu() {
            document.getElementById('sidebar').classList.toggle('open');
            const overlay = document.getElementById('sidebarOverlay');
            if (overlay) overlay.style.display = document.getElementById('sidebar').classList.contains('open') ? 'block' : 'none';
        }

        function closeMobileMenu() {
            document.getElementById('sidebar').classList.remove('open');
            const overlay = document.getElementById('sidebarOverlay');
            if (overlay) overlay.style.display = 'none';
        }

        document.getElementById('mobileMenuBtn')?.addEventListener('click', toggleMobileMenu);

        // Auto-refresh activity logs every 30 seconds
        setInterval(function() {
            if ('<?= $activeTab ?>' === 'overview') {
                fetch(window.location.href + '&get_activity_ajax=1&nocache=' + Date.now(), {
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.logs && data.logs.length > 0) {
                            const tbody = document.getElementById('activityLogsBody');
                            if (tbody) {
                                let html = '';
                                data.logs.forEach(log => {
                                    html += `<tr><td class="activity-time"><i class="far fa-clock"></i> ${escapeHtml(log.formatted_date)} <small>(${escapeHtml(log.time_ago)})</small></td><td>${escapeHtml(log.username)}</td><td>${escapeHtml(log.action)}</td></tr>`;
                                });
                                tbody.innerHTML = html;
                            }
                        }
                    })
                    .catch(err => console.log('Auto-refresh failed:', err));
            }
        }, 30000);

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        document.addEventListener('DOMContentLoaded', initCharts);
    </script>
</body>

</html>