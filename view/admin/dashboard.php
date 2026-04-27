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

// ── STATISTICS ─────────────────────────────────────────────────────────────
// User stats
$totalUsers = $conn->query("SELECT COUNT(*) FROM users")->fetchColumn();
$totalAdmins = $conn->query("SELECT COUNT(*) FROM users WHERE user_role='admin'")->fetchColumn();
$totalManagers = $conn->query("SELECT COUNT(*) FROM users WHERE user_role='manager'")->fetchColumn();
$totalRegularUsers = $conn->query("SELECT COUNT(*) FROM users WHERE user_role='user'")->fetchColumn();

// Egg stats
$totalBatches = $conn->query("SELECT COUNT(*) FROM egg")->fetchColumn();
$totalEggs = $conn->query("SELECT SUM(total_egg) FROM egg")->fetchColumn() ?: 0;
$totalChicks = $conn->query("SELECT SUM(chick_count) FROM egg")->fetchColumn() ?: 0;
$totalBalut = $conn->query("SELECT SUM(balut_count) FROM egg")->fetchColumn() ?: 0;
$totalFailed = $conn->query("SELECT SUM(failed_count) FROM egg")->fetchColumn() ?: 0;
$successRate = $totalEggs > 0 ? round((($totalChicks + $totalBalut) / $totalEggs) * 100, 1) : 0;

// ── RECENT BATCHES ────────────────────────────────────────────────────────
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

// ── ACTIVITY LOGS (Real-time, last 10) ────────────────────────────────────
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

// ── ANALYTICS DATA ────────────────────────────────────────────────────────
// Daily activity trend (last 14 days)
$dates = [];
$activityTrend = [];
$uniqueUsers = [];

for ($i = 13; $i >= 0; $i--) {
    $dates[] = date('Y-m-d', strtotime("-$i days"));
}

$stmt = $conn->prepare("
    SELECT DATE(log_date) as date, 
           COUNT(*) as count,
           COUNT(DISTINCT user_id) as unique_users
    FROM user_activity_logs
    WHERE log_date >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
      AND log_date IS NOT NULL
    GROUP BY DATE(log_date)
    ORDER BY date
");
$stmt->execute();
$dailyStats = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $dailyStats[$row['date']] = ['count' => (int)$row['count'], 'unique_users' => (int)$row['unique_users']];
}

foreach ($dates as $date) {
    $activityTrend[] = isset($dailyStats[$date]) ? $dailyStats[$date]['count'] : 0;
    $uniqueUsers[] = isset($dailyStats[$date]) ? $dailyStats[$date]['unique_users'] : 0;
}

// Hourly activity pattern
$stmt = $conn->prepare("
    SELECT HOUR(log_date) as hour, COUNT(*) as count
    FROM user_activity_logs
    WHERE log_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)
      AND log_date IS NOT NULL
    GROUP BY HOUR(log_date)
    ORDER BY hour
");
$stmt->execute();
$hourlyActivity = array_fill(0, 24, 0);
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $hourlyActivity[(int)$row['hour']] = (int)$row['count'];
}
$peakHour = array_search(max($hourlyActivity), $hourlyActivity);

