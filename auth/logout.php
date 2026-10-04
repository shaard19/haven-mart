<?php

require_once __DIR__ . '/../includes/auth.php';

if (is_logged_in()) {
    $user = current_user();

    $log = $pdo->prepare("
        INSERT INTO login_logs
        (user_id, status, ip_address, user_agent)
        VALUES (?, 'LOGOUT', ?, ?)
    ");

    $log->execute([
        $user['id'],
        $_SERVER['REMOTE_ADDR'] ?? '',
        $_SERVER['HTTP_USER_AGENT'] ?? ''
    ]);
}

logout_user();

header('Location: ' . APP_URL . '/auth/login.php');
exit;