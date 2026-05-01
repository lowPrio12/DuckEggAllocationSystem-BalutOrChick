<?php
require '../../model/config.php';

// Check if user is logged in and has user role
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'user') {
    header("Location: ../../index.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['user_name'] ?? 'User';

// Check database connection
if (!isset($conn) || $conn === null) {
    die("Database connection not established. Please check config.php");
}

// ── Report data handling (Matching Manager Dashboard) ──────────────────────────
$reportData = [];
$reportType = $_GET['report'] ?? '';
$startDate  = $_GET['start'] ?? date('Y-m-01');
$endDate    = $_GET['end']   ?? date('Y-m-d');

if ($reportType === 'production_summary') {
    // My Production Summary - User's own data only
    $stmt = $conn->prepare("
        SELECT 
            e.batch_number,
            e.total_egg,
            e.status,
            e.balut_count,
            e.chick_count,
            e.failed_count,
            DATE(e.date_started_incubation) AS started_date,
            (e.balut_count + e.chick_count) AS successful,
            ROUND((e.chick_count / NULLIF(e.total_egg, 0)) * 100, 1) AS success_rate
        FROM egg e
        WHERE e.user_id = ?
        ORDER BY e.batch_number DESC
    ");
    $stmt->execute([$user_id]);
    $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($reportType === 'batch_report') {
    // My Batch Report - User's own batches
    $stmt = $conn->prepare("
        SELECT 
            e.batch_number,
            e.total_egg,
            e.status,
            DATE(e.date_started_incubation) AS started_date,
            DATEDIFF(NOW(), e.date_started_incubation) + 1 AS current_day,
            e.balut_count,
            e.chick_count,
            e.failed_count
        FROM egg e
        WHERE e.user_id = ?
        ORDER BY e.batch_number DESC
    ");
    $stmt->execute([$user_id]);
    $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($reportType === 'daily_egg_logs') {
    // Daily Egg Logs - User's own daily logs
    $stmt = $conn->prepare("
        SELECT 
            e.batch_number,
            edl.day_number,
            edl.balut_count,
            edl.chick_count,
            edl.failed_count,
            DATE(edl.created_at) AS log_date
        FROM egg_daily_logs edl
        JOIN egg e ON edl.egg_id = e.egg_id
        WHERE e.user_id = ?
            AND DATE(edl.created_at) BETWEEN ? AND ?
        ORDER BY e.batch_number DESC, edl.day_number ASC
    ");
    $stmt->execute([$user_id, $startDate, $endDate]);
    $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($reportType === 'failed_egg_report') {
    // Failed Egg Report - User's own failed eggs
    $stmt = $conn->prepare("
        SELECT 
            e.batch_number,
            SUM(edl.failed_count) AS total_failed,
            COUNT(DISTINCT edl.day_number) AS days_with_failures,
            MIN(DATE(edl.created_at)) AS first_failure_date,
            MAX(DATE(edl.created_at)) AS last_failure_date
        FROM egg_daily_logs edl
        JOIN egg e ON edl.egg_id = e.egg_id
        WHERE e.user_id = ?
            AND edl.failed_count > 0
            AND DATE(edl.created_at) BETWEEN ? AND ?
        GROUP BY e.batch_number
        ORDER BY total_failed DESC
    ");
    $stmt->execute([$user_id, $startDate, $endDate]);
    $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($reportType === 'monthly_summary') {
    // Monthly Summary - User's own monthly totals
    $stmt = $conn->prepare("
        SELECT 
            DATE_FORMAT(e.date_started_incubation, '%Y-%m') AS month,
            COUNT(e.egg_id) AS batch_count,
            SUM(e.total_egg) AS total_eggs,
            SUM(e.balut_count) AS total_balut,
            SUM(e.chick_count) AS total_chicks,
            SUM(e.failed_count) AS total_failed,
            ROUND((SUM(e.chick_count) / NULLIF(SUM(e.total_egg), 0)) * 100, 1) AS success_rate
        FROM egg e
        WHERE e.user_id = ?
            AND DATE(e.date_started_incubation) BETWEEN ? AND ?
        GROUP BY DATE_FORMAT(e.date_started_incubation, '%Y-%m')
        ORDER BY month DESC
    ");
    $stmt->execute([$user_id, $startDate, $endDate]);
    $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

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

// ── Incubation constants ─────────────────────────────────────────────────────
define('BALUT_UNLOCK_DAY', 14);
define('CHICK_UNLOCK_DAY', 25);
define('EXPECTED_HATCH_DAY', 28);

// ── Real-time Day calculation function ──────────────────────────────────────
function getCurrentDay($dateStarted)
{
    if (empty($dateStarted)) return 1;

    $start = new DateTime($dateStarted);
    $today = new DateTime('now');
    $diff = $start->diff($today)->days;

    // Day 1 is the start day, so add 1 to the difference
    $day = $diff + 1;

    // Cap at 35 days maximum
    return min($day, 35);
}

// ── Add Batch ─────────────────────────────────────────────────────────────
if (isset($_POST['add_batch'])) {
    $total_egg = intval($_POST['total_egg']);
    if ($total_egg <= 0) {
        $_SESSION['error'] = "Please enter a valid number of eggs.";
        header("Location: dashboard.php");
        exit;
    }
    try {
        $stmt = $conn->prepare("SELECT COALESCE(MAX(batch_number), 0) + 1 AS next_batch FROM egg WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $next_batch = $stmt->fetch(PDO::FETCH_ASSOC)['next_batch'];

        $stmt = $conn->prepare("INSERT INTO egg (user_id, total_egg, status, date_started_incubation, batch_number, failed_count, balut_count, chick_count) VALUES (?, ?, 'incubating', NOW(), ?, 0, 0, 0)");
        if ($stmt->execute([$user_id, $total_egg, $next_batch])) {
            $log_stmt = $conn->prepare("INSERT INTO user_activity_logs (user_id, action) VALUES (?, ?)");
            $log_stmt->execute([$user_id, "Added batch #{$next_batch} with {$total_egg} eggs"]);
            $_SESSION['success'] = "Batch #{$next_batch} added successfully!";
        } else {
            $_SESSION['error'] = "Failed to add batch.";
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "DB error: " . $e->getMessage();
    }
    header("Location: dashboard.php");
    exit;
}

// ── Delete Batch ─────────────────────────────────────────────────────────
if (isset($_POST['delete_batch'])) {
    $egg_id = intval($_POST['egg_id']);
    try {
        $stmt = $conn->prepare("SELECT batch_number FROM egg WHERE egg_id = ? AND user_id = ?");
        $stmt->execute([$egg_id, $user_id]);
        $batch = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($batch) {
            $conn->prepare("DELETE FROM egg_daily_logs WHERE egg_id = ?")->execute([$egg_id]);
            $conn->prepare("DELETE FROM egg WHERE egg_id = ? AND user_id = ?")->execute([$egg_id, $user_id]);
            $conn->prepare("INSERT INTO user_activity_logs (user_id, action) VALUES (?, ?)")->execute([$user_id, "Deleted batch #{$batch['batch_number']}"]);
            $_SESSION['success'] = "Batch deleted successfully.";
        } else {
            $_SESSION['error'] = "Batch not found.";
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "DB error: " . $e->getMessage();
    }
    header("Location: dashboard.php");
    exit;
}

// ── Daily Update ─────────────────────────────────────────────────────────
if (isset($_POST['update_daily'])) {
    $egg_id       = intval($_POST['egg_id']);
    $failed_count = intval($_POST['failed_count']);
    $balut_count  = intval($_POST['balut_count']);
    $chick_count  = intval($_POST['chick_count']);

    if ($failed_count < 0 || $balut_count < 0 || $chick_count < 0) {
        $_SESSION['error'] = "Values cannot be negative.";
        header("Location: dashboard.php");
        exit;
    }
    if ($failed_count + $balut_count + $chick_count == 0) {
        $_SESSION['error'] = "Enter at least one value greater than 0.";
        header("Location: dashboard.php");
        exit;
    }

    try {
        $stmt = $conn->prepare("SELECT * FROM egg WHERE egg_id = ? AND user_id = ?");
        $stmt->execute([$egg_id, $user_id]);
        $egg = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($egg) {
            $current_day = getCurrentDay($egg['date_started_incubation']);

            if (($balut_count > 0 || $chick_count > 0) && $current_day < BALUT_UNLOCK_DAY) {
                $_SESSION['error'] = "Balut/chick fields unlock at Day " . BALUT_UNLOCK_DAY . ". Today is Day {$current_day}.";
                header("Location: dashboard.php");
                exit;
            }

            if ($chick_count > 0 && $current_day < CHICK_UNLOCK_DAY) {
                $_SESSION['error'] = "Chick harvest is only allowed from Day " . CHICK_UNLOCK_DAY . ". Today is Day {$current_day}.";
                header("Location: dashboard.php");
                exit;
            }

            $total_processed = $egg['failed_count'] + $egg['balut_count'] + $egg['chick_count'];
            $remaining       = $egg['total_egg'] - $total_processed;
            $total_input     = $failed_count + $balut_count + $chick_count;

            if ($total_input > $remaining) {
                $_SESSION['error'] = "Input ({$total_input}) exceeds remaining eggs ({$remaining}).";
                header("Location: dashboard.php");
                exit;
            }

            $chk = $conn->prepare("SELECT log_id FROM egg_daily_logs WHERE egg_id = ? AND day_number = ?");
            $chk->execute([$egg_id, $current_day]);

            if ($chk->fetch()) {
                $_SESSION['error'] = "Batch #{$egg['batch_number']} already updated for Day {$current_day}. Come back tomorrow.";
            } else {
                $insert_log = $conn->prepare("INSERT INTO egg_daily_logs (egg_id, day_number, failed_count, balut_count, chick_count) VALUES (?, ?, ?, ?, ?)");
                $insert_log->execute([$egg_id, $current_day, $failed_count, $balut_count, $chick_count]);

                $update_egg = $conn->prepare("UPDATE egg SET failed_count = failed_count + ?, balut_count = balut_count + ?, chick_count = chick_count + ? WHERE egg_id = ?");
                $update_egg->execute([$failed_count, $balut_count, $chick_count, $egg_id]);

                $new_processed = $total_processed + $total_input;
                $done = '';
                if ($new_processed >= $egg['total_egg']) {
                    $conn->prepare("UPDATE egg SET status = 'complete' WHERE egg_id = ?")->execute([$egg_id]);
                    $done = " Batch complete!";
                }

                $log_action = $conn->prepare("INSERT INTO user_activity_logs (user_id, action) VALUES (?, ?)");
                $log_action->execute([$user_id, "Updated batch #{$egg['batch_number']} Day {$current_day}: F:{$failed_count} B:{$balut_count} C:{$chick_count}"]);

                $_SESSION['success'] = "Batch #{$egg['batch_number']} updated for Day {$current_day}!{$done}";
            }
        } else {
            $_SESSION['error'] = "Invalid batch.";
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "DB error: " . $e->getMessage();
    }
    header("Location: dashboard.php");
    exit;
}

// ── Data Fetch ───────────────────────────────────────────────────────────
try {
    $stmt = $conn->prepare("SELECT * FROM egg WHERE user_id = ? ORDER BY date_started_incubation DESC");
    $stmt->execute([$user_id]);
    $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $batches = [];
}

// Calculate current day for each batch and statistics
$stat_incubating = $stat_complete = $stat_chicks = $stat_balut = $stat_failed = 0;
$total_eggs = $processed_eggs = 0;
$batch_remaining = [];

foreach ($batches as &$b) {
    $b['current_day'] = getCurrentDay($b['date_started_incubation']);

    if ($b['status'] === 'incubating') $stat_incubating++;
    else $stat_complete++;
    $stat_chicks += $b['chick_count'];
    $stat_balut  += $b['balut_count'];
    $stat_failed += $b['failed_count'];
    $total_eggs  += $b['total_egg'];
    $proc = $b['failed_count'] + $b['balut_count'] + $b['chick_count'];
    $processed_eggs += $proc;
    $batch_remaining[$b['egg_id']] = $b['total_egg'] - $proc;
}

$success_rate = $processed_eggs > 0 ? round(($stat_chicks / $processed_eggs) * 100, 1) : 0;
$balut_rate   = $processed_eggs > 0 ? round(($stat_balut / $processed_eggs) * 100, 1) : 0;

// Fetch daily analytics
try {
    $stmt = $conn->prepare("SELECT edl.day_number, SUM(edl.balut_count) AS balut, SUM(edl.chick_count) AS chicks, SUM(edl.failed_count) AS failed FROM egg_daily_logs edl JOIN egg e ON edl.egg_id = e.egg_id WHERE e.user_id = ? GROUP BY edl.day_number ORDER BY edl.day_number ASC LIMIT 28");
    $stmt->execute([$user_id]);
    $daily_analytics = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $daily_analytics = [];
}

// Fetch activity logs
try {
    $stmt = $conn->prepare("SELECT * FROM user_activity_logs WHERE user_id = ? ORDER BY log_date DESC LIMIT 50");
    $stmt->execute([$user_id]);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $logs = [];
}

// Pre-build today_logged map
$today_logged_map = [];
foreach ($batches as $b) {
    $day = $b['current_day'];
    $chk = $conn->prepare("SELECT log_id FROM egg_daily_logs WHERE egg_id = ? AND day_number = ?");
    $chk->execute([$b['egg_id'], $day]);
    $today_logged_map[$b['egg_id']] = (bool)$chk->fetch();
}

// Active tab
$activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'overview';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>User Dashboard | EggFlow</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

    <!-- External CSS Files -->
    <link rel="stylesheet" href="../../assets/user/css/user_style.css">
</head>

<body>
    <button class="mobile-menu-btn" id="mobileMenuBtn">
        <i class="fas fa-bars"></i>
    </button>
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="closeMobileMenu()"></div>

    <div class="dashboard">
        <!-- Sidebar - Professional unified style -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h2><i class="fas fa-chart-line"></i> EggFlow</h2>
                <p>User Panel</p>
            </div>
            <ul class="sidebar-menu">
                <li class="<?= $activeTab === 'overview' ? 'active' : '' ?>">
                    <a href="?tab=overview"><i class="fas fa-tachometer-alt"></i> Overview</a>
                </li>
                <li class="<?= $activeTab === 'batches' ? 'active' : '' ?>">
                    <a href="?tab=batches"><i class="fas fa-layer-group"></i> My Batches</a>
                </li>
                <li class="<?= $activeTab === 'analytics' ? 'active' : '' ?>">
                    <a href="?tab=analytics"><i class="fas fa-chart-line"></i> Analytics</a>
                </li>
                <li class="<?= $activeTab === 'reports' ? 'active' : '' ?>">
                    <a href="?tab=reports"><i class="fas fa-file-alt"></i> Reports</a>
                </li>
                <li class="<?= $activeTab === 'profile' ? 'active' : '' ?>">
                    <a href="?tab=profile"><i class="fas fa-user-circle"></i> Profile</a>
                </li>
                <li>
                    <a href="../../controller/auth/signout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
                </li>
            </ul>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Top Bar - Professional style -->
            <div class="top-bar">
                <div class="welcome-text">
                    <h1><?= $activeTab === 'overview' ? 'Dashboard Overview' : ($activeTab === 'batches' ? 'My Batches' : ($activeTab === 'analytics' ? 'Production Analytics' : ($activeTab === 'reports' ? 'My Reports' : 'My Profile'))) ?></h1>
                    <p><i class="fas fa-user"></i> Welcome back, <?= htmlspecialchars($username) ?>! Track your production and manage your batches.</p>
                </div>
                <div class="date-badge">
                    <i class="far fa-calendar-alt"></i> <?= date('M d, Y') ?>
                </div>
            </div>

            <!-- Flash Messages -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?= htmlspecialchars($_SESSION['success']) ?>
                </div>
            <?php unset($_SESSION['success']);
            endif; ?>
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($_SESSION['error']) ?>
                </div>
            <?php unset($_SESSION['error']);
            endif; ?>

            <!-- =================== OVERVIEW TAB =================== -->
            <?php if ($activeTab === 'overview'): ?>
                <!-- Stats Cards -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-info">
                            <h3>My Batches</h3>
                            <p><?= count($batches) ?></p>
                        </div>
                        <div class="stat-icon"><i class="fas fa-layer-group"></i></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-info">
                            <h3>Incubating</h3>
                            <p><?= $stat_incubating ?></p>
                        </div>
                        <div class="stat-icon"><i class="fas fa-egg"></i></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-info">
                            <h3>Total Chicks</h3>
                            <p><?= number_format($stat_chicks) ?></p>
                        </div>
                        <div class="stat-icon"><i class="fas fa-hat-wizard"></i></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-info">
                            <h3>Total Balut</h3>
                            <p><?= number_format($stat_balut) ?></p>
                        </div>
                        <div class="stat-icon"><i class="fas fa-drumstick-bite"></i></div>
                    </div>
                </div>

                <!-- Incubation Timeline -->
                <div class="timeline-card">
                    <div class="timeline-header">
                        <i class="fas fa-clock"></i>
                        <h3>Duck Egg Incubation Timeline</h3>
                        <span class="timeline-total">28 Days Total</span>
                    </div>
                    <div class="timeline-bar">
                        <div class="tl-segment seg-safe" style="width:46.4%"><span>Days 1–13</span><small>Development</small></div>
                        <div class="tl-segment seg-balut" style="width:17.9%"><span>Days 14–18</span><small>🥚 Balut Ready</small></div>
                        <div class="tl-segment seg-watch" style="width:25%"><span>Days 19–25</span><small>Late Dev</small></div>
                        <div class="tl-segment seg-hatch" style="width:10.7%"><span>Days 26–28</span><small>🐣 Hatching</small></div>
                    </div>
                </div>

                <!-- Active Batches -->
                <div class="table-container">
                    <div class="table-header">
                        <h3><i class="fas fa-play-circle"></i> Active Batches</h3>
                        <a href="?tab=batches" class="btn btn-outline btn-sm">
                            <i class="fas fa-layer-group"></i> View All
                        </a>
                    </div>
                    <?php
                    $active = array_filter($batches, function ($b) {
                        return $b['status'] === 'incubating';
                    });
                    ?>
                    <?php if (empty($active)): ?>
                        <div class="empty-state">
                            <i class="fas fa-box-open"></i>
                            <p>No active batches. <a href="#" onclick="openAddModal()">Start your first batch →</a></p>
                        </div>
                    <?php else: ?>
                        <div class="table-scroll-wrapper">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Batch #</th>
                                        <th>Total Eggs</th>
                                        <th>Day</th>
                                        <th>Progress</th>
                                        <th>Remaining</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($active as $b):
                                        $day  = $b['current_day'];
                                        $proc = $b['failed_count'] + $b['balut_count'] + $b['chick_count'];
                                        $rem  = $b['total_egg'] - $proc;
                                        $pct  = $b['total_egg'] > 0 ? round(($proc / $b['total_egg']) * 100) : 0;
                                        $locked = $day < BALUT_UNLOCK_DAY;
                                        $logged = isset($today_logged_map[$b['egg_id']]) ? $today_logged_map[$b['egg_id']] : false;
                                    ?>
                                        <tr>
                                            <td data-label="Batch #"><strong>#<?= $b['batch_number'] ?></strong></td>
                                            <td data-label="Total Eggs"><?= number_format($b['total_egg']) ?></td>
                                            <td data-label="Day"><span class="day-badge <?= $locked ? 'day-early' : ($day >= CHICK_UNLOCK_DAY ? 'day-late' : 'day-mid') ?>">Day <?= $day ?></span></td>
                                            <td data-label="Progress">
                                                <div class="mini-progress">
                                                    <div class="mini-bar" style="width:<?= $pct ?>%"></div>
                                                </div>
                                                <small><?= $pct ?>%</small>
                                            </td>
                                            <td data-label="Remaining"><?= number_format($rem) ?></td>
                                            <td data-label="Action">
                                                <?php if ($logged): ?>
                                                    <span class="badge-done"><i class="fas fa-check"></i> Updated Today</span>
                                                <?php elseif ($rem > 0): ?>
                                                    <button class="btn btn-warning btn-sm" onclick="openUpdateModal(<?= $b['egg_id'] ?>, <?= $day ?>, <?= $rem ?>, <?= $locked ? 'true' : 'false' ?>)">
                                                        <i class="fas fa-edit"></i> Update
                                                    </button>
                                                <?php else: ?>
                                                    <span class="badge-done">Complete</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Recent Activity -->
                <?php if (!empty($logs)): ?>
                    <div class="table-container">
                        <div class="table-header">
                            <h3><i class="fas fa-history"></i> Recent Activity</h3>
                        </div>
                        <div class="table-scroll-wrapper">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Date & Time</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_slice($logs, 0, 8) as $log): ?>
                                        <tr>
                                            <td class="activity-time"><?= timeAgo($log['log_date']) ?><br><small><?= formatDateTime($log['log_date']) ?></small></td>
                                            <td><?= htmlspecialchars($log['action']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <!-- =================== BATCHES TAB =================== -->
            <?php if ($activeTab === 'batches'): ?>
                <div class="action-bar">
                    <div class="action-bar-left">
                        <button class="btn btn-primary" onclick="openAddModal()">
                            <i class="fas fa-plus-circle"></i> Add New Batch
                        </button>
                    </div>
                </div>

                <div class="table-container">
                    <div class="table-header">
                        <h3><i class="fas fa-layer-group"></i> All Batches</h3>
                    </div>
                    <?php if (empty($batches)): ?>
                        <div class="empty-state">
                            <i class="fas fa-box-open"></i>
                            <p>No batches yet. Click "Add New Batch" to get started!</p>
                        </div>
                    <?php else: ?>
                        <div class="table-scroll-wrapper">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Batch #</th>
                                        <th>Total</th>
                                        <th>Status</th>
                                        <th>Started</th>
                                        <th>Day</th>
                                        <th>Balut</th>
                                        <th>Chicks</th>
                                        <th>Failed</th>
                                        <th>Remaining</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($batches as $b):
                                        $day    = $b['current_day'];
                                        $proc   = $b['failed_count'] + $b['balut_count'] + $b['chick_count'];
                                        $rem    = $b['total_egg'] - $proc;
                                        $locked = $day < BALUT_UNLOCK_DAY;
                                        $logged = isset($today_logged_map[$b['egg_id']]) ? $today_logged_map[$b['egg_id']] : false;
                                    ?>
                                        <tr>
                                            <td data-label="Batch #"><strong>#<?= $b['batch_number'] ?></strong></td>
                                            <td data-label="Total"><?= number_format($b['total_egg']) ?></td>
                                            <td data-label="Status"><span class="status-badge status-<?= $b['status'] ?>"><?= ucfirst($b['status']) ?></span></td>
                                            <td data-label="Started"><?= date('M d, Y', strtotime($b['date_started_incubation'])) ?></td>
                                            <td data-label="Day"><span class="day-badge <?= $locked ? 'day-early' : ($day >= CHICK_UNLOCK_DAY ? 'day-late' : 'day-mid') ?>">Day <?= $day ?></span></td>
                                            <td data-label="Balut"><?= number_format($b['balut_count']) ?></td>
                                            <td data-label="Chicks"><?= number_format($b['chick_count']) ?></td>
                                            <td data-label="Failed"><?= number_format($b['failed_count']) ?></td>
                                            <td data-label="Remaining"><strong><?= number_format($rem) ?></strong></td>
                                            <td data-label="Actions">
                                                <div class="action-btns">
                                                    <?php if ($b['status'] === 'incubating' && $rem > 0 && !$logged): ?>
                                                        <button class="btn btn-warning btn-sm" onclick="openUpdateModal(<?= $b['egg_id'] ?>, <?= $day ?>, <?= $rem ?>, <?= $locked ? 'true' : 'false' ?>)">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                    <?php elseif ($logged && $b['status'] === 'incubating'): ?>
                                                        <span class="badge-done"><i class="fas fa-check"></i></span>
                                                    <?php endif; ?>
                                                    <form method="post" style="display:inline;" onsubmit="return confirm('Delete this batch? Cannot be undone.')">
                                                        <input type="hidden" name="egg_id" value="<?= $b['egg_id'] ?>">
                                                        <button class="btn btn-danger btn-sm" type="submit" name="delete_batch">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Daily Logs for Each Batch -->
                <?php foreach ($batches as $b):
                    try {
                        $ls = $conn->prepare("SELECT * FROM egg_daily_logs WHERE egg_id = ? ORDER BY day_number ASC");
                        $ls->execute([$b['egg_id']]);
                        $dlogs = $ls->fetchAll(PDO::FETCH_ASSOC);
                    } catch (PDOException $e) {
                        $dlogs = [];
                    }
                    if (empty($dlogs)) continue;

                    $total_balut = array_sum(array_column($dlogs, 'balut_count'));
                    $total_chicks = array_sum(array_column($dlogs, 'chick_count'));
                    $total_failed = array_sum(array_column($dlogs, 'failed_count'));
                ?>
                    <div class="batch-logs-section">
                        <div class="batch-logs-header">
                            <h4><i class="fas fa-history"></i> Batch #<?= $b['batch_number'] ?> — Daily Log</h4>
                            <span class="batch-badge"><?= count($dlogs) ?> days recorded</span>
                        </div>
                        <div class="logs-summary">
                            <span class="logs-summary-item"><i class="fas fa-drumstick-bite" style="color:#f59e0b"></i> Balut: <?= number_format($total_balut) ?></span>
                            <span class="logs-summary-item"><i class="fas fa-dove" style="color:#10b981"></i> Chicks: <?= number_format($total_chicks) ?></span>
                            <span class="logs-summary-item"><i class="fas fa-times-circle" style="color:#ef4444"></i> Failed: <?= number_format($total_failed) ?></span>
                        </div>
                        <div class="table-scroll-wrapper daily-logs-desktop">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Day</th>
                                        <th>Balut</th>
                                        <th>Chicks</th>
                                        <th>Failed</th>
                                        <th>Date Logged</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($dlogs as $dl): ?>
                                        <tr>
                                            <td>Day <?= $dl['day_number'] ?></td>
                                            <td><?= number_format($dl['balut_count']) ?></td>
                                            <td><?= number_format($dl['chick_count']) ?></td>
                                            <td><?= number_format($dl['failed_count']) ?></td>
                                            <td><?= date('M d, Y', strtotime($dl['created_at'])) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="daily-logs-mobile">
                            <?php foreach ($dlogs as $dl): ?>
                                <div class="log-card">
                                    <div class="log-card-header">
                                        <span class="log-day">Day <?= $dl['day_number'] ?></span>
                                        <span class="log-date"><i class="far fa-calendar-alt"></i> <?= date('M d, Y', strtotime($dl['created_at'])) ?></span>
                                    </div>
                                    <div class="log-stats">
                                        <span><i class="fas fa-drumstick-bite" style="color:#f59e0b"></i> Balut: <?= number_format($dl['balut_count']) ?></span>
                                        <span><i class="fas fa-dove" style="color:#10b981"></i> Chicks: <?= number_format($dl['chick_count']) ?></span>
                                        <span><i class="fas fa-times-circle" style="color:#ef4444"></i> Failed: <?= number_format($dl['failed_count']) ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <!-- =================== ANALYTICS TAB =================== -->
            <?php if ($activeTab === 'analytics'): ?>
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-info">
                            <h3>Total Eggs</h3>
                            <p><?= number_format($total_eggs) ?></p>
                        </div>
                        <div class="stat-icon"><i class="fas fa-egg"></i></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-info">
                            <h3>Chick Rate</h3>
                            <p><?= $success_rate ?>%</p>
                        </div>
                        <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-info">
                            <h3>Balut Rate</h3>
                            <p><?= $balut_rate ?>%</p>
                        </div>
                        <div class="stat-icon"><i class="fas fa-percentage"></i></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-info">
                            <h3>Success Rate</h3>
                            <p><?= $processed_eggs > 0 ? round((($stat_chicks + $stat_balut) / $processed_eggs) * 100, 1) : 0 ?>%</p>
                        </div>
                        <div class="stat-icon"><i class="fas fa-trophy"></i></div>
                    </div>
                </div>

                <div class="chart-row">
                    <div class="chart-card">
                        <h3><i class="fas fa-chart-pie" style="color:#10b981"></i> Production Distribution</h3>
                        <canvas id="pieChartCanvas"></canvas>
                    </div>
                    <div class="chart-card">
                        <h3><i class="fas fa-chart-bar" style="color:#3b82f6"></i> Daily Production Log</h3>
                        <canvas id="dailyChartCanvas"></canvas>
                    </div>
                </div>

                <div class="table-container">
                    <div class="table-header">
                        <h3><i class="fas fa-table"></i> Batch Summary</h3>
                    </div>
                    <div class="table-scroll-wrapper">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Batch #</th>
                                    <th>Eggs</th>
                                    <th>Balut</th>
                                    <th>Chicks</th>
                                    <th>Failed</th>
                                    <th>Success Rate</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($batches as $b):
                                    $batch_proc = $b['failed_count'] + $b['balut_count'] + $b['chick_count'];
                                    $batch_success = $batch_proc > 0 ? round(($b['chick_count'] / $batch_proc) * 100, 1) : 0;
                                ?>
                                    <tr>
                                        <td>#<?= $b['batch_number'] ?></td>
                                        <td><?= number_format($b['total_egg']) ?></td>
                                        <td><?= number_format($b['balut_count']) ?></td>
                                        <td><?= number_format($b['chick_count']) ?></td>
                                        <td><?= number_format($b['failed_count']) ?></td>
                                        <td><strong><?= $batch_success ?>%</strong></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <!-- =================== REPORTS TAB (Improved - Matching Manager Dashboard) =================== -->
            <?php if ($activeTab === 'reports'): ?>
                <!-- Report Controls Section - Exactly matching Manager layout -->
                <div class="report-controls">
                    <div class="form-group">
                        <label>Report Type</label>
                        <select id="reportType">
                            <option value="production_summary" <?= $reportType == 'production_summary' ? 'selected' : '' ?>>My Production Summary</option>
                            <option value="batch_report" <?= $reportType == 'batch_report' ? 'selected' : '' ?>>My Batch Report</option>
                            <option value="daily_egg_logs" <?= $reportType == 'daily_egg_logs' ? 'selected' : '' ?>>Daily Egg Logs</option>
                            <option value="failed_egg_report" <?= $reportType == 'failed_egg_report' ? 'selected' : '' ?>>Failed Egg Report</option>
                            <option value="monthly_summary" <?= $reportType == 'monthly_summary' ? 'selected' : '' ?>>Monthly Summary</option>
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
                    <button class="btn btn-outline" onclick="printReport()">
                        <i class="fas fa-print"></i> Print
                    </button>
                </div>

                <!-- Report Preview Section - Exactly matching Manager layout -->
                <div class="table-container" id="reportPreview">
                    <div class="table-header">
                        <h3 id="reportTitle">Report Preview</h3>
                    </div>
                    <div class="table-scroll-wrapper" id="reportContent">
                        <?php if ($reportData && !empty($reportData)): ?>
                            <?php
                            $titles = [
                                'production_summary' => 'My Production Summary Report',
                                'batch_report' => 'My Batch Report',
                                'daily_egg_logs' => 'Daily Egg Logs Report',
                                'failed_egg_report' => 'Failed Egg Report',
                                'monthly_summary' => 'Monthly Summary Report',
                            ];
                            $title = $titles[$reportType] ?? 'Report Preview';
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
                                                <td><?= htmlspecialchars($value ?? '0') ?></td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <p style="text-align:center; padding: 2rem; color:#94a3b8;">
                                <i class="fas fa-chart-line" style="font-size: 2rem; margin-bottom: 0.5rem; display: block;"></i>
                                Select a report type and date range, then click Generate to view your personal production data.
                            </p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Incubation Guide -->
                <div class="guide-hero">
                    <i class="fas fa-egg guide-hero-icon"></i>
                    <div>
                        <h2>Duck Egg Incubation Guide</h2>
                        <p>Everything you need to know about balut production — from egg to table.</p>
                    </div>
                </div>

                <div class="guide-grid">
                    <div class="guide-card phase-early">
                        <div class="guide-card-header">
                            <span class="phase-num">Phase 1</span>
                            <h3>Early Development</h3>
                            <span class="phase-days">Days 1 – 13</span>
                        </div>
                        <ul class="guide-list">
                            <li><i class="fas fa-thermometer-half"></i> Maintain 37.5–38°C (99.5–100.4°F)</li>
                            <li><i class="fas fa-tint"></i> Humidity: 55–65%</li>
                            <li><i class="fas fa-sync-alt"></i> Turn eggs 3–5× daily</li>
                            <li><i class="fas fa-ban"></i> <strong>Balut &amp; chick harvesting locked</strong></li>
                        </ul>
                        <div class="guide-tip"><i class="fas fa-lightbulb"></i> Remove infertile eggs during candling.</div>
                    </div>

                    <div class="guide-card phase-balut">
                        <div class="guide-card-header">
                            <span class="phase-num">Phase 2</span>
                            <h3>Balut Harvest Window</h3>
                            <span class="phase-days">Days 14 – 18</span>
                        </div>
                        <ul class="guide-list">
                            <li><i class="fas fa-egg"></i> Embryo well-developed — ideal for balut</li>
                            <li><i class="fas fa-fire"></i> Boil harvested eggs 20–30 minutes</li>
                            <li><i class="fas fa-star"></i> Day 17–18 is peak balut quality</li>
                        </ul>
                        <div class="guide-tip"><i class="fas fa-lightbulb"></i> The broth is rich in protein — crack a small hole and drink it first!</div>
                    </div>

                    <div class="guide-card phase-watch">
                        <div class="guide-card-header">
                            <span class="phase-num">Phase 3</span>
                            <h3>Late Incubation</h3>
                            <span class="phase-days">Days 19 – 25</span>
                        </div>
                        <ul class="guide-list">
                            <li><i class="fas fa-eye"></i> Stop turning eggs at Day 25</li>
                            <li><i class="fas fa-tint"></i> Increase humidity to 70–75%</li>
                            <li><i class="fas fa-volume-up"></i> You may hear peeping inside the eggs</li>
                        </ul>
                        <div class="guide-tip"><i class="fas fa-lightbulb"></i> Mist eggs lightly with warm water daily.</div>
                    </div>

                    <div class="guide-card phase-hatch">
                        <div class="guide-card-header">
                            <span class="phase-num">Phase 4</span>
                            <h3>Hatching</h3>
                            <span class="phase-days">Days 26 – 28</span>
                        </div>
                        <ul class="guide-list">
                            <li><i class="fas fa-egg"></i> Pipping begins around Day 26</li>
                            <li><i class="fas fa-clock"></i> Full hatch takes 12–24 hrs — do not help</li>
                            <li><i class="fas fa-child"></i> Remove dried chicks 12–24h after hatch</li>
                        </ul>
                        <div class="guide-tip"><i class="fas fa-lightbulb"></i> Ducklings don't need food/water for 24h after hatch.</div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- =================== PROFILE TAB =================== -->
            <?php if ($activeTab === 'profile'): ?>
                <div class="table-container">
                    <div class="table-header">
                        <h3><i class="fas fa-user-circle"></i> My Profile</h3>
                    </div>
                    <div class="table-scroll-wrapper">
                        <table class="data-table">
                            <tbody>
                                <tr>
                                    <td style="width: 140px; font-weight: 600;">Username</td>
                                    <td><?= htmlspecialchars($username) ?></td>
                                </tr>
                                <tr>
                                    <td style="font-weight: 600;">Role</td>
                                    <td><span class="status-badge status-user">User</span></td>
                                </tr>
                                <tr>
                                    <td style="font-weight: 600;">Member Since</td>
                                    <td><?= date('F j, Y', strtotime($_SESSION['created_at'] ?? 'now')) ?></td>
                                </tr>
                                <tr>
                                    <td style="font-weight: 600;">Total Batches</td>
                                    <td><?= count($batches) ?></td>
                                </tr>
                                <tr>
                                    <td style="font-weight: 600;">Total Production</td>
                                    <td><?= number_format($stat_chicks) ?> chicks, <?= number_format($stat_balut) ?> balut</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

        </main>
    </div>

    <!-- Add Batch Modal -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-plus-circle"></i> Add New Batch</h3>
                <button class="close" onclick="closeModal('addModal')">&times;</button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Total Eggs</label>
                        <input type="number" name="total_egg" min="1" placeholder="e.g., 100" required>
                    </div>
                    <div class="guide-tip" style="margin-top:0.5rem">
                        <i class="fas fa-info-circle"></i>
                        Today is Day 1. The day will automatically increment each day.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('addModal')">Cancel</button>
                    <button type="submit" name="add_batch" class="btn btn-primary">Start Batch</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Update Modal -->
    <div id="updateModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-edit"></i> Update Batch — Day <span id="modalDay">1</span></h3>
                <button class="close" onclick="closeModal('updateModal')">&times;</button>
            </div>
            <form method="post" onsubmit="return validateUpdate()">
                <input type="hidden" name="egg_id" id="updateEggId">
                <div class="modal-body">
                    <div class="remaining-info">
                        <i class="fas fa-info-circle"></i>
                        <span id="remainingText">0 eggs remaining</span>
                    </div>
                    <div id="lockNotice" class="lock-notice" style="display:none">
                        <i class="fas fa-lock"></i>
                        <div>
                            <strong>Balut &amp; Chick updates locked</strong>
                            <p>Unlocks at Day <?= BALUT_UNLOCK_DAY ?>. Only failed egg removal is allowed now.</p>
                        </div>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-times-circle" style="color:#ef4444"></i> Failed Eggs</label>
                        <input type="number" name="failed_count" id="failedInput" min="0" value="0" oninput="checkTotal()">
                    </div>
                    <div class="form-group" id="balutGroup">
                        <label><i class="fas fa-drumstick-bite" style="color:#f59e0b"></i> Balut Harvested</label>
                        <input type="number" name="balut_count" id="balutInput" min="0" value="0" oninput="checkTotal()">
                    </div>
                    <div class="form-group" id="chickGroup">
                        <label><i class="fas fa-dove" style="color:#10b981"></i> Chicks Hatched</label>
                        <input type="number" name="chick_count" id="chickInput" min="0" value="0" oninput="checkTotal()">
                    </div>
                    <div id="validationMsg" class="alert alert-error" style="display:none;margin-top:0.5rem"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('updateModal')">Cancel</button>
                    <button type="submit" name="update_daily" class="btn btn-primary" id="submitUpdateBtn">Save Update</button>
                </div>
            </form>
        </div>
    </div>

    <div id="customToast"></div>

    <!-- Pass PHP variables to JavaScript -->
    <script>
        window.EggFlowConfig = {
            totalBalut: <?= $stat_balut ?>,
            totalChicks: <?= $stat_chicks ?>,
            totalFailed: <?= $stat_failed ?>,
            incubating: <?= $stat_incubating ?>,
            complete: <?= $stat_complete ?>,
            dailyAnalytics: <?= json_encode($daily_analytics) ?>,
            batchRemaining: <?= json_encode($batch_remaining) ?>,
            BALUT_UNLOCK: <?= BALUT_UNLOCK_DAY ?>,
            CHICK_UNLOCK: <?= CHICK_UNLOCK_DAY ?>
        };
    </script>

    <!-- External JavaScript File -->
    <script src="../../assets/user/js/user_function.js"></script>
</body>

</html>