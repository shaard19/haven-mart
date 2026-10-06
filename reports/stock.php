<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/bootstrap.php';

require_login();
require_permission('reports.view');

$user = $_SESSION['user'] ?? [];

$roleName = (string)($user['role_name'] ?? $user['role'] ?? '');
$branchId = isset($user['branch_id']) && (int)$user['branch_id'] > 0
    ? (int)$user['branch_id']
    : null;

$allBranches = in_array(
    strtolower($roleName),
    ['administrator', 'owner'],
    true
);

$statusFilter = strtoupper(trim((string)($_GET['status'] ?? 'ALL')));

if (!in_array($statusFilter, ['ALL', 'IN_STOCK', 'LOW_STOCK', 'OUT_OF_STOCK'], true)) {
    $statusFilter = 'ALL';
}

$search = trim((string)($_GET['search'] ?? ''));

$branchFilter = '';
$params = [];

if (!$allBranches && $branchId !== null) {
    $branchFilter = ' AND bs.branch_id = :branch_id ';
    $params[':branch_id'] = $branchId;
}

$searchFilter = '';

if ($search !== '') {
    $searchFilter = "
        AND (
            p.name LIKE :search
            OR p.sku LIKE :search
            OR p.barcode LIKE :search
        )
    ";
    $params[':search'] = '%' . $search . '%';
}

$summaryStmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(bs.quantity), 0) AS total_quantity,
        COALESCE(SUM(bs.quantity * p.cost_price), 0) AS stock_cost_value,
        COALESCE(SUM(bs.quantity * p.selling_price), 0) AS stock_selling_value,
        COALESCE(
            SUM(bs.quantity * (p.selling_price - p.cost_price)),
            0
        ) AS potential_profit,
        COUNT(DISTINCT bs.product_id) AS product_count,
        COUNT(
            DISTINCT CASE
                WHEN bs.quantity <= 0 THEN bs.product_id
            END
        ) AS out_of_stock,
        COUNT(
            DISTINCT CASE
                WHEN bs.quantity > 0
                 AND bs.quantity <= p.reorder_level
                THEN bs.product_id
            END
        ) AS low_stock
    FROM branch_stock bs
    INNER JOIN products p ON p.id = bs.product_id
    WHERE p.status = 'ACTIVE'
      {$branchFilter}
");

$summaryStmt->execute($params);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$totalQuantity = (float)($summary['total_quantity'] ?? 0);
$stockCostValue = (float)($summary['stock_cost_value'] ?? 0);
$stockSellingValue = (float)($summary['stock_selling_value'] ?? 0);
$potentialProfit = (float)($summary['potential_profit'] ?? 0);
$productCount = (int)($summary['product_count'] ?? 0);
$outOfStock = (int)($summary['out_of_stock'] ?? 0);
$lowStock = (int)($summary['low_stock'] ?? 0);

$potentialMargin = $stockSellingValue > 0
    ? ($potentialProfit / $stockSellingValue) * 100
    : 0;
$productParams = $params;

$statusWhere = '';

if ($statusFilter === 'IN_STOCK') {
    $statusWhere = '
        WHERE stock_data.stock_quantity > stock_data.reorder_level
    ';
} elseif ($statusFilter === 'LOW_STOCK') {
    $statusWhere = '
        WHERE stock_data.stock_quantity > 0
          AND stock_data.stock_quantity <= stock_data.reorder_level
    ';
} elseif ($statusFilter === 'OUT_OF_STOCK') {
    $statusWhere = '
        WHERE stock_data.stock_quantity <= 0
    ';
}

