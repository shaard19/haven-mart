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

$from = trim((string)($_GET['from'] ?? date('Y-m-01')));
$to = trim((string)($_GET['to'] ?? date('Y-m-d')));
$categoryId = isset($_GET['category_id']) && (int)$_GET['category_id'] > 0
    ? (int)$_GET['category_id']
    : null;

$validDate = static function (string $date): bool {
    $d = DateTime::createFromFormat('Y-m-d', $date);

    return $d !== false && $d->format('Y-m-d') === $date;
};

if (!$validDate($from)) {
    $from = date('Y-m-01');
}

if (!$validDate($to)) {
    $to = date('Y-m-d');
}

if ($from > $to) {
    [$from, $to] = [$to, $from];
}

$branchFilter = '';
$params = [
    ':from_date' => $from,
    ':to_date' => $to
];

if (!$allBranches && $branchId !== null) {
    $branchFilter = ' AND e.branch_id = :branch_id ';
    $params[':branch_id'] = $branchId;
}

$categoryFilter = '';

if ($categoryId !== null) {
    $categoryFilter = ' AND e.category_id = :category_id ';
    $params[':category_id'] = $categoryId;
}

$categoryStmt = $pdo->query("
    SELECT
        id,
        name
    FROM expense_categories
    WHERE status = 'ACTIVE'
    ORDER BY name ASC
");

$categories = $categoryStmt->fetchAll(PDO::FETCH_ASSOC);

$summaryStmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(e.amount), 0) AS total_expenses,
        COUNT(*) AS expense_count,
        COALESCE(AVG(e.amount), 0) AS average_expense,
        COALESCE(MAX(e.amount), 0) AS largest_expense
    FROM expenses e
    WHERE e.expense_date BETWEEN :from_date AND :to_date
      {$branchFilter}
      {$categoryFilter}
");

$summaryStmt->execute($params);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$totalExpenses = (float)($summary['total_expenses'] ?? 0);
$expenseCount = (int)($summary['expense_count'] ?? 0);
$averageExpense = (float)($summary['average_expense'] ?? 0);
$largestExpense = (float)($summary['largest_expense'] ?? 0);

