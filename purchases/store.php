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
    http_response_code(403);
    exit('Invalid security token.');
}

$userId = filter_var(
    $_SESSION['user_id'] ?? null,
    FILTER_VALIDATE_INT
);

if ($userId === false || $userId === null || $userId < 1) {
    http_response_code(403);
    exit('Invalid user session.');
}

$branchId = filter_var(
    $_POST['branch_id'] ?? null,
    FILTER_VALIDATE_INT
);

if ($branchId === false || $branchId === null || $branchId < 1) {
    header('Location: create.php?error=branch');
    exit;
}

$supplierId = filter_var(
    $_POST['supplier_id'] ?? null,
    FILTER_VALIDATE_INT
);

if ($supplierId === false || $supplierId === null || $supplierId < 1) {
    $supplierId = null;
}

$referenceNumber = trim((string) ($_POST['reference_number'] ?? ''));
$purchaseDate = trim((string) ($_POST['purchase_date'] ?? ''));
$notes = trim((string) ($_POST['notes'] ?? ''));
$discountInput = $_POST['discount'] ?? '0';
$items = $_POST['items'] ?? [];

if (
    $referenceNumber === '' ||
    mb_strlen($referenceNumber, 'UTF-8') > 100 ||
    preg_match('/[\r\n]/', $referenceNumber)
) {
    header('Location: create.php?error=reference');
    exit;
}

$dateObject = DateTime::createFromFormat('!Y-m-d', $purchaseDate);

if (
    !$dateObject ||
    DateTime::getLastErrors() !== false &&
    (
        DateTime::getLastErrors()['warning_count'] > 0 ||
        DateTime::getLastErrors()['error_count'] > 0
    ) ||
    $dateObject->format('Y-m-d') !== $purchaseDate
) {
    header('Location: create.php?error=date');
    exit;
}

if (mb_strlen($notes, 'UTF-8') > 5000) {
    header('Location: create.php?error=notes');
    exit;
}

if (!is_array($items) || count($items) < 1) {
    header('Location: create.php?error=no_items');
    exit;
}

if (count($items) > 500) {
    header('Location: create.php?error=too_many_items');
    exit;
}

if (
    is_array($discountInput) ||
    !is_numeric($discountInput) ||
    !is_finite((float) $discountInput)
) {
    header('Location: create.php?error=discount');
    exit;
}

$discount = round((float) $discountInput, 2);

if ($discount < 0) {
    header('Location: create.php?error=discount');
    exit;
}

$cleanItems = [];
$productIds = [];

