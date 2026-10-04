<?php
require_once __DIR__ . '/../includes/auth.php';

require_login();
require_permission('sales.view');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . APP_URL . '/sales/create.php');
    exit;
}

verify_csrf($_POST['csrf_token'] ?? '');

$branchId = (int)($_POST['branch_id'] ?? 0);
$items = $_POST['items'] ?? [];
$overallDiscount = (float)($_POST['discount'] ?? 0);

if ($branchId <= 0) {
    header('Location: ' . APP_URL . '/sales/create.php?error=' . urlencode('Please select a valid branch.'));
    exit;
}

if (!is_array($items) || empty($items)) {
    header('Location: ' . APP_URL . '/sales/create.php?error=' . urlencode('Please add at least one product.'));
    exit;
}

if ($overallDiscount < 0) {
    header('Location: ' . APP_URL . '/sales/create.php?error=' . urlencode('Invalid discount amount.'));
    exit;
}

$currentUser = current_user();

if (!$currentUser || empty($currentUser['id'])) {
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

$cashierId = (int)$currentUser['id'];

try {
    $pdo->beginTransaction();

    $branchStmt = $pdo->prepare("
        SELECT id, name
        FROM branches
        WHERE id = ?
          AND status = 'ACTIVE'
        LIMIT 1
    ");

    $branchStmt->execute([$branchId]);
    $branch = $branchStmt->fetch(PDO::FETCH_ASSOC);

    if (!$branch) {
        throw new Exception('The selected branch is not active or does not exist.');
    }

    $cleanItems = [];
    $subtotal = 0;

    foreach ($items as $item) {
        $productId = (int)($item['product_id'] ?? 0);
        $quantity = (float)($item['quantity'] ?? 0);

        if ($productId <= 0) {
            throw new Exception('One of the selected products is invalid.');
        }

        if ($quantity <= 0) {
            throw new Exception('Product quantity must be greater than zero.');
        }

        $productStmt = $pdo->prepare("
            SELECT
                p.id,
                p.name,
                p.sku,
                p.selling_price,
                p.status,
                u.name AS unit_name
            FROM products p
            LEFT JOIN units u ON u.id = p.unit_id
            WHERE p.id = ?
            LIMIT 1
        ");

        $productStmt->execute([$productId]);
        $product = $productStmt->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
            throw new Exception('One of the selected products no longer exists.');
        }

        if ($product['status'] !== 'ACTIVE') {
            throw new Exception('Product "' . $product['name'] . '" is inactive.');
        }

        $unitPrice = (float)$product['selling_price'];

        $itemDiscount = (float)($item['discount'] ?? 0);

        if ($itemDiscount < 0) {
            throw new Exception('Invalid item discount for "' . $product['name'] . '".');
        }

        $grossTotal = $quantity * $unitPrice;

        if ($itemDiscount > $grossTotal) {
            throw new Exception('Discount for "' . $product['name'] . '" cannot exceed its value.');
        }

        $lineTotal = $grossTotal - $itemDiscount;

        $stockStmt = $pdo->prepare("
            SELECT
                id,
                quantity,
                reserved_quantity
            FROM branch_stock
            WHERE product_id = ?
              AND branch_id = ?
            FOR UPDATE
        ");

        $stockStmt->execute([
            $productId,
            $branchId
        ]);

        $stockRow = $stockStmt->fetch(PDO::FETCH_ASSOC);

        if (!$stockRow) {
            throw new Exception(
                'No stock record exists for "' . $product['name'] . '" in ' . $branch['name'] . '.'
            );
        }

        $availableStock =
            (float)$stockRow['quantity'] -
            (float)$stockRow['reserved_quantity'];

        if ($quantity > $availableStock) {
            throw new Exception(
                'Insufficient stock for "' . $product['name'] .
                '". Available: ' . number_format($availableStock, 3) .
                ', requested: ' . number_format($quantity, 3) . '.'
            );
        }

        $cleanItems[] = [
            'product_id' => $productId,
            'product_name' => $product['name'],
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'discount' => $itemDiscount,
            'total' => $lineTotal,
            'stock_id' => (int)$stockRow['id'],
            'available_stock' => $availableStock
        ];

        $subtotal += $lineTotal;
    }

    if (empty($cleanItems)) {
        throw new Exception('No valid sale items were supplied.');
    }

    if ($overallDiscount > $subtotal) {
        throw new Exception('Overall discount cannot exceed the sale subtotal.');
    }

    $total = $subtotal - $overallDiscount;

    do {
        $invoiceNumber =
            'INV-' .
            date('YmdHis') .
            '-' .
            strtoupper(bin2hex(random_bytes(3)));

        $invoiceCheck = $pdo->prepare("
            SELECT id
            FROM sales
            WHERE invoice_number = ?
            LIMIT 1
        ");

        $invoiceCheck->execute([$invoiceNumber]);

    } while ($invoiceCheck->fetchColumn());

    $saleStmt = $pdo->prepare("
        INSERT INTO sales (
            invoice_number,
            branch_id,
            cashier_id,
            subtotal,
            discount,
            total,
            payment_status,
            sale_status,
            sale_date,
            created_at,
            updated_at
        ) VALUES (
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            'UNPAID',
            'COMPLETED',
            NOW(),
            NOW(),
            NOW()
        )
    ");

    $saleStmt->execute([
        $invoiceNumber,
        $branchId,
        $cashierId,
        $subtotal,
        $overallDiscount,
        $total
    ]);

    $saleId = (int)$pdo->lastInsertId();

    $itemStmt = $pdo->prepare("
        INSERT INTO sale_items (
            sale_id,
            product_id,
            quantity,
            unit_price,
            discount,
            total
        ) VALUES (
            ?,
            ?,
            ?,
            ?,
            ?,
            ?
        )
    ");

    $stockUpdateStmt = $pdo->prepare("
        UPDATE branch_stock
        SET quantity = quantity - ?
        WHERE id = ?
          AND quantity - reserved_quantity >= ?
    ");

    $movementStmt = $pdo->prepare("
        INSERT INTO stock_movements (
            product_id,
            branch_id,
            quantity,
            movement_type,
            reference_type,
            reference_id,
            notes,
            created_at
        ) VALUES (
            ?,
            ?,
            ?,
            'SALE',
            'SALE',
            ?,
            ?,
            NOW()
        )
    ");

    foreach ($cleanItems as $item) {

        $itemStmt->execute([
            $saleId,
            $item['product_id'],
            $item['quantity'],
            $item['unit_price'],
            $item['discount'],
            $item['total']
        ]);

        $stockUpdateStmt->execute([
            $item['quantity'],
            $item['stock_id'],
            $item['quantity']
        ]);

        if ($stockUpdateStmt->rowCount() !== 1) {
            throw new Exception(
                'Stock changed while processing "' .
                $item['product_name'] .
                '". Please try again.'
            );
        }

        $movementStmt->execute([
            $item['product_id'],
            $branchId,
            -$item['quantity'],
            $saleId,
            'Sale ' . $invoiceNumber
        ]);
    }

    $pdo->commit();

    header(
        'Location: ' .
        APP_URL .
        '/sales/view.php?id=' .
        $saleId .
        '&success=' .
        urlencode('Sale created successfully.')
    );

    exit;

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    header(
        'Location: ' .
        APP_URL .
        '/sales/create.php?error=' .
        urlencode($e->getMessage())
    );

    exit;
}