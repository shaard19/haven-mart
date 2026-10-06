<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();
require_permission('reports.view');

$page_title = 'Reports';

$from = $_GET['from'] ?? date('Y-m-d');
$to = $_GET['to'] ?? date('Y-m-d');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $from = date('Y-m-d');
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $to = date('Y-m-d');
}

if ($from > $to) {
    [$from, $to] = [$to, $from];
}

$roleName = $user['role_name'] ?? $user['role'] ?? '';

$allBranches = in_array(strtolower((string) $roleName), ['administrator', 'owner'], true);

$branchId = null;

if (!$allBranches) {
    $branchId = isset($user['branch_id']) ? (int) $user['branch_id'] : null;
}

$branchFilter = '';
$params = [
    ':from' => $from . ' 00:00:00',
    ':to' => $to . ' 23:59:59'
];

if ($branchId !== null && $branchId > 0) {
    $branchFilter = ' AND s.branch_id = :branch_id ';
    $params[':branch_id'] = $branchId;
}

$salesStmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(s.total), 0) AS total_sales,
        COUNT(*) AS transactions,
        COALESCE(SUM(s.discount), 0) AS discounts
    FROM sales s
    WHERE s.sale_date BETWEEN :from AND :to
      AND s.sale_status = 'COMPLETED'
      {$branchFilter}
");

$salesStmt->execute($params);
$salesSummary = $salesStmt->fetch() ?: [];

$totalSales = (float) ($salesSummary['total_sales'] ?? 0);
$transactions = (int) ($salesSummary['transactions'] ?? 0);
$discounts = (float) ($salesSummary['discounts'] ?? 0);

$paymentParams = [
    ':from' => $from . ' 00:00:00',
    ':to' => $to . ' 23:59:59'
];

$paymentBranchFilter = '';

if ($branchId !== null && $branchId > 0) {
    $paymentBranchFilter = ' AND s.branch_id = :branch_id ';
    $paymentParams[':branch_id'] = $branchId;
}

$paymentStmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(CASE
            WHEN p.payment_method = 'CASH'
            AND p.payment_status = 'COMPLETED'
            THEN p.amount ELSE 0 END), 0) AS cash_total,
        COALESCE(SUM(CASE
            WHEN p.payment_method = 'MPESA'
            AND p.payment_status = 'COMPLETED'
            THEN p.amount ELSE 0 END), 0) AS mpesa_total
    FROM payments p
    INNER JOIN sales s ON s.id = p.sale_id
    WHERE s.sale_date BETWEEN :from AND :to
      AND s.sale_status = 'COMPLETED'
      {$paymentBranchFilter}
");

$paymentStmt->execute($paymentParams);
$paymentSummary = $paymentStmt->fetch() ?: [];

$cashTotal = (float) ($paymentSummary['cash_total'] ?? 0);
$mpesaTotal = (float) ($paymentSummary['mpesa_total'] ?? 0);

$expenseParams = [
    ':from' => $from,
    ':to' => $to
];

$expenseBranchFilter = '';

if ($branchId !== null && $branchId > 0) {
    $expenseBranchFilter = ' AND e.branch_id = :branch_id ';
    $expenseParams[':branch_id'] = $branchId;
}

$expenseStmt = $pdo->prepare("
    SELECT COALESCE(SUM(e.amount), 0) AS total_expenses
    FROM expenses e
    WHERE e.expense_date BETWEEN :from AND :to
      {$expenseBranchFilter}
");

$expenseStmt->execute($expenseParams);
$totalExpenses = (float) ($expenseStmt->fetchColumn() ?: 0);

$profitParams = [
    ':from' => $from . ' 00:00:00',
    ':to' => $to . ' 23:59:59'
];

$profitBranchFilter = '';

if ($branchId !== null && $branchId > 0) {
    $profitBranchFilter = ' AND s.branch_id = :branch_id ';
    $profitParams[':branch_id'] = $branchId;
}

$profitStmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(si.quantity * p.cost_price), 0) AS cost_of_goods
    FROM sale_items si
    INNER JOIN sales s ON s.id = si.sale_id
    INNER JOIN products p ON p.id = si.product_id
    WHERE s.sale_date BETWEEN :from AND :to
      AND s.sale_status = 'COMPLETED'
      {$profitBranchFilter}
