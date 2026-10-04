<?php
require_once __DIR__ . '/../includes/auth.php';

require_login();
require_permission('inventory.view');

$product_id = (int)($_GET['product_id'] ?? 0);
$branch_id = (int)($_GET['branch_id'] ?? 0);

if ($product_id <= 0) {
    header('Location: ' . APP_URL . '/inventory/');
    exit;
}

$stmt = $pdo->prepare("
    SELECT
        p.id,
        p.category_id,
        p.unit_id,
        p.sku,
        p.barcode,
        p.name,
        p.description,
        p.cost_price,
        p.selling_price,
        p.reorder_level,
        p.status,
        p.created_at,
        p.updated_at,
        c.name AS category_name,
        u.name AS unit_name,
        u.short_name AS unit_short_name
    FROM products p
    INNER JOIN categories c ON c.id = p.category_id
    INNER JOIN units u ON u.id = p.unit_id
    WHERE p.id = :product_id
    LIMIT 1
");

$stmt->execute([
    'product_id' => $product_id
]);

$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    header('Location: ' . APP_URL . '/inventory/');
    exit;
}

$stock_where = [
    'bs.product_id = :product_id'
];

$stock_params = [
    'product_id' => $product_id
];

if ($branch_id > 0) {
    $stock_where[] = 'bs.branch_id = :branch_id';
    $stock_params['branch_id'] = $branch_id;
}

$stock_where_sql = implode(' AND ', $stock_where);

