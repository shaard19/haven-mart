<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_login();
require_permission('inventory.view');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . APP_URL . '/inventory/');
    exit;
}

if (!verify_csrf($_POST['csrf_token'] ?? null)) {
    header('Location: ' . APP_URL . '/inventory/');
    exit;
}

$product_id = (int)($_POST['product_id'] ?? 0);
$branch_id = (int)($_POST['branch_id'] ?? 0);
$adjustment_type = strtoupper(trim((string)($_POST['adjustment_type'] ?? '')));
$quantity = (float)($_POST['quantity'] ?? 0);
$reason = trim((string)($_POST['reason'] ?? ''));

if ($product_id <= 0 || $branch_id <= 0) {
    header('Location: ' . APP_URL . '/inventory/');
    exit;
}

if (!in_array($adjustment_type, ['IN', 'OUT'], true)) {
    header(
        'Location: ' .
        APP_URL .
        '/inventory/adjust.php?product_id=' .
        $product_id .
        '&branch_id=' .
        $branch_id .
        '&error=invalid_adjustment'
    );
    exit;
}

if ($quantity <= 0) {
    header(
        'Location: ' .
        APP_URL .
        '/inventory/adjust.php?product_id=' .
        $product_id .
        '&branch_id=' .
        $branch_id .
        '&error=invalid_quantity'
    );
    exit;
}

if ($reason === '') {
    header(
        'Location: ' .
        APP_URL .
        '/inventory/adjust.php?product_id=' .
        $product_id .
        '&branch_id=' .
        $branch_id .
        '&error=reason_required'
    );
    exit;
}

if (mb_strlen($reason) > 500) {
    $reason = mb_substr($reason, 0, 500);
}

$user = current_user();
$user_id = (int)($user['id'] ?? 0);

if ($user_id <= 0) {
    header('Location: ' . APP_URL . '/auth/login.php');
    exit;
}

try {
    $pdo->beginTransaction();

    $productStmt = $pdo->prepare("
        SELECT id, name, status
        FROM products
        WHERE id = ?
        LIMIT 1
        FOR UPDATE
    ");
    $productStmt->execute([$product_id]);
    $product = $productStmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        throw new RuntimeException('Product not found.');
    }

    if ($product['status'] !== 'ACTIVE') {
        throw new RuntimeException('This product is inactive.');
    }

    $branchStmt = $pdo->prepare("
        SELECT id, name, status
        FROM branches
        WHERE id = ?
        LIMIT 1
        FOR UPDATE
    ");
    $branchStmt->execute([$branch_id]);
    $branch = $branchStmt->fetch(PDO::FETCH_ASSOC);

    if (!$branch) {
        throw new RuntimeException('Branch not found.');
    }

    if ($branch['status'] !== 'ACTIVE') {
        throw new RuntimeException('This branch is inactive.');
    }

    $stockStmt = $pdo->prepare("
        SELECT
            id,
            quantity,
            reserved_quantity
        FROM branch_stock
        WHERE product_id = ?
          AND branch_id = ?
        LIMIT 1
        FOR UPDATE
    ");
    $stockStmt->execute([$product_id, $branch_id]);
    $stock = $stockStmt->fetch(PDO::FETCH_ASSOC);

    $current_quantity = $stock
        ? (float)$stock['quantity']
        : 0.00;

    $reserved_quantity = $stock
        ? (float)$stock['reserved_quantity']
        : 0.00;

    if ($adjustment_type === 'OUT') {
        $available_quantity = $current_quantity - $reserved_quantity;

        if ($quantity > $available_quantity) {
            throw new RuntimeException(
                'Cannot remove ' .
                number_format($quantity, 3) .
                ' units. Only ' .
                number_format(max(0, $available_quantity), 3) .
                ' units are available.'
            );
        }

        $new_quantity = $current_quantity - $quantity;
        $movement_type = 'ADJUSTMENT_OUT';
        $movement_quantity = -$quantity;
    } else {
        $new_quantity = $current_quantity + $quantity;
        $movement_type = 'ADJUSTMENT_IN';
        $movement_quantity = $quantity;
    }

    if ($stock) {
        $updateStmt = $pdo->prepare("
            UPDATE branch_stock
            SET quantity = ?
            WHERE id = ?
        ");

        $updateStmt->execute([
            $new_quantity,
            (int)$stock['id']
        ]);
    } else {
        if ($adjustment_type === 'OUT') {
            throw new RuntimeException(
                'No stock record exists for this product and branch.'
            );
        }

        $insertStockStmt = $pdo->prepare("
            INSERT INTO branch_stock (
                product_id,
                branch_id,
                quantity,
                reserved_quantity
            ) VALUES (?, ?, ?, 0)
        ");

        $insertStockStmt->execute([
            $product_id,
            $branch_id,
            $new_quantity
        ]);
    }

    $movementStmt = $pdo->prepare("
        INSERT INTO stock_movements (
            product_id,
            branch_id,
            quantity,
            movement_type,
            reference_type,
            reference_id,
            notes,
            created_by,
            created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");

    $movementStmt->execute([
        $product_id,
        $branch_id,
        $movement_quantity,
        $movement_type,
        'MANUAL_ADJUSTMENT',
        null,
        $reason,
        $user_id
    ]);

    $pdo->commit();

    header(
        'Location: ' .
        APP_URL .
        '/inventory/view.php?product_id=' .
        $product_id .
        '&branch_id=' .
        $branch_id .
        '&success=adjusted'
    );
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    header(
        'Location: ' .
        APP_URL .
        '/inventory/adjust.php?product_id=' .
        $product_id .
        '&branch_id=' .
        $branch_id .
        '&error=' .
        urlencode($e->getMessage())
    );
    exit;
}