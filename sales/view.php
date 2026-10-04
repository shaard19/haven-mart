<?php
require_once __DIR__ . '/../includes/auth.php';

require_login();
require_permission('sales.view');

$sale_id = (int)($_GET['id'] ?? 0);

if ($sale_id <= 0) {
    header('Location: ' . APP_URL . '/sales/');
    exit;
}

$saleStmt = $pdo->prepare("
    SELECT
        s.id,
        s.invoice_number,
        s.branch_id,
        s.cashier_id,
        s.subtotal,
        s.discount,
        s.total,
        s.payment_status,
        s.sale_status,
        s.sale_date,
        s.created_at,
        s.updated_at,
        b.name AS branch_name,
        b.code AS branch_code,
        b.location AS branch_location,
        b.phone AS branch_phone,
        u.full_name AS cashier_name,
        u.username AS cashier_username
    FROM sales s
    INNER JOIN branches b ON b.id = s.branch_id
    INNER JOIN users u ON u.id = s.cashier_id
    WHERE s.id = ?
    LIMIT 1
");
$saleStmt->execute([$sale_id]);
$sale = $saleStmt->fetch(PDO::FETCH_ASSOC);

if (!$sale) {
    header('Location: ' . APP_URL . '/sales/');
    exit;
}

$itemsStmt = $pdo->prepare("
    SELECT
        si.id,
        si.product_id,
        si.quantity,
        si.unit_price,
        si.discount,
        si.total,
        p.name AS product_name,
        p.sku,
        p.barcode,
        u.name AS unit_name
    FROM sale_items si
    INNER JOIN products p ON p.id = si.product_id
    INNER JOIN units u ON u.id = p.unit_id
    WHERE si.sale_id = ?
    ORDER BY si.id ASC
");
$itemsStmt->execute([$sale_id]);
$items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

$itemCount = count($items);
$totalQuantity = 0;

foreach ($items as $item) {
    $totalQuantity += (float)$item['quantity'];
}

$pageTitle = 'Sale ' . $sale['invoice_number'];

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="page-content">
    <div class="page-header">
        <div>
            <h1><i class="fas fa-file-invoice"></i> Sale Details</h1>
            <p>Transaction <?= e($sale['invoice_number']) ?></p>
        </div>

        <div class="page-actions">
            <a href="<?= APP_URL ?>/sales/" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Sales
            </a>

            <button type="button" class="btn btn-primary" onclick="window.print()">
                <i class="fas fa-print"></i> Print
            </button>
        </div>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon">
                <i class="fas fa-hashtag"></i>
            </div>
            <div class="stat-content">
                <span>Invoice</span>
                <strong><?= e($sale['invoice_number']) ?></strong>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">
                <i class="fas fa-box"></i>
            </div>
            <div class="stat-content">
                <span>Items</span>
                <strong><?= number_format($itemCount) ?></strong>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">
                <i class="fas fa-layer-group"></i>
            </div>
            <div class="stat-content">
                <span>Quantity</span>
                <strong><?= number_format($totalQuantity, 3) ?></strong>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">
                <i class="fas fa-money-bill-wave"></i>
            </div>
            <div class="stat-content">
                <span>Total</span>
                <strong><?= CURRENCY ?> <?= number_format((float)$sale['total'], 2) ?></strong>
            </div>
        </div>
    </div>

    <div class="content-grid">
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-receipt"></i> Sale Information</h2>
            </div>

            <div class="card-body">
                <div class="info-grid">
                    <div class="info-item">
                        <span class="info-label">Invoice Number</span>
                        <strong><?= e($sale['invoice_number']) ?></strong>
                    </div>

                    <div class="info-item">
                        <span class="info-label">Sale Date</span>
                        <strong><?= date('d M Y, H:i:s', strtotime($sale['sale_date'])) ?></strong>
                    </div>

                    <div class="info-item">
                        <span class="info-label">Branch</span>
                        <strong><?= e($sale['branch_name']) ?></strong>
                        <?php if ($sale['branch_code']): ?>
                            <small><?= e($sale['branch_code']) ?></small>
                        <?php endif; ?>
                    </div>

                    <div class="info-item">
                        <span class="info-label">Cashier</span>
                        <strong><?= e($sale['cashier_name']) ?></strong>
                        <small><?= e($sale['cashier_username']) ?></small>
                    </div>

                    <div class="info-item">
                        <span class="info-label">Payment Status</span>
                        <?php
                        $paymentClass = match ($sale['payment_status']) {
                            'PAID' => 'badge-success',
                            'PARTIAL' => 'badge-warning',
                            'REFUNDED' => 'badge-danger',
                            default => 'badge-secondary'
                        };
                        ?>
                        <span class="badge <?= $paymentClass ?>">
                            <?= e($sale['payment_status']) ?>
                        </span>
                    </div>

                    <div class="info-item">
                        <span class="info-label">Sale Status</span>
                        <?php
                        $statusClass = match ($sale['sale_status']) {
                            'COMPLETED' => 'badge-success',
                            'VOIDED' => 'badge-danger',
                            'RETURNED' => 'badge-warning',
                            default => 'badge-secondary'
                        };
                        ?>
                        <span class="badge <?= $statusClass ?>">
                            <?= e($sale['sale_status']) ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-store"></i> Branch Information</h2>
            </div>

            <div class="card-body">
                <div class="info-list">
                    <div class="info-list-item">
                        <span>Branch</span>
                        <strong><?= e($sale['branch_name']) ?></strong>
                    </div>

                    <?php if ($sale['branch_code']): ?>
                        <div class="info-list-item">
                            <span>Code</span>
                            <strong><?= e($sale['branch_code']) ?></strong>
                        </div>
                    <?php endif; ?>

                    <?php if ($sale['branch_location']): ?>
                        <div class="info-list-item">
                            <span>Location</span>
                            <strong><?= e($sale['branch_location']) ?></strong>
                        </div>
                    <?php endif; ?>

                    <?php if ($sale['branch_phone']): ?>
                        <div class="info-list-item">
                            <span>Phone</span>
                            <strong><?= e($sale['branch_phone']) ?></strong>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div>
                <h2><i class="fas fa-cart-shopping"></i> Sale Items</h2>
                <span class="card-subtitle">
                    <?= number_format($itemCount) ?> line<?= $itemCount === 1 ? '' : 's' ?>
                </span>
            </div>
        </div>

        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Product</th>
                        <th>SKU</th>
                        <th>Quantity</th>
                        <th>Unit Price</th>
                        <th>Discount</th>
                        <th>Total</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (!$items): ?>
                        <tr>
                            <td colspan="7" class="empty-state">
                                <i class="fas fa-box-open"></i>
                                <h3>No Items</h3>
                                <p>This sale has no recorded items.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($items as $index => $item): ?>
                            <tr>
                                <td><?= $index + 1 ?></td>

                                <td>
                                    <strong><?= e($item['product_name']) ?></strong>
                                    <?php if ($item['barcode']): ?>
                                        <small><?= e($item['barcode']) ?></small>
                                    <?php endif; ?>
                                </td>

                                <td><?= e($item['sku']) ?></td>

                                <td>
                                    <?= number_format((float)$item['quantity'], 3) ?>
                                    <?php if ($item['unit_name']): ?>
                                        <small><?= e($item['unit_name']) ?></small>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?= CURRENCY ?> <?= number_format((float)$item['unit_price'], 2) ?>
                                </td>

                                <td>
                                    <?= CURRENCY ?> <?= number_format((float)$item['discount'], 2) ?>
                                </td>

                                <td>
                                    <strong>
                                        <?= CURRENCY ?> <?= number_format((float)$item['total'], 2) ?>
                                    </strong>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="content-grid">
        <div></div>

        <div class="card totals-card">
            <div class="card-header">
                <h2><i class="fas fa-calculator"></i> Sale Summary</h2>
            </div>

            <div class="card-body">
                <div class="total-row">
                    <span>Subtotal</span>
                    <strong><?= CURRENCY ?> <?= number_format((float)$sale['subtotal'], 2) ?></strong>
                </div>

                <div class="total-row">
                    <span>Discount</span>
                    <strong><?= CURRENCY ?> <?= number_format((float)$sale['discount'], 2) ?></strong>
                </div>

                <div class="total-row total-grand">
                    <span>Grand Total</span>
                    <strong><?= CURRENCY ?> <?= number_format((float)$sale['total'], 2) ?></strong>
                </div>
            </div>
        </div>
    </div>

    <div class="card print-meta">
        <div class="card-body">
            <div class="info-grid">
                <div class="info-item">
                    <span class="info-label">Created</span>
                    <strong><?= date('d M Y, H:i:s', strtotime($sale['created_at'])) ?></strong>
                </div>

                <div class="info-item">
                    <span class="info-label">Last Updated</span>
                    <strong><?= date('d M Y, H:i:s', strtotime($sale['updated_at'])) ?></strong>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
@media print {
    .sidebar,
    .topbar,
    .page-actions,
    .btn,
    footer {
        display: none !important;
    }

    .page-content {
        margin: 0 !important;
        padding: 0 !important;
    }

    .card {
        box-shadow: none !important;
        border: 1px solid #ddd !important;
    }

    .print-meta {
        display: none !important;
    }
}
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>