$detailStmt = $pdo->prepare("
    SELECT
        e.id,
        e.expense_date,
        e.amount,
        e.description,
        e.reference_number,
        ec.name AS category_name,
        b.name AS branch_name,
        b.code AS branch_code,
        u.full_name AS created_by_name
    FROM expenses e
    INNER JOIN expense_categories ec
        ON ec.id = e.category_id
    INNER JOIN branches b
        ON b.id = e.branch_id
    LEFT JOIN users u
        ON u.id = e.created_by
    WHERE e.expense_date BETWEEN :from_date AND :to_date
      {$branchFilter}
      {$categoryFilter}
    ORDER BY
        e.expense_date DESC,
        e.id DESC
");

$detailStmt->execute($params);
$expenseRows = $detailStmt->fetchAll(PDO::FETCH_ASSOC);

$categoryParams = [
    ':from_date' => $from,
    ':to_date' => $to
];

$categoryBranchFilter = '';

if (!$allBranches && $branchId !== null) {
    $categoryBranchFilter = ' AND e.branch_id = :branch_id ';
    $categoryParams[':branch_id'] = $branchId;
}

$categorySummaryStmt = $pdo->prepare("
    SELECT
        ec.name AS category_name,
        COUNT(e.id) AS expense_count,
        COALESCE(SUM(e.amount), 0) AS total_amount
    FROM expense_categories ec
    LEFT JOIN expenses e
        ON e.category_id = ec.id
        AND e.expense_date BETWEEN :from_date AND :to_date
        {$categoryBranchFilter}
    WHERE ec.status = 'ACTIVE'
    GROUP BY
        ec.id,
        ec.name
    HAVING
        COUNT(e.id) > 0
    ORDER BY
        total_amount DESC,
        category_name ASC
");

$categorySummaryStmt->execute($categoryParams);
$categoryRows = $categorySummaryStmt->fetchAll(PDO::FETCH_ASSOC);

$dailyParams = [
    ':from_date' => $from,
    ':to_date' => $to
];

$dailyBranchFilter = '';

if (!$allBranches && $branchId !== null) {
    $dailyBranchFilter = ' AND e.branch_id = :branch_id ';
    $dailyParams[':branch_id'] = $branchId;
}

$dailyStmt = $pdo->prepare("
    SELECT
        e.expense_date,
        COUNT(*) AS expense_count,
        COALESCE(SUM(e.amount), 0) AS total_amount
    FROM expenses e
    WHERE e.expense_date BETWEEN :from_date AND :to_date
      {$dailyBranchFilter}
    GROUP BY e.expense_date
    ORDER BY e.expense_date DESC
");

$dailyStmt->execute($dailyParams);
$dailyRows = $dailyStmt->fetchAll(PDO::FETCH_ASSOC);

$branchRows = [];

if ($allBranches) {
    $branchStmt = $pdo->prepare("
        SELECT
            b.id,
            b.name,
            b.code,
            COUNT(e.id) AS expense_count,
            COALESCE(SUM(e.amount), 0) AS total_amount
        FROM branches b
        LEFT JOIN expenses e
            ON e.branch_id = b.id
            AND e.expense_date BETWEEN :from_date AND :to_date
        WHERE b.status = 'ACTIVE'
        GROUP BY
            b.id,
            b.name,
            b.code
        ORDER BY
            total_amount DESC,
            b.name ASC
    ");

    $branchStmt->execute([
        ':from_date' => $from,
        ':to_date' => $to
    ]);

    $branchRows = $branchStmt->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle = 'Expenses Report';

require_once __DIR__ . '/../includes/header.php';
?>

<style>
.expenses-report {
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
    min-width: 160px;
}

.report-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.report-nav {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.report-nav a {
    text-decoration: none;
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

.amount {
    font-weight: 700;
}

.expense-category {
    display: inline-flex;
    align-items: center;
    padding: 5px 9px;
    border-radius: 999px;
    background: #f5f3f0;
    color: #64131f;
    font-size: 11px;
    font-weight: 700;
}

.total-row td {
    font-weight: 800;
    border-top: 2px solid #64131f;
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

<div class="expenses-report">

    <div class="welcome-card">
        <div>
            <h1>Expenses Report</h1>
            <p>Track business expenses, spending categories and branch expenditure.</p>
        </div>
    </div>

    <div class="panel report-toolbar no-print">
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

            <div class="report-field">
                <label for="category_id">Category</label>
                <select
                    id="category_id"
                    name="category_id"
                    class="form-control"
                >
                    <option value="">All Categories</option>

                    <?php foreach ($categories as $category): ?>
                        <option
                            value="<?= (int)$category['id'] ?>"
                            <?= $categoryId === (int)$category['id'] ? 'selected' : '' ?>
                        >
                            <?= e($category['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit" class="btn btn-primary">
                Filter
            </button>

            <a
                href="<?= e(APP_URL) ?>/reports/expenses.php"
                class="btn btn-secondary"
            >
                Reset
            </a>
        </form>

        <div class="report-actions">
            <button
                type="button"
                class="btn btn-secondary"
                onclick="window.print()"
            >
                Print
            </button>
        </div>
    </div>

    <div class="report-nav no-print">
        <a
            href="<?= e(APP_URL) ?>/reports/"
            class="btn btn-secondary"
        >
            Reports
        </a>

        <a
            href="<?= e(APP_URL) ?>/reports/sales.php"
            class="btn btn-secondary"
        >
            Sales
        </a>

        <a
            href="<?= e(APP_URL) ?>/reports/products.php"
            class="btn btn-secondary"
        >
            Product Sales
        </a>

        <a
            href="<?= e(APP_URL) ?>/reports/profit.php"
            class="btn btn-secondary"
        >
            Profit
        </a>

        <a
            href="<?= e(APP_URL) ?>/reports/stock.php"
            class="btn btn-secondary"
        >
            Stock
        </a>
    </div>

    <div class="stats-grid">

        <div class="stat-card">
            <div class="stat-label">Total Expenses</div>
            <div class="stat-value">
                KES <?= number_format($totalExpenses, 2) ?>
            </div>
            <div class="stat-meta">
                Selected period
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-label">Expense Transactions</div>
            <div class="stat-value">
                <?= number_format($expenseCount) ?>
            </div>
            <div class="stat-meta">
                Recorded expenses
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-label">Average Expense</div>
            <div class="stat-value">
                KES <?= number_format($averageExpense, 2) ?>
            </div>
            <div class="stat-meta">
                Average per transaction
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-label">Largest Expense</div>
            <div class="stat-value">
                KES <?= number_format($largestExpense, 2) ?>
            </div>
            <div class="stat-meta">
                Highest single expense
            </div>
        </div>

    </div>

    <div class="panel">
        <div class="panel-header">
            <div>
                <h2>Expense Summary</h2>
                <p>
                    <?= e($from) ?> to <?= e($to) ?>
                    <?php if ($categoryId !== null): ?>
                        · Filtered by selected category
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <div class="report-table-wrap">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th>Transactions</th>
                        <th class="text-right">Total Amount</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (!$categoryRows): ?>
                    <tr>
                        <td colspan="3">
                            No expenses found for the selected period.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($categoryRows as $row): ?>
                        <tr>
                            <td>
                                <span class="expense-category">
                                    <?= e($row['category_name']) ?>
                                </span>
                            </td>

                            <td>
                                <?= number_format((int)$row['expense_count']) ?>
                            </td>

                            <td class="text-right amount">
                                KES <?= number_format(
                                    (float)$row['total_amount'],
                                    2
                                ) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <tr class="total-row">
                        <td>Total</td>
                        <td>
                            <?= number_format($expenseCount) ?>
                        </td>
                        <td class="text-right">
                            KES <?= number_format($totalExpenses, 2) ?>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="panel">
        <div class="panel-header">
            <div>
                <h2>Expense Details</h2>
                <p>Detailed expense transactions for the selected period.</p>
            </div>
        </div>

        <div class="report-table-wrap">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Category</th>
                        <th>Description</th>
                        <th>Reference</th>
                        <th>Branch</th>
                        <th>Recorded By</th>
                        <th class="text-right">Amount</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (!$expenseRows): ?>
                    <tr>
                        <td colspan="7">
                            No expense transactions found.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($expenseRows as $row): ?>
                        <tr>
                            <td>
                                <?= e($row['expense_date']) ?>
                            </td>

                            <td>
                                <span class="expense-category">
                                    <?= e($row['category_name']) ?>
                                </span>
                            </td>

                            <td>
                                <?= e($row['description']) ?>
                            </td>

                            <td>
                                <?= e(
                                    (string)($row['reference_number'] ?? '—')
                                ) ?>
                            </td>

                            <td>
                                <?= e($row['branch_name']) ?>
                                <small>
                                    (<?= e($row['branch_code']) ?>)
                                </small>
                            </td>

                            <td>
                                <?= e(
                                    (string)($row['created_by_name'] ?? '—')
                                ) ?>
                            </td>

                            <td class="text-right amount">
                                KES <?= number_format(
                                    (float)$row['amount'],
                                    2
                                ) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <tr class="total-row">
                        <td colspan="6">Total Expenses</td>
                        <td class="text-right">
                            KES <?= number_format($totalExpenses, 2) ?>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="panel">
        <div class="panel-header">
            <div>
                <h2>Daily Expenses</h2>
                <p>Daily spending during the selected period.</p>
            </div>
        </div>

        <div class="report-table-wrap">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Transactions</th>
                        <th class="text-right">Total Amount</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (!$dailyRows): ?>
                    <tr>
                        <td colspan="3">
                            No daily expense data found.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($dailyRows as $row): ?>
                        <tr>
                            <td>
                                <?= e($row['expense_date']) ?>
                            </td>

                            <td>
                                <?= number_format(
                                    (int)$row['expense_count']
                                ) ?>
                            </td>

                            <td class="text-right amount">
                                KES <?= number_format(
                                    (float)$row['total_amount'],
                                    2
                                ) ?>
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
                    <h2>Branch Expenses</h2>
                    <p>Expense comparison across active branches.</p>
                </div>
            </div>

            <div class="report-table-wrap">
                <table class="report-table">
                    <thead>
                        <tr>
                            <th>Branch</th>
                            <th>Code</th>
                            <th>Transactions</th>
                            <th class="text-right">Total Expenses</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php if (!$branchRows): ?>
                        <tr>
                            <td colspan="4">
                                No branch expense data found.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($branchRows as $row): ?>
                            <tr>
                                <td>
                                    <strong><?= e($row['name']) ?></strong>
                                </td>

                                <td>
                                    <?= e($row['code']) ?>
                                </td>

                                <td>
                                    <?= number_format(
                                        (int)$row['expense_count']
                                    ) ?>
                                </td>

                                <td class="text-right amount">
                                    KES <?= number_format(
                                        (float)$row['total_amount'],
                                        2
                                    ) ?>
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