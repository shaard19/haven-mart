<?php

require_once __DIR__ . '/../includes/bootstrap.php';

if (isset($_SESSION['user_id'])) {
    header('Location: ' . APP_URL . '/dashboard/');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please refresh the page and try again.';
    } else {

        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username === '' || $password === '') {
            $error = 'Please enter your username and password.';
        } else {

            $stmt = $pdo->prepare("
                SELECT id, username, password, status
                FROM users
                WHERE username = ?
                LIMIT 1
            ");

            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user && $user['status'] === 'ACTIVE' && password_verify($password, $user['password'])) {

                session_regenerate_id(true);

                $_SESSION['user_id'] = $user['id'];
                $_SESSION['login_time'] = time();
                $_SESSION['last_activity'] = time();

                $log = $pdo->prepare("
                    INSERT INTO login_logs
                    (user_id, status, ip_address, user_agent)
                    VALUES (?, 'SUCCESS', ?, ?)
                ");

                $log->execute([
                    $user['id'],
                    $_SERVER['REMOTE_ADDR'] ?? '',
                    $_SERVER['HTTP_USER_AGENT'] ?? ''
                ]);

                header('Location: ' . APP_URL . '/dashboard/');
                exit;

            } else {

                $log = $pdo->prepare("
                    INSERT INTO login_logs
                    (user_id, status, ip_address, user_agent)
                    VALUES (NULL, 'FAILED', ?, ?)
                ");

                $log->execute([
                    $_SERVER['REMOTE_ADDR'] ?? '',
                    $_SERVER['HTTP_USER_AGENT'] ?? ''
                ]);

                $error = 'Invalid username or password.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Haven Mart</title>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/app.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
</head>
<body class="login-page">

<div class="login-container">

    <div class="login-brand">
        <div class="login-logo">
            <i class="fas fa-store"></i>
        </div>

        <h1>Haven Mart</h1>
        <p>Retail Management System</p>
    </div>

    <div class="login-card">

        <div class="login-heading">
            <h2>Welcome Back</h2>
            <p>Sign in to continue</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-circle-exclamation"></i>
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST">
              <?= csrf_field() ?>

            <div class="form-group">
                <label for="username">Username</label>

                <div class="input-wrapper">
                    <i class="fas fa-user"></i>
                    <input
                        type="text"
                        id="username"
                        name="username"
                        autocomplete="username"
                        required
                    >
                </div>
            </div>

            <div class="form-group">
                <label for="password">Password</label>

                <div class="input-wrapper">
                    <i class="fas fa-lock"></i>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        autocomplete="current-password"
                        required
                    >
                </div>
            </div>

            <button type="submit" class="login-button">
                <i class="fas fa-right-to-bracket"></i>
                Sign In
            </button>

        </form>
    </div>

    <div class="login-footer">
        <strong>Haven Mart</strong>
        <span>Secure Retail Management</span>
    </div>

</div>

</body>
</html>