<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_login();
require_permission('pos.access');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . APP_URL . '/pos/');
    exit;
}

function checkout_error(string $message): never
{
    $safeMessage = e($message);
    $posUrl = APP_URL . '/pos/';
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Checkout Error | Haven Mart</title>
        <style>
            :root {
                --maroon: #64131f;
                --maroon-dark: #4d0e17;
                --maroon-light: #8b1e2d;
                --gold: #f2c94c;
                --cream: #fff4c7;
                --bg: #f5f3f0;
                --text: #292321;
                --muted: #756d69;
                --danger: #b42318;
                --white: #ffffff;
                --border: #e4dfda;
                --shadow: 0 18px 45px rgba(77, 14, 23, .12);
            }

            * {
                box-sizing: border-box;
            }

            body {
                margin: 0;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 24px;
                background:
                    linear-gradient(135deg, rgba(100, 19, 31, .06), transparent 45%),
                    var(--bg);
                color: var(--text);
                font-family: Arial, Helvetica, sans-serif;
            }

            .checkout-error {
                width: 100%;
                max-width: 560px;
            }

            .card {
                background: var(--white);
                border: 1px solid var(--border);
                border-radius: 20px;
                box-shadow: var(--shadow);
                overflow: hidden;
            }

            .card-header {
                padding: 28px 30px;
                background: var(--maroon);
                color: var(--white);
                text-align: center;
                border-bottom: 4px solid var(--gold);
            }

            .brand {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 12px;
                margin-bottom: 8px;
            }

            .brand-mark {
                width: 42px;
                height: 42px;
                display: flex;
                align-items: center;
                justify-content: center;
                border-radius: 12px;
                background: var(--gold);
                color: var(--maroon);
                font-size: 20px;
                font-weight: 800;
            }

            .brand-name {
                font-size: 25px;
                font-weight: 800;
                letter-spacing: .3px;
            }

            .brand-subtitle {
                margin: 0;
                color: rgba(255, 255, 255, .78);
                font-size: 13px;
            }

            .card-body {
                padding: 34px 30px 30px;
                text-align: center;
            }

            .error-icon {
                width: 72px;
                height: 72px;
                margin: 0 auto 20px;
                display: flex;
                align-items: center;
                justify-content: center;
                border-radius: 50%;
                background: #fdecea;
                color: var(--danger);
                font-size: 34px;
                font-weight: 800;
            }

            h1 {
                margin: 0 0 10px;
                color: var(--maroon);
                font-size: 24px;
            }

            .message {
                margin: 0 auto;
                max-width: 460px;
                padding: 15px 17px;
                border: 1px solid #f2c9c5;
                border-radius: 12px;
                background: #fff7f6;
                color: #7a211a;
                font-size: 14px;
                line-height: 1.6;
            }

            .actions {
                display: flex;
                justify-content: center;
                gap: 12px;
                margin-top: 26px;
                flex-wrap: wrap;
            }

            .btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                min-height: 46px;
                padding: 0 22px;
                border-radius: 10px;
                text-decoration: none;
                font-size: 14px;
                font-weight: 700;
                transition: .2s ease;
            }

            .btn-primary {
                background: var(--maroon);
                color: var(--white);
            }

            .btn-primary:hover {
                background: var(--maroon-dark);
                transform: translateY(-1px);
            }

            .footer {
                padding: 16px 24px;
                border-top: 1px solid var(--border);
                background: #faf9f7;
                color: var(--muted);
                text-align: center;
                font-size: 12px;
            }

            .footer strong {
                color: var(--maroon);
            }

            @media (max-width: 520px) {
                body {
                    padding: 14px;
                }

                .card-header {
                    padding: 24px 20px;
                }

                .card-body {
                    padding: 28px 20px 24px;
                }

                .brand-name {
                    font-size: 22px;
                }

                .actions,
                .btn {
                    width: 100%;
                }
            }
        </style>
    </head>
    <body>
        <main class="checkout-error">
            <section class="card">
                <header class="card-header">
                    <div class="brand">
                        <div class="brand-mark">HM</div>
                        <div class="brand-name">Haven Mart</div>
                    </div>
                    <p class="brand-subtitle">Point of Sale System</p>
                </header>

                <div class="card-body">
                    <div class="error-icon">!</div>
                    <h1>Checkout Could Not Be Completed</h1>

                    <div class="message">
                        <?= $safeMessage ?>
                    </div>

                    <div class="actions">
                        <a href="<?= e($posUrl) ?>" class="btn btn-primary">
                            Return to POS
                        </a>
                    </div>
                </div>

                <footer class="footer">
                    <strong>Haven Mart</strong> &middot; Secure Sales Management
                </footer>
            </section>
        </main>
    </body>
    </html>
    <?php
    exit;
}