$stock_stmt = $pdo->prepare("
    SELECT
        b.id AS branch_id,
        b.name AS branch_name,
        b.code AS branch_code,
        COALESCE(SUM(bs.quantity), 0) AS quantity,
        COALESCE(SUM(bs.reserved_quantity), 0) AS reserved_quantity,
        GREATEST(
            COALESCE(SUM(bs.quantity), 0) - COALESCE(SUM(bs.reserved_quantity), 0),
            0
        ) AS available_quantity
    FROM branch_stock bs
    INNER JOIN branches b ON b.id = bs.branch_id
    WHERE {$stock_where_sql}
    GROUP BY
        b.id,
        b.name,
        b.code
    ORDER BY b.name ASC
");

$stock_stmt->execute($stock_params);
$branch_stock = $stock_stmt->fetchAll(PDO::FETCH_ASSOC);

$total_quantity = 0;
$total_reserved = 0;
$total_available = 0;

foreach ($branch_stock as $stock) {
    $total_quantity += (float)$stock['quantity'];
    $total_reserved += (float)$stock['reserved_quantity'];
    $total_available += (float)$stock['available_quantity'];
}

$reorder_level = (float)$product['reorder_level'];

if ($total_quantity <= 0) {
    $stock_status = 'OUT OF STOCK';
    $stock_class = 'status-out';
    $stock_icon = 'fa-circle-xmark';
} elseif ($total_quantity <= $reorder_level) {
    $stock_status = 'LOW STOCK';
    $stock_class = 'status-low';
    $stock_icon = 'fa-triangle-exclamation';
} else {
    $stock_status = 'IN STOCK';
    $stock_class = 'status-in';
    $stock_icon = 'fa-circle-check';
}

$branches = $pdo->query("
    SELECT id, name, code
    FROM branches
    WHERE status = 'ACTIVE'
    ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$movement_stmt = $pdo->prepare("
    SELECT
        sm.id,
        sm.quantity,
        sm.movement_type,
        sm.reference_type,
        sm.reference_id,
        sm.notes,
        sm.created_at,
        b.name AS branch_name,
        b.code AS branch_code
    FROM stock_movements sm
    INNER JOIN branches b ON b.id = sm.branch_id
    WHERE sm.product_id = :product_id
    ORDER BY sm.created_at DESC, sm.id DESC
    LIMIT 10
");

$movement_stmt->execute([
    'product_id' => $product_id
]);

$movements = $movement_stmt->fetchAll(PDO::FETCH_ASSOC);

function movement_label(string $type): string
{
    return ucwords(strtolower(str_replace(['_', '-'], ' ', $type)));
}

function movement_class(string $type): string
{
    $type = strtoupper($type);

    if (in_array($type, ['IN', 'PURCHASE', 'RECEIPT', 'RECEIVED', 'TRANSFER_IN', 'ADJUSTMENT_IN'], true)) {
        return 'movement-in';
    }

    if (in_array($type, ['OUT', 'SALE', 'TRANSFER_OUT', 'ADJUSTMENT_OUT'], true)) {
        return 'movement-out';
    }

    return 'movement-neutral';
}

require_once __DIR__ . '/../includes/header.php';
?>

<style>
.inventory-view {
    max-width: 1400px;
    margin: 0 auto;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 18px;
    margin-bottom: 22px;
}

.page-header-left {
    display: flex;
    align-items: center;
    gap: 14px;
}

.back-btn {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #f5efec;
    color: #64131f;
    text-decoration: none;
}

.back-btn:hover {
    background: #fff4c7;
}

.page-title h1 {
    margin: 0 0 5px;
    color: #64131f;
    font-size: 25px;
}

.page-title p {
    margin: 0;
    color: #888;
    font-size: 13px;
}

.page-actions {
    display: flex;
    gap: 9px;
    flex-wrap: wrap;
}

.action-link {
    height: 40px;
    padding: 0 14px;
    border-radius: 9px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    text-decoration: none;
    font-size: 12px;
    font-weight: 700;
}

.action-primary {
    background: #64131f;
    color: #fff;
}

.action-primary:hover {
    background: #4d0e17;
}

.action-secondary {
    background: #fff4c7;
    color: #64131f;
    border: 1px solid #ead58b;
}

.action-secondary:hover {
    background: #f2c94c;
}

.product-overview {
    display: grid;
    grid-template-columns: 1.7fr 1fr;
    gap: 18px;
    margin-bottom: 18px;
}

.product-card,
.summary-card,
.stock-card,
.movement-card {
    background: #fff;
    border: 1px solid #eadfda;
    border-radius: 14px;
    box-shadow: 0 5px 18px rgba(0,0,0,.04);
}

.product-card {
    padding: 22px;
}

.product-heading {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 15px;
}

.product-name {
    margin: 0 0 7px;
    color: #64131f;
    font-size: 23px;
    font-weight: 800;
}

.product-meta {
    color: #888;
    font-size: 12px;
    line-height: 1.7;
}

.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 11px;
    border-radius: 20px;
    font-size: 10px;
    font-weight: 800;
    white-space: nowrap;
}

.status-in {
    background: #e8f5ec;
    color: #24703c;
}

.status-low {
    background: #fff4c7;
    color: #8a6200;
}

.status-out {
    background: #fbe5e8;
    color: #a51f35;
}

.product-description {
    margin-top: 18px;
    padding-top: 17px;
    border-top: 1px solid #eee5e1;
    color: #666;
    font-size: 13px;
    line-height: 1.6;
}

.product-details {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
    margin-top: 20px;
}

.detail-item {
    padding: 12px;
    border-radius: 10px;
    background: #faf7f5;
}

.detail-label {
    color: #999;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .4px;
    margin-bottom: 5px;
}

.detail-value {
    color: #444;
    font-size: 13px;
    font-weight: 700;
}

.summary-card {
    padding: 20px;
}

.summary-title {
    margin-bottom: 15px;
    color: #555;
    font-size: 14px;
    font-weight: 800;
}

.summary-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
}

.summary-item {
    padding: 14px;
    border-radius: 11px;
    background: #faf7f5;
}

.summary-label {
    color: #888;
    font-size: 11px;
    margin-bottom: 6px;
}

.summary-value {
    color: #333;
    font-size: 21px;
    font-weight: 800;
}

.summary-unit {
    color: #999;
    font-size: 10px;
    margin-left: 3px;
}

.stock-section,
.movement-section {
    margin-top: 18px;
}

.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    margin-bottom: 12px;
}

.section-title {
    color: #444;
    font-size: 16px;
    font-weight: 800;
}

.section-subtitle {
    color: #999;
    font-size: 12px;
}

.stock-card {
    overflow: hidden;
}

