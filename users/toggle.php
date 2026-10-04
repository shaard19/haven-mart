<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();
require_permission('users.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . APP_URL . '/users/');
    exit;
}

if (!verify_csrf($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit('Invalid security token.');
}

$id = (int) ($_POST['id'] ?? 0);

if ($id <= 0) {
    header('Location: ' . APP_URL . '/users/');
    exit;
}

$current_user = current_user();

if ((int) $current_user['id'] === $id) {
    header(
        'Location: ' . APP_URL . '/users/?error=self'
    );
    exit;
}

$stmt = $pdo->prepare("
    SELECT
        u.id,
        u.username,
        u.full_name,
        u.role_id,
        u.branch_id,
        u.status,
        r.name AS role_name
    FROM users u
    INNER JOIN roles r ON r.id = u.role_id
    WHERE u.id = ?
    LIMIT 1
");

$stmt->execute([$id]);

$user = $stmt->fetch();

if (!$user) {
    header('Location: ' . APP_URL . '/users/');
    exit;
}

$new_status = $user['status'] === 'ACTIVE'
    ? 'INACTIVE'
    : 'ACTIVE';

try {

    $pdo->beginTransaction();

    $update = $pdo->prepare("
        UPDATE users
        SET status = ?
        WHERE id = ?
    ");

    $update->execute([
        $new_status,
        $id
    ]);

    $action = $new_status === 'ACTIVE'
        ? 'USER_ACTIVATED'
        : 'USER_DEACTIVATED';

    $old_values = json_encode([
        'status' => $user['status']
    ]);

    $new_values = json_encode([
        'status' => $new_status
    ]);

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
        $user['branch_id'],
        $action,
        'users',
        $id,
        $old_values,
        $new_values,
        $_SERVER['REMOTE_ADDR'] ?? null,
        $_SERVER['HTTP_USER_AGENT'] ?? null
    ]);

    $pdo->commit();

    header('Location: ' . APP_URL . '/users/');
    exit;

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    exit('Unable to change user status.');
}