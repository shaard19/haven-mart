<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();
require_permission('users.manage');

$id = (int) ($_GET['id'] ?? 0);

if ($id <= 0) {
    header('Location: ' . APP_URL . '/users/');
    exit;
}

$stmt = $pdo->prepare("
    SELECT
        u.*,
        r.name AS role_name,
        b.name AS branch_name
    FROM users u
    INNER JOIN roles r ON r.id = u.role_id
    LEFT JOIN branches b ON b.id = u.branch_id
    WHERE u.id = ?
    LIMIT 1
");

$stmt->execute([$id]);

$user = $stmt->fetch();

if (!$user) {
    header('Location: ' . APP_URL . '/users/');
    exit;
}

$current_user = current_user();

if ((int) $current_user['id'] === $id && $user['status'] !== 'ACTIVE') {
    header('Location: ' . APP_URL . '/users/');
    exit;
}

$page_title = 'Edit User';

$error = '';

$roles_stmt = $pdo->query("
    SELECT
        id,
        name
    FROM roles
    ORDER BY
        CASE name
            WHEN 'Administrator' THEN 1
            WHEN 'Owner' THEN 2
            WHEN 'Branch Manager' THEN 3
            WHEN 'Cashier' THEN 4
            ELSE 5
        END,
        name ASC
");

$roles = $roles_stmt->fetchAll();

$branches_stmt = $pdo->query("
    SELECT
        id,
        code,
        name,
        status
    FROM branches
    ORDER BY name ASC
");

$branches = $branches_stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verify_csrf($_POST['csrf_token'] ?? '')) {

        $error = 'Invalid security token. Please refresh and try again.';

    } else {

        $username = strtolower(trim($_POST['username'] ?? ''));
        $full_name = trim($_POST['full_name'] ?? '');
        $role_id = (int) ($_POST['role_id'] ?? 0);
        $branch_id = (int) ($_POST['branch_id'] ?? 0);
        $status = $_POST['status'] ?? 'ACTIVE';

        if ($username === '' || $full_name === '' || !$role_id) {

            $error = 'Username, full name and role are required.';

        } elseif (!preg_match('/^[a-z0-9._-]{3,50}$/', $username)) {

            $error = 'Username may contain only letters, numbers, dots, underscores and hyphens.';

        } elseif (!in_array($status, ['ACTIVE', 'INACTIVE'], true)) {

            $error = 'Invalid user status selected.';

        } elseif ((int) $current_user['id'] === $id && $status !== 'ACTIVE') {

            $error = 'You cannot deactivate your own account.';

        } else {

            $role_stmt = $pdo->prepare("
                SELECT
                    id,
                    name
                FROM roles
                WHERE id = ?
                LIMIT 1
            ");

            $role_stmt->execute([$role_id]);

            $role = $role_stmt->fetch();

            if (!$role) {

                $error = 'Selected role does not exist.';

            } else {

                if (
                    in_array(
                        $role['name'],
                        ['Cashier', 'Branch Manager'],
                        true
                    )
                    && $branch_id <= 0
                ) {

                    $error = 'Cashiers and Branch Managers must be assigned to a branch.';

                } else {

                    if ($branch_id > 0) {

                        $branch_stmt = $pdo->prepare("
                            SELECT
                                id,
                                name,
                                status
                            FROM branches
                            WHERE id = ?
                            LIMIT 1
                        ");

                        $branch_stmt->execute([$branch_id]);

                        $branch = $branch_stmt->fetch();

                        if (!$branch) {

                            $error = 'Selected branch does not exist.';

                        } elseif ($branch['status'] !== 'ACTIVE') {

                            $error = 'Users cannot be assigned to an inactive branch.';

                        }

                    }

                    if ($error === '') {

                        $check = $pdo->prepare("
                            SELECT id
                            FROM users
                            WHERE username = ?
                            AND id != ?
                            LIMIT 1
                        ");

                        $check->execute([
                            $username,
                            $id
                        ]);

                        if ($check->fetch()) {

                            $error = 'Username already exists. Please choose another username.';

                        } else {

                            if (
                                in_array(
                                    $role['name'],
                                    ['Administrator', 'Owner'],
                                    true
                                )
                            ) {
                                $branch_id = null;
                            }

                            $old_values = [
                                'username' => $user['username'],
                                'full_name' => $user['full_name'],
                                'role_id' => (int) $user['role_id'],
                                'branch_id' => $user['branch_id'] !== null
                                    ? (int) $user['branch_id']
                                    : null,
                                'status' => $user['status']
                            ];

                            $new_values = [
                                'username' => $username,
                                'full_name' => $full_name,
                                'role_id' => $role_id,
                                'branch_id' => $branch_id,
                                'status' => $status
                            ];

                            try {

                                $pdo->beginTransaction();

                                $update = $pdo->prepare("
                                    UPDATE users
                                    SET
                                        username = ?,
                                        full_name = ?,
                                        role_id = ?,
                                        branch_id = ?,
                                        status = ?
                                    WHERE id = ?
                                ");

                                $update->execute([
                                    $username,
                                    $full_name,
                                    $role_id,
                                    $branch_id,
                                    $status,
                                    $id
                                ]);

                                $changes = [];

                                if (
                                    $user['username']
                                    !== $username
                                ) {
                                    $changes[] = 'username';
                                }

                                if (
                                    $user['full_name']
                                    !== $full_name
                                ) {
                                    $changes[] = 'full_name';
                                }

                                if (
                                    (int) $user['role_id']
                                    !== $role_id
                                ) {
                                    $changes[] = 'role';
                                }

                                $old_branch_id = $user['branch_id'] !== null
                                    ? (int) $user['branch_id']
                                    : null;

                                if ($old_branch_id !== $branch_id) {
                                    $changes[] = 'branch';
                                }

                                if (
                                    $user['status']
                                    !== $status
                                ) {
                                    $changes[] = 'status';
                                }

                                $action = empty($changes)
                                    ? 'USER_UPDATED'
                                    : 'USER_' . strtoupper(implode('_', $changes)) . '_UPDATED';

                                $audit = $pdo->prepare("
                                    INSERT INTO audit_logs
                                    (
                                        user_id,
                                        branch_id,
                                        action,
                                        module,
                                        record_id,
                                        old_values,
                                        new_values,
                                        ip_address,
                                        user_agent
                                    )
                                    VALUES
                                    (?, ?, ?, ?, ?, ?, ?, ?, ?)
                                ");

                                $audit->execute([
                                    $current_user['id'],
                                    $branch_id,
                                    $action,
                                    'users',
                                    $id,
                                    json_encode($old_values),
                                    json_encode($new_values),
                                    $_SERVER['REMOTE_ADDR'] ?? null,
                                    $_SERVER['HTTP_USER_AGENT'] ?? null
                                ]);

                                $pdo->commit();

                                header(
                                    'Location: ' . APP_URL . '/users/'
                                );

                                exit;

                            } catch (Throwable $e) {

                                if ($pdo->inTransaction()) {
                                    $pdo->rollBack();
                                }

                                $error = 'Unable to update the user. Please try again.';
                            }
                        }
                    }
                }
            }
        }
    }
}

include __DIR__ . '/../includes/header.php';
?>

<style>

.user-edit-wrapper {
    max-width:950px;
}

.user-edit-card {
    background:#fff;
    border:1px solid #eee;
    border-radius:18px;
    padding:35px;
    box-shadow:0 10px 30px rgba(0,0,0,.06);
}

.user-edit-title {
    margin-bottom:28px;
}

.user-edit-title h3 {
    color:#64131f;
    font-size:20px;
    margin-bottom:6px;
}

.user-edit-title p {
    color:#777;
    font-size:14px;
}

.user-edit-grid {
    display:grid;
    grid-template-columns:repeat(2,1fr);
    gap:22px;
}

.user-edit-group label {
    display:block;
    font-size:13px;
    font-weight:700;
    color:#4d0e17;
    margin-bottom:8px;
}

.user-edit-group input,
.user-edit-group select {
    width:100%;
    height:46px;
    padding:0 14px;
    border-radius:10px;
    border:1px solid #ddd;
    background:#fff;
    font-size:14px;
    outline:none;
}

.user-edit-group input:focus,
.user-edit-group select:focus {
    border-color:#f2c94c;
    box-shadow:0 0 0 3px rgba(242,201,76,.25);
}

.user-edit-group small {
    display:block;
    margin-top:6px;
    color:#888;
    font-size:12px;
}

.user-account-info {
    grid-column:1 / -1;
    background:#faf7f5;
    border:1px solid #eee;
    border-radius:14px;
    padding:20px;
}

.user-account-info h4 {
    margin:0 0 15px;
    color:#64131f;
    font-size:15px;
}

.user-account-grid {
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:18px;
}

.account-item {
    display:flex;
    flex-direction:column;
    gap:4px;
}

.account-item span:first-child {
    font-size:11px;
    text-transform:uppercase;
    color:#999;
    font-weight:700;
}

.account-item span:last-child {
    font-size:14px;
    color:#4d0e17;
    font-weight:600;
}

.user-edit-actions {
    margin-top:30px;
    padding-top:20px;
    border-top:1px solid #eee;
    display:flex;
    justify-content:flex-end;
    gap:10px;
}

.user-save {
    background:#64131f;
    color:#fff;
    border:none;
    border-radius:10px;
    padding:12px 25px;
    font-weight:700;
    cursor:pointer;
    display:flex;
    align-items:center;
    gap:8px;
}

.user-save:hover {
    background:#8b1e2d;
}

.user-back {
    background:#f2c94c;
    color:#4d0e17;
    font-weight:700;
}

.user-warning {
    margin-top:18px;
    background:#fff8e1;
    border:1px solid #f2c94c;
    color:#6b4b00;
    border-radius:10px;
    padding:12px 15px;
    font-size:13px;
}

@media(max-width:768px) {

    .user-edit-card {
        padding:20px;
    }

    .user-edit-grid,
    .user-account-grid {
        grid-template-columns:1fr;
    }

    .user-account-info {
        grid-column:auto;
    }

}

</style>

<div class="page-header">

    <div>
        <p class="page-kicker">Haven Mart</p>
        <h2>Edit User</h2>
        <p>Update account details, role, branch assignment and status.</p>
    </div>

    <a href="<?= APP_URL ?>/users/" class="button user-back">
        <i class="fas fa-arrow-left"></i>
        Back
    </a>

</div>

<div class="user-edit-wrapper">

    <div class="user-edit-card">

        <div class="user-edit-title">

            <h3>
                <i class="fas fa-user-pen"></i>
                User Account
            </h3>

            <p>
                Changes to roles and branches are recorded in the audit log.
            </p>

        </div>

        <?php if ($error): ?>

            <div class="alert alert-error">

                <i class="fas fa-circle-exclamation"></i>

                <?= e($error) ?>

            </div>

        <?php endif; ?>

        <form method="POST">

            <?= csrf_field() ?>

            <div class="user-edit-grid">

                <div class="user-account-info">

                    <h4>
                        <i class="fas fa-circle-info"></i>
                        Account Information
                    </h4>

                    <div class="user-account-grid">

                        <div class="account-item">

                            <span>User ID</span>

                            <span>
                                #<?= (int) $user['id'] ?>
                            </span>

                        </div>

                        <div class="account-item">

                             <span>Account Status</span>

                            <span>
                         <?= e($user['status']) ?>
                          </span>

                             </div>

                        <div class="account-item">

                            <span>Current Role</span>

                            <span>
                                <?= e($user['role_name']) ?>
                            </span>

                        </div>

                    </div>

                </div>

                <div class="user-edit-group">

                    <label>Full Name *</label>

                    <input
                        type="text"
                        name="full_name"
                        value="<?= e($_POST['full_name'] ?? $user['full_name']) ?>"
                        maxlength="100"
                        required
                    >

                </div>

                <div class="user-edit-group">

                    <label>Username *</label>

                    <input
                        type="text"
                        name="username"
                        value="<?= e($_POST['username'] ?? $user['username']) ?>"
                        maxlength="50"
                        autocomplete="username"
                        required
                    >

                </div>

                <div class="user-edit-group">

                    <label>Role *</label>

                    <select name="role_id" id="role_id" required>

                        <option value="">Select role</option>

                        <?php

                        $selected_role_id = (int) (
                            $_POST['role_id']
                            ?? $user['role_id']
                        );

                        ?>

                        <?php foreach ($roles as $role): ?>

                            <option
                                value="<?= (int) $role['id'] ?>"
                                <?= $selected_role_id === (int) $role['id'] ? 'selected' : '' ?>
                            >
                                <?= e($role['name']) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div class="user-edit-group">

                    <label>Branch</label>

                    <?php

                    $selected_branch_id = (int) (
                        $_POST['branch_id']
                        ?? ($user['branch_id'] ?? 0)
                    );

                    ?>

                    <select name="branch_id" id="branch_id">

                        <option value="">
                            System-wide / No Branch
                        </option>

                        <?php foreach ($branches as $branch): ?>

                            <option
                                value="<?= (int) $branch['id'] ?>"
                                <?= $selected_branch_id === (int) $branch['id'] ? 'selected' : '' ?>
                                <?= $branch['status'] !== 'ACTIVE' ? 'disabled' : '' ?>
                            >
                                <?= e($branch['code']) ?> -
                                <?= e($branch['name']) ?>
                                <?= $branch['status'] !== 'ACTIVE' ? ' (Inactive)' : '' ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                    <small>
                        Cashiers and Branch Managers must have an active branch.
                    </small>

                </div>

                <div class="user-edit-group">

                    <label>Status</label>

                    <?php

                    $selected_status = $_POST['status']
                        ?? $user['status'];

                    ?>

                    <select name="status">

                        <option
                            value="ACTIVE"
                            <?= $selected_status === 'ACTIVE' ? 'selected' : '' ?>
                        >
                            Active
                        </option>

                        <option
                            value="INACTIVE"
                            <?= $selected_status === 'INACTIVE' ? 'selected' : '' ?>
                        >
                            Inactive
                        </option>

                    </select>

                </div>

            </div>

            <?php if ((int) $current_user['id'] === $id): ?>

                <div class="user-warning">

                    <i class="fas fa-shield-halved"></i>

                    You are editing your own account. Your account cannot be
                    deactivated from this page.

                </div>

            <?php endif; ?>

            <div class="user-edit-actions">

                <a
                    href="<?= APP_URL ?>/users/"
                    class="button user-back"
                >
                    Cancel
                </a>

                <button
                    type="submit"
                    class="user-save"
                >
                    <i class="fas fa-save"></i>
                    Save Changes
                </button>

            </div>

        </form>

    </div>

</div>

<script>

document.addEventListener('DOMContentLoaded', function () {

    const roleSelect = document.getElementById('role_id');
    const branchSelect = document.getElementById('branch_id');

    function updateBranchState() {

        if (!roleSelect || !branchSelect) {
            return;
        }

        const selectedRole = roleSelect.options[
            roleSelect.selectedIndex
        ];

        if (!selectedRole) {
            return;
        }

        const roleName = selectedRole.text.trim();

        const systemRole =
            roleName === 'Administrator' ||
            roleName === 'Owner';

        if (systemRole) {

            branchSelect.value = '';
            branchSelect.disabled = true;

        } else {

            branchSelect.disabled = false;

        }

    }

    roleSelect.addEventListener(
        'change',
        updateBranchState
    );

    updateBranchState();

});

</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>