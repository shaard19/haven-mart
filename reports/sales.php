<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();
require_permission('reports.view');

$page_title = 'Sales Report';

$from = $_GET['from'] ?? date('Y-m-d');
$to = $_GET['to'] ?? date('Y-m-d');
$selectedBranch = isset($_GET['branch_id']) ? (int) $_GET['branch_id'] : 0;
$selectedCashier = isset($_GET['cashier_id']) ? (int) $_GET['cashier_id'] : 0;
$selectedPayment = $_GET['payment_method'] ?? '';

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
$roleName = strtolower((string) $roleName);

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

$cashiers = [];

if ($allBranches) {
    $cashierStmt = $pdo->query("
        SELECT id, full_name
        FROM users
        WHERE status = 'ACTIVE'
        ORDER BY full_name
    ");
} else {
    $cashierStmt = $pdo->prepare("
        SELECT id, full_name
        FROM users
        WHERE status = 'ACTIVE'
          AND branch_id = ?
        ORDER BY full_name
    ");
    $cashierStmt->execute([$userBranchId]);
}

$cashiers = $cashierStmt->fetchAll();

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

if ($selectedCashier > 0) {
    $where[] = 's.cashier_id = :cashier_id';
    $params[':cashier_id'] = $selectedCashier;
}

if (in_array($selectedPayment, ['CASH', 'MPESA'], true)) {
    $where[] = "
        EXISTS (
            SELECT 1
            FROM payments px
            WHERE px.sale_id = s.id
              AND px.payment_method = :payment_method
              AND px.payment_status = 'COMPLETED'
        )
    ";
    $params[':payment_method'] = $selectedPayment;
}

$whereSql = implode(' AND ', $where);

$summaryStmt = $pdo->prepare("
    SELECT
        COUNT(*) AS transactions,
        COALESCE(SUM(s.subtotal), 0) AS subtotal,
        COALESCE(SUM(s.discount), 0) AS discounts,
        COALESCE(SUM(s.total), 0) AS total_sales
    FROM sales s
    WHERE {$whereSql}
");

$summaryStmt->execute($params);
$summary = $summaryStmt->fetch() ?: [];

$transactions = (int) ($summary['transactions'] ?? 0);
$subtotal = (float) ($summary['subtotal'] ?? 0);
$discounts = (float) ($summary['discounts'] ?? 0);
$totalSales = (float) ($summary['total_sales'] ?? 0);

$paymentParams = [
    ':from' => $from . ' 00:00:00',
    ':to' => $to . ' 23:59:59'
];

$paymentWhere = [
    "s.sale_date BETWEEN :from AND :to",
    "s.sale_status = 'COMPLETED'",
    "p.payment_status = 'COMPLETED'"
];

if (!$allBranches && $userBranchId) {
    $paymentWhere[] = 's.branch_id = :user_branch_id';
    $paymentParams[':user_branch_id'] = $userBranchId;
} elseif ($allBranches && $selectedBranch > 0) {
    $paymentWhere[] = 's.branch_id = :branch_id';
    $paymentParams[':branch_id'] = $selectedBranch;
}

if ($selectedCashier > 0) {
    $paymentWhere[] = 's.cashier_id = :cashier_id';
    $paymentParams[':cashier_id'] = $selectedCashier;
}

if (in_array($selectedPayment, ['CASH', 'MPESA'], true)) {
    $paymentWhere[] = 'p.payment_method = :payment_method';
    $paymentParams[':payment_method'] = $selectedPayment;
}

$paymentWhereSql = implode(' AND ', $paymentWhere);

$paymentStmt = $pdo->prepare("
    SELECT
        p.payment_method,
        COALESCE(SUM(p.amount), 0) AS amount
    FROM payments p
    INNER JOIN sales s ON s.id = p.sale_id
    WHERE {$paymentWhereSql}
    GROUP BY p.payment_method
");

$paymentStmt->execute($paymentParams);

$cashTotal = 0.00;
$mpesaTotal = 0.00;

foreach ($paymentStmt->fetchAll() as $payment) {
    if ($payment['payment_method'] === 'CASH') {
        $cashTotal = (float) $payment['amount'];
    }

    if ($payment['payment_method'] === 'MPESA') {
        $mpesaTotal = (float) $payment['amount'];
    }
}

$salesStmt = $pdo->prepare("
    SELECT
        s.id,
        s.invoice_number,
        s.sale_date,
        s.subtotal,
        s.discount,
        s.total,
        s.payment_status,
        b.name AS branch_name,
        u.full_name AS cashier_name,
        COALESCE(
            GROUP_CONCAT(
                DISTINCT p.payment_method
                ORDER BY p.payment_method
                SEPARATOR ', '
            ),
            '-'
        ) AS payment_methods
    FROM sales s
    INNER JOIN branches b ON b.id = s.branch_id
    INNER JOIN users u ON u.id = s.cashier_id
    LEFT JOIN payments p
        ON p.sale_id = s.id
        AND p.payment_status = 'COMPLETED'
    WHERE {$whereSql}
    GROUP BY
        s.id,
        s.invoice_number,
        s.sale_date,
        s.subtotal,
        s.discount,
        s.total,
        s.payment_status,
        b.name,
        u.full_name
    ORDER BY s.sale_date DESC, s.id DESC
");

$salesStmt->execute($params);
$sales = $salesStmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="welcome-card">
    <div>
        <span class="eyebrow">HAVEN MART</span>
        <h2>Sales Report</h2>
        <p>Detailed sales transactions and payment performance.</p>
    </div>

    <div class="welcome-icon">
        <i class="fas fa-chart-column"></i>
    </div>
</div>

<div class="panel" style="margin-bottom:20px;">
    <div class="panel-header">
        <div>
            <h3>Filters</h3>
            <p>Choose the sales period and optional filters.</p>
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
                        <option value="<?= (int) $branch['id'] ?>" <?= $selectedBranch === (int) $branch['id'] ? 'selected' : '' ?>>
                            <?= e($branch['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>

        <div>
            <label for="cashier_id">Cashier</label>
            <select id="cashier_id" name="cashier_id">
                <option value="0">All Cashiers</option>
                <?php foreach ($cashiers as $cashier): ?>
                    <option value="<?= (int) $cashier['id'] ?>" <?= $selectedCashier === (int) $cashier['id'] ? 'selected' : '' ?>>
                        <?= e($cashier['full_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label for="payment_method">Payment</label>
            <select id="payment_method" name="payment_method">
                <option value="">All Payments</option>
                <option value="CASH" <?= $selectedPayment === 'CASH' ? 'selected' : '' ?>>Cash</option>
                <option value="MPESA" <?= $selectedPayment === 'MPESA' ? 'selected' : '' ?>>M-Pesa</option>
            </select>
        </div>

        <button type="submit" class="btn btn-primary">
            <i class="fas fa-filter"></i>
            Apply
        </button>

        <a href="<?= APP_URL ?>/reports/sales.php" class="btn btn-secondary">
            <i class="fas fa-rotate-left"></i>
            Reset
        </a>

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

</div>

<div class="stats-grid">

    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-list"></i>
        </div>
        <div>
            <span>Subtotal</span>
            <strong>KES <?= number_format($subtotal, 2) ?></strong>
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
            <h3>Sales Transactions</h3>
            <p><?= number_format($transactions) ?> completed transaction(s)</p>
        </div>

        <button type="button" class="btn btn-secondary" onclick="window.print()">
            <i class="fas fa-print"></i>
            Print
        </button>
    </div>

    <?php if (!$sales): ?>

        <div style="text-align:center;padding:40px 20px;">
            <i class="fas fa-receipt" style="font-size:36px;opacity:.5;"></i>
            <h3>No Sales Found</h3>
            <p>No completed sales match the selected filters.</p>
        </div>

    <?php else: ?>

        <div style="overflow-x:auto;">
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Invoice</th>
                        <th>Branch</th>
                        <th>Cashier</th>
                        <th>Payment</th>
                        <th>Subtotal</th>
                        <th>Discount</th>
                        <th>Total</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($sales as $sale): ?>
                        <tr>
                            <td><?= e(date('d M Y H:i', strtotime($sale['sale_date']))) ?></td>

                            <td>
                                <a href="<?= APP_URL ?>/pos/receipt.php?id=<?= (int) $sale['id'] ?>" target="_blank">
                                    <?= e($sale['invoice_number']) ?>
                                </a>
                            </td>

                            <td><?= e($sale['branch_name']) ?></td>

                            <td><?= e($sale['cashier_name']) ?></td>

                            <td><?= e($sale['payment_methods']) ?></td>

                            <td>KES <?= number_format((float) $sale['subtotal'], 2) ?></td>

                            <td>KES <?= number_format((float) $sale['discount'], 2) ?></td>

                            <td>
                                <strong>KES <?= number_format((float) $sale['total'], 2) ?></strong>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>

                <tfoot>
                    <tr>
                        <th colspan="5" style="text-align:right;">TOTAL</th>
                        <th>KES <?= number_format($subtotal, 2) ?></th>
                        <th>KES <?= number_format($discounts, 2) ?></th>
                        <th>KES <?= number_format($totalSales, 2) ?></th>
                    </tr>
                </tfoot>
            </table>
        </div>

    <?php endif; ?>
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