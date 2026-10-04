<?php
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/../includes/auth.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$csrfToken = $_POST['csrf_token'] ?? '';

if (!verify_csrf($csrfToken)) {
    header('Location: index.php?error=invalid_request');
    exit;
}

$userId = filter_var(
    $_SESSION['user_id'] ?? null,
    FILTER_VALIDATE_INT
);

$purchaseId = filter_input(
    INPUT_POST,
    'id',
    FILTER_VALIDATE_INT
);

if (
    $userId === false ||
    $userId === null ||
    $userId < 1 ||
    $purchaseId === false ||
    $purchaseId === null ||
    $purchaseId < 1
) {
    header('Location: index.php?error=invalid_request');
    exit;
}

try {
    $pdo->beginTransaction();

    $userStmt = $pdo->prepare("
        SELECT id
        FROM users
        WHERE id = ?
        AND status = 'ACTIVE'
        LIMIT 1
    ");

    $userStmt->execute([
        $userId
    ]);

    if (!$userStmt->fetchColumn()) {
        throw new RuntimeException(
            'Your account is no longer active.'
        );
    }

    $purchaseStmt = $pdo->prepare("
        SELECT
            id,
            branch_id,
            status,
            reference_number
        FROM purchases
        WHERE id = ?
        FOR UPDATE
    ");

    $purchaseStmt->execute([
        $purchaseId
    ]);

    $purchase = $purchaseStmt->fetch(
        PDO::FETCH_ASSOC
    );

    if (!$purchase) {
        throw new RuntimeException(
            'Purchase not found.'
        );
    }

    if ($purchase['status'] !== 'DRAFT') {
        throw new RuntimeException(
            'This purchase has already been processed.'
        );
    }

    $branchId = (int) $purchase['branch_id'];

    if ($branchId < 1) {
        throw new RuntimeException(
            'The purchase has an invalid branch.'
        );
    }

    $branchStmt = $pdo->prepare("
        SELECT id
        FROM branches
        WHERE id = ?
        AND status = 'ACTIVE'
        LIMIT 1
    ");

    $branchStmt->execute([
        $branchId
    ]);

    if (!$branchStmt->fetchColumn()) {
        throw new RuntimeException(
            'The branch assigned to this purchase is not active.'
        );
    }

    $itemsStmt = $pdo->prepare("
        SELECT
            id,
            product_id,
            quantity,
            unit_cost
        FROM purchase_items
        WHERE purchase_id = ?
        ORDER BY id ASC
        FOR UPDATE
    ");

    $itemsStmt->execute([
        $purchaseId
    ]);

    $items = $itemsStmt->fetchAll(
        PDO::FETCH_ASSOC
    );

    if (!$items) {
        throw new RuntimeException(
            'This purchase has no items to receive.'
        );
    }

    $productIds = [];

    foreach ($items as $item) {
        $productId = (int) $item['product_id'];
        $quantity = (float) $item['quantity'];

        if (
            $productId < 1 ||
            !is_finite($quantity) ||
            $quantity <= 0
        ) {
            throw new RuntimeException(
                'This purchase contains an invalid item.'
            );
        }

        $productIds[$productId] = $productId;
    }

    $productIds = array_values(
        $productIds
    );

    if (!$productIds) {
        throw new RuntimeException(
            'This purchase contains no valid products.'
        );
    }

    $placeholders = implode(
        ',',
        array_fill(
            0,
            count($productIds),
            '?'
        )
    );

    $productStmt = $pdo->prepare("
        SELECT
            id,
            name,
            status
        FROM products
        WHERE id IN ($placeholders)
        FOR UPDATE
    ");

    $productStmt->execute(
        $productIds
    );

    $products = $productStmt->fetchAll(
        PDO::FETCH_ASSOC
    );

    if (
        count($products) !==
        count($productIds)
    ) {
        throw new RuntimeException(
            'One or more products in this purchase no longer exist.'
        );
    }

    $productMap = [];

    foreach ($products as $product) {
        $productMap[(int) $product['id']] = $product;
    }

    $stockSelectStmt = $pdo->prepare("
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

    $stockUpdateStmt = $pdo->prepare("
        UPDATE branch_stock
        SET quantity = quantity + ?
        WHERE id = ?
    ");

    $stockInsertStmt = $pdo->prepare("
        INSERT INTO branch_stock (
            branch_id,
            product_id,
            quantity,
            reserved_quantity
        ) VALUES (?, ?, ?, 0.000)
    ");

    $movementStmt = $pdo->prepare("
        INSERT INTO stock_movements (
            branch_id,
            product_id,
            movement_type,
            quantity,
            reference_type,
            reference_id,
            notes,
            created_by
        ) VALUES (
            ?,
            ?,
            'PURCHASE',
            ?,
            'PURCHASE',
            ?,
            ?,
            ?
        )
    ");

    $movementNotes =
        'Purchase ' .
        $purchase['reference_number'] .
        ' received';

    $movementNotes = substr(
        $movementNotes,
        0,
        255
    );

    foreach ($items as $item) {
        $productId = (int) $item['product_id'];

        $quantity = round(
            (float) $item['quantity'],
            3
        );

        if (
            $quantity <= 0 ||
            !isset($productMap[$productId])
        ) {
            throw new RuntimeException(
                'Invalid product quantity detected.'
            );
        }

        $stockSelectStmt->execute([
            $branchId,
            $productId
        ]);

        $stock = $stockSelectStmt->fetch(
            PDO::FETCH_ASSOC
        );

        if ($stock) {
            $stockUpdateStmt->execute([
                $quantity,
                (int) $stock['id']
            ]);
        } else {
            $stockInsertStmt->execute([
                $branchId,
                $productId,
                $quantity
            ]);
        }

        $movementStmt->execute([
            $branchId,
            $productId,
            $quantity,
            $purchaseId,
            $movementNotes,
            $userId
        ]);
    }

    $updatePurchaseStmt = $pdo->prepare("
        UPDATE purchases
        SET status = 'RECEIVED'
        WHERE id = ?
        AND status = 'DRAFT'
    ");

    $updatePurchaseStmt->execute([
        $purchaseId
    ]);

    if ($updatePurchaseStmt->rowCount() !== 1) {
        throw new RuntimeException(
            'The purchase could not be marked as received.'
        );
    }

    $pdo->commit();

    header(
        'Location: view.php?id=' .
        $purchaseId .
        '&success=received'
    );

    exit;

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'Haven Mart purchase receive error: ' .
        $e->getMessage()
    );

    $message = $e instanceof RuntimeException
        ? $e->getMessage()
        : 'Unable to receive this purchase. Please try again.';

    if (
        strpos(
            $message,
            'already been processed'
        ) !== false
    ) {
        header(
            'Location: view.php?id=' .
            $purchaseId .
            '&error=already_received'
        );

        exit;
    }

    if (
        strpos(
            $message,
            'Purchase not found'
        ) !== false
    ) {
        header(
            'Location: index.php?error=not_found'
        );

        exit;
    }

    if (
        strpos(
            $message,
            'no items'
        ) !== false
    ) {
        header(
            'Location: view.php?id=' .
            $purchaseId .
            '&error=no_items'
        );

        exit;
    }

    header(
        'Location: view.php?id=' .
        $purchaseId .
        '&error=receive_failed'
    );

    exit;
}