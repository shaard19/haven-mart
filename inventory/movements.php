<?php
require_once __DIR__ . '/../includes/auth.php';

require_login();
require_permission('inventory.view');

$product_id = (int)($_GET['product_id'] ?? 0);
$branch_id = (int)($_GET['branch_id'] ?? 0);
$movement_type = strtoupper(trim($_GET['movement_type'] ?? ''));
$search = trim($_GET['search'] ?? '');
$date_from = trim($_GET['date_from'] ?? '');
$date_to = trim($_GET['date_to'] ?? '');

$branches = $pdo->query("
    SELECT id, name, code
    FROM branches
    WHERE status = 'ACTIVE'
    ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$products = $pdo->query("
    SELECT id, name, sku
    FROM products
    WHERE status = 'ACTIVE'
    ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$where = [];
$params = [];

if ($product_id > 0) {
    $where[] = "sm.product_id = :product_id";
    $params['product_id'] = $product_id;
}

if ($branch_id > 0) {
    $where[] = "sm.branch_id = :branch_id";
    $params['branch_id'] = $branch_id;
}

if ($movement_type !== '') {
    $where[] = "UPPER(sm.movement_type) = :movement_type";
    $params['movement_type'] = $movement_type;
}

if ($search !== '') {
    $where[] = "(
        p.name LIKE :search
        OR p.sku LIKE :search
        OR p.barcode LIKE :search
        OR b.name LIKE :search
        OR b.code LIKE :search
        OR sm.notes LIKE :search
        OR sm.reference_type LIKE :search
    )";

    $params['search'] = '%' . $search . '%';
}

if ($date_from !== '') {
    $where[] = "DATE(sm.created_at) >= :date_from";
    $params['date_from'] = $date_from;
}

if ($date_to !== '') {
    $where[] = "DATE(sm.created_at) <= :date_to";
    $params['date_to'] = $date_to;
}

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$count_stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM stock_movements sm
    INNER JOIN products p ON p.id = sm.product_id
    INNER JOIN branches b ON b.id = sm.branch_id
    {$where_sql}
");

$count_stmt->execute($params);
$total_rows = (int)$count_stmt->fetchColumn();

$summary_stmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total_movements,
        COALESCE(SUM(
            CASE
                WHEN UPPER(sm.movement_type) IN (
                    'IN',
                    'PURCHASE',
                    'RECEIPT',
                    'RECEIVED',
                    'TRANSFER_IN',
                    'ADJUSTMENT_IN'
                )
                THEN sm.quantity
                ELSE 0
            END
        ), 0) AS total_in,
        COALESCE(SUM(
            CASE
                WHEN UPPER(sm.movement_type) IN (
                    'OUT',
                    'SALE',
                    'TRANSFER_OUT',
                    'ADJUSTMENT_OUT'
                )
                THEN sm.quantity
                ELSE 0
            END
        ), 0) AS total_out
    FROM stock_movements sm
    INNER JOIN products p ON p.id = sm.product_id
    INNER JOIN branches b ON b.id = sm.branch_id
    {$where_sql}
");

$summary_stmt->execute($params);
$summary = $summary_stmt->fetch(PDO::FETCH_ASSOC);

$total_movements = (int)($summary['total_movements'] ?? 0);
$total_in = (float)($summary['total_in'] ?? 0);
$total_out = (float)($summary['total_out'] ?? 0);

$per_page = 20;
$page = max(1, (int)($_GET['page'] ?? 1));
$total_pages = max(1, (int)ceil($total_rows / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

$stmt = $pdo->prepare("
    SELECT
        sm.id,
        sm.product_id,
        sm.branch_id,
        sm.quantity,
        sm.movement_type,
        sm.reference_type,
        sm.reference_id,
        sm.notes,
        sm.created_at,
        p.name AS product_name,
        p.sku,
        p.barcode,
        u.short_name AS unit_short_name,
        b.name AS branch_name,
        b.code AS branch_code
    FROM stock_movements sm
    INNER JOIN products p ON p.id = sm.product_id
    INNER JOIN units u ON u.id = p.unit_id
    INNER JOIN branches b ON b.id = sm.branch_id
    {$where_sql}
    ORDER BY sm.created_at DESC, sm.id DESC
    LIMIT {$per_page} OFFSET {$offset}
");

$stmt->execute($params);
$movements = $stmt->fetchAll(PDO::FETCH_ASSOC);

function movement_label(string $type): string
{
    return ucwords(strtolower(str_replace(['_', '-'], ' ', $type)));
}

function movement_class(string $type): string
{
    $type = strtoupper($type);

    if (in_array($type, [
        'IN',
        'PURCHASE',
        'RECEIPT',
        'RECEIVED',
        'TRANSFER_IN',
        'ADJUSTMENT_IN'
    ], true)) {
        return 'movement-in';
    }

    if (in_array($type, [
        'OUT',
        'SALE',
        'TRANSFER_OUT',
        'ADJUSTMENT_OUT'
    ], true)) {
        return 'movement-out';
    }

    return 'movement-neutral';
}

function movement_icon(string $type): string
{
    $class = movement_class($type);

    if ($class === 'movement-in') {
        return 'fa-arrow-down';
    }

    if ($class === 'movement-out') {
        return 'fa-arrow-up';
    }

    return 'fa-right-left';
}

function movements_url(array $overrides = []): string
{
    $query = [
        'product_id' => $_GET['product_id'] ?? '',
        'branch_id' => $_GET['branch_id'] ?? '',
        'movement_type' => $_GET['movement_type'] ?? '',
        'search' => $_GET['search'] ?? '',
        'date_from' => $_GET['date_from'] ?? '',
        'date_to' => $_GET['date_to'] ?? '',
        'page' => $_GET['page'] ?? 1
    ];

    foreach ($overrides as $key => $value) {
        $query[$key] = $value;
    }

    $query = array_filter($query, static function ($value) {
        return $value !== '' && $value !== null;
    });

    return APP_URL . '/inventory/movements.php?' . http_build_query($query);
}

require_once __DIR__ . '/../includes/header.php';
?>

<style>
.movements-page {
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

.summary-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    margin-bottom: 20px;
}

.summary-card {
    background: #fff;
    border: 1px solid #eadfda;
    border-radius: 14px;
    padding: 19px;
    box-shadow: 0 5px 18px rgba(0,0,0,.04);
}

.summary-card-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
}

.summary-label {
    color: #777;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .4px;
}

.summary-value {
    margin-top: 7px;
    color: #333;
    font-size: 26px;
    font-weight: 800;
}

.summary-icon {
    width: 44px;
    height: 44px;
    border-radius: 11px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #f5efec;
    color: #64131f;
}

.summary-in .summary-icon {
    background: #e8f5ec;
    color: #24703c;
}

.summary-out .summary-icon {
    background: #fbe5e8;
    color: #a51f35;
}

.filters-card {
    background: #fff;
    border: 1px solid #eadfda;
    border-radius: 14px;
    padding: 18px;
    margin-bottom: 20px;
    box-shadow: 0 5px 18px rgba(0,0,0,.04);
}

.filter-grid {
    display: grid;
    grid-template-columns: 2fr 1.2fr 1.2fr 1fr 1fr 1fr auto auto;
    gap: 11px;
    align-items: end;
}

.filter-group label {
    display: block;
    margin-bottom: 7px;
    color: #555;
    font-size: 11px;
    font-weight: 700;
}

.filter-control {
    width: 100%;
    height: 41px;
    padding: 0 11px;
    border: 1px solid #d9cfca;
    border-radius: 8px;
    background: #fff;
    color: #333;
    outline: none;
    font-size: 12px;
}

.filter-control:focus {
    border-color: #8b1e2d;
    box-shadow: 0 0 0 3px rgba(139,30,45,.08);
}

.filter-btn,
.reset-btn {
    height: 41px;
    padding: 0 15px;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    text-decoration: none;
    font-size: 12px;
    font-weight: 700;
    white-space: nowrap;
}

.filter-btn {
    border: 1px solid #64131f;
    background: #64131f;
    color: #fff;
    cursor: pointer;
}

.filter-btn:hover {
    background: #4d0e17;
}

.reset-btn {
    border: 1px solid #d9cfca;
    background: #fff;
    color: #555;
}

.reset-btn:hover {
    background: #f7f3f1;
}

.movement-card {
    background: #fff;
    border: 1px solid #eadfda;
    border-radius: 14px;
    overflow: hidden;
    box-shadow: 0 5px 18px rgba(0,0,0,.04);
}

.table-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    padding: 18px 20px;
    border-bottom: 1px solid #eee5e1;
}

.table-title {
    color: #333;
    font-size: 16px;
    font-weight: 800;
}

.table-count {
    color: #888;
    font-size: 12px;
}

.table-scroll {
    overflow-x: auto;
}

.movements-table {
    width: 100%;
    min-width: 1050px;
    border-collapse: collapse;
}

.movements-table th {
    padding: 13px 15px;
    background: #faf7f5;
    color: #666;
    border-bottom: 1px solid #eadfda;
    text-align: left;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .4px;
    white-space: nowrap;
}

.movements-table td {
    padding: 14px 15px;
    border-bottom: 1px solid #f0e9e6;
    color: #444;
    font-size: 12px;
    vertical-align: middle;
}

.movements-table tbody tr:last-child td {
    border-bottom: 0;
}

.movements-table tbody tr:hover {
    background: #fffdfb;
}

.date-main {
    color: #555;
    font-weight: 700;
    white-space: nowrap;
}

.date-time {
    color: #999;
    font-size: 10px;
    margin-top: 3px;
}

.product-name {
    color: #64131f;
    font-weight: 800;
}

.product-meta {
    color: #999;
    font-size: 10px;
    margin-top: 4px;
}

.branch-name {
    color: #555;
    font-weight: 700;
}

.branch-code {
    color: #999;
    font-size: 10px;
    margin-top: 3px;
}

.movement-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 9px;
    border-radius: 20px;
    font-size: 10px;
    font-weight: 800;
    white-space: nowrap;
}

.movement-in {
    color: #24703c;
}

.movement-in.movement-badge {
    background: #e8f5ec;
}

.movement-out {
    color: #a51f35;
}

.movement-out.movement-badge {
    background: #fbe5e8;
}

.movement-neutral {
    color: #8a6040;
}

.movement-neutral.movement-badge {
    background: #f5efec;
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

.reference {
    color: #777;
    font-size: 11px;
}

.notes {
    max-width: 220px;
    color: #777;
    line-height: 1.4;
}

.view-btn {
    width: 33px;
    height: 33px;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #fff4c7;
    color: #64131f;
    text-decoration: none;
}

.view-btn:hover {
    background: #f2c94c;
}

.empty-state {
    padding: 55px 20px;
    text-align: center;
    color: #888;
}

.empty-state i {
    display: block;
    margin-bottom: 12px;
    color: #d7cbc5;
    font-size: 40px;
}

.empty-state h3 {
    margin: 0 0 6px;
    color: #555;
    font-size: 16px;
}

.empty-state p {
    margin: 0;
    font-size: 12px;
}

.pagination {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    padding: 16px 20px;
    flex-wrap: wrap;
}

.pagination-info {
    color: #777;
    font-size: 12px;
}

.pagination-links {
    display: flex;
    align-items: center;
    gap: 5px;
}

.page-link {
    min-width: 34px;
    height: 34px;
    padding: 0 9px;
    border: 1px solid #ded4cf;
    border-radius: 7px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #fff;
    color: #555;
    text-decoration: none;
    font-size: 11px;
    font-weight: 700;
}

.page-link:hover {
    border-color: #8b1e2d;
    color: #8b1e2d;
}

.page-link.active {
    background: #64131f;
    border-color: #64131f;
    color: #fff;
}

.page-link.disabled {
    opacity: .45;
    pointer-events: none;
}

@media (max-width: 1150px) {
    .filter-grid {
        grid-template-columns: repeat(4, 1fr);
    }
}

@media (max-width: 750px) {
    .summary-grid {
        grid-template-columns: 1fr;
    }

    .filter-grid {
        grid-template-columns: repeat(2, 1fr);
    }

    .page-header {
        align-items: flex-start;
    }
}

@media (max-width: 500px) {
    .filter-grid {
        grid-template-columns: 1fr;
    }

    .page-header-left {
        align-items: flex-start;
    }
}
</style>

<div class="movements-page">

    <div class="page-header">

        <div class="page-header-left">

            <a href="<?= APP_URL ?>/inventory/" class="back-btn" title="Back to Inventory">
                <i class="fas fa-arrow-left"></i>
            </a>

            <div class="page-title">
                <h1>Stock Movements</h1>
                <p>Complete inventory movement history across branches.</p>
            </div>

        </div>

    </div>

    <div class="summary-grid">

        <div class="summary-card">

            <div class="summary-card-top">

                <div>
                    <div class="summary-label">Total Movements</div>
                    <div class="summary-value">
                        <?= number_format($total_movements) ?>
                    </div>
                </div>

                <div class="summary-icon">
                    <i class="fas fa-clock-rotate-left"></i>
                </div>

            </div>

        </div>

        <div class="summary-card summary-in">

            <div class="summary-card-top">

                <div>
                    <div class="summary-label">Stock In</div>
                    <div class="summary-value">
                        <?= number_format($total_in, 3) ?>
                    </div>
                </div>

                <div class="summary-icon">
                    <i class="fas fa-arrow-down"></i>
                </div>

            </div>

        </div>

        <div class="summary-card summary-out">

            <div class="summary-card-top">

                <div>
                    <div class="summary-label">Stock Out</div>
                    <div class="summary-value">
                        <?= number_format($total_out, 3) ?>
                    </div>
                </div>

                <div class="summary-icon">
                    <i class="fas fa-arrow-up"></i>
                </div>

            </div>

        </div>

    </div>

    <div class="filters-card">

        <form method="get">

            <div class="filter-grid">

                <div class="filter-group">
                    <label>Search</label>
                    <input
                        type="text"
                        name="search"
                        class="filter-control"
                        placeholder="Product, SKU, branch, notes..."
                        value="<?= e($search) ?>"
                    >
                </div>

                <div class="filter-group">
                    <label>Product</label>
                    <select name="product_id" class="filter-control">

                        <option value="0">All Products</option>

                        <?php foreach ($products as $product): ?>

                            <option
                                value="<?= (int)$product['id'] ?>"
                                <?= $product_id === (int)$product['id'] ? 'selected' : '' ?>
                            >
                                <?= e($product['name']) ?> — <?= e($product['sku']) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>
                </div>

                <div class="filter-group">
                    <label>Branch</label>
                    <select name="branch_id" class="filter-control">

                        <option value="0">All Branches</option>

                        <?php foreach ($branches as $branch): ?>

                            <option
                                value="<?= (int)$branch['id'] ?>"
                                <?= $branch_id === (int)$branch['id'] ? 'selected' : '' ?>
                            >
                                <?= e($branch['name']) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>
                </div>

                <div class="filter-group">
                    <label>Movement</label>
                    <select name="movement_type" class="filter-control">

                        <option value="">All Types</option>
                        <option value="PURCHASE" <?= $movement_type === 'PURCHASE' ? 'selected' : '' ?>>Purchase</option>
                        <option value="RECEIVED" <?= $movement_type === 'RECEIVED' ? 'selected' : '' ?>>Received</option>
                        <option value="SALE" <?= $movement_type === 'SALE' ? 'selected' : '' ?>>Sale</option>
                        <option value="TRANSFER_IN" <?= $movement_type === 'TRANSFER_IN' ? 'selected' : '' ?>>Transfer In</option>
                        <option value="TRANSFER_OUT" <?= $movement_type === 'TRANSFER_OUT' ? 'selected' : '' ?>>Transfer Out</option>
                        <option value="ADJUSTMENT_IN" <?= $movement_type === 'ADJUSTMENT_IN' ? 'selected' : '' ?>>Adjustment In</option>
                        <option value="ADJUSTMENT_OUT" <?= $movement_type === 'ADJUSTMENT_OUT' ? 'selected' : '' ?>>Adjustment Out</option>

                    </select>
                </div>

                <div class="filter-group">
                    <label>From</label>
                    <input
                        type="date"
                        name="date_from"
                        class="filter-control"
                        value="<?= e($date_from) ?>"
                    >
                </div>

                <div class="filter-group">
                    <label>To</label>
                    <input
                        type="date"
                        name="date_to"
                        class="filter-control"
                        value="<?= e($date_to) ?>"
                    >
                </div>

                <button type="submit" class="filter-btn">
                    <i class="fas fa-filter"></i>
                    Filter
                </button>

                <a href="<?= APP_URL ?>/inventory/movements.php" class="reset-btn">
                    <i class="fas fa-rotate-left"></i>
                    Reset
                </a>

            </div>

        </form>

    </div>

    <div class="movement-card">

        <div class="table-header">

            <div class="table-title">
                Movement Register
            </div>

            <div class="table-count">
                <?= number_format($total_rows) ?>
                record<?= $total_rows === 1 ? '' : 's' ?>
            </div>

        </div>

        <?php if (!$movements): ?>

            <div class="empty-state">

                <i class="fas fa-clock-rotate-left"></i>

                <h3>No stock movements found</h3>

                <p>
                    Try changing your search or filter options.
                </p>

            </div>

        <?php else: ?>

            <div class="table-scroll">

                <table class="movements-table">

                    <thead>

                        <tr>
                            <th>Date</th>
                            <th>Product</th>
                            <th>Branch</th>
                            <th>Movement</th>
                            <th>Quantity</th>
                            <th>Reference</th>
                            <th>Notes</th>
                            <th>Action</th>
                        </tr>

                    </thead>

                    <tbody>

                    <?php foreach ($movements as $movement): ?>

                        <?php
                        $type = strtoupper((string)$movement['movement_type']);
                        $movement_css = movement_class($type);
                        $movement_icon = movement_icon($type);
                        ?>

                        <tr>

                            <td>

                                <div class="date-main">
                                    <?= e(date('d M Y', strtotime($movement['created_at']))) ?>
                                </div>

                                <div class="date-time">
                                    <?= e(date('H:i', strtotime($movement['created_at']))) ?>
                                </div>

                            </td>

                            <td>

                                <div class="product-name">
                                    <?= e($movement['product_name']) ?>
                                </div>

                                <div class="product-meta">
                                    SKU: <?= e($movement['sku']) ?>

                                    <?php if (!empty($movement['barcode'])): ?>
                                        · <?= e($movement['barcode']) ?>
                                    <?php endif; ?>
                                </div>

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

                                <span class="movement-badge <?= e($movement_css) ?>">

                                    <i class="fas <?= e($movement_icon) ?>"></i>

                                    <?= e(movement_label($type)) ?>

                                </span>

                            </td>

                            <td>

                                <span class="quantity">
                                    <?= number_format((float)$movement['quantity'], 3) ?>
                                </span>

                                <span class="quantity-unit">
                                    <?= e($movement['unit_short_name']) ?>
                                </span>

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

                                <div class="notes">
                                    <?= !empty($movement['notes']) ? e($movement['notes']) : '—' ?>
                                </div>

                            </td>

                            <td>

                                <a
                                    href="<?= APP_URL ?>/inventory/view.php?product_id=<?= (int)$movement['product_id'] ?>&branch_id=<?= (int)$movement['branch_id'] ?>"
                                    class="view-btn"
                                    title="View product stock"
                                >
                                    <i class="fas fa-eye"></i>
                                </a>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

            <?php if ($total_pages > 1): ?>

                <div class="pagination">

                    <div class="pagination-info">

                        Showing
                        <?= number_format($offset + 1) ?>
                        –
                        <?= number_format(min($offset + $per_page, $total_rows)) ?>
                        of
                        <?= number_format($total_rows) ?>

                    </div>

                    <div class="pagination-links">

                        <a
                            href="<?= $page > 1 ? movements_url(['page' => $page - 1]) : '#' ?>"
                            class="page-link <?= $page <= 1 ? 'disabled' : '' ?>"
                        >
                            <i class="fas fa-chevron-left"></i>
                        </a>

                        <?php
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $page + 2);
                        ?>

                        <?php for ($i = $start_page; $i <= $end_page; $i++): ?>

                            <a
                                href="<?= movements_url(['page' => $i]) ?>"
                                class="page-link <?= $i === $page ? 'active' : '' ?>"
                            >
                                <?= $i ?>
                            </a>

                        <?php endfor; ?>

                        <a
                            href="<?= $page < $total_pages ? movements_url(['page' => $page + 1]) : '#' ?>"
                            class="page-link <?= $page >= $total_pages ? 'disabled' : '' ?>"
                        >
                            <i class="fas fa-chevron-right"></i>
                        </a>

                    </div>

                </div>

            <?php endif; ?>

        <?php endif; ?>

    </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>