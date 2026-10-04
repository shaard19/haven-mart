<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();
require_permission('users.manage');

$page_title = 'Add User';

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
    WHERE status = 'ACTIVE'
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
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        $status = $_POST['status'] ?? 'ACTIVE';

        if ($username === '' || $full_name === '' || !$role_id || $password === '') {

            $error = 'Username, full name, role and password are required.';

        } elseif (!preg_match('/^[a-z0-9._-]{3,50}$/', $username)) {

            $error = 'Username may contain only letters, numbers, dots, underscores and hyphens.';

        } elseif (strlen($password) < 8) {

            $error = 'Password must contain at least 8 characters.';

        } elseif ($password !== $confirm_password) {

            $error = 'Passwords do not match.';

        } elseif (!in_array($status, ['ACTIVE', 'INACTIVE'], true)) {

            $error = 'Invalid user status selected.';

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

                if (in_array($role['name'], ['Cashier', 'Branch Manager'], true) && $branch_id <= 0) {

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
                            LIMIT 1
                        ");

                        $check->execute([$username]);

                        if ($check->fetch()) {

                            $error = 'Username already exists. Please choose another username.';

                        } else {

                            if (in_array($role['name'], ['Administrator', 'Owner'], true)) {
                                $branch_id = null;
                            }

                            $password_hash = password_hash(
                                $password,
                                PASSWORD_DEFAULT
                            );

                            try {

                                $pdo->beginTransaction();

                                $insert = $pdo->prepare("
                                    INSERT INTO users
                                    (
                                        username,
                                        password,
                                        full_name,
                                        role_id,
                                        branch_id,
                                        status
                                    )
                                    VALUES
                                    (?, ?, ?, ?, ?, ?)
                                ");

                                $insert->execute([
                                    $username,
                                    $password_hash,
                                    $full_name,
                                    $role_id,
                                    $branch_id,
                                    $status
                                ]);

                                $new_user_id = (int) $pdo->lastInsertId();

                                $current = current_user();

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
                                    $current['id'],
                                    $branch_id,
                                    'USER_CREATED',
                                    'users',
                                    $new_user_id,
                                    null,
                                    json_encode([
                                        'username' => $username,
                                        'full_name' => $full_name,
                                        'role_id' => $role_id,
                                        'branch_id' => $branch_id,
                                        'status' => $status
                                    ]),
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

                                $error = 'Unable to create the user. Please try again.';
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

.user-form-wrapper {
    max-width:950px;
}

.user-form-card {
    background:#fff;
    border:1px solid #eee;
    border-radius:18px;
    padding:35px;
    box-shadow:0 10px 30px rgba(0,0,0,.06);
}

.user-form-title {
    margin-bottom:28px;
}

.user-form-title h3 {
    color:#64131f;
    font-size:20px;
    margin-bottom:6px;
}

.user-form-title p {
    color:#777;
    font-size:14px;
}

.user-form-grid {
    display:grid;
    grid-template-columns:repeat(2,1fr);
    gap:22px;
}

.user-form-group label {
    display:block;
    font-size:13px;
    font-weight:700;
    color:#4d0e17;
    margin-bottom:8px;
}

.user-form-group input,
.user-form-group select {
    width:100%;
    height:46px;
    padding:0 14px;
    border-radius:10px;
    border:1px solid #ddd;
    background:#fff;
    font-size:14px;
    outline:none;
}

.user-form-group input:focus,
.user-form-group select:focus {
    border-color:#f2c94c;
    box-shadow:0 0 0 3px rgba(242,201,76,.25);
}

.user-form-group small {
    display:block;
    margin-top:6px;
    color:#888;
    font-size:12px;
}

.user-password-box {
    grid-column:1 / -1;
    background:#faf7f5;
    border:1px solid #eee;
    border-radius:14px;
    padding:20px;
}

.user-password-box h4 {
    margin:0 0 16px;
    color:#64131f;
    font-size:15px;
}

.user-password-grid {
    display:grid;
    grid-template-columns:repeat(2,1fr);
    gap:22px;
}

.user-form-actions {
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

@media(max-width:768px) {

    .user-form-card {
        padding:20px;
    }

    .user-form-grid,
    .user-password-grid {
        grid-template-columns:1fr;
    }

    .user-password-box {
        grid-column:auto;
    }

}

</style>

<div class="page-header">

    <div>
        <p class="page-kicker">Haven Mart</p>
        <h2>Add New User</h2>
        <p>Create a secure account and assign the appropriate role and branch.</p>
    </div>

    <a href="<?= APP_URL ?>/users/" class="button user-back">
        <i class="fas fa-arrow-left"></i>
        Back
    </a>

</div>

<div class="user-form-wrapper">

    <div class="user-form-card">

        <div class="user-form-title">

            <h3>
                <i class="fas fa-user-plus"></i>
                User Information
            </h3>

            <p>
                Create an account for a Haven Mart staff member.
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

            <div class="user-form-grid">

                <div class="user-form-group">

                    <label>Full Name *</label>

                    <input
                        type="text"
                        name="full_name"
                        value="<?= e($_POST['full_name'] ?? '') ?>"
                        placeholder="e.g. John Kamau"
                        maxlength="100"
                        required
                    >

                </div>

                <div class="user-form-group">

                    <label>Username *</label>

                    <input
                        type="text"
                        name="username"
                        value="<?= e($_POST['username'] ?? '') ?>"
                        placeholder="e.g. john.kamau"
                        maxlength="50"
                        autocomplete="username"
                        required
                    >

                    <small>
                        3–50 characters. Letters, numbers, dots, underscores and hyphens.
                    </small>

                </div>

                <div class="user-form-group">

                    <label>Role *</label>

                    <select name="role_id" id="role_id" required>

                        <option value="">Select role</option>

                        <?php foreach ($roles as $role): ?>

                            <option
                                value="<?= (int) $role['id'] ?>"
                                <?= ((int) ($_POST['role_id'] ?? 0) === (int) $role['id']) ? 'selected' : '' ?>
                            >
                                <?= e($role['name']) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div class="user-form-group">

                    <label>Branch</label>

                    <select name="branch_id" id="branch_id">

                        <option value="">System-wide / No Branch</option>

                        <?php foreach ($branches as $branch): ?>

                            <option
                                value="<?= (int) $branch['id'] ?>"
                                <?= ((int) ($_POST['branch_id'] ?? 0) === (int) $branch['id']) ? 'selected' : '' ?>
                            >
                                <?= e($branch['code']) ?> -
                                <?= e($branch['name']) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                    <small>
                        Cashiers and Branch Managers must have a branch.
                    </small>

                </div>

                <div class="user-form-group">

                    <label>Status</label>

                    <select name="status">

                        <option
                            value="ACTIVE"
                            <?= ($_POST['status'] ?? 'ACTIVE') === 'ACTIVE' ? 'selected' : '' ?>
                        >
                            Active
                        </option>

                        <option
                            value="INACTIVE"
                            <?= ($_POST['status'] ?? '') === 'INACTIVE' ? 'selected' : '' ?>
                        >
                            Inactive
                        </option>

                    </select>

                </div>

                <div class="user-password-box">

                    <h4>
                        <i class="fas fa-lock"></i>
                        Account Password
                    </h4>

                    <div class="user-password-grid">

                        <div class="user-form-group">

                            <label>Password *</label>

                            <input
                                type="password"
                                name="password"
                                minlength="8"
                                autocomplete="new-password"
                                required
                            >

                            <small>
                                Minimum 8 characters.
                            </small>

                        </div>

                        <div class="user-form-group">

                            <label>Confirm Password *</label>

                            <input
                                type="password"
                                name="confirm_password"
                                minlength="8"
                                autocomplete="new-password"
                                required
                            >

                        </div>

                    </div>

                </div>

            </div>

            <div class="user-form-actions">

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
                    <i class="fas fa-user-plus"></i>
                    Create User
                </button>

            </div>

        </form>

    </div>

</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>