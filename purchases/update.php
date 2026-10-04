<?php
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/auth.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

if (!verify_csrf($_POST['csrf_token'] ?? '')) {
    header('Location: index.php?error=invalid_request');
    exit;
}

$userId = filter_var(
    $_SESSION['user_id'] ?? null,
    FILTER_VALIDATE_INT
);

if ($userId === false || $userId === null || $userId < 1) {
    header('Location: index.php?error=unauthorized');
    exit;
}

$purchaseId = filter_var(
    $_POST['purchase_id'] ?? null,
    FILTER_VALIDATE_INT
);

if ($purchaseId === false || $purchaseId === null || $purchaseId < 1) {
    header('Location: index.php?error=invalid_purchase');
    exit;
}

$branchId = filter_var(
    $_POST['branch_id'] ?? null,
    FILTER_VALIDATE_INT
);

if ($branchId === false || $branchId === null || $branchId < 1) {
    header(
        'Location: edit.php?id=' .
        $purchaseId .
        '&error=invalid_data'
    );
    exit;
}

$supplierIdRaw = $_POST['supplier_id'] ?? '';
$supplierId = null;

if ($supplierIdRaw !== '' && $supplierIdRaw !== null) {
    if (
        is_array($supplierIdRaw) ||
        !is_numeric($supplierIdRaw)
    ) {
        header(
            'Location: edit.php?id=' .
            $purchaseId .
            '&error=invalid_data'
        );
        exit;
    }

    $supplierId = filter_var(
        $supplierIdRaw,
        FILTER_VALIDATE_INT
    );

    if (
        $supplierId === false ||
        $supplierId === null ||
        $supplierId < 1
    ) {
        header(
            'Location: edit.php?id=' .
            $purchaseId .
            '&error=invalid_data'
        );
        exit;
    }
}

$referenceNumber = trim(
    (string) ($_POST['reference_number'] ?? '')
);

if (
    $referenceNumber === '' ||
    mb_strlen($referenceNumber, 'UTF-8') > 100 ||
    preg_match('/[\r\n]/', $referenceNumber)
) {
    header(
        'Location: edit.php?id=' .
        $purchaseId .
        '&error=invalid_data'
    );
    exit;
}

$purchaseDate = trim(
    (string) ($_POST['purchase_date'] ?? '')
);

$dateObject = DateTime::createFromFormat(
    '!Y-m-d',
    $purchaseDate
);

$dateErrors = DateTime::getLastErrors();

if (
    !$dateObject ||
    (
        $dateErrors !== false &&
        (
            $dateErrors['warning_count'] > 0 ||
            $dateErrors['error_count'] > 0
        )
    ) ||
    $dateObject->format('Y-m-d') !== $purchaseDate
) {
    header(
        'Location: edit.php?id=' .
        $purchaseId .
        '&error=invalid_data'
    );
    exit;
}

$notes = trim(
    (string) ($_POST['notes'] ?? '')
);

if (mb_strlen($notes, 'UTF-8') > 5000) {
    header(
        'Location: edit.php?id=' .
        $purchaseId .
        '&error=invalid_data'
    );
    exit;
}

$discountRaw = $_POST['discount'] ?? '0';

if (
    is_array($discountRaw) ||
    !is_numeric($discountRaw)
) {
    header(
        'Location: edit.php?id=' .
        $purchaseId .
        '&error=invalid_data'
    );
    exit;
}

$discount = (float) $discountRaw;

if (!is_finite($discount) || $discount < 0) {
    header(
        'Location: edit.php?id=' .
        $purchaseId .
        '&error=invalid_data'
    );
    exit;
}

$submittedItems = $_POST['items'] ?? [];

if (
    !is_array($submittedItems) ||
    count($submittedItems) < 1 ||
    count($submittedItems) > 500
) {
    header(
        'Location: edit.php?id=' .
        $purchaseId .
        '&error=invalid_data'
    );
    exit;
}

$items = [];
$productIds = [];

