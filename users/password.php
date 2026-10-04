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
        u.id,
        u.username,
        u.full_name,
        u.status,
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

$page_title = 'Change Password';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verify_csrf($_POST['csrf_token'] ?? '')) {

        $error = 'Invalid security token. Please refresh and try again.';

    } else {

        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if ($password === '') {

            $error = 'New password is required.';

        } elseif (strlen($password) < 8) {

            $error = 'Password must contain at least 8 characters.';

        } elseif ($password !== $confirm_password) {

            $error = 'Passwords do not match.';

        } else {

            try {

                $password_hash = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                $pdo->beginTransaction();

                $update = $pdo->prepare("
                    UPDATE users
                    SET password = ?
                    WHERE id = ?
                ");

                $update->execute([
                    $password_hash,
                    $id
                ]);

                $current_user = current_user();

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
                    $user['branch_id'] ?? null,
                    'USER_PASSWORD_CHANGED',
                    'users',
                    $id,
                    null,
                    json_encode([
                        'username' => $user['username'],
                        'password_changed' => true
                    ]),
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null
                ]);

                $pdo->commit();

                $success = 'Password changed successfully.';

                $_POST = [];

            } catch (Throwable $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $error = 'Unable to change the password. Please try again.';
            }
        }
    }
}

include __DIR__ . '/../includes/header.php';
?>

<style>

.password-wrapper {
    max-width:750px;
}

.password-card {
    background:#fff;
    border:1px solid #eee;
    border-radius:18px;
    padding:35px;
    box-shadow:0 10px 30px rgba(0,0,0,.06);
}

.password-title {
    margin-bottom:28px;
}

.password-title h3 {
    color:#64131f;
    font-size:20px;
    margin-bottom:6px;
}

.password-title p {
    color:#777;
    font-size:14px;
}

.password-user {
    background:#faf7f5;
    border:1px solid #eee;
    border-radius:14px;
    padding:18px;
    margin-bottom:25px;
    display:flex;
    align-items:center;
    gap:15px;
}

.password-user-icon {
    width:48px;
    height:48px;
    border-radius:12px;
    background:#64131f;
    color:#fff;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:20px;
}

.password-user-info strong {
    display:block;
    color:#64131f;
    font-size:16px;
}

.password-user-info span {
    display:block;
    color:#888;
    font-size:13px;
    margin-top:3px;
}

.password-form {
    display:grid;
    gap:20px;
}

.password-group label {
    display:block;
    font-size:13px;
    font-weight:700;
    color:#4d0e17;
    margin-bottom:8px;
}

.password-group input {
    width:100%;
    height:48px;
    padding:0 14px;
    border-radius:10px;
    border:1px solid #ddd;
    background:#fff;
    font-size:14px;
    outline:none;
    box-sizing:border-box;
}

.password-group input:focus {
    border-color:#f2c94c;
    box-shadow:0 0 0 3px rgba(242,201,76,.25);
}

.password-group small {
    display:block;
    margin-top:7px;
    color:#888;
    font-size:12px;
}

.password-requirements {
    background:#fff8e1;
    border:1px solid #f2c94c;
    border-radius:12px;
    padding:15px;
    color:#6b4b00;
    font-size:13px;
}

.password-requirements strong {
    display:block;
    margin-bottom:7px;
}

.password-requirements ul {
    margin:0;
    padding-left:20px;
}

.password-requirements li {
    margin-bottom:4px;
}

.password-actions {
    margin-top:10px;
    padding-top:20px;
    border-top:1px solid #eee;
    display:flex;
    justify-content:flex-end;
    gap:10px;
}

.password-save {
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

.password-save:hover {
    background:#8b1e2d;
}

.password-back {
    background:#f2c94c;
    color:#4d0e17;
    font-weight:700;
}

.password-success {
    background:#e8f7ef;
    border:1px solid #b7e4c7;
    color:#137333;
    border-radius:10px;
    padding:12px 15px;
    margin-bottom:20px;
}

.password-error {
    margin-bottom:20px;
}

@media(max-width:768px) {

    .password-card {
        padding:20px;
    }

    .password-actions {
        flex-direction:column;
    }

    .password-actions .button,
    .password-actions button {
        width:100%;
        justify-content:center;
    }

}

</style>

<div class="page-header">

    <div>
        <p class="page-kicker">Haven Mart</p>
        <h2>Change Password</h2>
        <p>Securely reset the password for this user account.</p>
    </div>

    <a href="<?= APP_URL ?>/users/" class="button password-back">
        <i class="fas fa-arrow-left"></i>
        Back
    </a>

</div>

<div class="password-wrapper">

    <div class="password-card">

        <div class="password-title">

            <h3>
                <i class="fas fa-key"></i>
                Password Reset
            </h3>

            <p>
                Set a new secure password for the selected account.
            </p>

        </div>

        <div class="password-user">

            <div class="password-user-icon">
                <i class="fas fa-user"></i>
            </div>

            <div class="password-user-info">

                <strong>
                    <?= e($user['full_name']) ?>
                </strong>

                <span>
                    @<?= e($user['username']) ?>
                    ·
                    <?= e($user['role_name']) ?>

                    <?php if ($user['branch_name']): ?>
                        · <?= e($user['branch_name']) ?>
                    <?php endif; ?>
                </span>

            </div>

        </div>

        <?php if ($success): ?>

            <div class="password-success">

                <i class="fas fa-circle-check"></i>

                <?= e($success) ?>

            </div>

        <?php endif; ?>

        <?php if ($error): ?>

            <div class="alert alert-error password-error">

                <i class="fas fa-circle-exclamation"></i>

                <?= e($error) ?>

            </div>

        <?php endif; ?>

        <form method="POST" class="password-form">

            <?= csrf_field() ?>

            <div class="password-group">

                <label>New Password *</label>

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

            <div class="password-group">

                <label>Confirm New Password *</label>

                <input
                    type="password"
                    name="confirm_password"
                    minlength="8"
                    autocomplete="new-password"
                    required
                >

            </div>

            <div class="password-requirements">

                <strong>
                    <i class="fas fa-shield-halved"></i>
                    Password Security
                </strong>

                <ul>
                    <li>Use at least 8 characters.</li>
                    <li>Use a combination of letters and numbers.</li>
                    <li>Avoid easily guessed passwords.</li>
                    <li>The password is stored using a secure password hash.</li>
                </ul>

            </div>

            <div class="password-actions">

                <a
                    href="<?= APP_URL ?>/users/"
                    class="button password-back"
                >
                    Cancel
                </a>

                <button
                    type="submit"
                    class="password-save"
                >
                    <i class="fas fa-key"></i>
                    Change Password
                </button>

            </div>

        </form>

    </div>

</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>