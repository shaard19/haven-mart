<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();
require_permission('users.view');

$page_title = 'Users';

$stmt = $pdo->query("
    SELECT
        u.id,
        u.username,
        u.full_name,
        u.status,
        u.created_at,
        r.name AS role_name,
        b.name AS branch_name,
        b.status AS branch_status
    FROM users u
    INNER JOIN roles r ON r.id = u.role_id
    LEFT JOIN branches b ON b.id = u.branch_id
    ORDER BY u.full_name ASC
");

$users = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<style>

.users-wrapper {
    width:100%;
}

.users-card {
    background:#fff;
    border:1px solid #eee;
    border-radius:18px;
    box-shadow:0 10px 30px rgba(0,0,0,.06);
    overflow:hidden;
}

.users-table-wrap {
    width:100%;
    overflow-x:auto;
}

.users-table {
    width:100%;
    border-collapse:collapse;
    min-width:900px;
}

.users-table th {
    background:#faf7f5;
    color:#4d0e17;
    font-size:12px;
    text-transform:uppercase;
    letter-spacing:.5px;
    padding:15px 18px;
    text-align:left;
    border-bottom:1px solid #eee;
}

.users-table td {
    padding:16px 18px;
    border-bottom:1px solid #f0f0f0;
    font-size:14px;
    color:#444;
    vertical-align:middle;
}

.users-table tbody tr:hover {
    background:#fffdf8;
}

.user-name {
    font-weight:700;
    color:#64131f;
}

.user-username {
    font-size:12px;
    color:#888;
    margin-top:3px;
}

.role-badge {
    display:inline-flex;
    align-items:center;
    gap:6px;
    background:#fff4c7;
    color:#4d0e17;
    padding:6px 10px;
    border-radius:20px;
    font-size:12px;
    font-weight:700;
}

.branch-name {
    font-weight:600;
}

.branch-inactive {
    display:block;
    color:#b42318;
    font-size:11px;
    margin-top:3px;
}

.status-badge {
    display:inline-flex;
    align-items:center;
    gap:6px;
    padding:6px 11px;
    border-radius:20px;
    font-size:12px;
    font-weight:700;
}

.status-active {
    background:#e8f7ef;
    color:#137333;
}

.status-inactive {
    background:#fdecec;
    color:#b42318;
}

.user-actions {
    display:flex;
    align-items:center;
    gap:7px;
}

.user-action {
    width:36px;
    height:36px;
    border-radius:9px;
    border:1px solid #e5e5e5;
    background:#fff;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    color:#64131f;
    text-decoration:none;
    cursor:pointer;
    transition:.2s;
}

.user-action:hover {
    background:#64131f;
    color:#fff;
    border-color:#64131f;
}

.user-action.danger {
    color:#b42318;
}

.user-action.danger:hover {
    background:#b42318;
    color:#fff;
    border-color:#b42318;
}

.user-action.success {
    color:#137333;
}

.user-action.success:hover {
    background:#137333;
    color:#fff;
    border-color:#137333;
}

.empty-users {
    padding:60px 20px;
    text-align:center;
    color:#888;
}

.empty-users i {
    font-size:42px;
    color:#d6cfc9;
    margin-bottom:15px;
}

.empty-users h3 {
    color:#64131f;
    margin-bottom:6px;
}

.empty-users p {
    font-size:14px;
}

@media(max-width:768px) {

    .page-header {
        align-items:flex-start;
        gap:15px;
    }

    .page-header .button {
        white-space:nowrap;
    }

}

</style>

<div class="page-header">

    <div>
        <p class="page-kicker">Haven Mart</p>
        <h2>Users</h2>
        <p>Manage system users, roles and branch assignments.</p>
    </div>

    <?php if (has_permission('users.manage')): ?>
        <a href="<?= APP_URL ?>/users/create.php" class="button">
            <i class="fas fa-user-plus"></i>
            Add User
        </a>
    <?php endif; ?>

</div>

<div class="users-wrapper">

    <div class="users-card">

        <?php if (!$users): ?>

            <div class="empty-users">

                <i class="fas fa-users"></i>

                <h3>No Users Found</h3>

                <p>
                    No system users have been created yet.
                </p>

                <?php if (has_permission('users.manage')): ?>

                    <a href="<?= APP_URL ?>/users/create.php" class="button" style="margin-top:18px;">
                        <i class="fas fa-user-plus"></i>
                        Create First User
                    </a>

                <?php endif; ?>

            </div>

        <?php else: ?>

            <div class="users-table-wrap">

                <table class="users-table">

                    <thead>

                        <tr>
                            <th>User</th>
                            <th>Role</th>
                            <th>Branch</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>

                    </thead>

                    <tbody>

                    <?php foreach ($users as $user): ?>

                        <tr>

                            <td>

                                <div class="user-name">
                                    <?= e($user['full_name']) ?>
                                </div>

                                <div class="user-username">
                                    @<?= e($user['username']) ?>
                                </div>

                            </td>

                            <td>

                                <span class="role-badge">

                                    <i class="fas fa-user-shield"></i>

                                    <?= e($user['role_name']) ?>

                                </span>

                            </td>

                            <td>

                                <?php if ($user['branch_name']): ?>

                                    <div class="branch-name">
                                        <?= e($user['branch_name']) ?>
                                    </div>

                                    <?php if ($user['branch_status'] !== 'ACTIVE'): ?>

                                        <span class="branch-inactive">
                                            <i class="fas fa-circle-exclamation"></i>
                                            Branch inactive
                                        </span>

                                    <?php endif; ?>

                                <?php else: ?>

                                    <span style="color:#888;">
                                        System-wide
                                    </span>

                                <?php endif; ?>

                            </td>

                            <td>

                                <?php if ($user['status'] === 'ACTIVE'): ?>

                                    <span class="status-badge status-active">
                                        <i class="fas fa-circle-check"></i>
                                        Active
                                    </span>

                                <?php else: ?>

                                    <span class="status-badge status-inactive">
                                        <i class="fas fa-circle-xmark"></i>
                                        Inactive
                                    </span>

                                <?php endif; ?>

                            </td>

                            <td>
                                <?= e(date('d M Y', strtotime($user['created_at']))) ?>
                            </td>

                            <td>

                                <div class="user-actions">

                                    <?php if (has_permission('users.manage')): ?>

                                        <a
                                            href="<?= APP_URL ?>/users/edit.php?id=<?= (int) $user['id'] ?>"
                                            class="user-action"
                                            title="Edit User"
                                        >
                                            <i class="fas fa-pen"></i>
                                        </a>

                                        <a
                                            href="<?= APP_URL ?>/users/password.php?id=<?= (int) $user['id'] ?>"
                                            class="user-action"
                                            title="Change Password"
                                        >
                                            <i class="fas fa-key"></i>
                                        </a>

                                        <?php if ($user['status'] === 'ACTIVE'): ?>

                                            <form
                                                method="POST"
                                                action="<?= APP_URL ?>/users/toggle.php"
                                                onsubmit="return confirm('Are you sure you want to deactivate this user?');"
                                            >

                                                <?= csrf_field() ?>

                                                <input
                                                    type="hidden"
                                                    name="id"
                                                    value="<?= (int) $user['id'] ?>"
                                                >

                                                <button
                                                    type="submit"
                                                    class="user-action danger"
                                                    title="Deactivate User"
                                                >
                                                    <i class="fas fa-user-slash"></i>
                                                </button>

                                            </form>

                                        <?php else: ?>

                                            <form
                                                method="POST"
                                                action="<?= APP_URL ?>/users/toggle.php"
                                                onsubmit="return confirm('Are you sure you want to activate this user?');"
                                            >

                                                <?= csrf_field() ?>

                                                <input
                                                    type="hidden"
                                                    name="id"
                                                    value="<?= (int) $user['id'] ?>"
                                                >

                                                <button
                                                    type="submit"
                                                    class="user-action success"
                                                    title="Activate User"
                                                >
                                                    <i class="fas fa-user-check"></i>
                                                </button>

                                            </form>

                                        <?php endif; ?>

                                    <?php endif; ?>

                                </div>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </div>

</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>