foreach ($submittedItems as $item) {
    if (!is_array($item)) {
        header(
            'Location: edit.php?id=' .
            $purchaseId .
            '&error=invalid_data'
        );
        exit;
    }

    $productId = filter_var(
        $item['product_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    if (
        $productId === false ||
        $productId === null ||
        $productId < 1
    ) {
        header(
            'Location: edit.php?id=' .
            $purchaseId .
            '&error=invalid_data'
        );
        exit;
    }

    $quantityRaw = $item['quantity'] ?? null;
    $unitCostRaw = $item['unit_cost'] ?? null;

    if (
        is_array($quantityRaw) ||
        is_array($unitCostRaw) ||
        !is_numeric($quantityRaw) ||
        !is_numeric($unitCostRaw)
    ) {
        header(
            'Location: edit.php?id=' .
            $purchaseId .
            '&error=invalid_data'
        );
        exit;
    }

    $quantity = (float) $quantityRaw;
    $unitCost = (float) $unitCostRaw;

    if (
        !is_finite($quantity) ||
        $quantity <= 0 ||
        !is_finite($unitCost) ||
        $unitCost < 0
    ) {
        header(
            'Location: edit.php?id=' .
            $purchaseId .
            '&error=invalid_data'
        );
        exit;
    }

    $quantity = round($quantity, 3);
    $unitCost = round($unitCost, 2);

    if (
        $quantity <= 0 ||
        $unitCost < 0
    ) {
        header(
            'Location: edit.php?id=' .
            $purchaseId .
            '&error=invalid_data'
        );
        exit;
    }

    if (isset($productIds[$productId])) {
        header(
            'Location: edit.php?id=' .
            $purchaseId .
            '&error=invalid_data'
        );
        exit;
    }

    $lineTotal = round(
        $quantity * $unitCost,
        2
    );

    if (
        !is_finite($lineTotal) ||
        $lineTotal < 0
    ) {
        header(
            'Location: edit.php?id=' .
            $purchaseId .
            '&error=invalid_data'
        );
        exit;
    }

    $productIds[$productId] = true;

    $items[] = [
        'product_id' => $productId,
        'quantity' => $quantity,
        'unit_cost' => $unitCost,
        'total' => $lineTotal
    ];
}

$subtotal = 0.00;

foreach ($items as $item) {
    $subtotal = round(
        $subtotal + $item['total'],
        2
    );
}

if ($discount > $subtotal) {
    $discount = $subtotal;
}

$discount = round(
    $discount,
    2
);

$total = round(
    $subtotal - $discount,
    2
);

if ($total < 0) {
    $total = 0.00;
}

try {
    $pdo->beginTransaction();

    $userStmt = $pdo->prepare(
        "SELECT id
         FROM users
         WHERE id = ?
           AND status = 'ACTIVE'
         LIMIT 1"
    );

    $userStmt->execute([
        $userId
    ]);

    if (!$userStmt->fetchColumn()) {
        throw new RuntimeException(
            'Invalid user.'
        );
    }

    $purchaseStmt = $pdo->prepare(
        "SELECT
            id,
            status,
            reference_number
         FROM purchases
         WHERE id = ?
         FOR UPDATE"
    );

    $purchaseStmt->execute([
        $purchaseId
    ]);

    $existingPurchase = $purchaseStmt->fetch(
        PDO::FETCH_ASSOC
    );

    if (!$existingPurchase) {
        $pdo->rollBack();

        header(
            'Location: index.php?error=not_found'
        );
        exit;
    }

    if ($existingPurchase['status'] !== 'DRAFT') {
        $pdo->rollBack();

        header(
            'Location: view.php?id=' .
            $purchaseId .
            '&error=not_editable'
        );
        exit;
    }

    $branchStmt = $pdo->prepare(
        "SELECT id
         FROM branches
         WHERE id = ?
           AND status = 'ACTIVE'
         LIMIT 1"
    );

    $branchStmt->execute([
        $branchId
    ]);

    if (!$branchStmt->fetchColumn()) {
        throw new RuntimeException(
            'Invalid branch.'
        );
    }

    if ($supplierId !== null) {
        $supplierStmt = $pdo->prepare(
            "SELECT id
             FROM suppliers
             WHERE id = ?
               AND status = 'ACTIVE'
             LIMIT 1"
        );

        $supplierStmt->execute([
            $supplierId
        ]);

        if (!$supplierStmt->fetchColumn()) {
            throw new RuntimeException(
                'Invalid supplier.'
            );
        }
    }

    $referenceStmt = $pdo->prepare(
        "SELECT id
         FROM purchases
         WHERE reference_number = ?
           AND id <> ?
         LIMIT 1"
    );

    $referenceStmt->execute([
        $referenceNumber,
        $purchaseId
    ]);

    if ($referenceStmt->fetchColumn()) {
        $pdo->rollBack();

        header(
            'Location: edit.php?id=' .
            $purchaseId .
            '&error=duplicate_reference'
        );
        exit;
    }

    $placeholders = implode(
        ',',
        array_fill(
            0,
            count($productIds),
            '?'
        )
    );

    $productStmt = $pdo->prepare(
        "SELECT id, status
         FROM products
         WHERE id IN ($placeholders)"
    );

    $productStmt->execute(
        array_keys($productIds)
    );

    $validProducts = [];

    while (
        $product = $productStmt->fetch(
            PDO::FETCH_ASSOC
        )
    ) {
        $validProducts[(int) $product['id']]=
            $product['status'];
    }

    foreach ($items as $item) {
        $productId = (int) $item['product_id'];

        if (!isset($validProducts[$productId])) {
            throw new RuntimeException(
                'Invalid product.'
            );
        }

        if ($validProducts[$productId] !== 'ACTIVE') {
            throw new RuntimeException(
                'Inactive product.'
            );
        }
    }

    $updateStmt = $pdo->prepare(
        "UPDATE purchases
         SET
            branch_id = ?,
            supplier_id = ?,
            reference_number = ?,
            purchase_date = ?,
            subtotal = ?,
            discount = ?,
            total = ?,
            notes = ?
         WHERE id = ?
           AND status = 'DRAFT'"
    );

    $updateStmt->execute([
        $branchId,
        $supplierId,
        $referenceNumber,
        $purchaseDate,
        number_format(
            $subtotal,
            2,
            '.',
            ''
        ),
        number_format(
            $discount,
            2,
            '.',
            ''
        ),
        number_format(
            $total,
            2,
            '.',
            ''
        ),
        $notes !== '' ? $notes : null,
        $purchaseId
    ]);

    if ($updateStmt->rowCount() === 0) {
        throw new RuntimeException(
            'Purchase update failed.'
        );
    }

    $deleteItemsStmt = $pdo->prepare(
        "DELETE FROM purchase_items
         WHERE purchase_id = ?"
    );

    $deleteItemsStmt->execute([
        $purchaseId
    ]);

    $insertItemStmt = $pdo->prepare(
        "INSERT INTO purchase_items (
            purchase_id,
            product_id,
            quantity,
            unit_cost,
            total
         ) VALUES (?, ?, ?, ?, ?)"
    );

    foreach ($items as $item) {
        $insertItemStmt->execute([
            $purchaseId,
            $item['product_id'],
            number_format(
                $item['quantity'],
                3,
                '.',
                ''
            ),
            number_format(
                $item['unit_cost'],
                2,
                '.',
                ''
            ),
            number_format(
                $item['total'],
                2,
                '.',
                ''
            )
        ]);
    }

    $pdo->commit();

    header(
        'Location: view.php?id=' .
        $purchaseId .
        '&success=updated'
    );
    exit;

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'Haven purchase update database error: ' .
        $e->getMessage()
    );

    $errorInfo = $e->errorInfo ?? null;
    $errorCode = is_array($errorInfo) &&
        isset($errorInfo[1])
        ? (int) $errorInfo[1]
        : 0;

    if ($errorCode === 1062) {
        header(
            'Location: edit.php?id=' .
            $purchaseId .
            '&error=duplicate_reference'
        );
        exit;
    }

    header(
        'Location: edit.php?id=' .
        $purchaseId .
        '&error=save_failed'
    );
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'Haven purchase update failed: ' .
        $e->getMessage()
    );

    header(
        'Location: edit.php?id=' .
        $purchaseId .
        '&error=save_failed'
    );
    exit;
}