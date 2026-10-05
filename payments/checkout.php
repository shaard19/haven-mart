<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_login();
require_permission('pos.access');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . APP_URL . '/pos/');
    exit;
}

if (!verify_csrf($_POST['csrf_token'] ?? null)) {
    header('Location: ' . APP_URL . '/pos/?error=' . urlencode('Invalid security token. Please refresh the POS page and try again.'));
    exit;
}

$currentUser = current_user();

if (!$currentUser) {
    header('Location: ' . APP_URL . '/auth/login.php');
    exit;
}

$branchId = (int)($_POST['branch_id'] ?? 0);
$paymentMethod = strtoupper(trim((string)($_POST['payment_method'] ?? '')));
$cashReceived = (float)($_POST['cash_received'] ?? 0);
$mpesaReference = trim((string)($_POST['mpesa_reference'] ?? ''));
$overallDiscount = (float)($_POST['discount'] ?? 0);
$items = $_POST['items'] ?? [];

if ($branchId <= 0) {
    header('Location: ' . APP_URL . '/pos/?error=' . urlencode('Please select a valid branch.'));
    exit;
}

if (!in_array($paymentMethod, ['CASH', 'MPESA'], true)) {
    header('Location: ' . APP_URL . '/pos/?error=' . urlencode('Invalid payment method.'));
    exit;
}

if (!is_array($items) || count($items) === 0) {
    header('Location: ' . APP_URL . '/pos/?error=' . urlencode('Your cart is empty.'));
    exit;
}

if ($overallDiscount < 0) {
    header('Location: ' . APP_URL . '/pos/?error=' . urlencode('Invalid overall discount.'));
    exit;
}

if (
    $currentUser['role_name'] !== 'Administrator' &&
    !empty($currentUser['branch_id']) &&
    (int)$currentUser['branch_id'] !== $branchId
) {
    header('Location: ' . APP_URL . '/pos/?error=' . urlencode('You are not authorized to process sales for the selected branch.'));
    exit;
}

if ($paymentMethod === 'MPESA' && $mpesaReference === '') {
    header('Location: ' . APP_URL . '/pos/?error=' . urlencode('M-Pesa transaction code is required.'));
    exit;
}

if ($paymentMethod === 'CASH' && $cashReceived < 0) {
    header('Location: ' . APP_URL . '/pos/?error=' . urlencode('Invalid cash amount.'));
    exit;
}

$normalizedItems = [];

foreach ($items as $item) {
    if (!is_array($item)) {
        continue;
    }

    $productId = (int)($item['product_id'] ?? 0);
    $quantity = (float)($item['quantity'] ?? 0);
    $discount = (float)($item['discount'] ?? 0);

    if ($productId <= 0 || $quantity <= 0) {
        header('Location: ' . APP_URL . '/pos/?error=' . urlencode('Invalid product or quantity detected.'));
        exit;
    }

    if ($discount < 0) {
        header('Location: ' . APP_URL . '/pos/?error=' . urlencode('Invalid item discount detected.'));
        exit;
    }

    if (isset($normalizedItems[$productId])) {
        header('Location: ' . APP_URL . '/pos/?error=' . urlencode('Duplicate product detected in the cart.'));
        exit;
    }

    $normalizedItems[$productId] = [
        'product_id' => $productId,
        'quantity' => $quantity,
        'discount' => $discount
    ];
}

if (count($normalizedItems) === 0) {
    header('Location: ' . APP_URL . '/pos/?error=' . urlencode('Your cart is empty.'));
    exit;
}

