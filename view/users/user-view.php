<?php

// Fetch users
$stmt = $conn->query("
SELECT user_id, username, user_role, created_at
FROM users
ORDER BY created_at DESC
");

$users = $stmt->fetchAll(PDO::FETCH_ASSOC);


// Handle Delete User
if (isset($_POST['delete_user'])) {

    $user_id = $_POST['user_id'];

    // Prevent admin from deleting themselves
    if ($user_id == $_SESSION['user_id']) {

        echo "<script>alert('You cannot delete your own account');</script>";
    } else {

        $stmt = $conn->prepare("SELECT username FROM users WHERE user_id=?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {

            $stmt = $conn->prepare("DELETE FROM users WHERE user_id=?");
            $stmt->execute([$user_id]);

            // Log activity
            $action = "Admin deleted user: {$user['username']}";

            $stmt = $conn->prepare("
            INSERT INTO user_activity_logs (user_id, action)
            VALUES (?,?)
            ");

            $stmt->execute([$_SESSION['user_id'], $action]);
        }

        echo "<script>window.location.href='../admin/dashboard.php'</script>";
    }
}

?>


<table class="styled-table">

    <thead>

        <tr>

            <th>ID</th>
            <th>Username</th>
            <th>Role</th>
            <th>Created</th>
            <th>Actions</th>

        </tr>

    </thead>

    <tbody>

        <?php foreach ($users as $user): ?>

            <tr>

                <td><?= $user['user_id'] ?></td>

                <td><?= htmlspecialchars($user['username']) ?></td>

                <td>

                    <?php

                    $role = $user['user_role'];

                    if ($role == "admin") {
                        echo "<span class='role-admin'>Admin</span>";
                    } elseif ($role == "manager") {
                        echo "<span class='role-manager'>Manager</span>";
                    } else {
                        echo "<span class='role-user'>User</span>";
                    }

                    ?>

                </td>

                <td>
                    <?= date("M d, Y", strtotime($user['created_at'])) ?>
                </td>

                <td>

                    <button class="btn-edit"
                        onclick="openEditModal('<?= $user['user_id'] ?>','<?= $user['username'] ?>')">

                        Edit

                    </button>

                    <form method="POST" style="display:inline;">

                        <input type="hidden" name="user_id" value="<?= $user['user_id'] ?>">

                        <button
                            class="btn-delete"
                            name="delete_user"
                            onclick="return confirm('Delete this user?')">

                            Delete

                        </button>

                    </form>

                </td>

            </tr>

        <?php endforeach; ?>

    </tbody>

</table>