$productStmt = $pdo->prepare("
    SELECT
        stock_data.id,
        stock_data.sku,
        stock_data.barcode,
        stock_data.name,
        stock_data.cost_price,
        stock_data.selling_price,
        stock_data.reorder_level,
        stock_data.stock_quantity,
        stock_data.cost_value,
        stock_data.selling_value,
        stock_data.potential_profit
    FROM (
        SELECT
            p.id,
            p.sku,
            p.barcode,
            p.name,
            p.cost_price,
            p.selling_price,
            p.reorder_level,
            COALESCE(SUM(bs.quantity), 0) AS stock_quantity,
            COALESCE(
                SUM(bs.quantity * p.cost_price),
                0
            ) AS cost_value,
            COALESCE(
                SUM(bs.quantity * p.selling_price),
                0
            ) AS selling_value,
            COALESCE(
                SUM(
                    bs.quantity * (
                        p.selling_price - p.cost_price
                    )
                ),
                0
            ) AS potential_profit
        FROM products p
        LEFT JOIN branch_stock bs
            ON bs.product_id = p.id
            {$branchFilter}
        WHERE p.status = 'ACTIVE'
          {$searchFilter}
        GROUP BY
            p.id,
            p.sku,
            p.barcode,
            p.name,
            p.cost_price,
            p.selling_price,
            p.reorder_level
    ) AS stock_data

    {$statusWhere}

    ORDER BY
        CASE
            WHEN stock_data.stock_quantity <= 0 THEN 1
            WHEN stock_data.stock_quantity <= stock_data.reorder_level THEN 2
            ELSE 3
        END,
        stock_data.name ASC
");

$productStmt->execute($productParams);
$productRows = $productStmt->fetchAll(PDO::FETCH_ASSOC);
$branchRows = [];

if ($allBranches) {
    $branchStmt = $pdo->query("
        SELECT
            b.id,
            b.name,
            b.code,
            COUNT(DISTINCT CASE
                WHEN p.status = 'ACTIVE' THEN bs.product_id
            END) AS products,
            COALESCE(
                SUM(
                    CASE
                        WHEN p.status = 'ACTIVE'
                        THEN bs.quantity
                        ELSE 0
                    END
                ),
                0
            ) AS quantity,
            COALESCE(
                SUM(
                    CASE
                        WHEN p.status = 'ACTIVE'
                        THEN bs.quantity * p.cost_price
                        ELSE 0
                    END
                ),
                0
            ) AS cost_value,
            COALESCE(
                SUM(
                    CASE
                        WHEN p.status = 'ACTIVE'
                        THEN bs.quantity * p.selling_price
                        ELSE 0
                    END
                ),
                0
            ) AS selling_value
        FROM branches b
        LEFT JOIN branch_stock bs
            ON bs.branch_id = b.id
        LEFT JOIN products p
            ON p.id = bs.product_id
        WHERE b.status = 'ACTIVE'
        GROUP BY
            b.id,
            b.name,
            b.code
        ORDER BY
            b.name ASC
    ");

    $branchRows = $branchStmt->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle = 'Stock Report';

require_once __DIR__ . '/../includes/header.php';
?>

<style>
.stock-report {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.report-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: end;
    gap: 16px;
    flex-wrap: wrap;
}

.report-filters {
    display: flex;
    align-items: end;
    gap: 12px;
    flex-wrap: wrap;
}

.report-field {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.report-field label {
    font-size: 13px;
    font-weight: 700;
}

.report-field input,
.report-field select {
    min-width: 170px;
}

.report-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.stock-stat {
    border-left: 4px solid var(--primary-color, #64131f);
}

.stock-stat.warning {
    border-left-color: #f2c94c;
}

.stock-stat.danger {
    border-left-color: #dc3545;
}

.stock-stat.success {
    border-left-color: #198754;
}

.report-table-wrap {
    overflow-x: auto;
}

.report-table {
    width: 100%;
    border-collapse: collapse;
}

.report-table th,
.report-table td {
    padding: 12px 10px;
    border-bottom: 1px solid #eee;
    text-align: left;
    white-space: nowrap;
}

.report-table th {
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: .04em;
}

.text-right {
    text-align: right !important;
}

.status-badge {
    display: inline-flex;
    align-items: center;
    padding: 5px 9px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
}

.status-in {
    background: #e8f7ee;
    color: #198754;
}

.status-low {
    background: #fff6d8;
    color: #8a6d00;
}

.status-out {
    background: #fdeaea;
    color: #dc3545;
}

.profit-positive {
    color: #198754;
    font-weight: 700;
}

.report-nav {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.report-nav a {
    text-decoration: none;
}

@media print {
    .report-toolbar,
    .report-nav,
    .sidebar,
    .topbar,
    .no-print,
    footer {
        display: none !important;
    }

    .main-content {
        margin: 0 !important;
        padding: 0 !important;
    }

    .panel,
    .stat-card {
        box-shadow: none !important;
        border: 1px solid #ddd !important;
    }
}
</style>

<div class="stock-report">

    <div class="welcome-card">
        <div>
            <h1>Stock Report</h1>
            <p>Current inventory levels, stock value and products requiring attention.</p>
        </div>
    </div>

    <div class="panel report-toolbar no-print">
        <form method="get" class="report-filters">

            <div class="report-field">
                <label for="search">Search</label>
                <input
                    type="text"
                    id="search"
                    name="search"
                    value="<?= e($search) ?>"
                    class="form-control"
                    placeholder="Name, SKU or barcode"
                >
            </div>

            <div class="report-field">
                <label for="status">Stock Status</label>
                <select id="status" name="status" class="form-control">
                    <option value="ALL" <?= $statusFilter === 'ALL' ? 'selected' : '' ?>>
                        All Stock
                    </option>
                    <option value="IN_STOCK" <?= $statusFilter === 'IN_STOCK' ? 'selected' : '' ?>>
                        In Stock
                    </option>
                    <option value="LOW_STOCK" <?= $statusFilter === 'LOW_STOCK' ? 'selected' : '' ?>>
                        Low Stock
                    </option>
                    <option value="OUT_OF_STOCK" <?= $statusFilter === 'OUT_OF_STOCK' ? 'selected' : '' ?>>
                        Out of Stock
                    </option>
                </select>
            </div>

            <button type="submit" class="btn btn-primary">
                Filter
            </button>

            <a
                href="<?= e(APP_URL) ?>/reports/stock.php"
                class="btn btn-secondary"
            >
                Reset
            </a>
        </form>

        <div class="report-actions">
            <button type="button" class="btn btn-secondary" onclick="window.print()">
                Print
            </button>
        </div>
    </div>

    <div class="report-nav no-print">
        <a href="<?= e(APP_URL) ?>/reports/" class="btn btn-secondary">Reports</a>
        <a href="<?= e(APP_URL) ?>/reports/sales.php" class="btn btn-secondary">Sales</a>
        <a href="<?= e(APP_URL) ?>/reports/products.php" class="btn btn-secondary">Product Sales</a>
        <a href="<?= e(APP_URL) ?>/reports/profit.php" class="btn btn-secondary">Profit</a>
        <a href="<?= e(APP_URL) ?>/reports/expenses.php" class="btn btn-secondary">Expenses</a>
    </div>

    <div class="stats-grid">

        <div class="stat-card">
            <div class="stat-label">Active Products</div>
            <div class="stat-value">
                <?= number_format($productCount) ?>
            </div>
            <div class="stat-meta">
                Products with stock records
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-label">Stock Quantity</div>
            <div class="stat-value">
                <?= number_format($totalQuantity, 3) ?>
            </div>
            <div class="stat-meta">
                Current inventory
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-label">Stock Cost Value</div>
            <div class="stat-value">
                KES <?= number_format($stockCostValue, 2) ?>
            </div>
            <div class="stat-meta">
                At current cost price
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-label">Selling Value</div>
            <div class="stat-value">
                KES <?= number_format($stockSellingValue, 2) ?>
            </div>
            <div class="stat-meta">
                If all stock is sold
            </div>
        </div>

        <div class="stat-card stock-stat success">
            <div class="stat-label">Potential Profit</div>
            <div class="stat-value">
                KES <?= number_format($potentialProfit, 2) ?>
            </div>
            <div class="stat-meta">
                <?= number_format($potentialMargin, 2) ?>% potential margin
            </div>
        </div>

        <div class="stat-card stock-stat warning">
            <div class="stat-label">Low Stock</div>
            <div class="stat-value">
                <?= number_format($lowStock) ?>
            </div>
            <div class="stat-meta">
                Products at reorder level
            </div>
        </div>

        <div class="stat-card stock-stat danger">
            <div class="stat-label">Out of Stock</div>
            <div class="stat-value">
                <?= number_format($outOfStock) ?>
            </div>
            <div class="stat-meta">
                Products requiring replenishment
            </div>
        </div>

    </div>

    <div class="panel">
        <div class="panel-header">
            <div>
                <h2>Current Stock</h2>
                <p>
                    <?php if ($statusFilter === 'ALL'): ?>
                        All active products
                    <?php else: ?>
                        <?= e(str_replace('_', ' ', $statusFilter)) ?>
                    <?php endif; ?>

                    <?php if ($search !== ''): ?>
                        matching "<?= e($search) ?>"
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <div class="report-table-wrap">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>SKU</th>
                        <th>Barcode</th>
                        <th class="text-right">Stock</th>
                        <th class="text-right">Reorder</th>
                        <th>Status</th>
                        <th class="text-right">Cost Value</th>
                        <th class="text-right">Selling Value</th>
                        <th class="text-right">Potential Profit</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (!$productRows): ?>
                    <tr>
                        <td colspan="9">
                            No stock records found for the selected filters.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($productRows as $row): ?>
                        <?php
                        $quantity = (float)$row['stock_quantity'];
                        $reorderLevel = (float)$row['reorder_level'];

                        if ($quantity <= 0) {
                            $stockStatus = 'OUT OF STOCK';
                            $statusClass = 'status-out';
                        } elseif ($quantity <= $reorderLevel) {
                            $stockStatus = 'LOW STOCK';
                            $statusClass = 'status-low';
                        } else {
                            $stockStatus = 'IN STOCK';
                            $statusClass = 'status-in';
                        }
                        ?>

                        <tr>
                            <td>
                                <strong><?= e($row['name']) ?></strong>
                            </td>

                            <td><?= e($row['sku']) ?></td>

                            <td>
                                <?= e((string)($row['barcode'] ?? '—')) ?>
                            </td>

                            <td class="text-right">
                                <?= number_format($quantity, 3) ?>
                            </td>

                            <td class="text-right">
                                <?= number_format($reorderLevel, 3) ?>
                            </td>

                            <td>
                                <span class="status-badge <?= e($statusClass) ?>">
                                    <?= e($stockStatus) ?>
                                </span>
                            </td>

                            <td class="text-right">
                                KES <?= number_format((float)$row['cost_value'], 2) ?>
                            </td>

                            <td class="text-right">
                                KES <?= number_format((float)$row['selling_value'], 2) ?>
                            </td>

                            <td class="text-right profit-positive">
                                KES <?= number_format((float)$row['potential_profit'], 2) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($allBranches): ?>

        <div class="panel">
            <div class="panel-header">
                <div>
                    <h2>Branch Stock Value</h2>
                    <p>Current inventory position across active branches.</p>
                </div>
            </div>

            <div class="report-table-wrap">
                <table class="report-table">
                    <thead>
                        <tr>
                            <th>Branch</th>
                            <th>Code</th>
                            <th>Products</th>
                            <th class="text-right">Quantity</th>
                            <th class="text-right">Cost Value</th>
                            <th class="text-right">Selling Value</th>
                            <th class="text-right">Potential Profit</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php if (!$branchRows): ?>
                        <tr>
                            <td colspan="7">
                                No branch stock data found.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($branchRows as $row): ?>
                            <?php
                            $branchCost = (float)$row['cost_value'];
                            $branchSelling = (float)$row['selling_value'];
                            $branchProfit = $branchSelling - $branchCost;
                            ?>

                            <tr>
                                <td>
                                    <strong><?= e($row['name']) ?></strong>
                                </td>

                                <td><?= e($row['code']) ?></td>

                                <td>
                                    <?= number_format((int)$row['products']) ?>
                                </td>

                                <td class="text-right">
                                    <?= number_format((float)$row['quantity'], 3) ?>
                                </td>

                                <td class="text-right">
                                    KES <?= number_format($branchCost, 2) ?>
                                </td>

                                <td class="text-right">
                                    KES <?= number_format($branchSelling, 2) ?>
                                </td>

                                <td class="text-right profit-positive">
                                    KES <?= number_format($branchProfit, 2) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php endif; ?>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>