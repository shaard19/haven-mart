<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();
require_permission('pos.access');

$saleId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$saleId) {
    header('Location: ' . APP_URL . '/pos/');
    exit;
}

$saleStmt = $pdo->prepare("
    SELECT
        s.id,
        s.invoice_number,
        s.subtotal,
        s.discount,
        s.total,
        s.payment_status,
        s.sale_status,
        s.sale_date,
        u.full_name AS cashier_name,
        b.name AS branch_name,
        b.location AS branch_location,
        b.phone AS branch_phone,
        b.email AS branch_email
    FROM sales s
    INNER JOIN users u ON u.id = s.cashier_id
    INNER JOIN branches b ON b.id = s.branch_id
    WHERE s.id = ?
    LIMIT 1
");

$saleStmt->execute([$saleId]);
$sale = $saleStmt->fetch();

if (!$sale) {
    http_response_code(404);
    exit('Sale not found.');
}

$itemStmt = $pdo->prepare("
    SELECT
        si.quantity,
        si.unit_price,
        si.discount,
        si.total,
        p.name AS product_name,
        p.sku
    FROM sale_items si
    INNER JOIN products p ON p.id = si.product_id
    WHERE si.sale_id = ?
    ORDER BY si.id ASC
");

$itemStmt->execute([$saleId]);
$items = $itemStmt->fetchAll();

$paymentStmt = $pdo->prepare("
    SELECT
        payment_method,
        amount,
        reference_number,
        payment_status,
        paid_at
    FROM payments
    WHERE sale_id = ?
    ORDER BY id ASC
");

$paymentStmt->execute([$saleId]);
$payments = $paymentStmt->fetchAll();

$totalPaid = 0.00;

foreach ($payments as $payment) {
    if ($payment['payment_status'] === 'COMPLETED') {
        $totalPaid += (float) $payment['amount'];
    }
}

$total = (float) $sale['total'];
$change = max(0, $totalPaid - $total);
$balance = max(0, $total - $totalPaid);

function receipt_money(float $amount): string
{
    return number_format($amount, 2);
}

function receipt_qty(float $quantity): string
{
    if (floor($quantity) === $quantity) {
        return number_format($quantity, 0);
    }

    return rtrim(rtrim(number_format($quantity, 3), '0'), '.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($sale['invoice_number']) ?> - Haven Mart Receipt</title>

    <style>
        * {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
            padding: 0;
            background: #eee;
            font-family: Arial, Helvetica, sans-serif;
            color: #000;
        }

        .receipt {
            width: 80mm;
            max-width: 80mm;
            margin: 20px auto;
            padding: 8px;
            background: #fff;
            font-size: 12px;
            line-height: 1.35;
        }

        .center {
            text-align: center;
        }

        .store-name {
            font-size: 21px;
            font-weight: 800;
            letter-spacing: 1px;
        }

        .store-tagline {
            margin-top: 2px;
            font-size: 11px;
        }

        .branch {
            margin-top: 5px;
            font-size: 11px;
        }

        .divider {
            border-top: 1px dashed #000;
            margin: 8px 0;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            gap: 10px;
        }

        .info-row span:last-child {
            text-align: right;
        }

        .items-header,
        .item-row {
            display: grid;
            grid-template-columns: 1fr 35px 75px;
            gap: 5px;
        }

        .items-header {
            font-weight: 700;
            border-bottom: 1px solid #000;
            padding-bottom: 4px;
        }

        .item-row {
            padding: 4px 0;
            align-items: start;
        }

        .item-name {
            overflow-wrap: anywhere;
        }

        .item-qty {
            text-align: center;
        }

        .item-total {
            text-align: right;
        }

        .totals {
            margin-top: 5px;
        }

        .total-row {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            padding: 2px 0;
        }

        .grand-total {
            font-size: 16px;
            font-weight: 800;
            padding: 5px 0;
        }

        .payment {
            margin-top: 4px;
        }

        .payment-row {
            display: flex;
            justify-content: space-between;
            gap: 8px;
            padding: 2px 0;
        }

        .payment-reference {
            font-size: 10px;
            word-break: break-all;
            text-align: right;
        }

        .footer {
            margin-top: 10px;
            text-align: center;
            font-size: 10px;
        }

        .developer {
            margin-top: 7px;
            text-align: center;
            font-size: 9px;
        }

        .print-button {
            display: block;
            width: 100%;
            margin: 15px auto 0;
            padding: 10px;
            border: 0;
            border-radius: 5px;
            background: #64131f;
            color: #fff;
            font-weight: 700;
            cursor: pointer;
        }

        @media print {
            @page {
                size: 80mm auto;
                margin: 0;
            }

            html,
            body {
                width: 80mm;
                margin: 0;
                padding: 0;
                background: #fff;
            }

            .receipt {
                width: 80mm;
                max-width: 80mm;
                margin: 0;
                padding: 4mm;
            }

            .print-button {
                display: none;
            }
        }

        @media screen and (max-width: 600px) {
            .receipt {
                margin: 0 auto;
            }
        }
    </style>
</head>
<body>

<div class="receipt">

    <div class="center">
        <div class="store-name">HAVEN MART</div>
        <div class="store-tagline">Quality • Value • Trust</div>

        <?php if (!empty($sale['branch_name'])): ?>
            <div class="branch"><?= e($sale['branch_name']) ?></div>
        <?php endif; ?>

        <?php if (!empty($sale['branch_location'])): ?>
            <div class="branch"><?= e($sale['branch_location']) ?></div>
        <?php endif; ?>

        <?php if (!empty($sale['branch_phone'])): ?>
            <div class="branch"><?= e($sale['branch_phone']) ?></div>
        <?php endif; ?>
    </div>

    <div class="divider"></div>

    <div class="info-row">
        <span>Receipt</span>
        <span><?= e($sale['invoice_number']) ?></span>
    </div>

    <div class="info-row">
        <span>Date</span>
        <span><?= date('d/m/Y H:i', strtotime($sale['sale_date'])) ?></span>
    </div>

    <div class="info-row">
        <span>Cashier</span>
        <span><?= e($sale['cashier_name']) ?></span>
    </div>

    <div class="divider"></div>

    <div class="items-header">
        <span>ITEM</span>
        <span>QTY</span>
        <span style="text-align:right;">TOTAL</span>
    </div>

    <?php foreach ($items as $item): ?>
        <div class="item-row">
            <div class="item-name">
                <?= e($item['product_name']) ?>
            </div>

            <div class="item-qty">
                <?= receipt_qty((float) $item['quantity']) ?>
            </div>

            <div class="item-total">
                <?= receipt_money((float) $item['total']) ?>
            </div>
        </div>
    <?php endforeach; ?>

    <div class="divider"></div>

    <div class="totals">

        <div class="total-row">
            <span>Subtotal</span>
            <span><?= receipt_money((float) $sale['subtotal']) ?></span>
        </div>

        <?php if ((float) $sale['discount'] > 0): ?>
            <div class="total-row">
                <span>Discount</span>
                <span>-<?= receipt_money((float) $sale['discount']) ?></span>
            </div>
        <?php endif; ?>

        <div class="total-row grand-total">
            <span>TOTAL</span>
            <span><?= receipt_money($total) ?></span>
        </div>

    </div>

    <div class="divider"></div>

    <div class="payment">

        <?php foreach ($payments as $payment): ?>

            <?php if ($payment['payment_status'] !== 'COMPLETED') {
                continue;
            } ?>

            <div class="payment-row">
                <span><?= e($payment['payment_method']) ?></span>
                <span><?= receipt_money((float) $payment['amount']) ?></span>
            </div>

            <?php if (!empty($payment['reference_number'])): ?>
                <div class="payment-row">
                    <span>Reference</span>
                    <span class="payment-reference">
                        <?= e($payment['reference_number']) ?>
                    </span>
                </div>
            <?php endif; ?>

        <?php endforeach; ?>

        <?php if ($change > 0): ?>
            <div class="payment-row">
                <strong>CHANGE</strong>
                <strong><?= receipt_money($change) ?></strong>
            </div>
        <?php elseif ($balance > 0): ?>
            <div class="payment-row">
                <strong>BALANCE</strong>
                <strong><?= receipt_money($balance) ?></strong>
            </div>
        <?php endif; ?>

    </div>

    <div class="divider"></div>

    <div class="footer">
        <strong>Thank you for shopping with Haven Mart!</strong><br>
        Please come again.
    </div>

    <div class="developer">
        Developed by Shadrack Kitele BSIT, ID_LABs
    </div>

    <button type="button" class="print-button" onclick="window.print()">
        PRINT RECEIPT
    </button>

</div>

<script>
    window.addEventListener('load', function () {
        setTimeout(function () {
            window.print();
        }, 400);
    });
</script>

</body>
</html>