try {
    $pdo->beginTransaction();

    $branchStmt = $pdo->prepare("
        SELECT id, name, code, status
        FROM branches
        WHERE id = ?
        LIMIT 1
        FOR UPDATE
    ");
    $branchStmt->execute([$branchId]);
    $branch = $branchStmt->fetch(PDO::FETCH_ASSOC);

    if (!$branch || $branch['status'] !== 'ACTIVE') {
        throw new RuntimeException('The selected branch is not active.');
    }

    $productStmt = $pdo->prepare("
        SELECT
            p.id,
            p.sku,
            p.barcode,
            p.name,
            p.cost_price,
            p.selling_price,
            p.status,
            c.name AS category_name,
            u.name AS unit_name
        FROM products p
        LEFT JOIN categories c ON c.id = p.category_id
        LEFT JOIN units u ON u.id = p.unit_id
        WHERE p.id = ?
        LIMIT 1
        FOR UPDATE
    ");

    $stockStmt = $pdo->prepare("
        SELECT
            id,
            quantity,
            reserved_quantity
        FROM branch_stock
        WHERE branch_id = ?
          AND product_id = ?
        LIMIT 1
        FOR UPDATE
    ");

    $saleItems = [];
    $subtotal = 0.00;
    $totalItemDiscount = 0.00;

    foreach ($normalizedItems as $item) {
        $productStmt->execute([$item['product_id']]);
        $product = $productStmt->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
            throw new RuntimeException('One of the selected products no longer exists.');
        }

        if ($product['status'] !== 'ACTIVE') {
            throw new RuntimeException('Product "' . $product['name'] . '" is inactive.');
        }

        $sellingPrice = round((float)$product['selling_price'], 2);
        $quantity = (float)$item['quantity'];
        $itemDiscount = round((float)$item['discount'], 2);

        if ($sellingPrice <= 0) {
            throw new RuntimeException('Product "' . $product['name'] . '" has an invalid selling price.');
        }

        if ($quantity <= 0) {
            throw new RuntimeException('Invalid quantity for "' . $product['name'] . '".');
        }

        $gross = round($sellingPrice * $quantity, 2);

        if ($itemDiscount > $gross) {
            throw new RuntimeException('Discount for "' . $product['name'] . '" cannot exceed the item value.');
        }

        $itemTotal = round($gross - $itemDiscount, 2);

        $stockStmt->execute([$branchId, $product['id']]);
        $stock = $stockStmt->fetch(PDO::FETCH_ASSOC);

        if (!$stock) {
            throw new RuntimeException('No stock record exists for "' . $product['name'] . '" in the selected branch.');
        }

        $availableStock = (float)$stock['quantity'] - (float)$stock['reserved_quantity'];

        if ($quantity > $availableStock) {
            throw new RuntimeException(
                'Insufficient stock for "' . $product['name'] . '". Available: ' .
                rtrim(rtrim(number_format($availableStock, 3, '.', ''), '0'), '.') .
                '. Requested: ' .
                rtrim(rtrim(number_format($quantity, 3, '.', ''), '0'), '.') . '.'
            );
        }

        $subtotal = round($subtotal + $gross, 2);
        $totalItemDiscount = round($totalItemDiscount + $itemDiscount, 2);

        $saleItems[] = [
            'product_id' => (int)$product['id'],
            'name' => $product['name'],
            'quantity' => $quantity,
            'unit_price' => $sellingPrice,
            'discount' => $itemDiscount,
            'total' => $itemTotal,
            'stock_id' => (int)$stock['id'],
            'available_stock' => $availableStock
        ];
    }

    $maximumDiscount = round($subtotal, 2);

    if ($overallDiscount > $maximumDiscount) {
        throw new RuntimeException('Overall discount cannot exceed the subtotal.');
    }

    $totalDiscount = round($totalItemDiscount + $overallDiscount, 2);
    $total = round($subtotal - $totalDiscount, 2);

    if ($total <= 0) {
        throw new RuntimeException('Sale total must be greater than zero.');
    }

    if ($paymentMethod === 'CASH') {
        if ($cashReceived < $total) {
            throw new RuntimeException(
                'Insufficient cash received. Amount required: KES ' .
                number_format($total, 2) .
                '.'
            );
        }
    }

    if ($paymentMethod === 'MPESA') {
        $duplicateStmt = $pdo->prepare("
            SELECT id
            FROM payments
            WHERE payment_method = 'MPESA'
              AND reference_number = ?
              AND payment_status = 'COMPLETED'
            LIMIT 1
        ");
        $duplicateStmt->execute([$mpesaReference]);

        if ($duplicateStmt->fetchColumn()) {
            throw new RuntimeException('This M-Pesa transaction code has already been used.');
        }
    }

    $invoiceNumber = 'INV-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(3)));

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
            sale_date
        ) VALUES (?, ?, ?, ?, ?, ?, 'PAID', 'COMPLETED', NOW())
    ");

    $saleStmt->execute([
        $invoiceNumber,
        $branchId,
        (int)$currentUser['id'],
        $subtotal,
        $totalDiscount,
        $total
    ]);

    $saleId = (int)$pdo->lastInsertId();

    $saleItemStmt = $pdo->prepare("
        INSERT INTO sale_items (
            sale_id,
            product_id,
            quantity,
            unit_price,
            discount,
            total
        ) VALUES (?, ?, ?, ?, ?, ?)
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
            created_by,
            created_at
        ) VALUES (?, ?, ?, 'SALE', 'SALE', ?, ?, ?, NOW())
    ");

    foreach ($saleItems as $saleItem) {
        $saleItemStmt->execute([
            $saleId,
            $saleItem['product_id'],
            $saleItem['quantity'],
            $saleItem['unit_price'],
            $saleItem['discount'],
            $saleItem['total']
        ]);

        $stockUpdateStmt->execute([
            $saleItem['quantity'],
            $saleItem['stock_id'],
            $saleItem['quantity']
        ]);

        if ($stockUpdateStmt->rowCount() !== 1) {
            throw new RuntimeException('Stock changed while processing "' . $saleItem['name'] . '". Please try again.');
        }

        $movementStmt->execute([
            $saleItem['product_id'],
            $branchId,
            -$saleItem['quantity'],
            $saleId,
            'POS sale ' . $invoiceNumber,
            (int)$currentUser['id']
        ]);
    }

    $paymentStmt = $pdo->prepare("
        INSERT INTO payments (
            sale_id,
            payment_method,
            amount,
            reference_number,
            payment_status,
            paid_at,
            recorded_by
        ) VALUES (?, ?, ?, ?, 'COMPLETED', NOW(), ?)
    ");

    $paymentStmt->execute([
        $saleId,
        $paymentMethod,
        $total,
        $paymentMethod === 'MPESA' ? $mpesaReference : null,
        (int)$currentUser['id']
    ]);

    $pdo->commit();

    $change = $paymentMethod === 'CASH'
        ? round($cashReceived - $total, 2)
        : 0.00;

    $message = 'Sale completed successfully. Invoice: ' . $invoiceNumber;

    if ($paymentMethod === 'CASH') {
        $message .= ' | Change: KES ' . number_format($change, 2);
    }

    header(
        'Location: ' .
        APP_URL .
        '/sales/view.php?id=' .
        $saleId .
        '&success=' .
        urlencode($message)
    );
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    header(
        'Location: ' .
        APP_URL .
        '/pos/?error=' .
        urlencode($e->getMessage())
    );
    exit;
}