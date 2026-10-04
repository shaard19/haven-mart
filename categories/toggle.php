<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();
require_permission('categories.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . APP_URL . '/categories/');
    exit;
}

if (!verify_csrf($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit('Invalid security token.');
}

$id = (int) ($_POST['id'] ?? 0);

if ($id <= 0) {
    header('Location: ' . APP_URL . '/categories/');
    exit;
}

$stmt = $pdo->prepare("
    SELECT
        id,
        name,
        status
    FROM categories
    WHERE id = ?
    LIMIT 1
");

$stmt->execute([$id]);

$category = $stmt->fetch();

if (!$category) {
    header('Location: ' . APP_URL . '/categories/');
    exit;
}

$new_status = $category['status'] === 'ACTIVE'
    ? 'INACTIVE'
    : 'ACTIVE';

try {

    $pdo->beginTransaction();

    $update = $pdo->prepare("
        UPDATE categories
        SET status = ?
        WHERE id = ?
    ");

    $update->execute([
        $new_status,
        $id
    ]);

    $user = current_user();

    $action = $new_status === 'ACTIVE'
        ? 'CATEGORY_ACTIVATED'
        : 'CATEGORY_DEACTIVATED';

    $old_values = json_encode([
        'name' => $category['name'],
        'status' => $category['status']
    ]);

    $new_values = json_encode([
        'name' => $category['name'],
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
        $user['branch_id'] ?: null,
        $action,
        'categories',
        $id,
        $old_values,
        $new_values,
        $_SERVER['REMOTE_ADDR'] ?? null,
        $_SERVER['HTTP_USER_AGENT'] ?? null
    ]);

    $pdo->commit();

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    exit('Unable to change category status.');
}

header('Location: ' . APP_URL . '/categories/');
exit;