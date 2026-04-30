<?php
require '../../model/config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../../index.php");
    exit;
}

$user_id = $_SESSION['user_id'];

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

// Log access
if (!isset($_SESSION['admin_dashboard_logged'])) {
    $stmt = $conn->prepare("INSERT INTO user_activity_logs (user_id, action, log_date) VALUES (?, ?, NOW())");
    $stmt->execute([$user_id, "Admin accessed dashboard"]);
    $_SESSION['admin_dashboard_logged'] = true;
}

// Get active tab
$activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'overview';
$validTabs = ['overview', 'analytics', 'reports'];
if (!in_array($activeTab, $validTabs)) {
    $activeTab = 'overview';
}

// ─────────────────────────────────────────────────────────────────────────────
// OVERVIEW TAB STATISTICS
// ─────────────────────────────────────────────────────────────────────────────

// User Statistics
$totalUsers = $conn->query("SELECT COUNT(*) FROM users")->fetchColumn();
$totalAdmins = $conn->query("SELECT COUNT(*) FROM users WHERE user_role='admin'")->fetchColumn();
$totalManagers = $conn->query("SELECT COUNT(*) FROM users WHERE user_role='manager'")->fetchColumn();
$totalRegularUsers = $conn->query("SELECT COUNT(*) FROM users WHERE user_role='user'")->fetchColumn();

// User Growth (Last 7 days)
$userGrowth = [];
$growthLabels = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $growthLabels[] = date('M d', strtotime($date));
    $stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE DATE(created_at) = ?");
    $stmt->execute([$date]);
    $userGrowth[] = (int)$stmt->fetchColumn();
}

// Production Statistics
$totalBatches = $conn->query("SELECT COUNT(*) FROM egg")->fetchColumn();
$totalEggs = $conn->query("SELECT SUM(total_egg) FROM egg")->fetchColumn() ?: 0;
$totalChicks = $conn->query("SELECT SUM(chick_count) FROM egg")->fetchColumn() ?: 0;
$totalBalut = $conn->query("SELECT SUM(balut_count) FROM egg")->fetchColumn() ?: 0;
$totalFailed = $conn->query("SELECT SUM(failed_count) FROM egg")->fetchColumn() ?: 0;

// Success/Failure Rates
$totalSuccessful = $totalChicks + $totalBalut;
$successRate = $totalEggs > 0 ? round(($totalSuccessful / $totalEggs) * 100, 1) : 0;
$failureRate = $totalEggs > 0 ? round(($totalFailed / $totalEggs) * 100, 1) : 0;

// Batch Status Distribution
$incubatingBatches = $conn->query("SELECT COUNT(*) FROM egg WHERE status='incubating'")->fetchColumn();
$completeBatches = $conn->query("SELECT COUNT(*) FROM egg WHERE status='complete'")->fetchColumn();

// Recent Activity (Last 24 hours)
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM user_activity_logs WHERE log_date >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
$stmt->execute();
$activity24h = $stmt->fetchColumn();

