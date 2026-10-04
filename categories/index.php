<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();
require_permission('products.view');

$page_title = 'Categories';

$stmt = $pdo->query("
    SELECT
        c.id,
        c.name,
        c.status,
        COUNT(p.id) AS product_count
    FROM categories c
    LEFT JOIN products p ON p.category_id = c.id
    GROUP BY
        c.id,
        c.name,
        c.status
    ORDER BY c.name ASC
");

$categories = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<style>

.categories-wrapper {
    width:100%;
}

.categories-card {
    background:#fff;
    border:1px solid #eee;
    border-radius:18px;
    box-shadow:0 10px 30px rgba(0,0,0,.06);
    overflow:hidden;
}

.categories-table-wrap {
    width:100%;
    overflow-x:auto;
}

.categories-table {
    width:100%;
    border-collapse:collapse;
    min-width:700px;
}

.categories-table th {
    background:#faf7f5;
    color:#4d0e17;
    font-size:12px;
    text-transform:uppercase;
    letter-spacing:.5px;
    padding:15px 18px;
    text-align:left;
    border-bottom:1px solid #eee;
}

.categories-table td {
    padding:16px 18px;
    border-bottom:1px solid #f0f0f0;
    font-size:14px;
    color:#444;
    vertical-align:middle;
}

.categories-table tbody tr:hover {
    background:#fffdf8;
}

.category-name {
    font-weight:700;
    color:#64131f;
}

.category-icon {
    width:38px;
    height:38px;
    border-radius:10px;
    background:#fff4c7;
    color:#64131f;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    margin-right:10px;
}

.category-name-wrap {
    display:flex;
    align-items:center;
}

.product-count {
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width:34px;
    padding:6px 10px;
    border-radius:20px;
    background:#f5f3f0;
    color:#4d0e17;
    font-weight:700;
    font-size:12px;
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

.category-actions {
    display:flex;
    align-items:center;
    gap:7px;
}

.category-action {
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

.category-action:hover {
    background:#64131f;
    color:#fff;
    border-color:#64131f;
}

.category-action.danger {
    color:#b42318;
}

.category-action.danger:hover {
    background:#b42318;
    color:#fff;
    border-color:#b42318;
}

.category-action.success {
    color:#137333;
}

.category-action.success:hover {
    background:#137333;
    color:#fff;
    border-color:#137333;
}

.empty-categories {
    padding:60px 20px;
    text-align:center;
    color:#888;
}

.empty-categories i {
    font-size:42px;
    color:#d6cfc9;
    margin-bottom:15px;
}

.empty-categories h3 {
    color:#64131f;
    margin-bottom:6px;
}

.empty-categories p {
    font-size:14px;
}

@media(max-width:768px) {

    .page-header {
        align-items:flex-start;
        gap:15px;
    }

    .page-header .button {
        white-space:nowrap;
    }

}

</style>

<div class="page-header">

    <div>
        <p class="page-kicker">Haven Mart</p>
        <h2>Categories</h2>
        <p>Organize products into manageable product categories.</p>
    </div>

    <?php if (has_permission('categories.manage')): ?>

        <a
            href="<?= APP_URL ?>/categories/create.php"
            class="button"
        >
            <i class="fas fa-plus"></i>
            Add Category
        </a>

    <?php endif; ?>

</div>

<div class="categories-wrapper">

    <div class="categories-card">

        <?php if (!$categories): ?>

            <div class="empty-categories">

                <i class="fas fa-layer-group"></i>

                <h3>No Categories Found</h3>

                <p>
                    No product categories are currently available.
                </p>

                <?php if (has_permission('categories.manage')): ?>

                    <a
                        href="<?= APP_URL ?>/categories/create.php"
                        class="button"
                        style="margin-top:18px;"
                    >
                        <i class="fas fa-plus"></i>
                        Create First Category
                    </a>

                <?php endif; ?>

            </div>

        <?php else: ?>

            <div class="categories-table-wrap">

                <table class="categories-table">

                    <thead>

                        <tr>
                            <th>Category</th>
                            <th>Products</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>

                    </thead>

                    <tbody>

                    <?php foreach ($categories as $category): ?>

                        <tr>

                            <td>

                                <div class="category-name-wrap">

                                    <span class="category-icon">
                                        <i class="fas fa-layer-group"></i>
                                    </span>

                                    <span class="category-name">
                                        <?= e($category['name']) ?>
                                    </span>

                                </div>

                            </td>

                            <td>

                                <span class="product-count">
                                    <?= (int) $category['product_count'] ?>
                                </span>

                            </td>

                            <td>

                                <?php if ($category['status'] === 'ACTIVE'): ?>

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

                                <div class="category-actions">

                                    <?php if (has_permission('categories.manage')): ?>

                                        <a
                                            href="<?= APP_URL ?>/categories/edit.php?id=<?= (int) $category['id'] ?>"
                                            class="category-action"
                                            title="Edit Category"
                                        >
                                            <i class="fas fa-pen"></i>
                                        </a>

                                        <form
                                            method="POST"
                                            action="<?= APP_URL ?>/categories/toggle.php"
                                            onsubmit="return confirm('Are you sure you want to change this category status?');"
                                        >

                                            <?= csrf_field() ?>

                                            <input
                                                type="hidden"
                                                name="id"
                                                value="<?= (int) $category['id'] ?>"
                                            >

                                            <?php if ($category['status'] === 'ACTIVE'): ?>

                                                <button
                                                    type="submit"
                                                    class="category-action danger"
                                                    title="Deactivate Category"
                                                >
                                                    <i class="fas fa-toggle-off"></i>
                                                </button>

                                            <?php else: ?>

                                                <button
                                                    type="submit"
                                                    class="category-action success"
                                                    title="Activate Category"
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