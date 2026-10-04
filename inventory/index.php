<?php
require_once __DIR__ . '/../includes/auth.php';

require_login();
require_permission('inventory.view');

$search = trim($_GET['search'] ?? '');
$branch_id = (int)($_GET['branch_id'] ?? 0);
$category_id = (int)($_GET['category_id'] ?? 0);
$stock_status = strtoupper(trim($_GET['stock_status'] ?? ''));

$branches = $pdo->query("
    SELECT id, name, code
    FROM branches
    WHERE status = 'ACTIVE'
    ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$categories = $pdo->query("
    SELECT id, name
    FROM categories
    WHERE status = 'ACTIVE'
    ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$where = [
    "p.status = 'ACTIVE'"
];

$params = [];

if ($search !== '') {
    $where[] = "(p.name LIKE :search OR p.sku LIKE :search OR p.barcode LIKE :search)";
    $params['search'] = '%' . $search . '%';
}

if ($branch_id > 0) {
    $where[] = "bs.branch_id = :branch_id";
    $params['branch_id'] = $branch_id;
}

if ($category_id > 0) {
    $where[] = "p.category_id = :category_id";
    $params['category_id'] = $category_id;
}

if ($stock_status === 'OUT') {
    $where[] = "COALESCE(bs.quantity, 0) = 0";
} elseif ($stock_status === 'LOW') {
    $where[] = "COALESCE(bs.quantity, 0) > 0 AND COALESCE(bs.quantity, 0) <= p.reorder_level";
} elseif ($stock_status === 'IN') {
    $where[] = "COALESCE(bs.quantity, 0) > p.reorder_level";
}

$where_sql = implode(' AND ', $where);

$summary_where = [
    "p.status = 'ACTIVE'"
];

$summary_params = [];

if ($branch_id > 0) {
    $summary_where[] = "bs.branch_id = :summary_branch_id";
    $summary_params['summary_branch_id'] = $branch_id;
}

$summary_where_sql = implode(' AND ', $summary_where);

$summary_stmt = $pdo->prepare("
    SELECT
        COUNT(DISTINCT p.id) AS total_products,
        COALESCE(SUM(COALESCE(bs.quantity, 0)), 0) AS total_units,
        COALESCE(SUM(
            CASE
                WHEN COALESCE(bs.quantity, 0) > 0
                AND COALESCE(bs.quantity, 0) <= p.reorder_level
                THEN 1
                ELSE 0
            END
        ), 0) AS low_stock,
        COALESCE(SUM(
            CASE
                WHEN COALESCE(bs.quantity, 0) = 0
                THEN 1
                ELSE 0
            END
        ), 0) AS out_of_stock
    FROM products p
    LEFT JOIN (
        SELECT
            product_id,
            branch_id,
            SUM(quantity) AS quantity
        FROM branch_stock
        GROUP BY product_id, branch_id
    ) bs ON bs.product_id = p.id
    WHERE {$summary_where_sql}
");

$summary_stmt->execute($summary_params);
$summary = $summary_stmt->fetch(PDO::FETCH_ASSOC);

$total_products = (int)($summary['total_products'] ?? 0);
$total_units = (float)($summary['total_units'] ?? 0);
$low_stock = (int)($summary['low_stock'] ?? 0);
$out_of_stock = (int)($summary['out_of_stock'] ?? 0);

$count_stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM (
        SELECT
            p.id
        FROM products p
        INNER JOIN categories c ON c.id = p.category_id
        INNER JOIN units u ON u.id = p.unit_id
        LEFT JOIN (
            SELECT
                product_id,
                branch_id,
                SUM(quantity) AS quantity,
                SUM(reserved_quantity) AS reserved_quantity
            FROM branch_stock
            GROUP BY product_id, branch_id
        ) bs ON bs.product_id = p.id
        LEFT JOIN branches b ON b.id = bs.branch_id
        WHERE {$where_sql}
        GROUP BY
            p.id,
            p.category_id,
            p.unit_id,
            p.sku,
            p.barcode,
            p.name,
            p.cost_price,
            p.selling_price,
            p.reorder_level,
            c.name,
            u.name,
            u.short_name,
            b.id,
            b.name,
            b.code,
            bs.quantity,
            bs.reserved_quantity
    ) inventory_count
");

$count_stmt->execute($params);
$total_rows = (int)$count_stmt->fetchColumn();

$per_page = 15;
$page = max(1, (int)($_GET['page'] ?? 1));
$total_pages = max(1, (int)ceil($total_rows / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

$stmt = $pdo->prepare("
    SELECT
        p.id,
        p.category_id,
        p.unit_id,
        p.sku,
        p.barcode,
        p.name,
        p.cost_price,
        p.selling_price,
        p.reorder_level,
        c.name AS category_name,
        u.name AS unit_name,
        u.short_name AS unit_short_name,
        b.id AS branch_id,
        b.name AS branch_name,
        b.code AS branch_code,
        COALESCE(bs.quantity, 0) AS quantity,
        COALESCE(bs.reserved_quantity, 0) AS reserved_quantity,
        GREATEST(
            COALESCE(bs.quantity, 0) - COALESCE(bs.reserved_quantity, 0),
            0
        ) AS available_quantity
    FROM products p
    INNER JOIN categories c ON c.id = p.category_id
    INNER JOIN units u ON u.id = p.unit_id
    LEFT JOIN (
        SELECT
            product_id,
            branch_id,
            SUM(quantity) AS quantity,
            SUM(reserved_quantity) AS reserved_quantity
        FROM branch_stock
        GROUP BY product_id, branch_id
    ) bs ON bs.product_id = p.id
    LEFT JOIN branches b ON b.id = bs.branch_id
    WHERE {$where_sql}
    ORDER BY p.name ASC, b.name ASC
    LIMIT {$per_page} OFFSET {$offset}
");

$stmt->execute($params);
$inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);

function inventory_status(float $quantity, float $reorder_level): array
{
    if ($quantity <= 0) {
        return [
            'label' => 'OUT OF STOCK',
            'class' => 'status-out'
        ];
    }

    if ($quantity <= $reorder_level) {
        return [
            'label' => 'LOW STOCK',
            'class' => 'status-low'
        ];
    }

    return [
        'label' => 'IN STOCK',
        'class' => 'status-in'
    ];
}

function inventory_url(array $overrides = []): string
{
    $query = [
        'search' => $_GET['search'] ?? '',
        'branch_id' => $_GET['branch_id'] ?? '',
        'category_id' => $_GET['category_id'] ?? '',
        'stock_status' => $_GET['stock_status'] ?? '',
        'page' => $_GET['page'] ?? 1
    ];

    foreach ($overrides as $key => $value) {
        $query[$key] = $value;
    }

    $query = array_filter($query, static function ($value) {
        return $value !== '' && $value !== null;
    });

    return APP_URL . '/inventory/?' . http_build_query($query);
}

require_once __DIR__ . '/../includes/header.php';
?>

<style>
.inventory-page {
    max-width: 1400px;
    margin: 0 auto;
}

.inventory-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
    margin-bottom: 24px;
}

.inventory-title h1 {
    margin: 0 0 6px;
    color: var(--maroon, #64131f);
    font-size: 28px;
}

.inventory-title p {
    margin: 0;
    color: #777;
    font-size: 14px;
}

.inventory-summary {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 22px;
}

.inventory-card {
    background: #fff;
    border-radius: 14px;
    padding: 20px;
    border: 1px solid #eadfda;
    box-shadow: 0 5px 18px rgba(0,0,0,.05);
}

.inventory-card-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
}

.inventory-card-label {
    color: #777;
    font-size: 13px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .4px;
}

.inventory-card-value {
    margin-top: 8px;
    font-size: 28px;
    font-weight: 800;
    color: #333;
}

.inventory-card-icon {
    width: 46px;
    height: 46px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #fff4c7;
    color: #8b1e2d;
    font-size: 19px;
}

.inventory-card.low .inventory-card-icon {
    background: #fff4c7;
    color: #9b6b00;
}

.inventory-card.out .inventory-card-icon {
    background: #fbe5e8;
    color: #a51f35;
}

.inventory-card.units .inventory-card-icon {
    background: #f0e8e4;
    color: #6b4423;
}

.inventory-filters {
    background: #fff;
    border: 1px solid #eadfda;
    border-radius: 14px;
    padding: 18px;
    margin-bottom: 20px;
    box-shadow: 0 5px 18px rgba(0,0,0,.04);
}

.filter-grid {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr 1fr auto auto;
    gap: 12px;
    align-items: end;
}

.filter-group label {
    display: block;
    margin-bottom: 7px;
    color: #555;
    font-size: 12px;
    font-weight: 700;
}

.filter-control {
    width: 100%;
    height: 42px;
    padding: 0 12px;
    border: 1px solid #d9cfca;
    border-radius: 9px;
    background: #fff;
    color: #333;
    outline: none;
}

.filter-control:focus {
    border-color: #8b1e2d;
    box-shadow: 0 0 0 3px rgba(139,30,45,.08);
}

.filter-btn,
.reset-btn {
    height: 42px;
    padding: 0 17px;
    border-radius: 9px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    white-space: nowrap;
}

.filter-btn {
    border: 1px solid #64131f;
    background: #64131f;
    color: #fff;
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

.inventory-table-wrap {
    background: #fff;
    border: 1px solid #eadfda;
    border-radius: 14px;
    overflow: hidden;
    box-shadow: 0 5px 18px rgba(0,0,0,.04);
}

.inventory-table-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 18px 20px;
    border-bottom: 1px solid #eee5e1;
}

.inventory-table-title {
    font-size: 16px;
    font-weight: 800;
    color: #333;
}

.inventory-table-count {
    color: #777;
    font-size: 13px;
}

.inventory-table-scroll {
    overflow-x: auto;
}

.inventory-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 1050px;
}

.inventory-table th {
    background: #faf7f5;
    color: #666;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .45px;
    padding: 13px 15px;
    text-align: left;
    border-bottom: 1px solid #eadfda;
    white-space: nowrap;
}

.inventory-table td {
    padding: 14px 15px;
    border-bottom: 1px solid #f0e9e6;
    vertical-align: middle;
    color: #444;
    font-size: 13px;
}

.inventory-table tbody tr:hover {
    background: #fffdfb;
}

.product-name {
    color: #64131f;
    font-weight: 800;
}

.product-meta {
    margin-top: 4px;
    color: #999;
    font-size: 11px;
}

.branch-name {
    font-weight: 700;
    color: #555;
}

.branch-code {
    color: #999;
    font-size: 11px;
    margin-top: 3px;
}

.quantity-main {
    font-weight: 800;
    color: #333;
}

.quantity-unit {
    color: #999;
    font-size: 11px;
    margin-left: 3px;
}

.reserved {
    color: #8a6040;
    font-size: 12px;
}

.available {
    font-weight: 700;
    color: #333;
}

.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 9px;
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

.action-btn {
    width: 34px;
    height: 34px;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: #64131f;
    background: #fff4c7;
    text-decoration: none;
}

.action-btn:hover {
    background: #f2c94c;
}

.empty-state {
    padding: 55px 20px;
    text-align: center;
    color: #888;
}

.empty-state i {
    font-size: 42px;
    color: #d7cbc5;
    margin-bottom: 14px;
}

.empty-state h3 {
    margin: 0 0 6px;
    color: #555;
}

.empty-state p {
    margin: 0;
    font-size: 13px;
}

.pagination {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 20px;
    gap: 15px;
    flex-wrap: wrap;
}

.pagination-info {
    color: #777;
    font-size: 13px;
}

.pagination-links {
    display: flex;
    gap: 6px;
    align-items: center;
}

.page-link {
    min-width: 34px;
    height: 34px;
    padding: 0 10px;
    border: 1px solid #ded4cf;
    border-radius: 7px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    color: #555;
    font-size: 12px;
    font-weight: 700;
    background: #fff;
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

@media (max-width: 1100px) {
    .inventory-summary {
        grid-template-columns: repeat(2, 1fr);
    }

    .filter-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 650px) {
    .inventory-header {
        align-items: flex-start;
    }

    .inventory-summary {
        grid-template-columns: 1fr;
    }

    .filter-grid {
        grid-template-columns: 1fr;
    }

    .inventory-title h1 {
        font-size: 23px;
    }
}
</style>

<div class="inventory-page">

    <div class="inventory-header">
        <div class="inventory-title">
            <h1><i class="fas fa-boxes-stacked"></i> Inventory</h1>
            <p>Monitor stock levels across all active branches.</p>
        </div>
    </div>

    <div class="inventory-summary">

        <div class="inventory-card">
            <div class="inventory-card-top">
                <div>
                    <div class="inventory-card-label">Products</div>
                    <div class="inventory-card-value"><?= number_format($total_products) ?></div>
                </div>
                <div class="inventory-card-icon">
                    <i class="fas fa-box"></i>
                </div>
            </div>
        </div>

        <div class="inventory-card units">
            <div class="inventory-card-top">
                <div>
                    <div class="inventory-card-label">Stock Units</div>
                    <div class="inventory-card-value"><?= number_format($total_units, 3) ?></div>
                </div>
                <div class="inventory-card-icon">
                    <i class="fas fa-cubes"></i>
                </div>
            </div>
        </div>

        <div class="inventory-card low">
            <div class="inventory-card-top">
                <div>
                    <div class="inventory-card-label">Low Stock</div>
                    <div class="inventory-card-value"><?= number_format($low_stock) ?></div>
                </div>
                <div class="inventory-card-icon">
                    <i class="fas fa-triangle-exclamation"></i>
                </div>
            </div>
        </div>

        <div class="inventory-card out">
            <div class="inventory-card-top">
                <div>
                    <div class="inventory-card-label">Out of Stock</div>
                    <div class="inventory-card-value"><?= number_format($out_of_stock) ?></div>
                </div>
                <div class="inventory-card-icon">
                    <i class="fas fa-circle-xmark"></i>
                </div>
            </div>
        </div>

    </div>

    <div class="inventory-filters">
        <form method="get">
            <div class="filter-grid">

                <div class="filter-group">
                    <label>Search Product</label>
                    <input
                        type="text"
                        name="search"
                        class="filter-control"
                        placeholder="Name, SKU or barcode..."
                        value="<?= e($search) ?>"
                    >
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
                                <?= e($branch['name']) ?><?= !empty($branch['code']) ? ' (' . e($branch['code']) . ')' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label>Category</label>
                    <select name="category_id" class="filter-control">
                        <option value="0">All Categories</option>
                        <?php foreach ($categories as $category): ?>
                            <option
                                value="<?= (int)$category['id'] ?>"
                                <?= $category_id === (int)$category['id'] ? 'selected' : '' ?>
                            >
                                <?= e($category['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label>Stock Status</label>
                    <select name="stock_status" class="filter-control">
                        <option value="">All Status</option>
                        <option value="IN" <?= $stock_status === 'IN' ? 'selected' : '' ?>>In Stock</option>
                        <option value="LOW" <?= $stock_status === 'LOW' ? 'selected' : '' ?>>Low Stock</option>
                        <option value="OUT" <?= $stock_status === 'OUT' ? 'selected' : '' ?>>Out of Stock</option>
                    </select>
                </div>

                <button type="submit" class="filter-btn">
                    <i class="fas fa-filter"></i>
                    Filter
                </button>

                <a href="<?= APP_URL ?>/inventory/" class="reset-btn">
                    <i class="fas fa-rotate-left"></i>
                    Reset
                </a>

            </div>
        </form>
    </div>

    <div class="inventory-table-wrap">

        <div class="inventory-table-header">
            <div class="inventory-table-title">
                Stock Overview
            </div>
            <div class="inventory-table-count">
                <?= number_format($total_rows) ?> stock record<?= $total_rows === 1 ? '' : 's' ?>
            </div>
        </div>

        <?php if (!$inventory): ?>

            <div class="empty-state">
                <i class="fas fa-box-open"></i>
                <h3>No inventory records found</h3>
                <p>Try adjusting your search or filter options.</p>
            </div>

        <?php else: ?>

            <div class="inventory-table-scroll">

                <table class="inventory-table">

                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Category</th>
                            <th>Branch</th>
                            <th>Stock</th>
                            <th>Reserved</th>
                            <th>Available</th>
                            <th>Reorder Level</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php foreach ($inventory as $item): ?>

                        <?php
                        $quantity = (float)$item['quantity'];
                        $reserved = (float)$item['reserved_quantity'];
                        $available = (float)$item['available_quantity'];
                        $reorder_level = (float)$item['reorder_level'];
                        $status = inventory_status($quantity, $reorder_level);
                        ?>

                        <tr>

                            <td>
                                <div class="product-name">
                                    <?= e($item['name']) ?>
                                </div>

                                <div class="product-meta">
                                    SKU: <?= e($item['sku']) ?>
                                    <?php if (!empty($item['barcode'])): ?>
                                        · <?= e($item['barcode']) ?>
                                    <?php endif; ?>
                                </div>
                            </td>

                            <td>
                                <?= e($item['category_name']) ?>
                            </td>

                            <td>
                                <?php if (!empty($item['branch_name'])): ?>
                                    <div class="branch-name">
                                        <?= e($item['branch_name']) ?>
                                    </div>

                                    <?php if (!empty($item['branch_code'])): ?>
                                        <div class="branch-code">
                                            <?= e($item['branch_code']) ?>
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color:#aaa;">No stock record</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <span class="quantity-main">
                                    <?= number_format($quantity, 3) ?>
                                </span>
                                <span class="quantity-unit">
                                    <?= e($item['unit_short_name']) ?>
                                </span>
                            </td>

                            <td>
                                <span class="reserved">
                                    <?= number_format($reserved, 3) ?>
                                </span>
                            </td>

                            <td>
                                <span class="available">
                                    <?= number_format($available, 3) ?>
                                </span>
                            </td>

                            <td>
                                <?= number_format($reorder_level, 3) ?>
                                <?= e($item['unit_short_name']) ?>
                            </td>

                            <td>
                                <span class="status-badge <?= e($status['class']) ?>">
                                    <?php if ($status['class'] === 'status-out'): ?>
                                        <i class="fas fa-circle-xmark"></i>
                                    <?php elseif ($status['class'] === 'status-low'): ?>
                                        <i class="fas fa-triangle-exclamation"></i>
                                    <?php else: ?>
                                        <i class="fas fa-circle-check"></i>
                                    <?php endif; ?>

                                    <?= e($status['label']) ?>
                                </span>
                            </td>

                            <td>
                                <a
                                    href="<?= APP_URL ?>/inventory/view.php?product_id=<?= (int)$item['id'] ?><?= $item['branch_id'] ? '&branch_id=' . (int)$item['branch_id'] : '' ?>"
                                    class="action-btn"
                                    title="View stock details"
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
                            href="<?= $page > 1 ? inventory_url(['page' => $page - 1]) : '#' ?>"
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
                                href="<?= inventory_url(['page' => $i]) ?>"
                                class="page-link <?= $i === $page ? 'active' : '' ?>"
                            >
                                <?= $i ?>
                            </a>

                        <?php endfor; ?>

                        <a
                            href="<?= $page < $total_pages ? inventory_url(['page' => $page + 1]) : '#' ?>"
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