// Top actions
$stmt = $conn->prepare("
    SELECT action, COUNT(*) as total_count, COUNT(DISTINCT user_id) as unique_users
    FROM user_activity_logs
    WHERE log_date IS NOT NULL
    GROUP BY action
    ORDER BY total_count DESC
    LIMIT 5
");
$stmt->execute();
$topActions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Batch status distribution
$incubatingBatches = $conn->query("SELECT COUNT(*) FROM egg WHERE status='incubating'")->fetchColumn();
$completeBatches = $conn->query("SELECT COUNT(*) FROM egg WHERE status='complete'")->fetchColumn();

// Top performing users
$stmt = $conn->prepare("
    SELECT u.username, 
           COUNT(e.egg_id) as batch_count,
           SUM(e.total_egg) as total_eggs,
           SUM(e.chick_count) as total_chicks,
           ROUND(COALESCE(SUM(e.chick_count) / NULLIF(SUM(e.total_egg), 0) * 100, 0), 1) as success_rate
    FROM users u
    JOIN egg e ON u.user_id = e.user_id
    WHERE e.total_egg > 0
    GROUP BY u.user_id
    ORDER BY total_chicks DESC
    LIMIT 5
");
$stmt->execute();
$topUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Format dates for charts
$formattedDates = array_map(function ($date) {
    return date('M d', strtotime($date));
}, $dates);

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

        /* Sidebar - Matching Manager Dashboard */
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

        /* Mobile menu button */
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

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 240px;
            padding: 1rem;
            transition: margin-left 0.3s ease;
            width: calc(100% - 240px);
            overflow-x: auto;
        }

        /* Top Bar */
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

        /* Tab System */
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

        /* Stats Grid */
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

        /* Chart Row */
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

        /* Table Container */
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
            width: 6px;
        }

        .table-scroll-wrapper::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 10px;
        }

        .table-scroll-wrapper::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 10px;
        }

        .activity-scroll-wrapper {
            max-height: 400px;
            overflow-y: auto;
            overflow-x: auto;
            scrollbar-width: thin;
        }

        .activity-scroll-wrapper::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }

        .activity-scroll-wrapper::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 10px;
        }

        .activity-scroll-wrapper::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 10px;
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

        /* Buttons */
        .btn {
            padding: 0.45rem 0.9rem;
            font-size: 0.75rem;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
        }

        .btn-primary {
            background: #10b981;
            color: white;
        }

        .btn-primary:hover {
            background: #059669;
        }

        .btn-outline {
            background: white;
            border: 1px solid #e2e8f0;
            color: #334155;
        }

        .btn-outline:hover {
            background: #f1f5f9;
        }

        /* Role Badges */
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

        /* User Info */
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

        /* Toast */
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

        /* Responsive */
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
                gap: 0.6rem;
            }

            .chart-row {
                grid-template-columns: 1fr;
                gap: 0.6rem;
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
        <!-- Sidebar - Matching Manager Dashboard -->
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

        <!-- Main Content -->
        <main class="main-content">
            <!-- Top Bar -->
            <div class="top-bar">
                <div class="welcome-text">
                    <h1>Admin Dashboard</h1>
                    <p id="page-subtitle"><?= $activeTab == 'overview' ? 'System overview & key metrics' : ($activeTab == 'analytics' ? 'System analytics & insights' : 'Generate & export reports') ?></p>
                </div>
                <div class="date-badge">
                    <i class="far fa-calendar-alt"></i> <?= date('M d, Y') ?>
                </div>
            </div>

            <!-- ═══════════════ OVERVIEW TAB ═══════════════ -->
            <div id="overview-section" class="tab-section <?= $activeTab == 'overview' ? 'active' : '' ?>">
                <!-- Stats Cards -->
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

                <!-- Recent Batches Table -->
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

                <!-- Activity Logs Table - Real Time -->
                <div class="table-container">
                    <div class="table-header">
                        <h3><i class="fas fa-history"></i> Recent Activity (Real Time)</h3>
                        <button class="btn btn-outline" onclick="exportActivityCSV()">
                            <i class="fas fa-download"></i> Export All Logs
                        </button>
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
                                            <td class="activity-time">
                                                <i class="far fa-clock"></i> <?= formatDateTime($log['log_date']) ?>
                                                <small>(<?= timeAgo($log['log_date']) ?>)</small>
                                            </td>
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
                <!-- Stats Summary -->
                <div class="stats-grid" style="margin-bottom:1rem;">
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

                <!-- Charts Row -->
                <div class="chart-row">
                    <div class="chart-card">
                        <h3><i class="fas fa-chart-line" style="color:#10b981;"></i> Daily Activity Trend (Last 14 Days)</h3>
                        <canvas id="dailyTrendChart"></canvas>
                    </div>
                    <div class="chart-card">
                        <h3><i class="fas fa-chart-bar" style="color:#3b82f6;"></i> Activity by Hour</h3>
                        <canvas id="hourlyChart"></canvas>
                    </div>
                </div>

                <div class="chart-row">
                    <div class="chart-card">
                        <h3><i class="fas fa-chart-pie" style="color:#f59e0b;"></i> Batch Status Distribution</h3>
                        <canvas id="batchStatusChart"></canvas>
                    </div>
                    <div class="chart-card">
                        <h3><i class="fas fa-chart-pie" style="color:#8b5cf6;"></i> User Role Distribution</h3>
                        <canvas id="userRoleChart"></canvas>
                    </div>
                </div>

                <!-- Top Actions Table -->
                <div class="table-container">
                    <div class="table-header">
                        <h3><i class="fas fa-fire"></i> Top User Actions</h3>
                    </div>
                    <div class="table-scroll-wrapper">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Action</th>
                                    <th>Total Count</th>
                                    <th>Unique Users</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($topActions)): ?>
                                    <tr>
                                        <td colspan="3" style="text-align:center">No action data available</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($topActions as $action): ?>
                                        <tr>
                                            <td><span class="badge badge-info"><?= htmlspecialchars($action['action']) ?></span></td>
                                            <td><strong><?= number_format($action['total_count']) ?></strong></td>
                                            <td><?= $action['unique_users'] ?> users</td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Top Performing Users -->
                <div class="table-container">
                    <div class="table-header">
                        <h3><i class="fas fa-trophy" style="color:#f59e0b;"></i> Top Performing Users</h3>
                    </div>
                    <div class="table-scroll-wrapper">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Batches</th>
                                    <th>Total Eggs</th>
                                    <th>Chicks Hatched</th>
                                    <th>Success Rate</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($topUsers)): ?>
                                    <tr>
                                        <td colspan="5" style="text-align:center">No user performance data available</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($topUsers as $user): ?>
                                        <tr>
                                            <td>
                                                <div class="user-info">
                                                    <div class="avatar"><?= strtoupper(substr($user['username'], 0, 1)) ?></div>
                                                    <?= htmlspecialchars($user['username']) ?>
                                                </div>
                    </div>
                    <td><?= $user['batch_count'] ?>
                </div>
                <td><?= number_format($user['total_eggs']) ?>
            </div>
            <td><strong><?= number_format($user['total_chicks']) ?></strong>
    </div>
    <td><span class="badge badge-success"><?= $user['success_rate'] ?>%</span></div>
        </tr>
    <?php endforeach; ?>
