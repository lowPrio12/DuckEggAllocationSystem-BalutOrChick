<?php
require '../../model/config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] === 'admin') {
    header("Location: ../../index.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// ---------------------------
// Handle CRUD Operations
// ---------------------------

// Add new batch
if (isset($_POST['add_batch'])) {
    // Get total eggs from form
    $total_egg = $_POST['total_egg'];
    $status = 'incubating'; // always incubating for new batch

    // Auto-generate batch number: last batch + 1 for this user
    $stmt = $conn->prepare("SELECT MAX(batch_number) AS last_batch FROM egg WHERE user_id=?");
    $stmt->execute([$user_id]);
    $last_batch = $stmt->fetch(PDO::FETCH_ASSOC)['last_batch'];
    $batch_number = $last_batch ? $last_batch + 1 : 1;

    // Start date = current date and time
    $date_started = date('Y-m-d H:i:s');

    // Insert into database
    $stmt = $conn->prepare("INSERT INTO egg (user_id, batch_number, total_egg, status, date_started_incubation) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$user_id, $batch_number, $total_egg, $status, $date_started]);

    header("Location: dashboard.php");
    exit;
}

// Update batch
if (isset($_POST['update_batch'])) {
    $egg_id = $_POST['egg_id'];
    $status = $_POST['status'];
    $balut = $_POST['balut'];
    $chick = $_POST['chick'];
    $failed = $_POST['failed'];

    $stmt = $conn->prepare("UPDATE egg SET status=?, balut_count=?, chick_count=?, failed_count=? WHERE egg_id=? AND user_id=?");
    $stmt->execute([$status, $balut, $chick, $failed, $egg_id, $user_id]);
    header("Location: dashboard.php");
    exit;
}

// Delete batch
if (isset($_POST['delete_batch'])) {
    $egg_id = $_POST['egg_id'];
    $stmt = $conn->prepare("DELETE FROM egg WHERE egg_id=? AND user_id=?");
    $stmt->execute([$egg_id, $user_id]);
    header("Location: dashboard.php");
    exit;
}

// ---------------------------
// Fetch data for dashboard
// ---------------------------

// Summary Cards
$stmt = $conn->prepare("SELECT
    SUM(CASE WHEN status='incubating' THEN total_egg ELSE 0 END) AS incubating_eggs,
    SUM(balut_count) AS balut,
    SUM(chick_count) AS hatched_chicks,
    SUM(failed_count) AS failed,
    COUNT(*) AS active_batches,
    SUM(chick_count)/SUM(total_egg)*100 AS success_rate
    FROM egg WHERE user_id=?");

$stmt->execute([$user_id]);
$summary = $stmt->fetch(PDO::FETCH_ASSOC);
// Fetch all batches
$stmt = $conn->prepare("SELECT * FROM egg WHERE user_id=? ORDER BY date_started_incubation DESC, batch_number DESC");
$stmt->execute([$user_id]);
$batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html>

<head>
    <title>User Dashboard</title>
    <link rel="stylesheet" href="../../assets/user_style.css">

</head>

<body>
    <div class="dashboard-container">
        <h1>Welcome User!</h1>
        <p><a href="../../logout.php">Logout</a></p>

        <!-- Summary Cards -->
        <div class="cards">
            <div class="card">
                <h3>Incubating Eggs</h3>
                <p><?= $summary['incubating_eggs'] ?? 0 ?></p>
            </div>
            <div class="card">
                <h3>Balut</h3>
                <p><?= $summary['balut'] ?? 0 ?></p>
            </div>
            <div class="card">
                <h3>Hatched Chicks</h3>
                <p><?= $summary['hatched_chicks'] ?? 0 ?></p>
            </div>
            <div class="card">
                <h3>Failed</h3>
                <p><?= $summary['failed'] ?? 0 ?></p>
            </div>
            <div class="card">
                <h3>Active Batches</h3>
                <p><?= $summary['active_batches'] ?? 0 ?></p>
            </div>
        </div>
        <p>Success Rate: <?= $summary['success_rate'] ? round($summary['success_rate'], 2) : 0 ?>%</p>

        <!-- Add Batch Button -->
        <button onclick="openModal('addModal')">Add New Batch</button>

        <!-- Batch Table -->
        <h2>Egg Batches</h2>
        <table border="1">
            <tr>
                <th>Batch #</th>
                <th>Total Eggs</th>
                <th>Status</th>
                <th>Start Date</th>
                <th>Balut</th>
                <th>Chick</th>
                <th>Failed</th>
                <th>Actions</th>
            </tr>
            <?php foreach ($batches as $batch): ?>
                <tr>
                    <td><?= $batch['batch_number'] ?></td>
                    <td><?= $batch['total_egg'] ?></td>
                    <td><?= $batch['status'] ?></td>
                    <td><?= $batch['date_started_incubation'] ?></td>
                    <td><?= $batch['balut_count'] ?></td>
                    <td><?= $batch['chick_count'] ?></td>
                    <td><?= $batch['failed_count'] ?></td>
                    <td class="actions">
                        <form method="post" style="display:inline;" onsubmit="return confirm('Delete this batch?');">
                            <input type="hidden" name="egg_id" value="<?= $batch['egg_id'] ?>">
                            <button type="submit" name="delete_batch">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <!-- Add Batch Modal -->
    <div class="modal" id="addModal">
        <div class="modal-content">
            <h3>Add New Batch</h3>
            <form method="post">
                <input type="number" name="total_egg" placeholder="Total Eggs" required>
                <button type="submit" name="add_batch">Save</button>
                <button type="button" onclick="closeModal('addModal')">Cancel</button>
            </form>
        </div>
    </div>

    <script>
        function openModal(id) {
            document.getElementById(id).classList.add('active');
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('active');
        }
/*
        function openEditModal(id, total, status, balut, chick, failed) {
            document.getElementById('editModal').classList.add('active');
            document.getElementById('edit_egg_id').value = id;
            document.getElementById('edit_batch_number').innerText = id;
            document.getElementById('edit_total_eggs').innerText = total;
            document.getElementById('edit_status').value = status;
            document.getElementById('edit_balut').value = balut;
            document.getElementById('edit_chick').value = chick;
            document.getElementById('edit_failed').value = failed;
        } */
    </script>
</body>

</html>