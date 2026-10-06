<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();
require_permission('reports.view');

$page_title = 'Product Sales Report';

$from = $_GET['from'] ?? date('Y-m-d');
$to = $_GET['to'] ?? date('Y-m-d');
$selectedBranch = isset($_GET['branch_id']) ? (int) $_GET['branch_id'] : 0;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $from = date('Y-m-d');
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $to = date('Y-m-d');
}

if ($from > $to) {
    [$from, $to] = [$to, $from];
}

$roleName = strtolower((string) ($user['role_name'] ?? $user['role'] ?? ''));
$allBranches = in_array($roleName, ['administrator', 'owner'], true);
$userBranchId = isset($user['branch_id']) ? (int) $user['branch_id'] : null;

if (!$allBranches) {
    $selectedBranch = $userBranchId ?: 0;
}

$branches = [];

if ($allBranches) {
    $branchStmt = $pdo->query("
        SELECT id, name
        FROM branches
        WHERE status = 'ACTIVE'
        ORDER BY name
    ");

    $branches = $branchStmt->fetchAll();
}

$params = [
    ':from' => $from . ' 00:00:00',
    ':to' => $to . ' 23:59:59'
];

$where = [
    "s.sale_date BETWEEN :from AND :to",
    "s.sale_status = 'COMPLETED'"
];

if (!$allBranches && $userBranchId) {
    $where[] = 's.branch_id = :user_branch_id';
    $params[':user_branch_id'] = $userBranchId;
} elseif ($allBranches && $selectedBranch > 0) {
    $where[] = 's.branch_id = :branch_id';
    $params[':branch_id'] = $selectedBranch;
}

$whereSql = implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT
        p.id,
        p.name,
        p.sku,
        SUM(si.quantity) AS quantity_sold,
        COUNT(DISTINCT s.id) AS transactions,
        COALESCE(SUM(si.total), 0) AS revenue,
        COALESCE(SUM(si.quantity * p.cost_price), 0) AS cost_of_goods
    FROM sale_items si
    INNER JOIN sales s ON s.id = si.sale_id
    INNER JOIN products p ON p.id = si.product_id
    WHERE {$whereSql}
    GROUP BY p.id, p.name, p.sku
    ORDER BY revenue DESC, p.name ASC
");

$stmt->execute($params);
$products = $stmt->fetchAll();

$totalQuantity = 0.0;
$totalRevenue = 0.0;
$totalCost = 0.0;
$totalProfit = 0.0;

foreach ($products as &$product) {
    $quantity = (float) $product['quantity_sold'];
    $revenue = (float) $product['revenue'];
    $cost = (float) $product['cost_of_goods'];
    $profit = $revenue - $cost;

    $product['profit'] = $profit;
    $product['margin'] = $revenue > 0 ? ($profit / $revenue) * 100 : 0;

    $totalQuantity += $quantity;
    $totalRevenue += $revenue;
    $totalCost += $cost;
    $totalProfit += $profit;
}

unset($product);

$totalMargin = $totalRevenue > 0
    ? ($totalProfit / $totalRevenue) * 100
    : 0;

require_once __DIR__ . '/../includes/header.php';
?>

<div class="welcome-card">
    <div>
        <span class="eyebrow">HAVEN MART</span>
        <h2>Product Sales Report</h2>
        <p>Analyse products sold, revenue, cost and gross profit.</p>
    </div>

    <div class="welcome-icon">
        <i class="fas fa-boxes-stacked"></i>
    </div>
</div>

<div class="panel" style="margin-bottom:20px;">
    <div class="panel-header">
        <div>
            <h3>Filters</h3>
            <p>Select the period and branch to analyse.</p>
        </div>
    </div>

    <form method="get" style="display:flex;gap:12px;flex-wrap:wrap;align-items:end;">

        <div>
            <label for="from">From</label>
            <input type="date" id="from" name="from" value="<?= e($from) ?>">
        </div>

        <div>
            <label for="to">To</label>
            <input type="date" id="to" name="to" value="<?= e($to) ?>">
        </div>

        <?php if ($allBranches): ?>
            <div>
                <label for="branch_id">Branch</label>
                <select id="branch_id" name="branch_id">
                    <option value="0">All Branches</option>

                    <?php foreach ($branches as $branch): ?>
                        <option
                            value="<?= (int) $branch['id'] ?>"
                            <?= $selectedBranch === (int) $branch['id'] ? 'selected' : '' ?>
                        >
                            <?= e($branch['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>

        <button type="submit" class="btn btn-primary">
            <i class="fas fa-filter"></i>
            Apply
        </button>

        <a href="<?= APP_URL ?>/reports/products.php" class="btn btn-secondary">
            <i class="fas fa-rotate-left"></i>
            Reset
        </a>

    </form>
</div>

<div class="stats-grid">

    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-box"></i>
        </div>
        <div>
            <span>Products Sold</span>
            <strong><?= number_format(count($products)) ?></strong>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-cubes"></i>
        </div>
        <div>
            <span>Quantity Sold</span>
            <strong><?= rtrim(rtrim(number_format($totalQuantity, 3), '0'), '.') ?></strong>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-money-bill-trend-up"></i>
        </div>
        <div>
            <span>Revenue</span>
            <strong>KES <?= number_format($totalRevenue, 2) ?></strong>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-chart-line"></i>
        </div>
        <div>
            <span>Gross Profit</span>
            <strong>KES <?= number_format($totalProfit, 2) ?></strong>
        </div>
    </div>

</div>

<div class="stats-grid">

    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-box-open"></i>
        </div>
        <div>
            <span>Cost of Goods</span>
            <strong>KES <?= number_format($totalCost, 2) ?></strong>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-percent"></i>
        </div>
        <div>
            <span>Profit Margin</span>
            <strong><?= number_format($totalMargin, 2) ?>%</strong>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-calendar"></i>
        </div>
        <div>
            <span>From</span>
            <strong><?= e($from) ?></strong>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-calendar-check"></i>
        </div>
        <div>
            <span>To</span>
            <strong><?= e($to) ?></strong>
        </div>
    </div>

</div>

<div class="panel" style="margin-top:20px;">
    <div class="panel-header">
        <div>
            <h3>Product Performance</h3>
            <p>Products ranked by revenue.</p>
        </div>

        <button type="button" class="btn btn-secondary" onclick="window.print()">
            <i class="fas fa-print"></i>
            Print
        </button>
    </div>

    <?php if (!$products): ?>

        <div style="text-align:center;padding:40px 20px;">
            <i class="fas fa-box-open" style="font-size:36px;opacity:.5;"></i>
            <h3>No Product Sales</h3>
            <p>No completed product sales match the selected filters.</p>
        </div>

    <?php else: ?>

        <div style="overflow-x:auto;">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Product</th>
                        <th>SKU</th>
                        <th>Qty Sold</th>
                        <th>Transactions</th>
                        <th>Revenue</th>
                        <th>Cost</th>
                        <th>Profit</th>
                        <th>Margin</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($products as $index => $product): ?>
                        <tr>
                            <td><?= $index + 1 ?></td>

                            <td>
                                <strong><?= e($product['name']) ?></strong>
                            </td>

                            <td><?= e($product['sku']) ?></td>

                            <td>
                                <?= rtrim(
                                    rtrim(number_format((float) $product['quantity_sold'], 3), '0'),
                                    '.'
                                ) ?>
                            </td>

                            <td><?= number_format((int) $product['transactions']) ?></td>

                            <td>
                                KES <?= number_format((float) $product['revenue'], 2) ?>
                            </td>

                            <td>
                                KES <?= number_format((float) $product['cost_of_goods'], 2) ?>
                            </td>

                            <td>
                                <strong>
                                    KES <?= number_format((float) $product['profit'], 2) ?>
                                </strong>
                            </td>

                            <td>
                                <?= number_format((float) $product['margin'], 2) ?>%
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>

                <tfoot>
                    <tr>
                        <th colspan="3" style="text-align:right;">TOTAL</th>

                        <th>
                            <?= rtrim(rtrim(number_format($totalQuantity, 3), '0'), '.') ?>
                        </th>

                        <th>-</th>

                        <th>
                            KES <?= number_format($totalRevenue, 2) ?>
                        </th>

                        <th>
                            KES <?= number_format($totalCost, 2) ?>
                        </th>

                        <th>
                            KES <?= number_format($totalProfit, 2) ?>
                        </th>

                        <th>
                            <?= number_format($totalMargin, 2) ?>%
                        </th>
                    </tr>
                </tfoot>
            </table>
        </div>

    <?php endif; ?>
</div>

<div class="panel" style="margin-top:20px;">
    <div class="panel-header">
        <div>
            <h3>Report Navigation</h3>
            <p>Continue exploring Haven Mart performance.</p>
        </div>
    </div>

    <div class="quick-actions">

        <a href="<?= APP_URL ?>/reports/" class="quick-action">
            <i class="fas fa-chart-line"></i>
            <span>Reports Dashboard</span>
        </a>

        <a href="<?= APP_URL ?>/reports/sales.php" class="quick-action">
            <i class="fas fa-chart-column"></i>
            <span>Sales Report</span>
        </a>

        <a href="<?= APP_URL ?>/reports/profit.php" class="quick-action">
            <i class="fas fa-money-bill-trend-up"></i>
            <span>Profit Report</span>
        </a>

        <a href="<?= APP_URL ?>/reports/stock.php" class="quick-action">
            <i class="fas fa-warehouse"></i>
            <span>Stock Report</span>
        </a>

        <a href="<?= APP_URL ?>/reports/expenses.php" class="quick-action">
            <i class="fas fa-file-invoice-dollar"></i>
            <span>Expenses Report</span>
        </a>

    </div>
</div>

<style>
@media print {
    .sidebar,
    .topbar,
    .welcome-card,
    .panel:first-of-type,
    .btn,
    .quick-actions {
        display: none !important;
    }

    body {
        background: #fff !important;
    }

    .main-content,
    .content,
    .container {
        width: 100% !important;
        max-width: none !important;
        margin: 0 !important;
        padding: 0 !important;
    }

    .panel {
        border: 0 !important;
        box-shadow: none !important;
    }

    table {
        width: 100% !important;
    }
}
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>