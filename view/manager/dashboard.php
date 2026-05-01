<?php
require_once '../../model/config.php';

// Check if user is logged in and is manager
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'manager') {
    header("Location: ../../index.php");
    exit;
}

$manager_id = $_SESSION['user_id'];

// Get active tab from URL parameter
$activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'overview';
$validTabs = ['overview', 'analytics', 'reports'];
if (!in_array($activeTab, $validTabs)) {
    $activeTab = 'overview';
}

// Helper: format date/time properly
function formatDateTime($datetime)
{
    if (!$datetime) return 'Never';
    $timestamp = strtotime($datetime);
    return date('M j, Y g:i A', $timestamp);
}

// Helper: time ago with more accurate display
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
$stmt = $conn->prepare("INSERT INTO user_activity_logs (user_id, action, log_date) VALUES (?, ?, NOW())");
$stmt->execute([$manager_id, "Manager accessed dashboard"]);

// ── Handle AJAX / POST actions ──────────────────────────────────────────────
$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// Handle AJAX activity refresh
if ($isAjax && isset($_GET['get_activity_ajax'])) {
    $stmt = $conn->prepare("
        SELECT l.log_date, u.username, l.action
        FROM user_activity_logs l
        JOIN users u ON l.user_id = u.user_id
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
            'username' => $log['username'],
            'action' => $log['action']
        ];
    }
    echo json_encode(['logs' => $logsData]);
    exit;
}

// Create user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_user') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $role     = in_array($_POST['role'] ?? '', ['user', 'manager']) ? $_POST['role'] : 'user';
    $errors   = [];
    if (strlen($username) < 3) $errors[] = 'Username must be at least 3 characters.';
    if (strlen($password) < 6) $errors[] = 'Password must be at least 6 characters.';
    // Check duplicate
    $chk = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
    $chk->execute([$username]);
    if ($chk->fetch()) $errors[] = 'Username already exists.';
    if (empty($errors)) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $ins  = $conn->prepare("INSERT INTO users (username, password, user_role) VALUES (?, ?, ?)");
        $ins->execute([$username, $hash, $role]);
        $stmt = $conn->prepare("INSERT INTO user_activity_logs (user_id, action, log_date) VALUES (?, ?, NOW())");
        $stmt->execute([$manager_id, "Created user: $username ($role)"]);
        if ($isAjax) {
            echo json_encode(['success' => true, 'message' => 'User created successfully.']);
            exit;
        }
    } else {
        if ($isAjax) {
            echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
            exit;
        }
    }
}

// Edit user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_user') {
    $edit_id  = (int)($_POST['user_id'] ?? 0);
    $username = trim($_POST['username'] ?? '');
    $role     = in_array($_POST['role'] ?? '', ['user', 'manager']) ? $_POST['role'] : 'user';
    $password = $_POST['password'] ?? '';
    if ($edit_id && strlen($username) >= 3) {
        if (!empty($password) && strlen($password) >= 6) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $upd  = $conn->prepare("UPDATE users SET username=?, user_role=?, password=? WHERE user_id=?");
            $upd->execute([$username, $role, $hash, $edit_id]);
        } else {
            $upd = $conn->prepare("UPDATE users SET username=?, user_role=? WHERE user_id=?");
            $upd->execute([$username, $role, $edit_id]);
        }
        $stmt = $conn->prepare("INSERT INTO user_activity_logs (user_id, action, log_date) VALUES (?, ?, NOW())");
        $stmt->execute([$manager_id, "Edited user ID: $edit_id"]);
        if ($isAjax) {
            echo json_encode(['success' => true, 'message' => 'User updated successfully.']);
            exit;
        }
    } else {
        if ($isAjax) {
            echo json_encode(['success' => false, 'message' => 'Invalid data.']);
            exit;
        }
    }
}

