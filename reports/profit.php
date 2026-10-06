<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/bootstrap.php';

require_login();
require_permission('reports.view');

$from = trim((string)($_GET['from'] ?? date('Y-m-01')));
$to = trim((string)($_GET['to'] ?? date('Y-m-d')));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $from = date('Y-m-01');
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $to = date('Y-m-d');
}

if ($from > $to) {
    [$from, $to] = [$to, $from];
}

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

$saleBranchFilter = '';
$saleParams = [
    ':from' => $from . ' 00:00:00',
    ':to' => $to . ' 23:59:59'
];

if (!$allBranches && $branchId !== null) {
    $saleBranchFilter = ' AND s.branch_id = :branch_id ';
    $saleParams[':branch_id'] = $branchId;
}

$salesStmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(s.total), 0) AS total_sales,
        COALESCE(SUM(s.subtotal), 0) AS subtotal,
        COALESCE(SUM(s.discount), 0) AS discounts,
        COUNT(*) AS transactions
    FROM sales s
    WHERE s.sale_status = 'COMPLETED'
      AND s.sale_date BETWEEN :from AND :to
      {$saleBranchFilter}
");
$salesStmt->execute($saleParams);
$sales = $salesStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$totalSales = (float)($sales['total_sales'] ?? 0);
$subtotal = (float)($sales['subtotal'] ?? 0);
$discounts = (float)($sales['discounts'] ?? 0);
$transactions = (int)($sales['transactions'] ?? 0);

$cogsStmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(si.quantity * p.cost_price), 0) AS cogs
    FROM sale_items si
    INNER JOIN sales s ON s.id = si.sale_id
    INNER JOIN products p ON p.id = si.product_id
    WHERE s.sale_status = 'COMPLETED'
      AND s.sale_date BETWEEN :from AND :to
      {$saleBranchFilter}
");
$cogsStmt->execute($saleParams);
$cogs = (float)$cogsStmt->fetchColumn();

$expenseBranchFilter = '';
$expenseParams = [
    ':from' => $from,
    ':to' => $to
];

if (!$allBranches && $branchId !== null) {
    $expenseBranchFilter = ' AND e.branch_id = :branch_id ';
    $expenseParams[':branch_id'] = $branchId;
}

$expenseStmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(e.amount), 0) AS expenses
    FROM expenses e
    WHERE e.expense_date BETWEEN :from AND :to
      {$expenseBranchFilter}
");
$expenseStmt->execute($expenseParams);
$totalExpenses = (float)$expenseStmt->fetchColumn();

$grossProfit = $totalSales - $cogs;
$netProfit = $grossProfit - $totalExpenses;

$grossMargin = $totalSales > 0
    ? ($grossProfit / $totalSales) * 100
    : 0;

$netMargin = $totalSales > 0
    ? ($netProfit / $totalSales) * 100
    : 0;

$dailyBranchFilter = '';
$dailyParams = [
    ':from' => $from . ' 00:00:00',
    ':to' => $to . ' 23:59:59'
];

if (!$allBranches && $branchId !== null) {
    $dailyBranchFilter = ' AND s.branch_id = :branch_id ';
    $dailyParams[':branch_id'] = $branchId;
}