.stock-table-wrap,
.movement-table-wrap {
    overflow-x: auto;
}

.stock-table,
.movement-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 800px;
}

.stock-table th,
.movement-table th {
    background: #faf7f5;
    color: #666;
    padding: 13px 15px;
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: .4px;
    text-align: left;
    white-space: nowrap;
    border-bottom: 1px solid #eadfda;
}

.stock-table td,
.movement-table td {
    padding: 14px 15px;
    border-bottom: 1px solid #f0e9e6;
    color: #444;
    font-size: 13px;
}

.stock-table tbody tr:last-child td,
.movement-table tbody tr:last-child td {
    border-bottom: 0;
}

.stock-table tbody tr:hover,
.movement-table tbody tr:hover {
    background: #fffdfb;
}

.branch-name {
    font-weight: 800;
    color: #555;
}

.branch-code {
    color: #999;
    font-size: 10px;
    margin-top: 3px;
}

.quantity {
    font-weight: 800;
    color: #333;
}

.quantity-unit {
    color: #999;
    font-size: 10px;
    margin-left: 3px;
}

.reorder-warning {
    color: #a51f35;
    font-weight: 700;
}

.movement-card {
    overflow: hidden;
}

.movement-in {
    color: #24703c;
    font-weight: 800;
}

.movement-out {
    color: #a51f35;
    font-weight: 800;
}

.movement-neutral {
    color: #8a6040;
    font-weight: 800;
}

