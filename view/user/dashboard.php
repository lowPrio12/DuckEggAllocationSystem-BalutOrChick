<?php
require '../../model/config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] === 'admin') {
    header("Location: ../../index.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// ---------------------------
// Display error if exists
// ---------------------------
if (isset($_SESSION['error'])) {
    echo '<div class="error-message">' . $_SESSION['error'] . '</div>';
    unset($_SESSION['error']);
}

// ---------------------------
// ADD BATCH
// ---------------------------
if (isset($_POST['add_batch'])) {
    $total_egg = $_POST['total_egg'];
    $status = 'incubating';

    $stmt = $conn->prepare("SELECT MAX(batch_number) FROM egg WHERE user_id=?");
    $stmt->execute([$user_id]);
    $batch_number = $stmt->fetchColumn();
    $batch_number = $batch_number ? $batch_number + 1 : 1;

    $date_started = date('Y-m-d'); // FIXED

    $stmt = $conn->prepare("INSERT INTO egg (user_id, batch_number, total_egg, status, date_started_incubation)
                            VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$user_id, $batch_number, $total_egg, $status, $date_started]);

    header("Location: dashboard.php");
    exit;
}

// ---------------------------
// DELETE
// ---------------------------
if (isset($_POST['delete_batch'])) {

    $egg_id = $_POST['egg_id'];

    $stmt = $conn->prepare("DELETE FROM egg WHERE egg_id=? AND user_id=?");
    $stmt->execute([$egg_id, $user_id]);

    header("Location: dashboard.php");
    exit;
}

// ---------------------------
// UPDATE DAILY
// ---------------------------
if (isset($_POST['update_daily'])) {
    $egg_id = $_POST['egg_id'];
    $failed = $_POST['failed_count'] ?? 0;
    $balut = $_POST['balut_count'] ?? 0;
    $chick = $_POST['chick_count'] ?? 0;

    // Get batch info
    $stmt = $conn->prepare("SELECT * FROM egg WHERE egg_id=? AND user_id=?");
    $stmt->execute([$egg_id, $user_id]);
    $batch = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$batch) exit('Batch not found');

    // Calculate current total
    $current_total = $batch['failed_count'] + $batch['balut_count'] + $batch['chick_count'];
    $input_total = $failed + $balut + $chick;

    // Validate input
    if ($current_total + $input_total > $batch['total_egg']) {
        $_SESSION['error'] = "Input exceeds total eggs in batch #{$batch['batch_number']}! " .
            "Remaining eggs: " . ($batch['total_egg'] - $current_total);
        header("Location: dashboard.php");
        exit;
    }

    // Calculate day number automatically
    $day_number = floor((time() - strtotime($batch['date_started_incubation'])) / 86400) + 1;

    // Insert daily log
    $stmt = $conn->prepare("INSERT INTO egg_daily_logs 
        (egg_id, day_number, failed_count, balut_count, chick_count)
        VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$egg_id, $day_number, $failed, $balut, $chick]);

    // Update totals
    $new_failed = $batch['failed_count'] + $failed;
    $new_balut = $batch['balut_count'] + $balut;
    $new_chick = $batch['chick_count'] + $chick;

    // Update status
    $status = 'incubating';
    if (($new_failed + $new_balut + $new_chick) >= $batch['total_egg']) {
        $status = 'complete';
    }

    $stmt = $conn->prepare("UPDATE egg SET 
        failed_count=?, balut_count=?, chick_count=?, status=? 
        WHERE egg_id=? AND user_id=?");
    $stmt->execute([$new_failed, $new_balut, $new_chick, $status, $egg_id, $user_id]);

    header("Location: dashboard.php");
    exit;
}

