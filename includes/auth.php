<?php

require_once __DIR__ . '/bootstrap.php';

function is_logged_in()
{
    return isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id']);
}

function current_user()
{
    global $pdo;

    static $user = null;

    if (!is_logged_in()) {
        return null;
    }

    if ($user !== null) {
        return $user;
    }

    $stmt = $pdo->prepare("
        SELECT
            u.id,
            u.username,
            u.full_name,
            u.role_id,
            u.branch_id,
            u.status,
            r.name AS role_name,
            b.name AS branch_name
        FROM users u
        INNER JOIN roles r ON r.id = u.role_id
        LEFT JOIN branches b ON b.id = u.branch_id
        WHERE u.id = ?
        LIMIT 1
    ");

    $stmt->execute([(int) $_SESSION['user_id']]);

    $user = $stmt->fetch();

    if (!$user || $user['status'] !== 'ACTIVE') {
        logout_user();
        return null;
    }

    return $user;
}

function require_login()
{
    if (!is_logged_in() || !current_user()) {
        header('Location: ' . APP_URL . '/auth/login.php');
        exit;
    }

    $timeout = 30 * 60;

    if (
        isset($_SESSION['last_activity']) &&
        time() - $_SESSION['last_activity'] > $timeout
    ) {
        logout_user();

        header('Location: ' . APP_URL . '/auth/login.php?timeout=1');
        exit;
    }

    $_SESSION['last_activity'] = time();
}

function has_permission($permission)
{
    global $pdo;

    $user = current_user();

    if (!$user) {
        return false;
    }

    if ($user['role_name'] === 'Administrator') {
        return true;
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM role_permissions rp
        INNER JOIN permissions p ON p.id = rp.permission_id
        WHERE rp.role_id = ?
        AND p.name = ?
    ");

    $stmt->execute([
        $user['role_id'],
        $permission
    ]);

    return (int) $stmt->fetchColumn() > 0;
}

function require_permission($permission)
{
    if (!has_permission($permission)) {
        http_response_code(403);
        exit('Access denied.');
    }
}

function logout_user()
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
}