.movement-type {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.reference {
    color: #777;
    font-size: 11px;
}

.movement-date {
    color: #777;
    white-space: nowrap;
}

.empty-state {
    padding: 42px 20px;
    text-align: center;
    color: #888;
}

.empty-state i {
    color: #d7cbc5;
    font-size: 36px;
    margin-bottom: 12px;
}

.empty-state h3 {
    margin: 0 0 5px;
    color: #555;
    font-size: 16px;
}

.empty-state p {
    margin: 0;
    font-size: 12px;
}

@media (max-width: 950px) {
    .product-overview {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 650px) {
    .page-header {
        align-items: flex-start;
    }

    .page-header,
    .page-header-left {
        flex-wrap: wrap;
    }

    .product-heading {
        flex-direction: column;
    }

    .product-details {
        grid-template-columns: repeat(2, 1fr);
    }

    .summary-grid {
        grid-template-columns: 1fr 1fr;
    }
}
</style>

<div class="inventory-view">

    <div class="page-header">

        <div class="page-header-left">

            <a href="<?= APP_URL ?>/inventory/" class="back-btn" title="Back to Inventory">
                <i class="fas fa-arrow-left"></i>
            </a>

            <div class="page-title">
                <h1>Stock Details</h1>
                <p>View inventory information for this product.</p>
            </div>

        </div>

        <div class="page-actions">

            <a
                href="<?= APP_URL ?>/inventory/movements.php?product_id=<?= (int)$product['id'] ?>"
                class="action-link action-secondary"
            >
                <i class="fas fa-clock-rotate-left"></i>
                Movements
            </a>

            <a
                href="<?= APP_URL ?>/inventory/adjust.php?product_id=<?= (int)$product['id'] ?>"
                class="action-link action-primary"
            >
                <i class="fas fa-sliders"></i>
                Adjust Stock
            </a>

        </div>

    </div>

    <div class="product-overview">

        <div class="product-card">

            <div class="product-heading">

                <div>
                    <h2 class="product-name">
                        <?= e($product['name']) ?>
                    </h2>

                    <div class="product-meta">
                        SKU: <?= e($product['sku']) ?>

                        <?php if (!empty($product['barcode'])): ?>
                            &nbsp;·&nbsp;
                            Barcode: <?= e($product['barcode']) ?>
                        <?php endif; ?>

                        <br>

                        Category: <?= e($product['category_name']) ?>
                        &nbsp;·&nbsp;
                        Unit: <?= e($product['unit_name']) ?>
                    </div>
                </div>

                <span class="status-badge <?= e($stock_class) ?>">
                    <i class="fas <?= e($stock_icon) ?>"></i>
                    <?= e($stock_status) ?>
                </span>

            </div>

            <?php if (!empty($product['description'])): ?>

                <div class="product-description">
                    <?= nl2br(e($product['description'])) ?>
                </div>

            <?php endif; ?>

            <div class="product-details">

                <div class="detail-item">
                    <div class="detail-label">Cost Price</div>
                    <div class="detail-value">
                        <?= number_format((float)$product['cost_price'], 2) ?>
                    </div>
                </div>

                <div class="detail-item">
                    <div class="detail-label">Selling Price</div>
                    <div class="detail-value">
                        <?= number_format((float)$product['selling_price'], 2) ?>
                    </div>
                </div>

                <div class="detail-item">
                    <div class="detail-label">Reorder Level</div>
                    <div class="detail-value">
                        <?= number_format($reorder_level, 3) ?>
                    </div>
                </div>

                <div class="detail-item">
                    <div class="detail-label">Product Status</div>
                    <div class="detail-value">
                        <?= e($product['status']) ?>
                    </div>
                </div>

                <div class="detail-item">
                    <div class="detail-label">Created</div>
                    <div class="detail-value">
                        <?= e(date('d M Y', strtotime($product['created_at']))) ?>
                    </div>
                </div>

                <div class="detail-item">
                    <div class="detail-label">Last Updated</div>
                    <div class="detail-value">
                        <?= e(date('d M Y', strtotime($product['updated_at']))) ?>
                    </div>
                </div>

            </div>

        </div>

        <div class="summary-card">

            <div class="summary-title">
                Overall Stock
            </div>

            <div class="summary-grid">

                <div class="summary-item">
                    <div class="summary-label">Total Quantity</div>
                    <div class="summary-value">
                        <?= number_format($total_quantity, 3) ?>
                        <span class="summary-unit">
                            <?= e($product['unit_short_name']) ?>
                        </span>
                    </div>
                </div>

                <div class="summary-item">
                    <div class="summary-label">Reserved</div>
                    <div class="summary-value">
                        <?= number_format($total_reserved, 3) ?>
                        <span class="summary-unit">
                            <?= e($product['unit_short_name']) ?>
                        </span>
                    </div>
                </div>

                <div class="summary-item">
                    <div class="summary-label">Available</div>
                    <div class="summary-value">
                        <?= number_format($total_available, 3) ?>
                        <span class="summary-unit">
                            <?= e($product['unit_short_name']) ?>
                        </span>
                    </div>
                </div>

                <div class="summary-item">
                    <div class="summary-label">Reorder Level</div>
                    <div class="summary-value">
                        <?= number_format($reorder_level, 3) ?>
                        <span class="summary-unit">
                            <?= e($product['unit_short_name']) ?>
                        </span>
                    </div>
                </div>

            </div>

        </div>

    </div>

    <div class="stock-section">

        <div class="section-header">
            <div>
                <div class="section-title">
                    Stock by Branch
                </div>
                <div class="section-subtitle">
                    Current stock distribution across branches.
                </div>
            </div>
        </div>

        <div class="stock-card">

            <?php if (!$branch_stock): ?>

                <div class="empty-state">
                    <i class="fas fa-box-open"></i>
                    <h3>No stock records</h3>
                    <p>This product currently has no stock record for the selected branch.</p>
                </div>

            <?php else: ?>

                <div class="stock-table-wrap">

                    <table class="stock-table">

                        <thead>
                            <tr>
                                <th>Branch</th>
                                <th>Quantity</th>
                                <th>Reserved</th>
                                <th>Available</th>
                                <th>Reorder Level</th>
                                <th>Status</th>
                            </tr>
                        </thead>

                        <tbody>

                        <?php foreach ($branch_stock as $stock): ?>

                            <?php
                            $quantity = (float)$stock['quantity'];
                            $reserved = (float)$stock['reserved_quantity'];
                            $available = (float)$stock['available_quantity'];

                            if ($quantity <= 0) {
                                $branch_status = 'OUT OF STOCK';
                                $branch_class = 'status-out';
                                $branch_icon = 'fa-circle-xmark';
                            } elseif ($quantity <= $reorder_level) {
                                $branch_status = 'LOW STOCK';
                                $branch_class = 'status-low';
                                $branch_icon = 'fa-triangle-exclamation';
                            } else {
                                $branch_status = 'IN STOCK';
                                $branch_class = 'status-in';
                                $branch_icon = 'fa-circle-check';
                            }
                            ?>

                            <tr>

                                <td>
                                    <div class="branch-name">
                                        <?= e($stock['branch_name']) ?>
                                    </div>

                                    <?php if (!empty($stock['branch_code'])): ?>
                                        <div class="branch-code">
                                            <?= e($stock['branch_code']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <span class="quantity">
                                        <?= number_format($quantity, 3) ?>
                                    </span>
                                    <span class="quantity-unit">
                                        <?= e($product['unit_short_name']) ?>
                                    </span>
                                </td>

                                <td>
                                    <?= number_format($reserved, 3) ?>
                                </td>

                                <td>
                                    <span class="quantity">
                                        <?= number_format($available, 3) ?>
                                    </span>
                                </td>

                                <td class="<?= $quantity <= $reorder_level ? 'reorder-warning' : '' ?>">
                                    <?= number_format($reorder_level, 3) ?>
                                </td>

                                <td>
                                    <span class="status-badge <?= e($branch_class) ?>">
                                        <i class="fas <?= e($branch_icon) ?>"></i>
                                        <?= e($branch_status) ?>
                                    </span>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php endif; ?>

        </div>

    </div>

    <div class="movement-section">

        <div class="section-header">

            <div>
                <div class="section-title">
                    Recent Stock Movements
                </div>
                <div class="section-subtitle">
                    Latest inventory activity for this product.
                </div>
            </div>

            <a
                href="<?= APP_URL ?>/inventory/movements.php?product_id=<?= (int)$product['id'] ?>"
                class="action-link action-secondary"
            >
                View All
            </a>

        </div>

        <div class="movement-card">

            <?php if (!$movements): ?>

                <div class="empty-state">
                    <i class="fas fa-clock-rotate-left"></i>
                    <h3>No movements recorded</h3>
                    <p>Stock movements for this product will appear here.</p>
                </div>

            <?php else: ?>

                <div class="movement-table-wrap">

                    <table class="movement-table">

                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Branch</th>
                                <th>Movement</th>
                                <th>Quantity</th>
                                <th>Reference</th>
                                <th>Notes</th>
                            </tr>
                        </thead>

                        <tbody>

                        <?php foreach ($movements as $movement): ?>

                            <?php
                            $type = strtoupper((string)$movement['movement_type']);
                            $movement_css = movement_class($type);
                            ?>

                            <tr>

                                <td>
                                    <span class="movement-date">
                                        <?= e(date('d M Y H:i', strtotime($movement['created_at']))) ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="branch-name">
                                        <?= e($movement['branch_name']) ?>
                                    </div>

                                    <?php if (!empty($movement['branch_code'])): ?>
                                        <div class="branch-code">
                                            <?= e($movement['branch_code']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <span class="movement-type <?= e($movement_css) ?>">
                                        <?php if ($movement_css === 'movement-in'): ?>
                                            <i class="fas fa-arrow-down"></i>
                                        <?php elseif ($movement_css === 'movement-out'): ?>
                                            <i class="fas fa-arrow-up"></i>
                                        <?php else: ?>
                                            <i class="fas fa-right-left"></i>
                                        <?php endif; ?>

                                        <?= e(movement_label($type)) ?>
                                    </span>
                                </td>

                                <td>
                                    <strong>
                                        <?= number_format((float)$movement['quantity'], 3) ?>
                                    </strong>
                                </td>

                                <td>
                                    <span class="reference">
                                        <?php if (!empty($movement['reference_type'])): ?>
                                            <?= e($movement['reference_type']) ?>

                                            <?php if (!empty($movement['reference_id'])): ?>
                                                #<?= (int)$movement['reference_id'] ?>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="reference">
                                        <?= !empty($movement['notes']) ? e($movement['notes']) : '—' ?>
                                    </span>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php endif; ?>

        </div>

    </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>