<?php
require '../../model/config.php';

// Fix the role check condition
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'user') {
    header("Location: ../../index.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Initialize variables
$incubating = 0;
$complete = 0;
$chicks = 0;
$batches_count = 0;
$success_rate = 0;
$batches = [];
$logs = [];

// Check if connection exists
if (!isset($conn) || $conn === null) {
    die("Database connection not established. Please check your config.php file.");
}

// Handle Add Batch
if (isset($_POST['add_batch'])) {
    $total_egg = intval($_POST['total_egg']);

    if ($total_egg <= 0) {
        $_SESSION['error'] = "Please enter a valid number of eggs.";
        header("Location: dashboard.php");
        exit;
    }

    try {
        // Get the next batch number
        $stmt = $conn->prepare("SELECT COALESCE(MAX(batch_number), 0) + 1 as next_batch FROM egg WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $next_batch = $result['next_batch'];

        // Insert new batch
        $stmt = $conn->prepare("INSERT INTO egg (user_id, total_egg, status, date_started_incubation, batch_number) 
                               VALUES (?, ?, 'incubating', NOW(), ?)");
        if ($stmt->execute([$user_id, $total_egg, $next_batch])) {
            $egg_id = $conn->lastInsertId();

            // Log activity
            $log_stmt = $conn->prepare("INSERT INTO user_activity_logs (user_id, action) VALUES (?, ?)");
            $log_stmt->execute([$user_id, "Added new batch #{$next_batch} with {$total_egg} eggs"]);

            $_SESSION['success'] = "Batch #{$next_batch} added successfully!";
        } else {
            $_SESSION['error'] = "Failed to add batch.";
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Database error: " . $e->getMessage();
    }

    header("Location: dashboard.php");
    exit;
}

// Handle Delete Batch
if (isset($_POST['delete_batch'])) {
    $egg_id = intval($_POST['egg_id']);

    try {
        // Get batch number for logging
        $stmt = $conn->prepare("SELECT batch_number FROM egg WHERE egg_id = ? AND user_id = ?");
        $stmt->execute([$egg_id, $user_id]);
        $batch = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($batch) {
            // Delete batch (cascade will delete daily logs)
            $stmt = $conn->prepare("DELETE FROM egg WHERE egg_id = ? AND user_id = ?");
            if ($stmt->execute([$egg_id, $user_id])) {
                // Log activity
                $log_stmt = $conn->prepare("INSERT INTO user_activity_logs (user_id, action) VALUES (?, ?)");
                $log_stmt->execute([$user_id, "Deleted batch #{$batch['batch_number']}"]);

                $_SESSION['success'] = "Batch deleted successfully!";
            } else {
                $_SESSION['error'] = "Failed to delete batch.";
            }
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Database error: " . $e->getMessage();
    }

    header("Location: dashboard.php");
    exit;
}

// Handle Daily Update
if (isset($_POST['update_daily'])) {
    $egg_id = intval($_POST['egg_id']);
    $failed_count = intval($_POST['failed_count']);
    $balut_count = intval($_POST['balut_count']);
    $chick_count = intval($_POST['chick_count']);

    // Validate inputs are not negative
    if ($failed_count < 0 || $balut_count < 0 || $chick_count < 0) {
        $_SESSION['error'] = "Values cannot be negative.";
        header("Location: dashboard.php");
        exit;
    }

    // Check if at least one value is entered
    if ($failed_count + $balut_count + $chick_count == 0) {
        $_SESSION['error'] = "Please enter at least one value greater than 0.";
        header("Location: dashboard.php");
        exit;
    }

    try {
        // Verify the egg belongs to this user and get current totals
        $stmt = $conn->prepare("SELECT * FROM egg WHERE egg_id = ? AND user_id = ?");
        $stmt->execute([$egg_id, $user_id]);
        $egg = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($egg) {
            // Calculate remaining eggs
            $total_processed = $egg['failed_count'] + $egg['balut_count'] + $egg['chick_count'];
            $remaining_eggs = $egg['total_egg'] - $total_processed;

            // Check if the total input exceeds remaining eggs
            $total_input = $failed_count + $balut_count + $chick_count;

            if ($total_input > $remaining_eggs) {
                $_SESSION['error'] = "Total input ({$total_input}) exceeds remaining eggs ({$remaining_eggs}). Please adjust your entries.";
                header("Location: dashboard.php");
                exit;
            }

            // Get current day number
            $start = new DateTime($egg['date_started_incubation']);
            $today = new DateTime();
            $day_number = $start->diff($today)->days + 1;

            // Check if log for this day already exists
            $check_stmt = $conn->prepare("SELECT log_id FROM egg_daily_logs WHERE egg_id = ? AND day_number = ?");
            $check_stmt->execute([$egg_id, $day_number]);

            if ($check_stmt->fetch()) {
                $_SESSION['error'] = "Daily log for day {$day_number} already exists.";
            } else {
                // Insert daily log
                $log_stmt = $conn->prepare("INSERT INTO egg_daily_logs (egg_id, day_number, failed_count, balut_count, chick_count) 
                                          VALUES (?, ?, ?, ?, ?)");
                if ($log_stmt->execute([$egg_id, $day_number, $failed_count, $balut_count, $chick_count])) {

                    // Update egg totals
                    $update_stmt = $conn->prepare("UPDATE egg SET 
                                                  failed_count = failed_count + ?,
                                                  balut_count = balut_count + ?,
                                                  chick_count = chick_count + ?
                                                  WHERE egg_id = ?");
                    $update_stmt->execute([$failed_count, $balut_count, $chick_count, $egg_id]);

                    // Check if all eggs are processed
                    $new_total_processed = $total_processed + $total_input;

                    if ($new_total_processed >= $egg['total_egg']) {
                        // Update status to complete
                        $status_stmt = $conn->prepare("UPDATE egg SET status = 'complete' WHERE egg_id = ?");
                        $status_stmt->execute([$egg_id]);
                        $status_message = " Batch completed!";
                    } else {
                        $status_message = "";
                    }

                    // Log activity
                    $activity_stmt = $conn->prepare("INSERT INTO user_activity_logs (user_id, action) VALUES (?, ?)");
                    $action = "Updated batch #{$egg['batch_number']} - Day {$day_number}: Failed:{$failed_count}, Balut:{$balut_count}, Chicks:{$chick_count}";
                    $activity_stmt->execute([$user_id, $action]);

                    $_SESSION['success'] = "Batch #{$egg['batch_number']} updated successfully!{$status_message}";
                } else {
                    $_SESSION['error'] = "Failed to update batch.";
                }
            }
        } else {
            $_SESSION['error'] = "Invalid batch.";
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Database error: " . $e->getMessage();
    }

    header("Location: dashboard.php");
    exit;
}

// Fetch user's batches
try {
    $stmt = $conn->prepare("SELECT * FROM egg WHERE user_id = ? ORDER BY date_started_incubation DESC");
    $stmt->execute([$user_id]);
    $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $_SESSION['error'] = "Error fetching batches: " . $e->getMessage();
    $batches = [];
}

// Calculate statistics
$incubating = 0;
$complete = 0;
$chicks = 0;
$total_eggs = 0;
$processed_eggs = 0;

foreach ($batches as $batch) {
    if ($batch['status'] == 'incubating') {
        $incubating++;
    } elseif ($batch['status'] == 'complete') {
        $complete++;
    }

    $chicks += $batch['chick_count'];
    $total_eggs += $batch['total_egg'];
    $processed_eggs += ($batch['failed_count'] + $batch['balut_count'] + $batch['chick_count']);
}

$batches_count = count($batches);
$success_rate = $total_eggs > 0 ? ($processed_eggs > 0 ? ($chicks / $processed_eggs) * 100 : 0) : 0;

// Fetch recent activity logs
try {
    $log_stmt = $conn->prepare("SELECT * FROM user_activity_logs WHERE user_id = ? ORDER BY log_date DESC LIMIT 10");
    $log_stmt->execute([$user_id]);
    $logs = $log_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $_SESSION['error'] = "Error fetching logs: " . $e->getMessage();
    $logs = [];
}

// Get remaining eggs for each batch to use in JavaScript
$batch_remaining = [];
foreach ($batches as $batch) {
    $total_processed = $batch['failed_count'] + $batch['balut_count'] + $batch['chick_count'];
    $batch_remaining[$batch['egg_id']] = $batch['total_egg'] - $total_processed;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Dashboard - Egg Incubation System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../../assets/user/js/css/user_style.css">
</head>

<body>
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <h2>🥚 Egg System</h2>
            <p>Incubation Tracker</p>
        </div>
        <ul class="sidebar-menu">
            <li class="active">
                <a href="dashboard.php">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            <li>
                <a href="../../controller/auth/signout.php">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </li>
        </ul>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Top Bar -->
        <div class="top-bar">
            <div class="welcome-text">
                <h1>Welcome back, <?= htmlspecialchars($_SESSION['user_name'] ?? 'User') ?>!</h1>
                <p>Here's what's happening with your incubation today.</p>
            </div>
            <div class="date-display">
                <i class="far fa-calendar-alt"></i>
                <?= date('l, F j, Y') ?>
            </div>
        </div>

        <!-- Messages -->
        <div class="message-container">
            <?php if (isset($_SESSION['success'])): ?>
                <div class="success-message">
                    <i class="fas fa-check-circle"></i>
                    <?= htmlspecialchars($_SESSION['success']) ?>
                </div>
                <?php unset($_SESSION['success']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-circle"></i>
                    <?= htmlspecialchars($_SESSION['error']) ?>
                </div>
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>
        </div>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-info">
                    <h3>Incubating Batches</h3>
                    <p><?= number_format($incubating) ?></p>
                </div>
                <div class="stat-icon">
                    <i class="fas fa-egg"></i>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-info">
                    <h3>Completed Batches</h3>
                    <p><?= number_format($complete) ?></p>
                </div>
                <div class="stat-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-info">
                    <h3>Total Chicks</h3>
                    <p><?= number_format($chicks) ?></p>
                </div>
                <div class="stat-icon">
                    <i class="fas fa-hat-wizard"></i>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-info">
                    <h3>Total Batches</h3>
                    <p><?= number_format($batches_count) ?></p>
                </div>
                <div class="stat-icon">
                    <i class="fas fa-layer-group"></i>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-info">
                    <h3>Success Rate</h3>
                    <p><?= number_format($success_rate, 1) ?>%</p>
                </div>
                <div class="stat-icon">
                    <i class="fas fa-chart-line"></i>
                </div>
            </div>
        </div>

        <!-- Action Bar -->
        <div class="action-bar">
            <button class="btn btn-primary" onclick="openModal('addModal')">
                <i class="fas fa-plus-circle"></i>
                Add New Batch
            </button>
        </div>

        <!-- Batches Table -->
        <div class="table-container">
            <div class="table-header">
                <h3><i class="fas fa-list" style="margin-right: 8px;"></i>Your Batches</h3>
            </div>

            <?php if (empty($batches)): ?>
                <div class="empty-state">
                    <i class="fas fa-box-open"></i>
                    <p>No batches found. Click "Add New Batch" to get started!</p>
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Batch #</th>
                            <th>Total Eggs</th>
                            <th>Status</th>
                            <th>Start Date</th>
                            <th>Day</th>
                            <th>Balut</th>
                            <th>Chicks</th>
                            <th>Failed</th>
                            <th>Remaining</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($batches as $batch):
                            $start = new DateTime($batch['date_started_incubation']);
                            $today = new DateTime();
                            $day_since = $start->diff($today)->days + 1;
                            $total_processed = $batch['failed_count'] + $batch['balut_count'] + $batch['chick_count'];
                            $remaining = $batch['total_egg'] - $total_processed;
                        ?>
                            <tr>
                                <td><strong>#<?= $batch['batch_number'] ?></strong></td>
                                <td><?= number_format($batch['total_egg']) ?></td>
                                <td>
                                    <span class="status-badge status-<?= strtolower($batch['status']) ?>">
                                        <?= ucfirst($batch['status']) ?>
                                    </span>
                                </td>
                                <td><?= date("M d, Y", strtotime($batch['date_started_incubation'])) ?></td>
                                <td>Day <?= $day_since ?></td>
                                <td><?= number_format($batch['balut_count']) ?></td>
                                <td><?= number_format($batch['chick_count']) ?></td>
                                <td><?= number_format($batch['failed_count']) ?></td>
                                <td><strong><?= number_format($remaining) ?></strong></td>
                                <td>
                                    <div class="action-buttons">
                                        <?php if ($batch['status'] == 'incubating' && $remaining > 0): ?>
                                            <button class="btn btn-success btn-sm" onclick="openUpdateModal(<?= $batch['egg_id'] ?>, <?= $day_since ?>, <?= $remaining ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                        <?php endif; ?>
                                        <form method="post" style="display:inline;" onsubmit="return confirmDeleteBatch();">
                                            <input type="hidden" name="egg_id" value="<?= $batch['egg_id'] ?>">
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
            <?php endif; ?>
        </div>

        <!-- Recent Activity -->
        <?php if (!empty($logs)): ?>
            <div class="activity-section">
                <h3><i class="fas fa-history" style="margin-right: 8px;"></i>Recent Activity</h3>
                <div class="activity-list">
                    <?php foreach ($logs as $log): ?>
                        <div class="activity-item">
                            <span class="activity-time">
                                <?= date("M d, H:i", strtotime($log['log_date'])) ?>
                            </span>
                            <span class="activity-text">
                                <?= htmlspecialchars($log['action']) ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </main>

    <!-- Add Batch Modal -->
    <div class="modal" id="addModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-plus-circle" style="margin-right: 8px;"></i>Add New Batch</h3>
            </div>
            <form method="post" onsubmit="return validateAddForm()">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Total Eggs</label>
                        <input type="number" name="total_egg" id="total_egg" min="1" placeholder="Enter number of eggs" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-warning" onclick="closeModal('addModal')">Cancel</button>
                    <button type="submit" name="add_batch" class="btn btn-success">Save Batch</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Update Modal -->
    <div class="modal" id="updateModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-edit" style="margin-right: 8px;"></i>Update Batch - Day <span id="modalDayNumber">1</span></h3>
            </div>
            <form method="post" onsubmit="return validateUpdateForm()">
                <div class="modal-body">
                    <input type="hidden" name="egg_id" id="updateEggId">
                    <div class="remaining-info" id="remainingInfo">
                        <i class="fas fa-info-circle"></i>
                        <span id="remainingEggs">0</span> eggs remaining in this batch
                    </div>
                    <div class="form-group">
                        <label>Failed Eggs</label>
                        <input type="number" name="failed_count" id="failed_count" min="0" value="0" placeholder="Number of failed eggs" oninput="checkRemaining()">
                    </div>
                    <div class="form-group">
                        <label>Balut</label>
                        <input type="number" name="balut_count" id="balut_count" min="0" value="0" placeholder="Number of balut" oninput="checkRemaining()">
                    </div>
                    <div class="form-group">
                        <label>Chicks</label>
                        <input type="number" name="chick_count" id="chick_count" min="0" value="0" placeholder="Number of hatched chicks" oninput="checkRemaining()">
                    </div>
                    <div id="validationMessage" style="color: #dc3545; font-size: 14px; margin-top: 10px; display: none;"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-warning" onclick="closeModal('updateModal')">Cancel</button>
                    <button type="submit" name="update_daily" class="btn btn-success" id="submitBtn">Update Batch</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Pass PHP data to JavaScript -->
    <script>
        // Store remaining eggs data
        const batchRemaining = <?= json_encode($batch_remaining) ?>;
    </script>

    <!-- Include external JavaScript file -->
    <script src="../../assets/user/js/user_dashboard.js"></script>
</body>

</html>