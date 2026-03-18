<?php
require '../../model/config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../../index.php");
    exit;
}

/* LOG ACCESS */
$action = "Admin opened dashboard";
$stmt = $conn->prepare("INSERT INTO user_activity_logs (user_id, action) VALUES (?, ?)");
$stmt->execute([$_SESSION['user_id'], $action]);

/* USER STATS */
$totalUsers = $conn->query("SELECT COUNT(*) FROM users")->fetchColumn();
$totalAdmins = $conn->query("SELECT COUNT(*) FROM users WHERE user_role='admin'")->fetchColumn();
$totalManagers = $conn->query("SELECT COUNT(*) FROM users WHERE user_role='manager'")->fetchColumn();
$totalRegularUsers = $conn->query("SELECT COUNT(*) FROM users WHERE user_role='user'")->fetchColumn();

/* EGG STATS */
$total_batches = $conn->query("SELECT COUNT(*) FROM egg")->fetchColumn();
$total_eggs = $conn->query("SELECT SUM(total_egg) FROM egg")->fetchColumn() ?: 0;
$total_chicks = $conn->query("SELECT SUM(chick_count) FROM egg")->fetchColumn() ?: 0;

/* FETCH BATCHES */
$stmt = $conn->query("
SELECT e.*, u.username,
       DATEDIFF(NOW(), e.date_started_incubation) as days_in_incubation
FROM egg e
JOIN users u ON e.user_id = u.user_id
ORDER BY e.date_started_incubation DESC
LIMIT 5
");
$batches = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ACTIVITY LOGS WITH METRICS */
$stmt = $conn->query("
SELECT l.*, u.username
FROM user_activity_logs l
LEFT JOIN users u ON l.user_id = u.user_id
ORDER BY log_date DESC
LIMIT 10
");
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* -------------------------
ENHANCED ANALYTICS
--------------------------*/

// 1. DAILY ACTIVITY (Last 14 days with trends)
$dates = [];
$activityTrend = [];
$uniqueUsers = [];

for ($i = 13; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $dates[] = $date;
}

// Debug: Check if activity logs table has data
$checkData = $conn->query("SELECT COUNT(*) as count FROM user_activity_logs")->fetch(PDO::FETCH_ASSOC);
$hasLogData = $checkData['count'] > 0;

$stmt = $conn->prepare("
    SELECT DATE(log_date) as date, 
           COUNT(*) as count,
           COUNT(DISTINCT user_id) as unique_users
    FROM user_activity_logs
    WHERE log_date >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
    GROUP BY DATE(log_date)
    ORDER BY date
");
$stmt->execute();
$dailyStats = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $dailyStats[$row['date']] = [
        'count' => (int)$row['count'],
        'unique_users' => (int)$row['unique_users']
    ];
}

// Calculate trends and averages
$totalActivity = 0;
$daysWithData = 0;

foreach ($dates as $date) {
    $count = isset($dailyStats[$date]) ? $dailyStats[$date]['count'] : 0;
    $unique = isset($dailyStats[$date]) ? $dailyStats[$date]['unique_users'] : 0;

    $activityTrend[] = $count;
    $uniqueUsers[] = $unique;

    if ($count > 0) {
        $totalActivity += $count;
        $daysWithData++;
    }
}

$avgDailyActivity = $daysWithData > 0 ? round($totalActivity / $daysWithData, 1) : 0;

// 2. HOURLY ACTIVITY PATTERN
$stmt = $conn->query("
    SELECT HOUR(log_date) as hour, 
           COUNT(*) as count
    FROM user_activity_logs
    WHERE log_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY HOUR(log_date)
    ORDER BY hour
");
$hourlyActivity = array_fill(0, 24, 0);
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $hourlyActivity[(int)$row['hour']] = (int)$row['count'];
}

// 3. TOP ACTIONS WITH TRENDS
$stmt = $conn->query("
    SELECT action, 
           COUNT(*) as total_count,
           COUNT(DISTINCT user_id) as unique_users,
           MAX(log_date) as last_performed
    FROM user_activity_logs
    GROUP BY action
    ORDER BY total_count DESC
    LIMIT 8
");
$actionStats = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 4. USER ENGAGEMENT METRICS
$stmt = $conn->query("
    SELECT 
        COUNT(DISTINCT user_id) as active_users_7d,
        COUNT(*) as total_actions_7d,
        AVG(daily_actions) as avg_actions_per_user
    FROM (
        SELECT user_id, 
               COUNT(*) as daily_actions
        FROM user_activity_logs
        WHERE log_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY user_id, DATE(log_date)
    ) as daily_user_actions
");
$engagementMetrics = $stmt->fetch(PDO::FETCH_ASSOC);

// 5. PERFORMANCE METRICS
$currentHour = (int)date('H');
$todayActivity = isset($hourlyActivity[$currentHour]) ? $hourlyActivity[$currentHour] : 0;
$peakHour = array_search(max($hourlyActivity), $hourlyActivity);
$peakActivity = max($hourlyActivity);

// Format dates for JavaScript
$formattedDates = array_map(function ($date) {
    return date('M d', strtotime($date));
}, $dates);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | Analytics</title>

    <!-- Fonts & Icons -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

    <!-- Custom CSS -->
    <link rel="stylesheet" href="../../assets/admin/js/css/main_dashboard.css">

    <style>
        /* Debug styles - remove in production */
        .debug-info {
            background: #fff3cd;
            border: 1px solid #ffeeba;
            color: #856404;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            display: none;
            /* Hide by default, show if no data */
        }

        .debug-info.visible {
            display: block;
        }
    </style>
</head>

<body>
    <div class="dashboard">
        <!-- Sidebar -->
        <aside class="sidebar">
            <h2>
                <i class="fas fa-egg"></i>
                EggFlow Analytics
            </h2>
            <ul>
                <li class="active">
                    <a href="dashboard.php">
                        <i class="fas fa-chart-pie"></i>
                        Dashboard
                    </a>
                </li>
                <li>
                    <a href="../users/dashboard.php">
                        <i class="fas fa-users"></i>
                        User Management
                    </a>
                </li>
                <li>
                    <a href="analytics.php">
                        <i class="fas fa-chart-line"></i>
                        Advanced Analytics
                    </a>
                </li>
                <li>
                    <a href="reports.php">
                        <i class="fas fa-file-alt"></i>
                        Reports
                    </a>
                </li>
                <li>
                    <a href="../../controller/auth/signout.php">
                        <i class="fas fa-sign-out-alt"></i>
                        Logout
                    </a>
                </li>
            </ul>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Top Bar -->
            <div class="top-bar">
                <div class="page-title">
                    <h1>Analytics Dashboard</h1>
                    <p>
                        <i class="fas fa-eye"></i>
                        Real-time system overview and activity monitoring
                    </p>
                </div>
                <div class="date-badge">
                    <i class="far fa-calendar"></i>
                    <?= date('l, F j, Y') ?>
                </div>
            </div>

            <!-- Debug Info (visible only if no data) -->
            <div class="debug-info <?= !$hasLogData ? 'visible' : '' ?>">
                <i class="fas fa-info-circle"></i>
                <strong>Debug:</strong> No activity log data found. Sample data will be shown for demonstration.
                <button onclick="loadSampleData()" class="btn btn-outline" style="margin-left: 1rem;">
                    <i class="fas fa-chart-line"></i> Load Sample Data
                </button>
            </div>

            <!-- Quick Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-header">
                        <span class="stat-title">Total Users</span>
                        <span class="stat-icon">
                            <i class="fas fa-users"></i>
                        </span>
                    </div>
                    <div class="stat-value"><?= number_format($totalUsers) ?></div>
                    <div class="stat-trend">
                        <i class="fas fa-arrow-up"></i>
                        <?= $totalRegularUsers ?> regular users
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <span class="stat-title">Active Today</span>
                        <span class="stat-icon">
                            <i class="fas fa-user-check"></i>
                        </span>
                    </div>
                    <div class="stat-value"><?= !empty($activityTrend) ? end($activityTrend) : 0 ?></div>
                    <div class="stat-trend">
                        <i class="fas fa-clock"></i>
                        Peak at <?= $peakHour !== false ? str_pad($peakHour, 2, '0', STR_PAD_LEFT) : '00' ?>:00
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <span class="stat-title">Egg Batches</span>
                        <span class="stat-icon">
                            <i class="fas fa-box"></i>
                        </span>
                    </div>
                    <div class="stat-value"><?= number_format($total_batches) ?></div>
                    <div class="stat-trend">
                        <i class="fas fa-egg"></i>
                        <?= number_format($total_eggs) ?> total eggs
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <span class="stat-title">Chicks Hatched</span>
                        <span class="stat-icon">
                            <i class="fas fa-hat-wizard"></i>
                        </span>
                    </div>
                    <div class="stat-value"><?= number_format($total_chicks) ?></div>
                    <div class="stat-trend">
                        <i class="fas fa-chart-line"></i>
                        <?= $total_batches > 0 ? round(($total_chicks / $total_eggs) * 100, 1) : 0 ?>% hatch rate
                    </div>
                </div>
            </div>

            <!-- Charts Grid -->
            <div class="chart-grid">
                <!-- Daily Activity Chart -->
                <div class="chart-card">
                    <div class="chart-header">
                        <div>
                            <h3 class="chart-title">Daily Activity Trend</h3>
                            <p class="chart-subtitle">Last 14 days • Avg <?= $avgDailyActivity ?> actions/day</p>
                        </div>
                        <div class="table-actions">
                            <button class="btn btn-outline" onclick="toggleChartType('daily')">
                                <i class="fas fa-chart-bar"></i>
                            </button>
                        </div>
                    </div>
                    <div class="chart-container">
                        <canvas id="dailyActivityChart"></canvas>
                    </div>
                </div>

                <!-- Hourly Activity Pattern -->
                <div class="chart-card">
                    <div class="chart-header">
                        <div>
                            <h3 class="chart-title">Activity by Hour</h3>
                            <p class="chart-subtitle">24-hour pattern • Peak at <?= $peakHour !== false ? str_pad($peakHour, 2, '0', STR_PAD_LEFT) : '00' ?>:00</p>
                        </div>
                    </div>
                    <div class="chart-container">
                        <canvas id="hourlyActivityChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Top Actions Table -->
            <div class="table-container">
                <div class="table-header">
                    <h3 class="table-title">Top Actions</h3>
                    <div class="table-actions">
                        <button class="btn btn-outline" onclick="exportToCSV()">
                            <i class="fas fa-download"></i>
                            Export
                        </button>
                    </div>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Action</th>
                            <th>Total</th>
                            <th>Unique Users</th>
                            <th>Last Performed</th>
                            <th>Frequency</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($actionStats)): ?>
                            <tr>
                                <td colspan="5" style="text-align: center; color: #64748b; padding: 2rem;">
                                    <i class="fas fa-info-circle"></i> No activity data available
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($actionStats as $action): ?>
                                <tr>
                                    <td>
                                        <span class="badge badge-info"><?= htmlspecialchars($action['action']) ?></span>
                                    </td>
                                    <td><strong><?= number_format($action['total_count']) ?></strong></td>
                                    <td><?= $action['unique_users'] ?></td>
                                    <td><?= date('M j, H:i', strtotime($action['last_performed'])) ?></td>
                                    <td>
                                        <div class="frequency-bar">
                                            <div class="frequency-fill" style="width: <?= min(100, ($action['total_count'] / $actionStats[0]['total_count'] * 100)) ?>%;"></div>
                                            <span><?= round(($action['total_count'] / $actionStats[0]['total_count'] * 100), 1) ?>%</span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Recent Batches -->
            <div class="table-container">
                <div class="table-header">
                    <h3 class="table-title">Recent Egg Batches</h3>
                    <a href="batches.php" class="btn btn-outline">
                        <i class="fas fa-external-link-alt"></i>
                        View All
                    </a>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Batch #</th>
                            <th>User</th>
                            <th>Total Eggs</th>
                            <th>Chicks</th>
                            <th>Status</th>
                            <th>Days in Incubation</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($batches)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; color: #64748b; padding: 2rem;">
                                    <i class="fas fa-info-circle"></i> No egg batches available
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($batches as $batch): ?>
                                <tr>
                                    <td><strong>#<?= $batch['batch_number'] ?></strong></td>
                                    <td>
                                        <span class="status-indicator status-active"></span>
                                        <?= htmlspecialchars($batch['username']) ?>
                                    </td>
                                    <td><?= number_format($batch['total_egg']) ?></td>
                                    <td><?= number_format($batch['chick_count']) ?></td>
                                    <td>
                                        <?php
                                        $statusClass = '';
                                        $statusIcon = '';
                                        switch ($batch['status']) {
                                            case 'active':
                                                $statusClass = 'badge-success';
                                                $statusIcon = 'fa-spinner';
                                                break;
                                            case 'completed':
                                                $statusClass = 'badge-info';
                                                $statusIcon = 'fa-check-circle';
                                                break;
                                            default:
                                                $statusClass = 'badge-warning';
                                                $statusIcon = 'fa-clock';
                                        }
                                        ?>
                                        <span class="badge <?= $statusClass ?>">
                                            <i class="fas <?= $statusIcon ?>"></i>
                                            <?= ucfirst($batch['status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?= $batch['days_in_incubation'] ?? 0 ?> days
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Recent Activity Logs -->
            <div class="table-container">
                <div class="table-header">
                    <h3 class="table-title">Recent Activity</h3>
                    <div class="table-actions">
                        <button class="btn btn-outline" onclick="refreshLogs()">
                            <i class="fas fa-sync-alt"></i>
                            Refresh
                        </button>
                    </div>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>User</th>
                            <th>Action</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                            <tr>
                                <td colspan="4" style="text-align: center; color: #64748b; padding: 2rem;">
                                    <i class="fas fa-info-circle"></i> No recent activity
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td>
                                        <span class="tooltip" data-tooltip="<?= date('F j, Y H:i:s', strtotime($log['log_date'])) ?>">
                                            <?= date('H:i', strtotime($log['log_date'])) ?>
                                        </span>
                                    </td>
                                    <td><?= $log['username'] ?? 'System' ?></td>
                                    <td>
                                        <span class="badge badge-success">
                                            <?= htmlspecialchars($log['action']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <i class="fas fa-info-circle" style="color: #94a3b8;"></i>
                                        <?= isset($log['details']) ? htmlspecialchars($log['details']) : '—' ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>

    <!-- Custom JavaScript -->
    <script src="../../assets/admin/js/main_dashboard.js"></script>
    <script>
        // Pass PHP data to JavaScript with explicit JSON encoding
        const chartData = {
            dates: <?= json_encode($formattedDates, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
            activityTrend: <?= json_encode($activityTrend, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
            uniqueUsers: <?= json_encode($uniqueUsers, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
            hourlyActivity: <?= json_encode(array_values($hourlyActivity), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
            peakHour: <?= $peakHour !== false ? $peakHour : 0 ?>,
            actionStats: <?= json_encode($actionStats, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
        };

        // Debug: Log the data to console
        console.log('Chart Data:', chartData);
        console.log('Activity Trend:', chartData.activityTrend);
        console.log('Hourly Activity:', chartData.hourlyActivity);

        // Initialize charts when page loads
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof initializeCharts === 'function') {
                initializeCharts(chartData);
            } else {
                console.error('initializeCharts function not found');
            }
        });

        // Load sample data function for debugging
        function loadSampleData() {
            const sampleData = {
                dates: ['Jan 1', 'Jan 2', 'Jan 3', 'Jan 4', 'Jan 5', 'Jan 6', 'Jan 7', 'Jan 8', 'Jan 9', 'Jan 10', 'Jan 11', 'Jan 12', 'Jan 13', 'Jan 14'],
                activityTrend: [5, 8, 12, 15, 10, 9, 14, 18, 22, 19, 16, 13, 11, 7],
                uniqueUsers: [3, 5, 7, 8, 6, 5, 8, 10, 12, 11, 9, 7, 6, 4],
                hourlyActivity: [2, 1, 0, 0, 1, 3, 5, 8, 12, 15, 18, 20, 22, 25, 23, 20, 18, 15, 12, 10, 8, 6, 4, 3],
                peakHour: 13,
                actionStats: [{
                        action: 'Login',
                        total_count: 45,
                        unique_users: 12,
                        last_performed: '2024-01-14 15:30:00'
                    },
                    {
                        action: 'View Dashboard',
                        total_count: 38,
                        unique_users: 10,
                        last_performed: '2024-01-14 14:20:00'
                    },
                    {
                        action: 'Create Batch',
                        total_count: 25,
                        unique_users: 5,
                        last_performed: '2024-01-14 13:15:00'
                    }
                ]
            };

            if (typeof initializeCharts === 'function') {
                initializeCharts(sampleData);
                document.querySelector('.debug-info').style.display = 'none';
            }
        }
    </script>
</body>

</html>