$dailyStmt = $pdo->prepare("
    SELECT
        DATE(s.sale_date) AS report_date,
        COUNT(DISTINCT s.id) AS transactions,
        COALESCE(SUM(s.total), 0) AS sales,
        COALESCE(SUM(si.quantity * p.cost_price), 0) AS cogs
    FROM sales s
    INNER JOIN sale_items si ON si.sale_id = s.id
    INNER JOIN products p ON p.id = si.product_id
    WHERE s.sale_status = 'COMPLETED'
      AND s.sale_date BETWEEN :from AND :to
      {$dailyBranchFilter}
    GROUP BY DATE(s.sale_date)
    ORDER BY report_date DESC
");
$dailyStmt->execute($dailyParams);
$dailyRows = $dailyStmt->fetchAll(PDO::FETCH_ASSOC);

$dailyExpenseStmt = $pdo->prepare("
    SELECT
        e.expense_date AS report_date,
        COALESCE(SUM(e.amount), 0) AS expenses
    FROM expenses e
    WHERE e.expense_date BETWEEN :from AND :to
      {$expenseBranchFilter}
    GROUP BY e.expense_date
");
$dailyExpenseStmt->execute($expenseParams);

$dailyExpenses = [];

foreach ($dailyExpenseStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $dailyExpenses[$row['report_date']] = (float)$row['expenses'];
}

$branchRows = [];

if ($allBranches) {
    $branchStmt = $pdo->prepare("
        SELECT
            b.id,
            b.name,
            COUNT(DISTINCT s.id) AS transactions,
            COALESCE(SUM(s.total), 0) AS sales,
            COALESCE(SUM(si.quantity * p.cost_price), 0) AS cogs
        FROM branches b
        LEFT JOIN sales s
            ON s.branch_id = b.id
            AND s.sale_status = 'COMPLETED'
            AND s.sale_date BETWEEN :from AND :to
        LEFT JOIN sale_items si ON si.sale_id = s.id
        LEFT JOIN products p ON p.id = si.product_id
        WHERE b.status = 'ACTIVE'
        GROUP BY b.id, b.name
        ORDER BY sales DESC, b.name ASC
    ");

    $branchStmt->execute([
        ':from' => $from . ' 00:00:00',
        ':to' => $to . ' 23:59:59'
    ]);

    $branchRows = $branchStmt->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle = 'Profit Report';

require_once __DIR__ . '/../includes/header.php';
?>

<style>
.profit-report {
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

.report-field input {
    min-width: 150px;
}

.report-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.profit-stat {
    border-left: 4px solid var(--primary-color, #64131f);
}

.profit-stat.positive {
    border-left-color: #198754;
}

.profit-stat.negative {
    border-left-color: #dc3545;
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

.profit-positive {
    color: #198754;
    font-weight: 700;
}

.profit-negative {
    color: #dc3545;
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

<div class="profit-report">

    <div class="welcome-card">
        <div>
            <h1>Profit Report</h1>
            <p>Sales, cost of goods, expenses and profitability for the selected period.</p>
        </div>
    </div>

    <div class="panel report-toolbar">
        <form method="get" class="report-filters">
            <div class="report-field">
                <label for="from">From</label>
                <input
                    type="date"
                    id="from"
                    name="from"
                    value="<?= e($from) ?>"
                    class="form-control"
                >
            </div>

            <div class="report-field">
                <label for="to">To</label>
                <input
                    type="date"
                    id="to"
                    name="to"
                    value="<?= e($to) ?>"
                    class="form-control"
                >
            </div>

            <button type="submit" class="btn btn-primary">
                Apply
            </button>
        </form>

        <div class="report-actions no-print">
            <button type="button" class="btn btn-secondary" onclick="window.print()">
                Print
            </button>
        </div>
    </div>

    <div class="report-nav no-print">
        <a href="<?= e(APP_URL) ?>/reports/" class="btn btn-secondary">Reports</a>
        <a href="<?= e(APP_URL) ?>/reports/sales.php" class="btn btn-secondary">Sales</a>
        <a href="<?= e(APP_URL) ?>/reports/products.php" class="btn btn-secondary">Product Sales</a>
        <a href="<?= e(APP_URL) ?>/reports/stock.php" class="btn btn-secondary">Stock</a>
        <a href="<?= e(APP_URL) ?>/reports/expenses.php" class="btn btn-secondary">Expenses</a>
    </div>

    <div class="stats-grid">

        <div class="stat-card">
            <div class="stat-label">Total Sales</div>
            <div class="stat-value">
                KES <?= number_format($totalSales, 2) ?>
            </div>
            <div class="stat-meta">
                <?= number_format($transactions) ?> transactions
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-label">Cost of Goods</div>
            <div class="stat-value">
                KES <?= number_format($cogs, 2) ?>
            </div>
            <div class="stat-meta">
                Product cost
            </div>
        </div>

        <div class="stat-card profit-stat <?= $grossProfit >= 0 ? 'positive' : 'negative' ?>">
            <div class="stat-label">Gross Profit</div>
            <div class="stat-value">
                KES <?= number_format($grossProfit, 2) ?>
            </div>
            <div class="stat-meta">
                <?= number_format($grossMargin, 2) ?>% gross margin
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-label">Expenses</div>
            <div class="stat-value">
                KES <?= number_format($totalExpenses, 2) ?>
            </div>
            <div class="stat-meta">
                Operating expenses
            </div>
        </div>

        <div class="stat-card profit-stat <?= $netProfit >= 0 ? 'positive' : 'negative' ?>">
            <div class="stat-label">Net Profit</div>
            <div class="stat-value">
                KES <?= number_format($netProfit, 2) ?>
            </div>
            <div class="stat-meta">
                <?= number_format($netMargin, 2) ?>% net margin
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-label">Discounts</div>
            <div class="stat-value">
                KES <?= number_format($discounts, 2) ?>
            </div>
            <div class="stat-meta">
                Applied to sales
            </div>
        </div>

    </div>

    <div class="panel">
        <div class="panel-header">
            <div>
                <h2>Profit Summary</h2>
                <p>
                    <?= e(date('d M Y', strtotime($from))) ?>
                    —
                    <?= e(date('d M Y', strtotime($to))) ?>
                </p>
            </div>
        </div>

        <div class="report-table-wrap">
            <table class="report-table">
                <tbody>
                    <tr>
                        <td>Sales Revenue</td>
                        <td class="text-right">KES <?= number_format($totalSales, 2) ?></td>
                    </tr>
                    <tr>
                        <td>Cost of Goods Sold</td>
                        <td class="text-right">KES <?= number_format($cogs, 2) ?></td>
                    </tr>
                    <tr>
                        <td><strong>Gross Profit</strong></td>
                        <td class="text-right profit-positive">
                            KES <?= number_format($grossProfit, 2) ?>
                        </td>
                    </tr>
                    <tr>
                        <td>Operating Expenses</td>
                        <td class="text-right">KES <?= number_format($totalExpenses, 2) ?></td>
                    </tr>
                    <tr>
                        <td><strong>Net Profit</strong></td>
                        <td class="text-right <?= $netProfit >= 0 ? 'profit-positive' : 'profit-negative' ?>">
                            KES <?= number_format($netProfit, 2) ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="panel">
        <div class="panel-header">
            <div>
                <h2>Daily Profit</h2>
                <p>Daily sales, cost and profit for the selected period.</p>
            </div>
        </div>

        <div class="report-table-wrap">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Transactions</th>
                        <th class="text-right">Sales</th>
                        <th class="text-right">COGS</th>
                        <th class="text-right">Gross Profit</th>
                        <th class="text-right">Expenses</th>
                        <th class="text-right">Net Profit</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$dailyRows): ?>
                    <tr>
                        <td colspan="7">No sales found for the selected period.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($dailyRows as $row): ?>
                        <?php
                        $daySales = (float)$row['sales'];
                        $dayCogs = (float)$row['cogs'];
                        $dayGross = $daySales - $dayCogs;
                        $dayExpenses = (float)($dailyExpenses[$row['report_date']] ?? 0);
                        $dayNet = $dayGross - $dayExpenses;
                        ?>
                        <tr>
                            <td><?= e(date('d M Y', strtotime($row['report_date']))) ?></td>
                            <td><?= number_format((int)$row['transactions']) ?></td>
                            <td class="text-right">KES <?= number_format($daySales, 2) ?></td>
                            <td class="text-right">KES <?= number_format($dayCogs, 2) ?></td>
                            <td class="text-right <?= $dayGross >= 0 ? 'profit-positive' : 'profit-negative' ?>">
                                KES <?= number_format($dayGross, 2) ?>
                            </td>
                            <td class="text-right">KES <?= number_format($dayExpenses, 2) ?></td>
                            <td class="text-right <?= $dayNet >= 0 ? 'profit-positive' : 'profit-negative' ?>">
                                KES <?= number_format($dayNet, 2) ?>
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
                    <h2>Branch Profitability</h2>
                    <p>Performance by active branch.</p>
                </div>
            </div>

            <div class="report-table-wrap">
                <table class="report-table">
                    <thead>
                        <tr>
                            <th>Branch</th>
                            <th>Transactions</th>
                            <th class="text-right">Sales</th>
                            <th class="text-right">COGS</th>
                            <th class="text-right">Gross Profit</th>
                            <th class="text-right">Margin</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$branchRows): ?>
                        <tr>
                            <td colspan="6">No branch data found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($branchRows as $row): ?>
                            <?php
                            $branchSales = (float)$row['sales'];
                            $branchCogs = (float)$row['cogs'];
                            $branchProfit = $branchSales - $branchCogs;
                            $branchMargin = $branchSales > 0
                                ? ($branchProfit / $branchSales) * 100
                                : 0;
                            ?>
                            <tr>
                                <td><?= e($row['name']) ?></td>
                                <td><?= number_format((int)$row['transactions']) ?></td>
                                <td class="text-right">KES <?= number_format($branchSales, 2) ?></td>
                                <td class="text-right">KES <?= number_format($branchCogs, 2) ?></td>
                                <td class="text-right <?= $branchProfit >= 0 ? 'profit-positive' : 'profit-negative' ?>">
                                    KES <?= number_format($branchProfit, 2) ?>
                                </td>
                                <td class="text-right">
                                    <?= number_format($branchMargin, 2) ?>%
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