// Top Performing Users
$stmt = $conn->prepare("
    SELECT u.username, 
           COUNT(DISTINCT e.egg_id) as batch_count,
           SUM(e.total_egg) as total_eggs,
           SUM(e.chick_count) + SUM(e.balut_count) as total_success,
           SUM(e.failed_count) as total_failed,
           ROUND((SUM(e.chick_count) + SUM(e.balut_count)) / NULLIF(SUM(e.total_egg), 0) * 100, 1) as success_rate
    FROM users u
    JOIN egg e ON u.user_id = e.user_id
    WHERE u.user_role = 'user'
    GROUP BY u.user_id
    ORDER BY total_success DESC
    LIMIT 5
");
$stmt->execute();
$topPerformers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent Batches
$stmt = $conn->prepare("
    SELECT e.*, u.username, DATEDIFF(NOW(), e.date_started_incubation) as days_in_incubation
    FROM egg e
    JOIN users u ON e.user_id = u.user_id
    ORDER BY e.date_started_incubation DESC
    LIMIT 10
");
$stmt->execute();
$recentBatches = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent Activity Logs
$stmt = $conn->prepare("
    SELECT l.*, u.username
    FROM user_activity_logs l
    LEFT JOIN users u ON l.user_id = u.user_id
    WHERE l.log_date IS NOT NULL
    ORDER BY l.log_date DESC
    LIMIT 15
");
$stmt->execute();
$activityLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Active Users Today
$stmt = $conn->prepare("SELECT COUNT(DISTINCT user_id) as active_users FROM user_activity_logs WHERE DATE(log_date) = CURDATE()");
$stmt->execute();
$activeToday = $stmt->fetchColumn();

// ─────────────────────────────────────────────────────────────────────────────
// ANALYTICS TAB - Advanced Statistics
// ─────────────────────────────────────────────────────────────────────────────

// Daily Production Trend (Last 30 days)
$dailyProduction = [];
$dailyLabels = [];
for ($i = 29; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $dailyLabels[] = date('M d', strtotime($date));

    $stmt = $conn->prepare("
        SELECT 
            COALESCE(SUM(balut_count), 0) as balut,
            COALESCE(SUM(chick_count), 0) as chicks,
            COALESCE(SUM(failed_count), 0) as failed,
            COALESCE(COUNT(*), 0) as batches
        FROM egg
        WHERE DATE(date_started_incubation) = ?
    ");
    $stmt->execute([$date]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $dailyProduction[] = [
        'balut' => (int)$result['balut'],
        'chicks' => (int)$result['chicks'],
        'failed' => (int)$result['failed'],
        'batches' => (int)$result['batches']
    ];
}

// Weekly Production Summary (Last 4 weeks)
$weeklySummary = [];
for ($i = 3; $i >= 0; $i--) {
    $weekStart = date('Y-m-d', strtotime("-$i weeks"));
    $weekEnd = date('Y-m-d', strtotime("-$i weeks +6 days"));
    $weekNumber = 4 - $i;

    $stmt = $conn->prepare("
        SELECT 
            COUNT(DISTINCT egg_id) as batches,
            COALESCE(SUM(total_egg), 0) as total_eggs,
            COALESCE(SUM(balut_count), 0) as balut,
            COALESCE(SUM(chick_count), 0) as chicks,
            COALESCE(SUM(failed_count), 0) as failed
        FROM egg
        WHERE DATE(date_started_incubation) BETWEEN ? AND ?
    ");
    $stmt->execute([$weekStart, $weekEnd]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    $weeklySummary[] = [
        'week_label' => "Week $weekNumber",
        'batches' => (int)$result['batches'],
        'total_eggs' => (int)$result['total_eggs'],
        'balut' => (int)$result['balut'],
        'chicks' => (int)$result['chicks'],
        'failed' => (int)$result['failed']
    ];
}

// Hourly Activity Pattern
$hourlyActivity = array_fill(0, 24, 0);
$stmt = $conn->prepare("SELECT HOUR(log_date) as hour, COUNT(*) as count FROM user_activity_logs WHERE log_date >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY HOUR(log_date)");
$stmt->execute();
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $hourlyActivity[(int)$row['hour']] = (int)$row['count'];
}

// Batch Efficiency Distribution
$stmt = $conn->prepare("
    SELECT 
        CASE 
            WHEN (balut_count + chick_count) / total_egg >= 0.9 THEN 'Excellent (90%+)'
            WHEN (balut_count + chick_count) / total_egg >= 0.7 THEN 'Good (70-89%)'
            WHEN (balut_count + chick_count) / total_egg >= 0.5 THEN 'Average (50-69%)'
            ELSE 'Poor (<50%)'
        END as efficiency_level,
        COUNT(*) as batch_count,
        ROUND(AVG((balut_count + chick_count) / total_egg * 100), 1) as avg_efficiency
    FROM egg
    WHERE total_egg > 0
    GROUP BY efficiency_level
    ORDER BY avg_efficiency DESC
");
$stmt->execute();
$efficiencyDistribution = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($efficiencyDistribution)) {
    $efficiencyDistribution = [
        ['efficiency_level' => 'Excellent (90%+)', 'batch_count' => 0, 'avg_efficiency' => 0],
        ['efficiency_level' => 'Good (70-89%)', 'batch_count' => 0, 'avg_efficiency' => 0],
        ['efficiency_level' => 'Average (50-69%)', 'batch_count' => 0, 'avg_efficiency' => 0],
        ['efficiency_level' => 'Poor (<50%)', 'batch_count' => 0, 'avg_efficiency' => 0]
    ];
}

// Failure Analysis by Day
$stmt = $conn->prepare("SELECT day_number, ROUND(AVG(failed_count), 2) as avg_failures, COUNT(*) as log_count FROM egg_daily_logs GROUP BY day_number ORDER BY day_number");
$stmt->execute();
$failureByDay = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Active users stats
$totalUsersWithBatches = $conn->query("SELECT COUNT(DISTINCT user_id) FROM egg")->fetchColumn();
$activeUsersLast30 = $conn->prepare("SELECT COUNT(DISTINCT user_id) FROM egg WHERE date_started_incubation >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
$activeUsersLast30->execute();
$activeUsersLast30Count = $activeUsersLast30->fetchColumn();
$retentionRate = $totalUsersWithBatches > 0 ? round(($activeUsersLast30Count / $totalUsersWithBatches) * 100, 1) : 0;

// Production by role
$stmt = $conn->prepare("
    SELECT u.user_role,
           COUNT(DISTINCT e.egg_id) as total_batches,
           COALESCE(SUM(e.total_egg), 0) as total_eggs,
           COALESCE(SUM(e.balut_count), 0) as total_balut,
           COALESCE(SUM(e.chick_count), 0) as total_chicks,
           COALESCE(SUM(e.failed_count), 0) as total_failed
    FROM users u
    LEFT JOIN egg e ON u.user_id = e.user_id
    GROUP BY u.user_role
");
$stmt->execute();
$productionByRole = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Most active users
$stmt = $conn->prepare("
    SELECT u.username, u.user_role, COUNT(l.log_id) as action_count
    FROM users u
    LEFT JOIN user_activity_logs l ON u.user_id = l.user_id AND l.log_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY u.user_id
    ORDER BY action_count DESC
    LIMIT 10
");
$stmt->execute();
$mostActiveUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle AJAX activity refresh
if (isset($_GET['get_activity_ajax'])) {
    $stmt = $conn->prepare("
        SELECT l.log_date, u.username, l.action 
        FROM user_activity_logs l 
        LEFT JOIN users u ON l.user_id = u.user_id 
        WHERE l.log_date IS NOT NULL 
        ORDER BY l.log_date DESC 
        LIMIT 15
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

// Handle activity log export
if (isset($_GET['export_activity']) && $_GET['export_activity'] === 'csv') {
    $stmt = $conn->prepare("
        SELECT l.log_date, u.username, l.action
        FROM user_activity_logs l
        LEFT JOIN users u ON l.user_id = u.user_id
        WHERE l.log_date IS NOT NULL
        ORDER BY l.log_date DESC
    ");
    $stmt->execute();
    $allLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="admin_activity_logs_' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($output, ['Date & Time', 'User', 'Action']);
    foreach ($allLogs as $log) {
        fputcsv($output, [formatDateTime($log['log_date']), $log['username'] ?? 'System', $log['action']]);
    }
    fclose($output);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// REPORTS TAB - Report Generation
// ─────────────────────────────────────────────────────────────────────────────

$reportType = isset($_GET['report']) ? $_GET['report'] : 'userSummary';
$startDate = isset($_GET['start']) ? $_GET['start'] : date('Y-m-01');
$endDate = isset($_GET['end']) ? $_GET['end'] : date('Y-m-d');
$reportData = [];
$reportColumns = [];
$reportTitle = '';

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
            $stmt->execute([$exportStart, $exportEnd]);
            $exportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $exportHeaders = ['Username', 'Role', 'Created Date', 'Total Batches', 'Total Balut', 'Total Chicks', 'Total Failed'];
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
                       COUNT(DISTINCT al.log_id) as action_count,
                       COUNT(DISTINCT DATE(al.log_date)) as active_days
                FROM users u
                LEFT JOIN user_activity_logs al ON u.user_id = al.user_id AND DATE(al.log_date) BETWEEN ? AND ?
                WHERE u.user_role = 'manager'
                GROUP BY u.user_id
                ORDER BY action_count DESC
            ");
            $stmt->execute([$exportStart, $exportEnd]);
            $exportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $exportHeaders = ['Manager Name', 'Created Date', 'Actions Performed', 'Active Days'];
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
                       COALESCE(SUM(e.total_egg), 0) as total_eggs,
                       COALESCE(SUM(e.balut_count), 0) as total_balut,
                       COALESCE(SUM(e.chick_count), 0) as total_chicks,
                       COALESCE(SUM(e.failed_count), 0) as total_failed,
                       ROUND((COALESCE(SUM(e.balut_count), 0) + COALESCE(SUM(e.chick_count), 0)) / NULLIF(COALESCE(SUM(e.total_egg), 0), 0) * 100, 2) as success_rate
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
                SELECT user_role as role, COUNT(*) as count,
                       ROUND(COUNT(*) / (SELECT COUNT(*) FROM users) * 100, 1) as percentage
                FROM users
                GROUP BY user_role
            ");
            $stmt->execute();
            $exportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $exportHeaders = ['Role', 'Count', 'Percentage (%)'];
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
            LIMIT 1000
        ");
        $stmt->execute([$startDate, $endDate]);
        $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $reportColumns = ['Batch Number', 'User', 'Day Number', 'Daily Balut', 'Daily Chicks', 'Daily Failed', 'Log Date'];
        $reportTitle = 'Daily Egg Logs Report';
        break;

    case 'managerPerformance':
        $stmt = $conn->prepare("
            SELECT u.username, DATE(u.created_at) as created_date,
                   COUNT(DISTINCT al.log_id) as action_count,
                   COUNT(DISTINCT DATE(al.log_date)) as active_days,
                   MAX(DATE(al.log_date)) as last_active
            FROM users u
            LEFT JOIN user_activity_logs al ON u.user_id = al.user_id AND DATE(al.log_date) BETWEEN ? AND ?
            WHERE u.user_role = 'manager'
            GROUP BY u.user_id
            ORDER BY action_count DESC
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
            LIMIT 2000
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
                   COALESCE(SUM(e.total_egg), 0) as total_eggs,
                   COALESCE(SUM(e.balut_count), 0) as total_balut,
                   COALESCE(SUM(e.chick_count), 0) as total_chicks,
                   COALESCE(SUM(e.failed_count), 0) as total_failed,
                   ROUND((COALESCE(SUM(e.balut_count), 0) + COALESCE(SUM(e.chick_count), 0)) / NULLIF(COALESCE(SUM(e.total_egg), 0), 0) * 100, 2) as success_rate
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
            SELECT user_role as role, COUNT(*) as count,
                   ROUND(COUNT(*) / (SELECT COUNT(*) FROM users) * 100, 1) as percentage
            FROM users
            GROUP BY user_role
        ");
        $stmt->execute();
        $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $reportColumns = ['Role', 'Count', 'Percentage (%)'];
        $reportTitle = 'Role Distribution Report';
        break;
}

$totalRecords = count($reportData);
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

    <!-- External CSS -->
    <link rel="stylesheet" href="../../assets/admin/css/admin_style.css">
</head>

<body>
    <button class="mobile-menu-btn" id="mobileMenuBtn"><i class="fas fa-bars"></i></button>
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
                    <a onclick="switchTab('analytics')"><i class="fas fa-chart-line"></i> System Analytics</a>
                </li>
                <li class="<?= $activeTab == 'reports' ? 'active' : '' ?>" data-tab="reports">
                    <a onclick="switchTab('reports')"><i class="fas fa-file-alt"></i> Reports</a>
                </li>
                <li><a href="../../controller/auth/signout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
            </ul>
        </aside>

        <main class="main-content">
            <div class="top-bar">
                <div class="welcome-text">
                    <h1>Admin Dashboard</h1>
                    <p id="page-subtitle"><?= $activeTab == 'overview' ? 'System overview & real-time metrics' : ($activeTab == 'analytics' ? 'Deep dive analytics & insights' : 'Generate & export reports') ?></p>
                </div>
                <div class="date-badge"><i class="far fa-calendar-alt"></i> <?= date('M d, Y') ?></div>
            </div>

            <!-- ═══════════════════════════════════════════════════════════════ -->
            <!-- OVERVIEW TAB -->
            <!-- ═══════════════════════════════════════════════════════════════ -->
            <div id="overview-section" class="tab-section <?= $activeTab == 'overview' ? 'active' : '' ?>">
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-info">
                            <h3><i class="fas fa-users"></i> Total Users</h3>
                            <p><?= number_format($totalUsers) ?></p>
                            <div class="trend"><i class="fas fa-chart-line"></i> <?= $totalAdmins ?> Admins | <?= $totalManagers ?> Managers | <?= $totalRegularUsers ?> Users</div>
                        </div>
                        <div class="stat-icon"><i class="fas fa-users"></i></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-info">
                            <h3><i class="fas fa-egg"></i> Production</h3>
                            <p><?= number_format($totalBatches) ?> Batches</p>
                            <div class="trend"><i class="fas fa-chart-simple"></i> <?= number_format($totalEggs) ?> Total Eggs</div>
                        </div>
                        <div class="stat-icon"><i class="fas fa-egg"></i></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-info">
                            <h3><i class="fas fa-chart-pie"></i> Success Rate</h3>
                            <p><?= $successRate ?>%</p>
                            <div class="trend"><i class="fas fa-chart-line"></i> <?= number_format($totalSuccessful) ?> / <?= number_format($totalEggs) ?> eggs</div>
                        </div>
                        <div class="stat-icon"><i class="fas fa-chart-pie"></i></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-info">
                            <h3><i class="fas fa-chart-line"></i> Active Users</h3>
                            <p><?= $activeToday ?> Today</p>
                            <div class="trend"><i class="fas fa-clock"></i> <?= $activity24h ?> actions (24h)</div>
                        </div>
                        <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-info">
                            <h3><i class="fas fa-dove"></i> Total Chicks</h3>
                            <p><?= number_format($totalChicks) ?></p>
                            <div class="trend"><i class="fas fa-drumstick-bite"></i> Balut: <?= number_format($totalBalut) ?></div>
                        </div>
                        <div class="stat-icon"><i class="fas fa-dove"></i></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-info">
                            <h3><i class="fas fa-times-circle"></i> Failed Eggs</h3>
                            <p><?= number_format($totalFailed) ?></p>
                            <div class="trend"><i class="fas fa-percentage"></i> <?= $failureRate ?>% failure rate</div>
                        </div>
                        <div class="stat-icon"><i class="fas fa-times-circle"></i></div>
                    </div>
                </div>

                <div class="chart-row">
                    <div class="chart-card">
                        <h3><i class="fas fa-chart-line" style="color:#10b981;"></i> User Growth (Last 7 Days)</h3>
                        <canvas id="userGrowthChart"></canvas>
                    </div>
                    <div class="chart-card">
                        <h3><i class="fas fa-chart-pie" style="color:#f59e0b;"></i> Batch Status Distribution</h3>
                        <canvas id="batchStatusChart"></canvas>
                        <div style="text-align:center; margin-top:0.5rem;">
                            <span class="badge badge-warning">Incubating: <?= $incubatingBatches ?></span>
                            <span class="badge badge-success">Complete: <?= $completeBatches ?></span>
                        </div>
                    </div>
                </div>

                <div class="table-container">
                    <div class="table-header">
                        <h3><i class="fas fa-trophy" style="color:#f59e0b;"></i> Top Performing Users</h3>
                        <span class="badge badge-info"><i class="fas fa-chart-line"></i> By Success Rate</span>
                    </div>
                    <div class="table-scroll-wrapper">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Batches</th>
                                    <th>Total Eggs</th>
                                    <th>Successful</th>
                                    <th>Failed</th>
                                    <th>Success Rate</th>
                                    <th>Performance</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($topPerformers)): ?>
                                    <tr>
                                        <td colspan="7" style="text-align:center">No production data available</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($topPerformers as $user): ?>
                                        <tr>
                                            <td>
                                                <div class="user-info">
                                                    <div class="avatar"><?= strtoupper(substr($user['username'], 0, 1)) ?></div><?= htmlspecialchars($user['username']) ?>
                                                </div>
                                            </td>
                                            <td><?= $user['batch_count'] ?></td>
                                            <td><?= number_format($user['total_eggs']) ?></td>
                                            <td><strong><?= number_format($user['total_success']) ?></strong></td>
                                            <td><?= number_format($user['total_failed']) ?></td>
                                            <td><span class="badge <?= $user['success_rate'] >= 80 ? 'badge-success' : ($user['success_rate'] >= 60 ? 'badge-warning' : 'badge-danger') ?>"><?= $user['success_rate'] ?>%</span></td>
                                            <td>
                                                <div class="progress-bar">
                                                    <div class="progress-fill" style="width:<?= $user['success_rate'] ?>%"></div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="table-container">
                    <div class="table-header">
                        <h3><i class="fas fa-clock"></i> Recent Batches</h3>
                        <button class="btn btn-outline" onclick="window.location.href='?tab=reports&report=batchProduction'"><i class="fas fa-chart-bar"></i> View All</button>
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
                                    <th>Failed</th>
                                    <th>Status</th>
                                    <th>Days</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recentBatches)): ?>
                                    <tr>
                                        <td colspan="8" style="text-align:center">No batches found</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($recentBatches as $batch): ?>
                                        <tr>
                                            <td><strong>#<?= htmlspecialchars($batch['batch_number'] ?? $batch['egg_id']) ?></strong></td>
                                            <td><?= htmlspecialchars($batch['username']) ?></td>
                                            <td><?= number_format($batch['total_egg']) ?></td>
                                            <td><span class="badge badge-success"><?= number_format($batch['chick_count']) ?></span></td>
                                            <td><span class="badge badge-info"><?= number_format($batch['balut_count']) ?></span></td>
                                            <td><span class="badge badge-danger"><?= number_format($batch['failed_count']) ?></span></td>
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

            <!-- ═══════════════════════════════════════════════════════════════ -->
            <!-- ANALYTICS TAB -->
            <!-- ═══════════════════════════════════════════════════════════════ -->
            <div id="analytics-section" class="tab-section <?= $activeTab == 'analytics' ? 'active' : '' ?>">
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-info">
                            <h3>Avg Success/Batch</h3>
                            <p><?= $totalBatches > 0 ? round($totalSuccessful / $totalBatches) : 0 ?></p>
                            <div class="trend">successful eggs per batch</div>
                        </div>
                        <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-info">
                            <h3>Avg Batches/User</h3>
                            <p><?= $totalUsersWithBatches > 0 ? round($totalBatches / $totalUsersWithBatches, 1) : 0 ?></p>
                            <div class="trend">batches per active user</div>
                        </div>
                        <div class="stat-icon"><i class="fas fa-chart-simple"></i></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-info">
                            <h3>User Retention</h3>
                            <p><?= $retentionRate ?>%</p>
                            <div class="trend">active in last 30 days</div>
                        </div>
                        <div class="stat-icon"><i class="fas fa-users"></i></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-info">
                            <h3>Peak Activity Hour</h3>
                            <p><?= array_search(max($hourlyActivity), $hourlyActivity) ?>:00</p>
                            <div class="trend">highest user engagement</div>
                        </div>
                        <div class="stat-icon"><i class="fas fa-clock"></i></div>
                    </div>
                </div>

                <div class="chart-card" style="margin-bottom:1rem;">
                    <h3><i class="fas fa-chart-line" style="color:#10b981;"></i> Daily Production Trend (Last 30 Days)</h3>
                    <canvas id="dailyProductionChart"></canvas>
                </div>

                <div class="chart-row">
                    <div class="chart-card">
                        <h3><i class="fas fa-chart-bar" style="color:#3b82f6;"></i> Weekly Production Summary</h3><canvas id="weeklySummaryChart"></canvas>
                    </div>
                    <div class="chart-card">
                        <h3><i class="fas fa-chart-pie" style="color:#f59e0b;"></i> Batch Efficiency Distribution</h3><canvas id="efficiencyChart"></canvas>
                    </div>
                </div>

                <div class="chart-card" style="margin-bottom:1rem;">
                    <h3><i class="fas fa-chart-bar" style="color:#8b5cf6;"></i> User Activity Pattern (Last 30 Days)</h3>
                    <canvas id="hourlyActivityChart"></canvas>
                </div>

                <div class="table-container">
                    <div class="table-header">
                        <h3><i class="fas fa-chart-line" style="color:#ef4444;"></i> Failure Analysis by Incubation Day</h3><span class="badge badge-info">Identifies critical days</span>
                    </div>
                    <div class="table-scroll-wrapper">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Incubation Day</th>
                                    <th>Avg Failures per Batch</th>
                                    <th>Logs Analyzed</th>
                                    <th>Risk Level</th>
                                </tr>
                            </thead>
                            <tbody><?php foreach ($failureByDay as $day): ?><tr>
                                        <td><strong>Day <?= $day['day_number'] ?></strong></td>
                                        <td><?= round($day['avg_failures'], 2) ?></td>
                                        <td><?= $day['log_count'] ?></td>
                                        <td><span class="badge <?= $day['avg_failures'] > 2 ? 'badge-danger' : ($day['avg_failures'] > 1 ? 'badge-warning' : 'badge-success') ?>"><?= $day['avg_failures'] > 2 ? 'High Risk' : ($day['avg_failures'] > 1 ? 'Medium Risk' : 'Low Risk') ?></span></td>
                                    </tr><?php endforeach; ?></tbody>
                        </table>
                    </div>
                </div>

                <div class="table-container">
                    <div class="table-header">
                        <h3><i class="fas fa-chart-simple"></i> Production by User Role</h3>
                    </div>
                    <div class="table-scroll-wrapper">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Role</th>
                                    <th>Batches</th>
                                    <th>Total Eggs</th>
                                    <th>Balut</th>
                                    <th>Chicks</th>
                                    <th>Failed</th>
                                    <th>Success Rate</th>
                                </tr>
                            </thead>
                            <tbody><?php foreach ($productionByRole as $role): $roleSuccess = ($role['total_eggs'] > 0) ? round((($role['total_balut'] + $role['total_chicks']) / $role['total_eggs']) * 100, 1) : 0; ?><tr>
                                        <td><span class="role-badge <?= $role['user_role'] ?>"><?= ucfirst($role['user_role']) ?></span></td>
                                        <td><?= number_format($role['total_batches']) ?></td>
                                        <td><?= number_format($role['total_eggs']) ?></td>
                                        <td><?= number_format($role['total_balut']) ?></td>
                                        <td><?= number_format($role['total_chicks']) ?></td>
                                        <td><?= number_format($role['total_failed']) ?></td>
                                        <td><span class="badge <?= $roleSuccess >= 70 ? 'badge-success' : 'badge-warning' ?>"><?= $roleSuccess ?>%</span></td>
                                    </tr><?php endforeach; ?></tbody>
                        </table>
                    </div>
                </div>

                <div class="table-container">
                    <div class="table-header">
                        <h3><i class="fas fa-fire" style="color:#f59e0b;"></i> Most Active Users (Last 30 Days)</h3>
                    </div>
                    <div class="table-scroll-wrapper">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Role</th>
                                    <th>Actions Performed</th>
                                    <th>Activity Level</th>
                                </tr>
                            </thead>
                            <tbody><?php if (!empty($mostActiveUsers)): $maxActions = $mostActiveUsers[0]['action_count'] ?? 1;
                                        foreach ($mostActiveUsers as $user): ?><tr>
                                            <td>
                                                <div class="user-info">
                                                    <div class="avatar"><?= strtoupper(substr($user['username'], 0, 1)) ?></div><?= htmlspecialchars($user['username']) ?>
                                                </div>
                                            </td>
                                            <td><span class="role-badge <?= $user['user_role'] ?>"><?= ucfirst($user['user_role']) ?></span></td>
                                            <td><strong><?= number_format($user['action_count']) ?></strong></td>
                                            <td>
                                                <div class="progress-bar">
                                                    <div class="progress-fill" style="width:<?= min(100, ($user['action_count'] / $maxActions) * 100) ?>%"></div>
                                                </div>
                                            </td>
                                        </tr><?php endforeach;
                                        endif; ?></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ═══════════════ REPORTS TAB ═══════════════ -->
            <div id="reports-section" class="tab-section <?= $activeTab == 'reports' ? 'active' : '' ?>">
                <div class="report-controls">
                    <div class="form-group"><label><i class="fas fa-chart-simple"></i> Report Type</label><select id="reportType">
                            <option value="userSummary" <?= $reportType == 'userSummary' ? 'selected' : '' ?>>1. User Summary Report</option>
                            <option value="batchProduction" <?= $reportType == 'batchProduction' ? 'selected' : '' ?>>2. Batch Production Report</option>
                            <option value="dailyEggLogs" <?= $reportType == 'dailyEggLogs' ? 'selected' : '' ?>>3. Daily Egg Logs Report</option>
                            <option value="managerPerformance" <?= $reportType == 'managerPerformance' ? 'selected' : '' ?>>4. Manager Performance Report</option>
                            <option value="userActivityLogs" <?= $reportType == 'userActivityLogs' ? 'selected' : '' ?>>5. User Activity Logs Report</option>
                            <option value="failedEggAnalysis" <?= $reportType == 'failedEggAnalysis' ? 'selected' : '' ?>>6. Failed Egg Analysis Report</option>
                            <option value="monthlySummary" <?= $reportType == 'monthlySummary' ? 'selected' : '' ?>>7. Monthly Production Summary</option>
                            <option value="roleDistribution" <?= $reportType == 'roleDistribution' ? 'selected' : '' ?>>8. Role Distribution Report</option>
                        </select></div>
                    <div class="form-group"><label><i class="fas fa-calendar-alt"></i> Start Date</label><input type="date" id="startDate" value="<?= $startDate ?>"></div>
                    <div class="form-group"><label><i class="fas fa-calendar-alt"></i> End Date</label><input type="date" id="endDate" value="<?= $endDate ?>"></div>
                    <button class="btn btn-primary" onclick="generateReport()"><i class="fas fa-chart-bar"></i> Generate Report</button>
                    <button class="btn btn-outline" onclick="exportReportCSV()"><i class="fas fa-file-csv"></i> Export CSV</button>
                    <button class="btn btn-outline" onclick="printReport()"><i class="fas fa-print"></i> Print Report</button>
                </div>

                <div id="print-area">
                    <div class="table-container">
                        <div class="table-header">
                            <h3 id="reportTitle"><i class="fas fa-chart-line"></i> <?= $reportTitle ?? 'Report Preview' ?></h3><span id="reportDateRange" style="font-size:0.7rem; color:#64748b;"><?php if ($reportType != 'roleDistribution'): ?><i class="far fa-calendar"></i> <?= date('M d, Y', strtotime($startDate)) ?> - <?= date('M d, Y', strtotime($endDate)) ?><?php endif; ?></span>
                        </div>
                        <div class="table-scroll-wrapper" id="reportContent">
                            <?php if ($reportData && count($reportData) > 0): ?>
                                <table class="data-table" id="reportTable">
                                    <thead>
                                        <tr><?php foreach ($reportColumns as $col): ?><th><?= htmlspecialchars($col) ?></th><?php endforeach; ?></tr>
                                    </thead>
                                    <tbody><?php foreach ($reportData as $row): ?><tr><?php foreach (array_values($row) as $value): ?><td><?= htmlspecialchars($value ?? '0') ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
                                </table>
                                <?php if ($reportType == 'monthlySummary' && count($reportData) > 0): $totalSuccess = 0;
                                    $totalMonths = 0;
                                    foreach ($reportData as $row) {
                                        $totalSuccess += floatval($row['success_rate']);
                                        $totalMonths++;
                                    }
                                    $avgSuccess = $totalMonths > 0 ? round($totalSuccess / $totalMonths, 1) : 0; ?>
                                    <div style="margin-top:1rem; padding:0.75rem; background:#f0fdf4; border-radius:8px; text-align:center;"><strong>Overall Success Rate: </strong><?= $avgSuccess ?>% average across <?= $totalMonths ?> month(s)</div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div style="text-align:center; padding: 3rem; color:#94a3b8;"><i class="fas fa-chart-simple" style="font-size: 2rem; margin-bottom: 0.5rem; display: block;"></i>Select a report type and click Generate Report</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <div id="toast" class="toast"><i class="fas fa-check-circle"></i> <span id="toastMsg"></span></div>

    <!-- Pass PHP data to JavaScript -->
    <script>
        window.AdminConfig = {
            userGrowth: <?= json_encode($userGrowth) ?>,
            growthLabels: <?= json_encode($growthLabels) ?>,
            incubating: <?= $incubatingBatches ?>,
            complete: <?= $completeBatches ?>,
            dailyProduction: <?= json_encode($dailyProduction) ?>,
            dailyLabels: <?= json_encode($dailyLabels) ?>,
            weeklySummary: <?= json_encode($weeklySummary) ?>,
            hourlyActivity: <?= json_encode(array_values($hourlyActivity)) ?>,
            efficiencyDistribution: <?= json_encode($efficiencyDistribution) ?>
        };
    </script>

    <!-- External JavaScript -->
    <script src="../../assets/admin/js/admin_function.js"></script>
</body>

</html>