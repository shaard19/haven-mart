<?php
require_once __DIR__ . '/../includes/auth.php';

require_login();
require_permission('sales.view');

$pageTitle = 'Sales';

$search = trim($_GET['search'] ?? '');
$branch_id = (int)($_GET['branch_id'] ?? 0);
$payment_status = strtoupper(trim($_GET['payment_status'] ?? ''));
$sale_status = strtoupper(trim($_GET['sale_status'] ?? ''));
$date_from = trim($_GET['date_from'] ?? '');
$date_to = trim($_GET['date_to'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;

$branchesStmt = $pdo->query("
    SELECT id, name, code
    FROM branches
    WHERE status = 'ACTIVE'
    ORDER BY name ASC
");
$branches = $branchesStmt->fetchAll(PDO::FETCH_ASSOC);

$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(
        s.invoice_number LIKE ?
        OR b.name LIKE ?
        OR b.code LIKE ?
        OR u.full_name LIKE ?
        OR EXISTS (
            SELECT 1
            FROM sale_items si_search
            INNER JOIN products p_search ON p_search.id = si_search.product_id
            WHERE si_search.sale_id = s.id
            AND (
                p_search.name LIKE ?
                OR p_search.sku LIKE ?
                OR p_search.barcode LIKE ?
            )
        )
    )";

    $searchValue = '%' . $search . '%';

    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;
}

if ($branch_id > 0) {
    $where[] = "s.branch_id = ?";
    $params[] = $branch_id;
}

if (in_array($payment_status, ['UNPAID', 'PARTIAL', 'PAID', 'REFUNDED'], true)) {
    $where[] = "s.payment_status = ?";
    $params[] = $payment_status;
}

if (in_array($sale_status, ['COMPLETED', 'VOIDED', 'RETURNED'], true)) {
    $where[] = "s.sale_status = ?";
    $params[] = $sale_status;
}

if ($date_from !== '') {
    $where[] = "DATE(s.sale_date) >= ?";
    $params[] = $date_from;
}

if ($date_to !== '') {
    $where[] = "DATE(s.sale_date) <= ?";
    $params[] = $date_to;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM sales s
    INNER JOIN branches b ON b.id = s.branch_id
    INNER JOIN users u ON u.id = s.cashier_id
    $whereSql
");
$countStmt->execute($params);
$total_records = (int)$countStmt->fetchColumn();

$total_pages = max(1, (int)ceil($total_records / $per_page));

if ($page > $total_pages) {
    $page = $total_pages;
}

$offset = ($page - 1) * $per_page;

$salesStmt = $pdo->prepare("
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
        b.name AS branch_name,
        b.code AS branch_code,
        u.full_name AS cashier_name,
        COUNT(si.id) AS item_count,
        COALESCE(SUM(si.quantity), 0) AS item_quantity
    FROM sales s
    INNER JOIN branches b ON b.id = s.branch_id
    INNER JOIN users u ON u.id = s.cashier_id
    LEFT JOIN sale_items si ON si.sale_id = s.id
    $whereSql
    GROUP BY
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
        b.name,
        b.code,
        u.full_name
    ORDER BY s.sale_date DESC, s.id DESC
    LIMIT $per_page OFFSET $offset
");
$salesStmt->execute($params);
$sales = $salesStmt->fetchAll(PDO::FETCH_ASSOC);

$summaryStmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total_sales,
        COALESCE(SUM(s.total), 0) AS total_value,
        COALESCE(SUM(CASE WHEN s.payment_status = 'PAID' THEN s.total ELSE 0 END), 0) AS paid_value,
        COALESCE(SUM(CASE WHEN s.payment_status IN ('UNPAID', 'PARTIAL') THEN s.total ELSE 0 END), 0) AS outstanding_value,
        COALESCE(SUM(CASE WHEN s.sale_status = 'VOIDED' THEN 1 ELSE 0 END), 0) AS voided_sales
    FROM sales s
    INNER JOIN branches b ON b.id = s.branch_id
    INNER JOIN users u ON u.id = s.cashier_id
    $whereSql
");
$summaryStmt->execute($params);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="page-content">
    <div class="page-header">
        <div>
            <h1><i class="fas fa-receipt"></i> Sales</h1>
            <p>View and manage sales transactions across your branches.</p>
        </div>

        <div class="page-actions">
            <a href="<?= APP_URL ?>/pos/" class="btn btn-primary">
                <i class="fas fa-cash-register"></i> Open POS
            </a>
        </div>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon">
                <i class="fas fa-receipt"></i>
            </div>
            <div class="stat-content">
                <span>Total Sales</span>
                <strong><?= number_format((int)$summary['total_sales']) ?></strong>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">
                <i class="fas fa-money-bill-wave"></i>
            </div>
            <div class="stat-content">
                <span>Total Value</span>
                <strong><?= CURRENCY ?> <?= number_format((float)$summary['total_value'], 2) ?></strong>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">
                <i class="fas fa-circle-check"></i>
            </div>
            <div class="stat-content">
                <span>Paid Value</span>
                <strong><?= CURRENCY ?> <?= number_format((float)$summary['paid_value'], 2) ?></strong>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">
                <i class="fas fa-clock"></i>
            </div>
            <div class="stat-content">
                <span>Outstanding</span>
                <strong><?= CURRENCY ?> <?= number_format((float)$summary['outstanding_value'], 2) ?></strong>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-filter"></i> Sales Filters</h2>
        </div>

        <div class="card-body">
            <form method="GET" action="<?= APP_URL ?>/sales/" class="filter-form">
                <div class="form-group">
                    <label for="search">Search</label>
                    <input
                        type="text"
                        name="search"
                        id="search"
                        class="form-control"
                        value="<?= e($search) ?>"
                        placeholder="Invoice, product, SKU, cashier..."
                    >
                </div>

                <div class="form-group">
                    <label for="branch_id">Branch</label>
                    <select name="branch_id" id="branch_id" class="form-control">
                        <option value="0">All Branches</option>
                        <?php foreach ($branches as $branch): ?>
                            <option value="<?= (int)$branch['id'] ?>" <?= $branch_id === (int)$branch['id'] ? 'selected' : '' ?>>
                                <?= e($branch['name']) ?><?= $branch['code'] ? ' (' . e($branch['code']) . ')' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="payment_status">Payment Status</label>
                    <select name="payment_status" id="payment_status" class="form-control">
                        <option value="">All Payments</option>
                        <option value="UNPAID" <?= $payment_status === 'UNPAID' ? 'selected' : '' ?>>Unpaid</option>
                        <option value="PARTIAL" <?= $payment_status === 'PARTIAL' ? 'selected' : '' ?>>Partial</option>
                        <option value="PAID" <?= $payment_status === 'PAID' ? 'selected' : '' ?>>Paid</option>
                        <option value="REFUNDED" <?= $payment_status === 'REFUNDED' ? 'selected' : '' ?>>Refunded</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="sale_status">Sale Status</label>
                    <select name="sale_status" id="sale_status" class="form-control">
                        <option value="">All Sales</option>
                        <option value="COMPLETED" <?= $sale_status === 'COMPLETED' ? 'selected' : '' ?>>Completed</option>
                        <option value="VOIDED" <?= $sale_status === 'VOIDED' ? 'selected' : '' ?>>Voided</option>
                        <option value="RETURNED" <?= $sale_status === 'RETURNED' ? 'selected' : '' ?>>Returned</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="date_from">Date From</label>
                    <input
                        type="date"
                        name="date_from"
                        id="date_from"
                        class="form-control"
                        value="<?= e($date_from) ?>"
                    >
                </div>

                <div class="form-group">
                    <label for="date_to">Date To</label>
                    <input
                        type="date"
                        name="date_to"
                        id="date_to"
                        class="form-control"
                        value="<?= e($date_to) ?>"
                    >
                </div>

                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Filter
                    </button>

                    <a href="<?= APP_URL ?>/sales/" class="btn btn-secondary">
                        <i class="fas fa-rotate-left"></i> Reset
                    </a>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div>
                <h2><i class="fas fa-list"></i> Sales Transactions</h2>
                <span class="card-subtitle">
                    <?= number_format($total_records) ?> transaction<?= $total_records === 1 ? '' : 's' ?> found
                </span>
            </div>
        </div>

        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Invoice</th>
                        <th>Branch</th>
                        <th>Cashier</th>
                        <th>Items</th>
                        <th>Subtotal</th>
                        <th>Discount</th>
                        <th>Total</th>
                        <th>Payment</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (!$sales): ?>
                        <tr>
                            <td colspan="11" class="empty-state">
                                <i class="fas fa-receipt"></i>
                                <h3>No Sales Found</h3>
                                <p>No sales transactions match your current filters.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($sales as $sale): ?>
                            <tr>
                                <td>
                                    <?= date('d M Y', strtotime($sale['sale_date'])) ?>
                                    <small><?= date('H:i', strtotime($sale['sale_date'])) ?></small>
                                </td>

                                <td>
                                    <strong><?= e($sale['invoice_number']) ?></strong>
                                </td>

                                <td>
                                    <?= e($sale['branch_name']) ?>
                                    <?php if ($sale['branch_code']): ?>
                                        <small><?= e($sale['branch_code']) ?></small>
                                    <?php endif; ?>
                                </td>

                                <td><?= e($sale['cashier_name']) ?></td>

                                <td>
                                    <?= number_format((int)$sale['item_count']) ?>
                                    <small><?= number_format((float)$sale['item_quantity'], 3) ?> units</small>
                                </td>

                                <td><?= CURRENCY ?> <?= number_format((float)$sale['subtotal'], 2) ?></td>

                                <td><?= CURRENCY ?> <?= number_format((float)$sale['discount'], 2) ?></td>

                                <td>
                                    <strong><?= CURRENCY ?> <?= number_format((float)$sale['total'], 2) ?></strong>
                                </td>

                                <td>
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
                                </td>

                                <td>
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
                                </td>

                                <td>
                                    <a
                                        href="<?= APP_URL ?>/sales/view.php?id=<?= (int)$sale['id'] ?>"
                                        class="btn btn-sm btn-secondary"
                                        title="View Sale"
                                    >
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php $queryParams = $_GET; ?>

                <?php if ($page > 1): ?>
                    <?php $queryParams['page'] = $page - 1; ?>
                    <a href="?<?= http_build_query($queryParams) ?>" class="pagination-link">
                        <i class="fas fa-chevron-left"></i>
                    </a>
                <?php endif; ?>

                <?php
                $startPage = max(1, $page - 2);
                $endPage = min($total_pages, $page + 2);
                ?>

                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                    <?php $queryParams['page'] = $i; ?>
                    <a
                        href="?<?= http_build_query($queryParams) ?>"
                        class="pagination-link <?= $i === $page ? 'active' : '' ?>"
                    >
                        <?= $i ?>
                    </a>
                <?php endfor; ?>

                <?php if ($page < $total_pages): ?>
                    <?php $queryParams['page'] = $page + 1; ?>
                    <a href="?<?= http_build_query($queryParams) ?>" class="pagination-link">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>