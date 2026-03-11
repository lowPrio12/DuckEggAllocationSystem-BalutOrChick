<?php
require '../../model/config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../../index.php");
    exit;
}

// Delete User
if (isset($_POST['delete_user'])) {

    $user_id = $_POST['user_id'];

    $stmt = $conn->prepare("DELETE FROM users WHERE user_id=? AND user_role!='admin'");
    $stmt->execute([$user_id]);

    header("Location: dashboard.php");
    exit;
}

// Delete Batch
if (isset($_POST['delete_batch'])) {

    $egg_id = $_POST['egg_id'];

    $stmt = $conn->prepare("DELETE FROM egg WHERE egg_id=?");
    $stmt->execute([$egg_id]);

    header("Location: dashboard.php");
    exit;
}


// --------------------
// Dashboard Summary
// --------------------

$stmt = $conn->query("SELECT COUNT(*) FROM users WHERE user_role='user'");
$total_users = $stmt->fetchColumn();

$stmt = $conn->query("SELECT COUNT(*) FROM egg");
$total_batches = $stmt->fetchColumn();

$stmt = $conn->query("SELECT SUM(total_egg) FROM egg");
$total_eggs = $stmt->fetchColumn();

$stmt = $conn->query("SELECT SUM(chick_count) FROM egg");
$total_chicks = $stmt->fetchColumn();


// --------------------
// Fetch Users
// --------------------

$stmt = $conn->query("SELECT * FROM users WHERE user_role='user' ORDER BY created_at DESC");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);


// --------------------
// Fetch Egg Batches
// --------------------

$stmt = $conn->query("
SELECT e.*, u.username
FROM egg e
JOIN users u ON e.user_id = u.user_id
ORDER BY e.date_started_incubation DESC
");

$batches = $stmt->fetchAll(PDO::FETCH_ASSOC);


// --------------------
// Activity Logs
// --------------------

$stmt = $conn->query("
SELECT l.*, u.username
FROM user_activity_logs l
LEFT JOIN users u ON l.user_id = u.user_id
ORDER BY log_date DESC
LIMIT 10
");

$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html>

<head>
    <title>Admin Dashboard</title>
    <link rel="stylesheet" href="../../assets/admin_style.css">
</head>

<body>

    <div class="wrapper">

        <!-- Sidebar -->
        <aside class="sidebar">
            <h2>Egg System Admin</h2>
            <ul>
                <li class="active">Dashboard</li>
                <li><a href="../../logout.php">Logout</a></li>
            </ul>
        </aside>

        <!-- Main Content -->
        <main class="content">

            <!-- Top Header -->
            <div class="header">
                <h1>Admin Dashboard</h1>
                <p>Overview of users, batches, and activity logs</p>
            </div>

            <!-- Summary Cards -->
            <div class="card-grid">
                <div class="stat-card">
                    <span>Total Users</span>
                    <h2><?= $total_users ?></h2>
                </div>
                <div class="stat-card">
                    <span>Total Batches</span>
                    <h2><?= $total_batches ?></h2>
                </div>
                <div class="stat-card">
                    <span>Total Eggs</span>
                    <h2><?= $total_eggs ?? 0 ?></h2>
                </div>
                <div class="stat-card">
                    <span>Total Chicks</span>
                    <h2><?= $total_chicks ?? 0 ?></h2>
                </div>
            </div>

            <!-- Users Table -->
            <div class="table-box">
                <h2>Users</h2>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Username</th>
                            <th>Created</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td><?= $user['user_id'] ?></td>
                                <td><?= $user['username'] ?></td>
                                <td><?= date("M d, Y", strtotime($user['created_at'])) ?></td>
                                <td>
                                    <form method="post" onsubmit="return confirm('Delete this user?');">
                                        <input type="hidden" name="user_id" value="<?= $user['user_id'] ?>">
                                        <button class="btn-delete" type="submit" name="delete_user">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Egg Batches Table -->
            <div class="table-box">
                <h2>All Egg Batches</h2>
                <table>
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Batch</th>
                            <th>Total Eggs</th>
                            <th>Status</th>
                            <th>Balut</th>
                            <th>Chick</th>
                            <th>Failed</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($batches as $batch): ?>
                            <tr>
                                <td><?= $batch['username'] ?></td>
                                <td>#<?= $batch['batch_number'] ?></td>
                                <td><?= $batch['total_egg'] ?></td>
                                <td><span class="status-badge"><?= $batch['status'] ?></span></td>
                                <td><?= $batch['balut_count'] ?></td>
                                <td><?= $batch['chick_count'] ?></td>
                                <td><?= $batch['failed_count'] ?></td>
                                <td>
                                    <form method="post" onsubmit="return confirm('Delete this batch?');">
                                        <input type="hidden" name="egg_id" value="<?= $batch['egg_id'] ?>">
                                        <button class="btn-delete" type="submit" name="delete_batch">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Activity Logs -->
            <div class="table-box">
                <h2>Recent Activity Logs</h2>
                <table>
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Action</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td><?= $log['username'] ?? 'System' ?></td>
                                <td><?= $log['action'] ?></td>
                                <td><?= date("M d, Y H:i", strtotime($log['log_date'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        </main>
    </div>

</body>

</html>