foreach ($items as $item) {
    if (!is_array($item)) {
        header('Location: create.php?error=items');
        exit;
    }

    $productId = filter_var(
        $item['product_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    if ($productId === false || $productId === null || $productId < 1) {
        header('Location: create.php?error=product');
        exit;
    }

    $quantityInput = $item['quantity'] ?? null;
    $unitCostInput = $item['unit_cost'] ?? null;

    if (
        is_array($quantityInput) ||
        is_array($unitCostInput) ||
        !is_numeric($quantityInput) ||
        !is_numeric($unitCostInput)
    ) {
        header('Location: create.php?error=item_values');
        exit;
    }

    $quantity = (float) $quantityInput;
    $unitCost = (float) $unitCostInput;

    if (
        !is_finite($quantity) ||
        !is_finite($unitCost) ||
        $quantity <= 0 ||
        $unitCost < 0
    ) {
        header('Location: create.php?error=item_values');
        exit;
    }

    $quantity = round($quantity, 3);
    $unitCost = round($unitCost, 2);

    if ($quantity <= 0 || $unitCost < 0) {
        header('Location: create.php?error=item_values');
        exit;
    }

    if (isset($cleanItems[$productId])) {
        header('Location: create.php?error=duplicate_product');
        exit;
    }

    $cleanItems[$productId] = [
        'product_id' => $productId,
        'quantity' => $quantity,
        'unit_cost' => $unitCost,
        'total' => 0.00
    ];

    $productIds[] = $productId;
}

$subtotal = 0.00;

foreach ($cleanItems as &$item) {
    $lineTotal = round(
        $item['quantity'] * $item['unit_cost'],
        2
    );

    if (!is_finite($lineTotal) || $lineTotal < 0) {
        header('Location: create.php?error=item_values');
        exit;
    }

    $item['total'] = $lineTotal;

    $subtotal = round(
        $subtotal + $lineTotal,
        2
    );
}

unset($item);

if ($discount > $subtotal) {
    $discount = $subtotal;
}

$total = round(
    $subtotal - $discount,
    2
);

if ($total < 0) {
    $total = 0.00;
}

try {
    $pdo->beginTransaction();

    $branchStmt = $pdo->prepare(
        "SELECT id
         FROM branches
         WHERE id = :id
           AND status = 'ACTIVE'
         LIMIT 1"
    );

    $branchStmt->execute([
        ':id' => $branchId
    ]);

    if (!$branchStmt->fetchColumn()) {
        throw new RuntimeException('Selected branch is not active.');
    }

    if ($supplierId !== null) {
        $supplierStmt = $pdo->prepare(
            "SELECT id
             FROM suppliers
             WHERE id = :id
               AND status = 'ACTIVE'
             LIMIT 1"
        );

        $supplierStmt->execute([
            ':id' => $supplierId
        ]);

        if (!$supplierStmt->fetchColumn()) {
            throw new RuntimeException('Selected supplier is not active.');
        }
    }

    $userStmt = $pdo->prepare(
        "SELECT id
         FROM users
         WHERE id = :id
           AND status = 'ACTIVE'
         LIMIT 1"
    );

    $userStmt->execute([
        ':id' => $userId
    ]);

    if (!$userStmt->fetchColumn()) {
        throw new RuntimeException('User account is not active.');
    }

    $referenceStmt = $pdo->prepare(
        "SELECT id
         FROM purchases
         WHERE reference_number = :reference
         LIMIT 1"
    );

    $referenceStmt->execute([
        ':reference' => $referenceNumber
    ]);

    if ($referenceStmt->fetchColumn()) {
        $pdo->rollBack();

        header('Location: create.php?error=duplicate_reference');
        exit;
    }

    $placeholders = [];
    $productParams = [];

    foreach ($productIds as $index => $productId) {
        $placeholder = ':product_' . $index;
        $placeholders[] = $placeholder;
        $productParams[$placeholder] = $productId;
    }

    $productSql =
        "SELECT id
         FROM products
         WHERE id IN (" .
        implode(', ', $placeholders) .
        ")";

    $productStmt = $pdo->prepare($productSql);

    foreach ($productParams as $placeholder => $value) {
        $productStmt->bindValue(
            $placeholder,
            $value,
            PDO::PARAM_INT
        );
    }

    $productStmt->execute();

    $validProductIds = [];

    while ($row = $productStmt->fetch(PDO::FETCH_ASSOC)) {
        $validProductIds[] = (int) $row['id'];
    }

    sort($validProductIds);

    $requestedProductIds = $productIds;
    sort($requestedProductIds);

    if ($validProductIds !== $requestedProductIds) {
        throw new RuntimeException(
            'One or more selected products do not exist.'
        );
    }

    $insertPurchase = $pdo->prepare(
        "INSERT INTO purchases (
            branch_id,
            supplier_id,
            reference_number,
            purchase_date,
            subtotal,
            discount,
            total,
            status,
            notes,
            created_by
        ) VALUES (
            :branch_id,
            :supplier_id,
            :reference_number,
            :purchase_date,
            :subtotal,
            :discount,
            :total,
            'DRAFT',
            :notes,
            :created_by
        )"
    );

    $insertPurchase->bindValue(
        ':branch_id',
        $branchId,
        PDO::PARAM_INT
    );

    if ($supplierId === null) {
        $insertPurchase->bindValue(
            ':supplier_id',
            null,
            PDO::PARAM_NULL
        );
    } else {
        $insertPurchase->bindValue(
            ':supplier_id',
            $supplierId,
            PDO::PARAM_INT
        );
    }

    $insertPurchase->bindValue(
        ':reference_number',
        $referenceNumber,
        PDO::PARAM_STR
    );

    $insertPurchase->bindValue(
        ':purchase_date',
        $purchaseDate,
        PDO::PARAM_STR
    );

    $insertPurchase->bindValue(
        ':subtotal',
        number_format($subtotal, 2, '.', ''),
        PDO::PARAM_STR
    );

    $insertPurchase->bindValue(
        ':discount',
        number_format($discount, 2, '.', ''),
        PDO::PARAM_STR
    );

    $insertPurchase->bindValue(
        ':total',
        number_format($total, 2, '.', ''),
        PDO::PARAM_STR
    );

    if ($notes === '') {
        $insertPurchase->bindValue(
            ':notes',
            null,
            PDO::PARAM_NULL
        );
    } else {
        $insertPurchase->bindValue(
            ':notes',
            $notes,
            PDO::PARAM_STR
        );
    }

    $insertPurchase->bindValue(
        ':created_by',
        $userId,
        PDO::PARAM_INT
    );

    $insertPurchase->execute();

    $purchaseId = (int) $pdo->lastInsertId();

    if ($purchaseId < 1) {
        throw new RuntimeException(
            'Purchase could not be created.'
        );
    }

    $insertItem = $pdo->prepare(
        "INSERT INTO purchase_items (
            purchase_id,
            product_id,
            quantity,
            unit_cost,
            total
        ) VALUES (
            :purchase_id,
            :product_id,
            :quantity,
            :unit_cost,
            :total
        )"
    );

    foreach ($cleanItems as $item) {
        $insertItem->bindValue(
            ':purchase_id',
            $purchaseId,
            PDO::PARAM_INT
        );

        $insertItem->bindValue(
            ':product_id',
            $item['product_id'],
            PDO::PARAM_INT
        );

        $insertItem->bindValue(
            ':quantity',
            number_format(
                $item['quantity'],
                3,
                '.',
                ''
            ),
            PDO::PARAM_STR
        );

        $insertItem->bindValue(
            ':unit_cost',
            number_format(
                $item['unit_cost'],
                2,
                '.',
                ''
            ),
            PDO::PARAM_STR
        );

        $insertItem->bindValue(
            ':total',
            number_format(
                $item['total'],
                2,
                '.',
                ''
            ),
            PDO::PARAM_STR
        );

        $insertItem->execute();
    }

    $pdo->commit();

    header(
        'Location: view.php?id=' .
        $purchaseId .
        '&success=created'
    );
    exit;

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'Haven purchase creation database error: ' .
        $e->getMessage()
    );

    $errorInfo = $e->errorInfo ?? null;
    $errorCode = is_array($errorInfo) && isset($errorInfo[1])
        ? (int) $errorInfo[1]
        : 0;

    if ($errorCode === 1062) {
        header(
            'Location: create.php?error=duplicate_reference'
        );
        exit;
    }

    header('Location: create.php?error=database');
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'Haven purchase creation failed: ' .
        $e->getMessage()
    );

    header('Location: create.php?error=save_failed');
    exit;
}