// ---------------------------
// SUMMARY
// ---------------------------
$stmt = $conn->prepare("SELECT
    SUM(CASE WHEN status='incubating' THEN total_egg ELSE 0 END),
    SUM(chick_count),
    COUNT(*)
    FROM egg WHERE user_id=?");

$stmt->execute([$user_id]);
$data = $stmt->fetch(PDO::FETCH_NUM);

$incubating = $data[0] ?? 0;
$chicks = $data[1] ?? 0;
$batches_count = $data[2] ?? 0;

$success_rate = ($incubating > 0) ? round(($chicks / $incubating) * 100, 2) : 0;

// ---------------------------
// FETCH BATCHES
// ---------------------------
$stmt = $conn->prepare("SELECT * FROM egg WHERE user_id=? ORDER BY date_started_incubation DESC");
$stmt->execute([$user_id]);
$batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html>

<head>
    <title>User Dashboard</title>
    <link rel="stylesheet" href="../../assets/user/js/css/user_style.css">
</head>
<style>
    /* Reset some default styles */
    body,
    html {
        margin: 0;
        padding: 0;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        background: #f5f5f5;
        color: #333;
    }

    .container {
        margin: 20px auto;
    }

    /* Header */
    h2 {
        margin-bottom: 20px;
        color: #1976d2;
    }

    /* Sidebar */
    .sidebar {
        position: fixed;
        left: 0;
        top: 0;
        width: 200px;
        height: 100%;
        background: #1976d2;
        color: white;
        padding: 20px 0;
        box-shadow: 2px 0 5px rgba(0, 0, 0, 0.1);
    }

    .sidebar h2 {
        text-align: center;
        margin-bottom: 30px;
        font-size: 20px;
    }

    .sidebar ul {
        list-style: none;
        padding: 0;
    }

    .sidebar ul li {
        margin: 15px 0;
        text-align: center;
    }

    .sidebar ul li a {
        color: white;
        text-decoration: none;
        font-weight: 500;
        display: block;
        padding: 8px 0;
    }

    .sidebar ul li.active a,
    .sidebar ul li a:hover {
        background: #1565c0;
        border-radius: 5px;
    }

    /* Main content shift */
    .main-container {
        margin-left: 220px;
        /* leave space for sidebar */
        padding: 20px;
    }

    /* Summary cards */
    .cards {
        display: flex;
        flex-wrap: wrap;
        gap: 15px;
        margin-bottom: 20px;
    }

    .card {
        background: white;
        border-radius: 8px;
        padding: 20px;
        flex: 1 1 200px;
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
        text-align: center;
    }

    .card h3 {
        margin: 0 0 10px;
        font-size: 16px;
        color: #555;
    }

    .card p {
        font-size: 24px;
        font-weight: bold;
        margin: 0;
        color: #1976d2;
    }

    /* Buttons */
    button {
        cursor: pointer;
        border: none;
        border-radius: 5px;
        padding: 8px 14px;
        margin: 5px 0;
        font-size: 14px;
        transition: all 0.2s;
    }

    button:hover {
        opacity: 0.9;
    }

    button[type="submit"] {
        background-color: #4caf50;
        color: white;
    }

    button[type="button"] {
        background-color: #757575;
        color: white;
    }

    /* Table */
    table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 20px;
        background: white;
        border-radius: 8px;
        overflow: hidden;
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
    }

    thead {
        background: #1976d2;
        color: white;
    }

    th,
    td {
        padding: 12px 10px;
        text-align: center;
        border-bottom: 1px solid #e0e0e0;
    }

    tbody tr:hover {
        background: #f1f1f1;
    }

    /* Status */
    td.status {
        font-weight: bold;
        text-transform: capitalize;
    }

    /* Modals */
    .modal {
        display: none;
        position: fixed;
        z-index: 1000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        justify-content: center;
        align-items: center;
    }

    .modal.active {
        display: flex;
    }

    .modal-content {
        background: white;
        padding: 20px;
        border-radius: 8px;
        width: 100%;
        max-width: 400px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        animation: fadeIn 0.3s;
    }

    .modal-content h3 {
        margin-top: 0;
        color: #1976d2;
    }

    .modal-content form {
        display: flex;
        flex-direction: column;
    }

    .modal-content label {
        margin-top: 10px;
        margin-bottom: 4px;
        font-weight: 500;
    }

    .modal-content input[type="number"] {
        padding: 8px;
        border-radius: 5px;
        border: 1px solid #ccc;
        font-size: 14px;
    }

    .modal-content button {
        margin-top: 15px;
    }

    /* Error message */
    .error-message {
        background: #ffebee;
        color: #c62828;
        padding: 10px 15px;
        border-left: 5px solid #c62828;
        border-radius: 5px;
        margin-bottom: 15px;
    }

    /* Fade-in animation for modal */
    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(-20px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
</style>


<body>

    <!-- Sidebar -->
    <aside class="sidebar">
        <h2>Egg System</h2>
        <ul>
            <li class="active"><a href="dashboard.php">Dashboard</a></li>
            <li><a href="../../controller/auth/signout.php">Logout</a></li>
        </ul>
    </aside>

    <!-- Main container -->
    <div class="main-container">

        <div class="container">

            <h2>User Dashboard</h2>

            <!-- SUMMARY -->
            <div class="cards">
                <div class="card">
                    <h3>Incubating Eggs</h3>
                    <p><?= $incubating ?></p>
                </div>
                <div class="card">
                    <h3>Hatched Chicks</h3>
                    <p><?= $chicks ?></p>
                </div>
                <div class="card">
                    <h3>Active Batches</h3>
                    <p><?= $batches_count ?></p>
                </div>
                <div class="card">
                    <h3>Success Rate</h3>
                    <p><?= $success_rate ?>%</p>
                </div>
            </div>

            <button type="button" onclick="openModal('addModal')">+ Add Batch</button>

            <!-- TABLE -->
            <table>
                <thead>
                    <tr>
                        <th>Batch</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th>Start Date</th>
                        <th>Balut</th>
                        <th>Chick</th>
                        <th>Failed</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($batches as $batch):
                        $start = new DateTime($batch['date_started_incubation']);
                        $today = new DateTime();
                        $day_since = $start->diff($today)->days + 1;
                    ?>
                        <tr>
                            <td>#<?= $batch['batch_number'] ?></td>
                            <td><?= $batch['total_egg'] ?></td>
                            <td><?= $batch['status'] ?></td>
                            <td>
                                <?= date("M d, Y", strtotime($batch['date_started_incubation'])) ?>
                                <br>
                                <small>Day <?= $day_since ?> (<?= date("M d, Y") ?>)</small>
                            </td>
                            <td><?= $batch['balut_count'] ?></td>
                            <td><?= $batch['chick_count'] ?></td>
                            <td><?= $batch['failed_count'] ?></td>
                            <td>
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="egg_id" value="<?= $batch['egg_id'] ?>">
                                    <button type="submit" name="delete_batch">Delete</button>
                                </form>
                                <button type="button" onclick="openUpdateModal(<?= $batch['egg_id'] ?>, <?= $day_since ?>)">
                                    Update
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

        </div>
    </div>

    <!-- ADD MODAL -->
    <div class="modal" id="addModal">
        <div class="modal-content">
            <h3>Add Batch</h3>
            <form method="post">
                <label>Total Eggs</label>
                <input type="number" name="total_egg" required>
                <button type="submit" name="add_batch">Save</button>
                <button type="button" onclick="closeModal('addModal')">Cancel</button>
            </form>
        </div>
    </div>

    <!-- UPDATE MODAL -->
    <div class="modal" id="updateModal">
        <div class="modal-content">
            <h3>Update Batch - Day <span id="modalDayNumber">1</span></h3>
            <p style="font-size:12px; color:#666;">Date: <?= date("M d, Y") ?></p>
            <form method="post">
                <input type="hidden" name="egg_id" id="updateEggId">
                <label>Failed</label>
                <input type="number" name="failed_count" value="0">
                <label>Balut</label>
                <input type="number" name="balut_count" value="0">
                <label>Chick</label>
                <input type="number" name="chick_count" value="0">
                <button type="submit" name="update_daily">Update</button>
                <button type="button" onclick="closeModal('updateModal')">Cancel</button>
            </form>
        </div>
    </div>

    <script>
        // MODAL FUNCTIONS
        function openModal(id) {
            document.getElementById(id).classList.add('active');
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('active');
        }

        function openUpdateModal(id, day) {
            document.getElementById('updateEggId').value = id;
            document.getElementById('modalDayNumber').innerText = day;
            openModal('updateModal');
        }
    </script>
</body>

</html>