<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();
require_permission('products.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . APP_URL . '/products/');
    exit;
}

if (!verify_csrf($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit('Invalid security token.');
}

$id = (int) ($_POST['id'] ?? 0);

if ($id <= 0) {
    header('Location: ' . APP_URL . '/products/');
    exit;
}

$stmt = $pdo->prepare("
    SELECT
        id,
        sku,
        name,
        status
    FROM products
    WHERE id = ?
    LIMIT 1
");

$stmt->execute([$id]);

$product = $stmt->fetch();

if (!$product) {
    header('Location: ' . APP_URL . '/products/');
    exit;
}

$new_status = $product['status'] === 'ACTIVE'
    ? 'INACTIVE'
    : 'ACTIVE';

try {

    $pdo->beginTransaction();

    $update = $pdo->prepare("
        UPDATE products
        SET status = ?
        WHERE id = ?
    ");

    $update->execute([
        $new_status,
        $id
    ]);

    $user = current_user();

    $action = $new_status === 'ACTIVE'
        ? 'PRODUCT_ACTIVATED'
        : 'PRODUCT_DEACTIVATED';

    $old_values = json_encode([
        'sku' => $product['sku'],
        'name' => $product['name'],
        'status' => $product['status']
    ]);

    $new_values = json_encode([
        'sku' => $product['sku'],
        'name' => $product['name'],
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
        'products',
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
    exit('Unable to change product status.');
}

header('Location: ' . APP_URL . '/products/');
exit;
