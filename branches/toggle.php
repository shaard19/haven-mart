<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();
require_permission('branches.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . APP_URL . '/branches/');
    exit;
}

if (!verify_csrf($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit('Invalid security token.');
}

$id = (int) ($_POST['id'] ?? 0);

if ($id <= 0) {
    header('Location: ' . APP_URL . '/branches/');
    exit;
}

$stmt = $pdo->prepare("
    SELECT
        id,
        code,
        name,
        status
    FROM branches
    WHERE id = ?
    LIMIT 1
");

$stmt->execute([$id]);

$branch = $stmt->fetch();

if (!$branch) {
    header('Location: ' . APP_URL . '/branches/');
    exit;
}

$new_status = $branch['status'] === 'ACTIVE'
    ? 'INACTIVE'
    : 'ACTIVE';

$update = $pdo->prepare("
    UPDATE branches
    SET status = ?
    WHERE id = ?
");

$update->execute([
    $new_status,
    $id
]);

$user = current_user();

$action = $new_status === 'ACTIVE'
    ? 'BRANCH_ACTIVATED'
    : 'BRANCH_DEACTIVATED';

$old_values = json_encode([
    'status' => $branch['status']
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
    $user['id'],
    $id,
    $action,
    'branches',
    $id,
    $old_values,
    $new_values,
    $_SERVER['REMOTE_ADDR'] ?? null,
    $_SERVER['HTTP_USER_AGENT'] ?? null
]);

header('Location: ' . APP_URL . '/branches/');
exit;