// Delete user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_user') {
    $del_id = (int)($_POST['user_id'] ?? 0);
    if ($del_id && $del_id !== $manager_id) {
        $del = $conn->prepare("DELETE FROM users WHERE user_id=?");
        $del->execute([$del_id]);
        $stmt = $conn->prepare("INSERT INTO user_activity_logs (user_id, action, log_date) VALUES (?, ?, NOW())");
        $stmt->execute([$manager_id, "Deleted user ID: $del_id"]);
        if ($isAjax) {
            echo json_encode(['success' => true, 'message' => 'User deleted.']);
            exit;
        }
    } else {
        if ($isAjax) {
            echo json_encode(['success' => false, 'message' => 'Cannot delete this user.']);
            exit;
        }
    }
}

// Handle activity log export
if (isset($_GET['export_activity']) && $_GET['export_activity'] === 'csv') {
    // Fetch all activity logs for export (no limit)
    $stmt = $conn->prepare("
        SELECT l.log_date, u.username, l.action
        FROM user_activity_logs l
        JOIN users u ON l.user_id = u.user_id
        WHERE l.log_date IS NOT NULL
        ORDER BY l.log_date DESC
    ");
    $stmt->execute();
    $allLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Set headers for CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="activity_logs_' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');
    // Add UTF-8 BOM for Excel compatibility
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
    // Headers
    fputcsv($output, ['Date & Time', 'User', 'Action']);
    // Data rows
    foreach ($allLogs as $log) {
        fputcsv($output, [
            formatDateTime($log['log_date']),
            $log['username'],
            $log['action']
        ]);
    }
    fclose($output);
    exit;
}

// ── Fetch statistics ─────────────────────────────────────────────────────────
$totalUsers   = $conn->query("SELECT COUNT(*) FROM users")->fetchColumn();
$totalBatches = $conn->query("SELECT COUNT(*) FROM egg")->fetchColumn();
$totalEggs    = $conn->query("SELECT SUM(total_egg) FROM egg")->fetchColumn() ?: 0;
$totalChicks  = $conn->query("SELECT SUM(chick_count) FROM egg")->fetchColumn() ?: 0;
$totalBalut   = $conn->query("SELECT SUM(balut_count) FROM egg")->fetchColumn() ?: 0;
$totalFailed  = $conn->query("SELECT SUM(failed_count) FROM egg")->fetchColumn() ?: 0;

// ── Users with summary - Keep all users for management table ─────────────────
$stmt = $conn->prepare("
    SELECT u.user_id, u.username, u.user_role, u.created_at,
           COALESCE(SUM(e.balut_count), 0)  AS total_balut,
           COALESCE(SUM(e.chick_count), 0)  AS total_chicks,
           COALESCE(SUM(e.failed_count), 0) AS total_failed,
           COALESCE(COUNT(e.egg_id), 0)     AS batch_count
    FROM users u
    LEFT JOIN egg e ON u.user_id = e.user_id
    WHERE u.user_role = 'user'
    GROUP BY u.user_id
    ORDER BY u.created_at DESC
");
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Activity logs with proper datetime - LIMIT to 10 rows for real-time display ──
$stmt = $conn->prepare("
    SELECT l.*, u.username
    FROM user_activity_logs l
    JOIN users u ON l.user_id = u.user_id
    WHERE l.log_date IS NOT NULL
    ORDER BY l.log_date DESC
    LIMIT 10
");
$stmt->execute();
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Analytics: Balut per user (top 8) - FIXED: Only regular users ────────────
$stmt = $conn->prepare("
    SELECT u.username, COALESCE(SUM(e.balut_count),0) AS total_balut
    FROM users u 
    LEFT JOIN egg e ON u.user_id = e.user_id
    WHERE u.user_role = 'user'
    GROUP BY u.user_id 
    ORDER BY total_balut DESC 
    LIMIT 8
");
$stmt->execute();
$balutPerUser = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Analytics: User Contribution Comparison - FIXED: Only regular users ──────
$stmt = $conn->prepare("
    SELECT u.username, 
           COALESCE(SUM(e.balut_count),0) AS total_balut,
           COALESCE(SUM(e.chick_count),0) AS total_chicks,
           COALESCE(SUM(e.failed_count),0) AS total_failed
    FROM users u 
    LEFT JOIN egg e ON u.user_id = e.user_id
    WHERE u.user_role = 'user'
    GROUP BY u.user_id 
    ORDER BY total_balut DESC
");
$stmt->execute();
$userContributions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Analytics: Weekly production trend (last 7 days) ─────────────────────────
$stmt = $conn->prepare("
    SELECT DATE(date_started_incubation) AS day,
           SUM(balut_count) AS balut, SUM(chick_count) AS chicks, SUM(failed_count) AS failed
    FROM egg
    WHERE date_started_incubation >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY DATE(date_started_incubation)
    ORDER BY day ASC
");
$stmt->execute();
$weeklyTrend = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Report data ───────────────────────────────────────────────────────────────
$reportData = [];
$reportType = $_GET['report'] ?? '';
$startDate  = $_GET['start'] ?? date('Y-m-01');
$endDate    = $_GET['end']   ?? date('Y-m-d');

if ($reportType === 'userSummary') {
    $stmt = $conn->prepare("
        SELECT u.username, u.user_role,
               COALESCE(SUM(e.balut_count),0)  AS total_balut,
               COALESCE(SUM(e.chick_count),0)  AS total_chicks,
               COALESCE(SUM(e.failed_count),0) AS total_failed,
               COALESCE(COUNT(e.egg_id),0)      AS batches
        FROM users u LEFT JOIN egg e ON u.user_id=e.user_id
            AND e.date_started_incubation BETWEEN ? AND ?
        WHERE u.user_role = 'user'
        GROUP BY u.user_id ORDER BY total_balut DESC
    ");
    $stmt->execute([$startDate, $endDate . ' 23:59:59']);
    $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($reportType === 'batchLog') {
    $stmt = $conn->prepare("
        SELECT e.batch_number, u.username, e.total_egg, e.status,
               e.balut_count, e.chick_count, e.failed_count,
               DATE(e.date_started_incubation) AS started
        FROM egg e JOIN users u ON e.user_id=u.user_id
        WHERE e.date_started_incubation BETWEEN ? AND ?
        ORDER BY e.batch_number DESC
    ");
    $stmt->execute([$startDate, $endDate . ' 23:59:59']);
    $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get incubating and complete batch counts for analytics
$incubating = $conn->query("SELECT COUNT(*) FROM egg WHERE status='incubating'")->fetchColumn();
$complete   = $conn->query("SELECT COUNT(*) FROM egg WHERE status='complete'")->fetchColumn();

// Get top performing users (regular users only)
$stmt = $conn->prepare("
    SELECT u.username, 
           COALESCE(SUM(e.balut_count),0) AS total_balut,
           COALESCE(COUNT(e.egg_id),0) AS batch_count
    FROM users u 
    LEFT JOIN egg e ON u.user_id = e.user_id
    WHERE u.user_role = 'user'
    GROUP BY u.user_id 
    ORDER BY total_balut DESC 
    LIMIT 5
");
$stmt->execute();
$topUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Manager Dashboard | EggFlow</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

    <!-- External CSS -->
    <link rel="stylesheet" href="../../assets/manager/css/manager_style.css">
</head>

<body>
    <!-- Mobile Menu Button -->
    <button class="mobile-menu-btn" id="mobileMenuBtn" onclick="toggleMobileMenu()">
        <i class="fas fa-bars"></i>
    </button>
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="closeMobileMenu()"></div>

    <div class="dashboard">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h2><i class="fas fa-chart-line"></i> EggFlow</h2>
                <p>Manager Panel</p>
            </div>
            <ul class="sidebar-menu">
                <li class="nav-item <?= $activeTab == 'overview' ? 'active' : '' ?>" data-tab="overview">
                    <a href="?tab=overview"><i class="fas fa-tachometer-alt"></i> Overview</a>
                </li>
                <li class="nav-item">
                    <a href="../users/user-management.php"><i class="fas fa-users"></i> User Management</a>
                </li>
                <li class="nav-item <?= $activeTab == 'analytics' ? 'active' : '' ?>" data-tab="analytics">
                    <a href="?tab=analytics"><i class="fas fa-chart-line"></i> Analytics</a>
                </li>
                <li class="nav-item <?= $activeTab == 'reports' ? 'active' : '' ?>" data-tab="reports">
                    <a href="?tab=reports"><i class="fas fa-file-alt"></i> Reports</a>
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
                    <h1>Welcome, <?= htmlspecialchars($_SESSION['user_name'] ?? 'Manager') ?></h1>
                    <p id="page-subtitle"><?= $activeTab == 'overview' ? 'Overview & metrics' : ($activeTab == 'analytics' ? 'Production analytics (Users only)' : 'Generate reports') ?></p>
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
                            <h3>Total Batches</h3>
                            <p><?= number_format($totalBatches) ?></p>
                        </div>
                        <div class="stat-icon"><i class="fas fa-egg"></i></div>
                    </div>
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

                <!-- Users Summary Table - Scrollable horizontally -->
                <div class="table-container">
                    <div class="table-header">
                        <h3><i class="fas fa-users"></i> Users & Balut Summary</h3>
                    </div>
                    <div class="table-scroll-wrapper">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Role</th>
                                    <th>Joined</th>
                                    <th>Batches</th>
                                    <th>Balut</th>
                                    <th>Chicks</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $u): ?>
                                    <tr>
                                        <td>
                                            <div class="user-info">
                                                <div class="avatar"><?= strtoupper(substr($u['username'], 0, 1)) ?></div>
                                                <div><?= htmlspecialchars($u['username']) ?></div>
                                            </div>
                                        </td>
                                        <td><span class="role-badge <?= $u['user_role'] ?>"><?= ucfirst($u['user_role']) ?></span></td>
                                        <td><?= date('M d, Y', strtotime($u['created_at'])) ?></td>
                                        <td><?= number_format($u['batch_count']) ?></td>
                                        <td><strong><?= number_format($u['total_balut']) ?></strong></td>
                                        <td><?= number_format($u['total_chicks']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Activity Logs Table - Scrollable with Real Time (LIMIT 10 rows, scrollable, with export button) -->
                <div class="table-container">
                    <div class="table-header">
                        <h3><i class="fas fa-history"></i> Recent Activity - Last 10 logs</h3>
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
                                <?php if ($logs): ?>
                                    <?php foreach ($logs as $log): ?>
                                        <tr>
                                            <td class="activity-time">
                                                <i class="far fa-clock"></i> <?= formatDateTime($log['log_date']) ?>
                                                <small>(<?= timeAgo($log['log_date']) ?>)</small>
                                            </td>
                                            <td><?= htmlspecialchars($log['username']) ?></td>
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
                <div class="stats-grid" style="margin-bottom:1rem;">
                    <div class="stat-card">
                        <div class="stat-info">
                            <h3>Avg Balut/Batch</h3>
                            <p><?= $totalBatches > 0 ? number_format($totalBalut / $totalBatches, 1) : '0' ?></p>
                        </div>
                        <div class="stat-icon"><i class="fas fa-calculator"></i></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-info">
                            <h3>Avg Chicks/Batch</h3>
                            <p><?= $totalBatches > 0 ? number_format($totalChicks / $totalBatches, 1) : '0' ?></p>
                        </div>
                        <div class="stat-icon"><i class="fas fa-dove"></i></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-info">
                            <h3>Success Rate</h3>
                            <p><?= $totalEggs > 0 ? number_format((($totalBalut + $totalChicks) / $totalEggs) * 100, 1) : '0' ?>%</p>
                        </div>
                        <div class="stat-icon"><i class="fas fa-percentage"></i></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-info">
                            <h3>Failure Rate</h3>
                            <p><?= $totalEggs > 0 ? number_format(($totalFailed / $totalEggs) * 100, 1) : '0' ?>%</p>
                        </div>
                        <div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
                    </div>
                </div>

                <div class="chart-row">
                    <div class="chart-card">
                        <h3><i class="fas fa-chart-bar" style="color:#10b981;"></i> Balut per User (Users Only)</h3>
                        <canvas id="balutChart"></canvas>
                    </div>
                    <div class="chart-card">
                        <h3><i class="fas fa-chart-line" style="color:#3b82f6;"></i> Weekly Trend (Last 7 Days)</h3>
                        <canvas id="trendChart"></canvas>
                    </div>
                </div>

                <div class="chart-row">
                    <div class="chart-card">
                        <h3><i class="fas fa-chart-pie" style="color:#f59e0b;"></i> Outcome Distribution</h3>
                        <canvas id="pieChart"></canvas>
                    </div>
                    <div class="chart-card">
                        <h3><i class="fas fa-chart-area" style="color:#8b5cf6;"></i> Batch Status</h3>
                        <canvas id="statusChart"></canvas>
                        <p style="text-align:center;color:#64748b;font-size:0.7rem;margin-top:0.5rem;">
                            Incubating: <strong><?= $incubating ?></strong> &nbsp;|&nbsp; Complete: <strong><?= $complete ?></strong>
                        </p>
                    </div>
                </div>

                <!-- Top Performing Users Table - Scrollable horizontally -->
                <div class="table-container">
                    <div class="table-header">
                        <h3><i class="fas fa-trophy" style="color:#f59e0b;"></i> Top Performing Users (Users Only)</h3>
                    </div>
                    <div class="table-scroll-wrapper">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Rank</th>
                                    <th>Username</th>
                                    <th>Total Balut</th>
                                    <th>Batches</th>
                                    <th>Avg/Batch</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $rank = 1;
                                foreach ($topUsers as $user):
                                    $avgBalut = $user['batch_count'] > 0 ? number_format($user['total_balut'] / $user['batch_count'], 1) : 0;
                                ?>
                                    <tr>
                                        <td style="font-weight: bold;">#<?= $rank++ ?></td>
                                        <td>
                                            <div class="user-info">
                                                <div class="avatar"><?= strtoupper(substr($user['username'], 0, 1)) ?></div><?= htmlspecialchars($user['username']) ?>
                                            </div>
                                        </td>
                                        <td><strong><?= number_format($user['total_balut']) ?></strong></td>
                                        <td><?= number_format($user['batch_count']) ?></td>
                                        <td><?= $avgBalut ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($topUsers)): ?>
                                    <tr>
                                        <td colspan="5" style="text-align:center">No production data available for regular users</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- User Contribution Comparison Table - Scrollable horizontally -->
                <div class="table-container">
                    <div class="table-header">
                        <h3><i class="fas fa-chart-simple"></i> User Contribution (Users Only)</h3>
                    </div>
                    <div class="table-scroll-wrapper">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Username</th>
                                    <th>Balut</th>
                                    <th>Chicks</th>
                                    <th>Failed</th>
                                    <th>Success Rate</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($userContributions as $user):
                                    $total = $user['total_balut'] + $user['total_chicks'] + $user['total_failed'];
                                    $successRate = $total > 0 ? number_format((($user['total_balut'] + $user['total_chicks']) / $total) * 100, 1) : 0;
                                ?>
                                    <tr>
                                        <td>
                                            <div class="user-info">
                                                <div class="avatar"><?= strtoupper(substr($user['username'], 0, 1)) ?></div><?= htmlspecialchars($user['username']) ?>
                                            </div>
                                        </td>
                                        <td><strong><?= number_format($user['total_balut']) ?></strong></td>
                                        <td><?= number_format($user['total_chicks']) ?></td>
                                        <td><?= number_format($user['total_failed']) ?></td>
                                        <td><?= $successRate ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($userContributions)): ?>
                                    <tr>
                                        <td colspan="5" style="text-align:center">No contribution data available</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ═══════════════ REPORTS TAB ═══════════════ -->
            <div id="reports-section" class="tab-section <?= $activeTab == 'reports' ? 'active' : '' ?>">
                <div class="report-controls">
                    <div class="form-group">
                        <label>Report Type</label>
                        <select id="reportType">
                            <option value="userSummary" <?= $reportType == 'userSummary' ? 'selected' : '' ?>>User Summary</option>
                            <option value="batchLog" <?= $reportType == 'batchLog' ? 'selected' : '' ?>>Batch Log</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Start Date</label>
                        <input type="date" id="startDate" value="<?= $startDate ?>">
                    </div>
                    <div class="form-group">
                        <label>End Date</label>
                        <input type="date" id="endDate" value="<?= $endDate ?>">
                    </div>
                    <button class="btn btn-primary" onclick="generateReport()">
                        <i class="fas fa-chart-bar"></i> Generate
                    </button>
                    <button class="btn btn-outline" onclick="exportCSV()">
                        <i class="fas fa-file-csv"></i> Export CSV
                    </button>
                </div>

                <div class="table-container" id="reportPreview">
                    <div class="table-header">
                        <h3 id="reportTitle">Report Preview</h3>
                    </div>
                    <div class="table-scroll-wrapper" id="reportContent">
                        <?php if ($reportData): ?>
                            <?php
                            $titles = [
                                'userSummary' => 'User Summary Report',
                                'batchLog' => 'Batch Log Report',
                            ];
                            $title = $titles[$reportType] ?? 'Report';
                            ?>
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <?php foreach (array_keys($reportData[0]) as $col): ?>
                                            <th><?= ucwords(str_replace('_', ' ', $col)) ?></th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($reportData as $row): ?>
                                        <tr>
                                            <?php foreach ($row as $value): ?>
                                                <td><?= htmlspecialchars($value ?? '') ?></td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <p style="text-align:center; padding: 1.5rem; color:#94a3b8;">Select a report type and click Generate.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Modal -->
    <div id="userModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle"><i class="fas fa-user-plus"></i> Add User</h3>
                <button class="close" onclick="closeModal()">&times;</button>
            </div>
            <form id="userForm" onsubmit="saveUser(event)">
                <input type="hidden" id="editUserId" value="">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Username</label>
                        <input type="text" id="modalUsername" required minlength="3" maxlength="50">
                    </div>
                    <div class="form-group">
                        <label id="passwordLabel">Password</label>
                        <input type="password" id="modalPassword" minlength="6">
                    </div>
                    <div class="form-group">
                        <label>Role</label>
                        <select id="modalRole">
                            <option value="user">Regular User</option>
                            <option value="manager">Manager</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="saveBtn">
                        <i class="fas fa-save"></i> Save
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Toast -->
    <div id="toast"><i class="fas fa-check-circle"></i> <span id="toastMsg"></span></div>

    <!-- Pass PHP data to JavaScript -->
    <input type="hidden" id="activeTab" value="<?= $activeTab ?>">

    <script>
        window.ManagerConfig = {
            balutPerUser: <?= json_encode($balutPerUser) ?>,
            weeklyTrend: <?= json_encode($weeklyTrend) ?>,
            totalBalut: <?= (int)$totalBalut ?>,
            totalChicks: <?= (int)$totalChicks ?>,
            totalFailed: <?= (int)$totalFailed ?>,
            incubating: <?= (int)$incubating ?>,
            complete: <?= (int)$complete ?>
        };
    </script>

    <!-- External JavaScript File -->
    <script src="../../assets/manager/js/manager_function.js"></script>
</body>

</html>