if (!verify_csrf($_POST['csrf_token'] ?? null)) {
    checkout_error(
        'Your security session has expired or the request is invalid. Please return to the POS and try again.'
    );
}

$user = current_user();

if (!$user) {
    header('Location: ' . APP_URL . '/auth/login.php');
    exit;
}

$branchId = (int)($_POST['branch_id'] ?? 0);
$paymentMethod = strtoupper(trim((string)($_POST['payment_method'] ?? '')));
$cashReceived = (float)($_POST['cash_received'] ?? 0);
$mpesaReference = trim((string)($_POST['mpesa_reference'] ?? ''));
$overallDiscount = (float)($_POST['discount'] ?? 0);
$items = $_POST['items'] ?? [];

try {
    if ($branchId <= 0) {
        checkout_error('Please select a valid branch before completing the sale.');
    }

    if (!in_array($paymentMethod, ['CASH', 'MPESA'], true)) {
        checkout_error('Please select a valid payment method.');
    }

    if (!is_array($items) || !$items) {
        checkout_error('Your cart is empty. Add at least one product before completing the sale.');
    }

    if ($overallDiscount < 0) {
        checkout_error('The overall discount cannot be negative.');
    }

    if ($paymentMethod === 'CASH' && $cashReceived < 0) {
        checkout_error('The cash received amount is invalid.');
    }

    if ($paymentMethod === 'MPESA' && $mpesaReference === '') {
        checkout_error('An M-Pesa transaction code is required.');
    }

    $userBranchId = (int)($user['branch_id'] ?? 0);
    $roleName = strtolower(trim((string)($user['role_name'] ?? '')));

    if (
        $roleName !== 'administrator' &&
        $userBranchId > 0 &&
        $userBranchId !== $branchId
    ) {
        checkout_error(
            'You are not authorized to process sales for the selected branch.'
        );
    }

    $cart = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $productId = (int)($item['product_id'] ?? 0);
        $quantity = (float)($item['quantity'] ?? 0);
        $discount = (float)($item['discount'] ?? 0);

        if ($productId <= 0) {
            checkout_error('An invalid product was detected in the cart.');
        }

        if ($quantity <= 0) {
            checkout_error('Product quantity must be greater than zero.');
        }

        if ($discount < 0) {
            checkout_error('An invalid product discount was detected.');
        }

        if (isset($cart[$productId])) {
            checkout_error('Duplicate product detected in the cart.');
        }

        $cart[$productId] = [
            'product_id' => $productId,
            'quantity' => $quantity,
            'discount' => $discount
        ];
    }

    if (!$cart) {
        checkout_error('Your cart is empty.');
    }

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

    if (!$branch) {
        throw new RuntimeException(
            'The selected branch could not be found.'
        );
    }

    if (strtoupper((string)$branch['status']) !== 'ACTIVE') {
        throw new RuntimeException(
            'The selected branch is inactive.'
        );
    }

    $productStmt = $pdo->prepare("
        SELECT
            p.id,
            p.name,
            p.sku,
            p.selling_price,
            p.status
        FROM products p
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

    $preparedItems = [];
    $subtotal = 0.00;
    $itemDiscountTotal = 0.00;

    foreach ($cart as $cartItem) {
        $productStmt->execute([
            $cartItem['product_id']
        ]);

        $product = $productStmt->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
            throw new RuntimeException(
                'One of the selected products no longer exists.'
            );
        }

        if (strtoupper((string)$product['status']) !== 'ACTIVE') {
            throw new RuntimeException(
                'Product "' .
                $product['name'] .
                '" is inactive.'
            );
        }

        $quantity = (float)$cartItem['quantity'];
        $unitPrice = round(
            (float)$product['selling_price'],
            2
        );

        $itemDiscount = round(
            (float)$cartItem['discount'],
            2
        );

        if ($unitPrice <= 0) {
            throw new RuntimeException(
                'Product "' .
                $product['name'] .
                '" has an invalid selling price.'
            );
        }

        $gross = round(
            $unitPrice * $quantity,
            2
        );

        if ($itemDiscount > $gross) {
            throw new RuntimeException(
                'Discount for "' .
                $product['name'] .
                '" cannot exceed the item value.'
            );
        }

        $lineTotal = round(
            $gross - $itemDiscount,
            2
        );

        $stockStmt->execute([
            $branchId,
            $product['id']
        ]);

        $stock = $stockStmt->fetch(PDO::FETCH_ASSOC);

        if (!$stock) {
            throw new RuntimeException(
                'No stock record exists for "' .
                $product['name'] .
                '" in the selected branch.'
            );
        }

        $quantityInStock = (float)$stock['quantity'];
        $reservedQuantity = (float)$stock['reserved_quantity'];
        $availableQuantity =
            $quantityInStock - $reservedQuantity;

        if ($quantity > $availableQuantity) {
            throw new RuntimeException(
                'Insufficient stock for "' .
                $product['name'] .
                '". Available: ' .
                rtrim(
                    rtrim(
                        number_format(
                            $availableQuantity,
                            3,
                            '.',
                            ''
                        ),
                        '0'
                    ),
                    '.'
                ) .
                '. Requested: ' .
                rtrim(
                    rtrim(
                        number_format(
                            $quantity,
                            3,
                            '.',
                            ''
                        ),
                        '0'
                    ),
                    '.'
                ) .
                '.'
            );
        }

        $subtotal = round(
            $subtotal + $gross,
            2
        );

        $itemDiscountTotal = round(
            $itemDiscountTotal + $itemDiscount,
            2
        );

        $preparedItems[] = [
            'product_id' => (int)$product['id'],
            'name' => $product['name'],
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'discount' => $itemDiscount,
            'total' => $lineTotal,
            'stock_id' => (int)$stock['id']
        ];
    }

    $overallDiscount = round(
        $overallDiscount,
        2
    );

    if ($overallDiscount > $subtotal) {
        throw new RuntimeException(
            'Overall discount cannot exceed the subtotal.'
        );
    }

    $totalDiscount = round(
        $itemDiscountTotal + $overallDiscount,
        2
    );

    $total = round(
        $subtotal - $totalDiscount,
        2
    );

    if ($total <= 0) {
        throw new RuntimeException(
            'Sale total must be greater than zero.'
        );
    }

    if (
        $paymentMethod === 'CASH' &&
        $cashReceived < $total
    ) {
        throw new RuntimeException(
            'Insufficient cash received. Amount required: KES ' .
            number_format($total, 2)
        );
    }

    if ($paymentMethod === 'MPESA') {
        $duplicateStmt = $pdo->prepare("
            SELECT id
            FROM payments
            WHERE payment_method = 'MPESA'
            AND reference_number = ?
            AND payment_status = 'COMPLETED'
            LIMIT 1
            FOR UPDATE
        ");

        $duplicateStmt->execute([
            $mpesaReference
        ]);

        if ($duplicateStmt->fetchColumn()) {
            throw new RuntimeException(
                'This M-Pesa transaction code has already been used.'
            );
        }
    }

    $invoiceNumber =
        'INV-' .
        date('YmdHis') .
        '-' .
        strtoupper(
            bin2hex(random_bytes(4))
        );

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
        ) VALUES (
            ?, ?, ?, ?, ?, ?,
            'PAID',
            'COMPLETED',
            NOW()
        )
    ");

    $saleStmt->execute([
        $invoiceNumber,
        $branchId,
        (int)$user['id'],
        $subtotal,
        $totalDiscount,
        $total
    ]);

    $saleId = (int)$pdo->lastInsertId();

    if ($saleId <= 0) {
        throw new RuntimeException(
            'The sale could not be created.'
        );
    }

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
        ) VALUES (
            ?, ?, ?, 'SALE', 'SALE', ?, ?, ?, NOW()
        )
    ");

    foreach ($preparedItems as $item) {
        $saleItemStmt->execute([
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
            throw new RuntimeException(
                'Stock changed while processing "' .
                $item['name'] .
                '". Please try again.'
            );
        }

        $movementStmt->execute([
            $item['product_id'],
            $branchId,
            -$item['quantity'],
            $saleId,
            'POS sale ' . $invoiceNumber,
            (int)$user['id']
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
        ) VALUES (
            ?, ?, ?, ?, 'COMPLETED', NOW(), ?
        )
    ");

    $paymentStmt->execute([
        $saleId,
        $paymentMethod,
        $total,
        $paymentMethod === 'MPESA'
            ? $mpesaReference
            : null,
        (int)$user['id']
    ]);

    $pdo->commit();

    $change = 0.00;

    if ($paymentMethod === 'CASH') {
        $change = round(
            $cashReceived - $total,
            2
        );
    }

    $message =
        'Sale completed successfully. Invoice: ' .
        $invoiceNumber;

    if ($paymentMethod === 'CASH') {
        $message .=
            ' | Change: KES ' .
            number_format(
                $change,
                2
            );
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

    checkout_error(
        $e->getMessage()
    );
}