<?php endif; ?>
</tbody>
</table>
</div>
</div>
</div>

<!-- ═══════════════ REPORTS TAB ═══════════════ -->
<div id="reports-section" class="tab-section <?= $activeTab == 'reports' ? 'active' : '' ?>">
    <div class="table-container">
        <div class="table-header">
            <h3><i class="fas fa-chart-line"></i> Activity Report</h3>
        </div>
        <div class="chart-card" style="margin-bottom:1rem;">
            <canvas id="reportChart" style="max-height:300px;"></canvas>
        </div>
        <div class="table-scroll-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Total Activities</th>
                        <th>Unique Users</th>
                    </tr>
                </thead>
                <tbody>
                    <?php for ($i = 0; $i < count($formattedDates); $i++): ?>
                        <tr>
                            <td><?= $formattedDates[$i] ?>
        </div>
    <td><?= $activityTrend[$i] ?></div>
    <td><?= $uniqueUsers[$i] ?></div>
        </tr>
    <?php endfor; ?>
    </tbody>
    </table>
    </div>
    </div>
    </div>
    </main>
    </div>

    <div id="toast" class="toast"><i class="fas fa-check-circle"></i> <span id="toastMsg"></span></div>

    <script>
        // Chart data from PHP
        const chartData = {
            dates: <?= json_encode($formattedDates) ?>,
            activityTrend: <?= json_encode($activityTrend) ?>,
            uniqueUsers: <?= json_encode($uniqueUsers) ?>,
            hourlyActivity: <?= json_encode(array_values($hourlyActivity)) ?>,
            incubating: <?= $incubatingBatches ?>,
            complete: <?= $completeBatches ?>,
            adminCount: <?= $totalAdmins ?>,
            managerCount: <?= $totalManagers ?>,
            userCount: <?= $totalRegularUsers ?>
        };

        let dailyChart, hourlyChart, batchStatusChart, userRoleChart, reportChart;

        function initCharts() {
            // Daily Activity Trend Chart
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

            // Hourly Activity Chart
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

            // Batch Status Chart
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

            // User Role Chart
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

            // Report Chart
            const reportCtx = document.getElementById('reportChart')?.getContext('2d');
            if (reportCtx) {
                reportChart = new Chart(reportCtx, {
                    type: 'line',
                    data: {
                        labels: chartData.dates,
                        datasets: [{
                            label: 'Total Activities',
                            data: chartData.activityTrend,
                            borderColor: '#10b981',
                            backgroundColor: 'rgba(16, 185, 129, 0.1)',
                            borderWidth: 2,
                            fill: true
                        }, {
                            label: 'Unique Users',
                            data: chartData.uniqueUsers,
                            borderColor: '#3b82f6',
                            borderWidth: 2,
                            borderDash: [5, 5],
                            fill: false
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
        }

        // Tab switching
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
                reports: 'Generate & export reports'
            };
            document.getElementById('page-subtitle').textContent = subtitles[tabName];

            if (tabName === 'analytics' || tabName === 'overview') {
                setTimeout(() => {
                    if (dailyChart) dailyChart.resize();
                    if (hourlyChart) hourlyChart.resize();
                    if (batchStatusChart) batchStatusChart.resize();
                    if (userRoleChart) userRoleChart.resize();
                    if (reportChart) reportChart.resize();
                }, 100);
            }

            if (window.innerWidth <= 768) closeMobileMenu();
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
                fetch(window.location.href + '?get_activity_ajax=1&nocache=' + Date.now(), {
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
                                    html += `<tr>
                                    <td class="activity-time"><i class="far fa-clock"></i> ${escapeHtml(log.formatted_date)} <small>(${escapeHtml(log.time_ago)})</small></td>
                                    <td>${escapeHtml(log.username)}</td>
                                    <td>${escapeHtml(log.action)}</td>
                                </tr>`;
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

        // Handle AJAX activity refresh endpoint
        <?php if ($isAjax = isset($_GET['get_activity_ajax'])): ?>
            <?php
            $stmt = $conn->prepare("SELECT l.log_date, u.username, l.action FROM user_activity_logs l LEFT JOIN users u ON l.user_id = u.user_id WHERE l.log_date IS NOT NULL ORDER BY l.log_date DESC LIMIT 10");
            $stmt->execute();
            $freshLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $logsData = [];
            foreach ($freshLogs as $log) {
                $logsData[] = ['formatted_date' => formatDateTime($log['log_date']), 'time_ago' => timeAgo($log['log_date']), 'username' => $log['username'] ?? 'System', 'action' => $log['action']];
            }
            echo json_encode(['logs' => $logsData]);
            exit;
            ?>
        <?php endif; ?>

        document.addEventListener('DOMContentLoaded', initCharts);
    </script>
</body>

</html>