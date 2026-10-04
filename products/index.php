<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();
require_permission('products.view');

$page_title = 'Products';

$stmt = $pdo->query("
    SELECT
        p.id,
        p.sku,
        p.name,
        p.category_id,
        p.unit_id,
        p.cost_price,
        p.selling_price,
        p.status,
        c.name AS category_name,
        u.name AS unit_name
    FROM products p
    LEFT JOIN categories c ON c.id = p.category_id
    LEFT JOIN units u ON u.id = p.unit_id
    ORDER BY p.name ASC
");

$products = $stmt->fetchAll();

?>

<?php include __DIR__ . '/../includes/header.php'; ?>

<style>

.products-wrapper {
    width:100%;
}

.products-card {
    background:#fff;
    border:1px solid #eee;
    border-radius:18px;
    box-shadow:0 10px 30px rgba(0,0,0,.06);
    overflow:hidden;
}

.products-table-wrap {
    width:100%;
    overflow-x:auto;
}

.products-table {
    width:100%;
    border-collapse:collapse;
    min-width:1000px;
}

.products-table th {
    background:#faf7f5;
    color:#4d0e17;
    font-size:12px;
    text-transform:uppercase;
    letter-spacing:.5px;
    padding:15px 18px;
    text-align:left;
    border-bottom:1px solid #eee;
    white-space:nowrap;
}

.products-table td {
    padding:15px 18px;
    border-bottom:1px solid #f0f0f0;
    font-size:14px;
    color:#444;
    vertical-align:middle;
}

.products-table tbody tr:hover {
    background:#fffdf8;
}

.product-name-wrap {
    display:flex;
    align-items:center;
    gap:11px;
}

.product-icon {
    width:40px;
    height:40px;
    border-radius:10px;
    background:#fff4c7;
    color:#64131f;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    flex-shrink:0;
}

.product-name {
    font-weight:700;
    color:#64131f;
}

.product-sku {
    font-size:12px;
    color:#888;
    margin-top:3px;
}

.product-category {
    color:#555;
    font-weight:600;
}

.product-unit {
    color:#777;
}

.product-price {
    font-weight:700;
    color:#4d0e17;
    white-space:nowrap;
}

.status-badge {
    display:inline-flex;
    align-items:center;
    gap:6px;
    padding:6px 11px;
    border-radius:20px;
    font-size:12px;
    font-weight:700;
}

.status-active {
    background:#e8f7ef;
    color:#137333;
}

.status-inactive {
    background:#fdecec;
    color:#b42318;
}

.product-actions {
    display:flex;
    align-items:center;
    gap:7px;
}

.product-action {
    width:36px;
    height:36px;
    border-radius:9px;
    border:1px solid #e5e5e5;
    background:#fff;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    color:#64131f;
    text-decoration:none;
    cursor:pointer;
    transition:.2s;
}

.product-action:hover {
    background:#64131f;
    color:#fff;
    border-color:#64131f;
}

.product-action.danger {
    color:#b42318;
}

.product-action.danger:hover {
    background:#b42318;
    color:#fff;
    border-color:#b42318;
}

.product-action.success {
    color:#137333;
}

.product-action.success:hover {
    background:#137333;
    color:#fff;
    border-color:#137333;
}

.empty-products {
    padding:70px 20px;
    text-align:center;
    color:#888;
}

.empty-products i {
    font-size:46px;
    color:#d6cfc9;
    margin-bottom:16px;
}

.empty-products h3 {
    color:#64131f;
    margin-bottom:7px;
}

.empty-products p {
    font-size:14px;
    margin-bottom:20px;
}

.products-summary {
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:15px;
    padding:18px 20px;
    border-bottom:1px solid #eee;
    background:#fff;
}

.products-count {
    display:flex;
    align-items:center;
    gap:9px;
    color:#666;
    font-size:13px;
}

.products-count strong {
    color:#64131f;
    font-size:15px;
}

.products-header-icon {
    width:38px;
    height:38px;
    border-radius:10px;
    background:#fff4c7;
    color:#64131f;
    display:inline-flex;
    align-items:center;
    justify-content:center;
}

@media(max-width:768px) {

    .page-header {
        align-items:flex-start;
        gap:15px;
    }

    .page-header .button {
        white-space:nowrap;
    }

    .products-summary {
        align-items:flex-start;
    }

}

</style>

<div class="page-header">

    <div>
        <p class="page-kicker">Haven Mart</p>

        <h2>Products</h2>

        <p>
            Manage products, pricing, categories and units.
        </p>
    </div>

    <?php if (has_permission('products.manage')): ?>

        <a
            href="<?= APP_URL ?>/products/create.php"
            class="button"
        >
            <i class="fas fa-plus"></i>
            Add Product
        </a>

    <?php endif; ?>

</div>

<div class="products-wrapper">

    <div class="products-card">

        <div class="products-summary">

            <div class="products-count">

                <span class="products-header-icon">
                    <i class="fas fa-boxes-stacked"></i>
                </span>

                <span>
                    Total Products:
                    <strong><?= count($products) ?></strong>
                </span>

            </div>

        </div>

        <?php if (!$products): ?>

            <div class="empty-products">

                <i class="fas fa-box-open"></i>

                <h3>No Products Found</h3>

                <p>
                    No products have been added to Haven Mart yet.
                </p>

                <?php if (has_permission('products.manage')): ?>

                    <a
                        href="<?= APP_URL ?>/products/create.php"
                        class="button"
                    >
                        <i class="fas fa-plus"></i>
                        Add First Product
                    </a>

                <?php endif; ?>

            </div>

        <?php else: ?>

            <div class="products-table-wrap">

                <table class="products-table">

                    <thead>

                        <tr>
                            <th>Product</th>
                            <th>Category</th>
                            <th>Unit</th>
                            <th>Cost Price</th>
                            <th>Selling Price</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>

                    </thead>

                    <tbody>

                    <?php foreach ($products as $product): ?>

                        <tr>

                            <td>

                                <div class="product-name-wrap">

                                    <span class="product-icon">
                                        <i class="fas fa-box"></i>
                                    </span>

                                    <div>

                                        <div class="product-name">
                                            <?= e($product['name']) ?>
                                        </div>

                                        <div class="product-sku">
                                            SKU:
                                            <?= e($product['sku']) ?>
                                        </div>

                                    </div>

                                </div>

                            </td>

                            <td>

                                <span class="product-category">
                                    <?= e($product['category_name'] ?? 'Uncategorized') ?>
                                </span>

                            </td>

                            <td>

                                <span class="product-unit">
                                    <?= e($product['unit_name'] ?? '-') ?>
                                </span>

                            </td>

                            <td>

                                <span class="product-price">
                                    KES <?= number_format((float) $product['cost_price'], 2) ?>
                                </span>

                            </td>

                            <td>

                                <span class="product-price">
                                    KES <?= number_format((float) $product['selling_price'], 2) ?>
                                </span>

                            </td>

                            <td>

                                <?php if ($product['status'] === 'ACTIVE'): ?>

                                    <span class="status-badge status-active">
                                        <i class="fas fa-circle-check"></i>
                                        Active
                                    </span>

                                <?php else: ?>

                                    <span class="status-badge status-inactive">
                                        <i class="fas fa-circle-xmark"></i>
                                        Inactive
                                    </span>

                                <?php endif; ?>

                            </td>

                            <td>

                                <div class="product-actions">

                                    <?php if (has_permission('products.manage')): ?>

                                        <a
                                            href="<?= APP_URL ?>/products/edit.php?id=<?= (int) $product['id'] ?>"
                                            class="product-action"
                                            title="Edit Product"
                                        >
                                            <i class="fas fa-pen"></i>
                                        </a>

                                        <form
                                            method="POST"
                                            action="<?= APP_URL ?>/products/toggle.php"
                                            onsubmit="return confirm('Are you sure you want to change this product status?');"
                                        >

                                            <?= csrf_field() ?>

                                            <input
                                                type="hidden"
                                                name="id"
                                                value="<?= (int) $product['id'] ?>"
                                            >

                                            <?php if ($product['status'] === 'ACTIVE'): ?>

                                                <button
                                                    type="submit"
                                                    class="product-action danger"
                                                    title="Deactivate Product"
                                                >
                                                    <i class="fas fa-toggle-off"></i>
                                                </button>

                                            <?php else: ?>

                                                <button
                                                    type="submit"
                                                    class="product-action success"
                                                    title="Activate Product"
                                                >
                                                    <i class="fas fa-toggle-on"></i>
                                                </button>

                                            <?php endif; ?>

                                        </form>

                                    <?php endif; ?>

                                </div>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </div>

</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>