");

$profitStmt->execute($profitParams);
$costOfGoods = (float) ($profitStmt->fetchColumn() ?: 0);

$grossProfit = $totalSales - $costOfGoods;
$netProfit = $grossProfit - $totalExpenses;

$stockParams = [];
$stockBranchFilter = '';

if ($branchId !== null && $branchId > 0) {
    $stockBranchFilter = ' WHERE bs.branch_id = :branch_id ';
    $stockParams[':branch_id'] = $branchId;
}

$stockStmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM branch_stock bs
    INNER JOIN products p ON p.id = bs.product_id
    {$stockBranchFilter}
    " . ($branchId !== null && $branchId > 0 ? " AND " : " WHERE ") . "
    p.status = 'ACTIVE'
    AND bs.quantity <= p.reorder_level
");

$stockStmt->execute($stockParams);
$lowStock = (int) $stockStmt->fetchColumn();

$topParams = [
    ':from' => $from . ' 00:00:00',
    ':to' => $to . ' 23:59:59'
];

$topBranchFilter = '';

if ($branchId !== null && $branchId > 0) {
    $topBranchFilter = ' AND s.branch_id = :branch_id ';
    $topParams[':branch_id'] = $branchId;
}

$topStmt = $pdo->prepare("
    SELECT
        p.name,
        SUM(si.quantity) AS quantity_sold,
        SUM(si.total) AS revenue
    FROM sale_items si
    INNER JOIN sales s ON s.id = si.sale_id
    INNER JOIN products p ON p.id = si.product_id
    WHERE s.sale_date BETWEEN :from AND :to
      AND s.sale_status = 'COMPLETED'
      {$topBranchFilter}
    GROUP BY p.id, p.name
    ORDER BY quantity_sold DESC
    LIMIT 5
");

$topStmt->execute($topParams);
$topProducts = $topStmt->fetchAll();

$branchRows = [];

if ($allBranches) {
    $branchStmt = $pdo->prepare("
        SELECT
            b.id,
            b.name,
            COUNT(s.id) AS transactions,
            COALESCE(SUM(s.total), 0) AS sales
        FROM branches b
        LEFT JOIN sales s
            ON s.branch_id = b.id
            AND s.sale_date BETWEEN :from AND :to
            AND s.sale_status = 'COMPLETED'
        WHERE b.status = 'ACTIVE'
        GROUP BY b.id, b.name
        ORDER BY sales DESC
    ");

    $branchStmt->execute([
        ':from' => $from . ' 00:00:00',
        ':to' => $to . ' 23:59:59'
    ]);

    $branchRows = $branchStmt->fetchAll();
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="welcome-card">
    <div>
        <span class="eyebrow">HAVEN MART</span>
        <h2>Reports</h2>
        <p>Review sales, payments, profit, expenses and stock performance.</p>
    </div>

    <div class="welcome-icon">
        <i class="fas fa-chart-line"></i>
    </div>
</div>

<div class="panel" style="margin-bottom:20px;">
    <div class="panel-header">
        <div>
            <h3>Report Period</h3>
            <p>Select the period you want to analyse.</p>
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

        <button type="submit" class="btn btn-primary">
            <i class="fas fa-filter"></i>
            Apply
        </button>
    </form>
</div>

<div class="stats-grid">

    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-cash-register"></i>
        </div>
        <div>
            <span>Total Sales</span>
            <strong>KES <?= number_format($totalSales, 2) ?></strong>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-receipt"></i>
        </div>
        <div>
            <span>Transactions</span>
            <strong><?= number_format($transactions) ?></strong>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-chart-line"></i>
        </div>
        <div>
            <span>Gross Profit</span>
            <strong>KES <?= number_format($grossProfit, 2) ?></strong>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-wallet"></i>
        </div>
        <div>
            <span>Net Profit</span>
            <strong>KES <?= number_format($netProfit, 2) ?></strong>
        </div>
    </div>

</div>

<div class="stats-grid">

    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-money-bill-wave"></i>
        </div>
        <div>
            <span>Cash</span>
            <strong>KES <?= number_format($cashTotal, 2) ?></strong>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-mobile-screen-button"></i>
        </div>
        <div>
            <span>M-Pesa</span>
            <strong>KES <?= number_format($mpesaTotal, 2) ?></strong>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-tags"></i>
        </div>
        <div>
            <span>Discounts</span>
            <strong>KES <?= number_format($discounts, 2) ?></strong>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-triangle-exclamation"></i>
        </div>
        <div>
            <span>Low Stock</span>
            <strong><?= number_format($lowStock) ?></strong>
        </div>
    </div>

</div>

<div class="dashboard-grid">

    <section class="panel">
        <div class="panel-header">
            <div>
                <h3>Financial Summary</h3>
                <p><?= e($from) ?> to <?= e($to) ?></p>
            </div>
        </div>

        <div style="display:grid;gap:12px;">
            <div style="display:flex;justify-content:space-between;">
                <span>Sales</span>
                <strong>KES <?= number_format($totalSales, 2) ?></strong>
            </div>

            <div style="display:flex;justify-content:space-between;">
                <span>Cost of Goods</span>
                <strong>KES <?= number_format($costOfGoods, 2) ?></strong>
            </div>

            <div style="display:flex;justify-content:space-between;">
                <span>Gross Profit</span>
                <strong>KES <?= number_format($grossProfit, 2) ?></strong>
            </div>

            <div style="display:flex;justify-content:space-between;">
                <span>Expenses</span>
                <strong>KES <?= number_format($totalExpenses, 2) ?></strong>
            </div>

            <div style="display:flex;justify-content:space-between;border-top:1px solid #ddd;padding-top:12px;">
                <span><strong>Net Profit</strong></span>
                <strong>KES <?= number_format($netProfit, 2) ?></strong>
            </div>
        </div>
    </section>

    <section class="panel">
        <div class="panel-header">
            <div>
                <h3>Top Selling Products</h3>
                <p>Highest quantities sold</p>
            </div>
        </div>

        <?php if (!$topProducts): ?>
            <p>No sales recorded for this period.</p>
        <?php else: ?>
            <div style="overflow-x:auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Qty</th>
                            <th>Revenue</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topProducts as $product): ?>
                            <tr>
                                <td><?= e($product['name']) ?></td>
                                <td><?= e(rtrim(rtrim(number_format((float) $product['quantity_sold'], 3), '0'), '.')) ?></td>
                                <td>KES <?= number_format((float) $product['revenue'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

</div>

<?php if ($allBranches): ?>
<div class="panel" style="margin-top:20px;">
    <div class="panel-header">
        <div>
            <h3>Branch Performance</h3>
            <p>Sales performance across active branches.</p>
        </div>
    </div>

    <div style="overflow-x:auto;">
        <table>
            <thead>
                <tr>
                    <th>Branch</th>
                    <th>Transactions</th>
                    <th>Sales</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($branchRows as $branch): ?>
                    <tr>
                        <td><?= e($branch['name']) ?></td>
                        <td><?= number_format((int) $branch['transactions']) ?></td>
                        <td>KES <?= number_format((float) $branch['sales'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="panel" style="margin-top:20px;">
    <div class="panel-header">
        <div>
            <h3>More Reports</h3>
            <p>Detailed reporting modules.</p>
        </div>
    </div>

    <div class="quick-actions">
        <a href="<?= APP_URL ?>/reports/sales.php" class="quick-action">
            <i class="fas fa-chart-column"></i>
            <span>Sales Report</span>
        </a>

        <a href="<?= APP_URL ?>/reports/products.php" class="quick-action">
            <i class="fas fa-boxes-stacked"></i>
            <span>Product Sales</span>
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>