<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();
require_permission('products.manage');

$id = (int) ($_GET['id'] ?? 0);

if ($id <= 0) {
    header('Location: ' . APP_URL . '/products/');
    exit;
}

$stmt = $pdo->prepare("
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
    WHERE p.id = ?
    LIMIT 1
");

$stmt->execute([$id]);

$product = $stmt->fetch();

if (!$product) {
    header('Location: ' . APP_URL . '/products/');
    exit;
}

$page_title = 'Edit Product';

$sku = $product['sku'];
$name = $product['name'];
$category_id = (int) $product['category_id'];
$unit_id = (int) $product['unit_id'];
$cost_price = $product['cost_price'];
$selling_price = $product['selling_price'];
$status = $product['status'];

$errors = [];

$categories_stmt = $pdo->query("
    SELECT
        id,
        name,
        status
    FROM categories
    ORDER BY name ASC
");

$categories = $categories_stmt->fetchAll();

$units_stmt = $pdo->query("
    SELECT
        id,
        name
    FROM units
    ORDER BY name ASC
");

$units = $units_stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Invalid security token.');
    }

    $sku = strtoupper(trim($_POST['sku'] ?? ''));
    $name = trim($_POST['name'] ?? '');
    $category_id = (int) ($_POST['category_id'] ?? 0);
    $unit_id = (int) ($_POST['unit_id'] ?? 0);
    $cost_price = trim($_POST['cost_price'] ?? '');
    $selling_price = trim($_POST['selling_price'] ?? '');
    $status = $_POST['status'] ?? 'ACTIVE';

    if ($sku === '') {
        $errors[] = 'SKU is required.';
    } elseif (!preg_match('/^[A-Z0-9._-]{2,50}$/', $sku)) {
        $errors[] = 'SKU may contain only letters, numbers, dots, hyphens and underscores.';
    }

    if ($name === '') {
        $errors[] = 'Product name is required.';
    }

    if (strlen($name) > 150) {
        $errors[] = 'Product name cannot exceed 150 characters.';
    }

    if ($category_id <= 0) {
        $errors[] = 'Please select a category.';
    }

    if ($unit_id <= 0) {
        $errors[] = 'Please select a unit.';
    }

    if ($cost_price === '' || !is_numeric($cost_price) || (float) $cost_price < 0) {
        $errors[] = 'Enter a valid cost price.';
    }

    if ($selling_price === '' || !is_numeric($selling_price) || (float) $selling_price < 0) {
        $errors[] = 'Enter a valid selling price.';
    }

    if (!in_array($status, ['ACTIVE', 'INACTIVE'], true)) {
        $errors[] = 'Invalid product status.';
    }

    if (!$errors) {

        $category_check = $pdo->prepare("
            SELECT
                id,
                status
            FROM categories
            WHERE id = ?
            LIMIT 1
        ");

        $category_check->execute([$category_id]);

        $selected_category = $category_check->fetch();

        if (!$selected_category) {
            $errors[] = 'Selected category does not exist.';
        } elseif (
            $selected_category['status'] !== 'ACTIVE'
            && (int) $category_id !== (int) $product['category_id']
        ) {
            $errors[] = 'You cannot assign an inactive category to this product.';
        }
    }

    if (!$errors) {

        $unit_check = $pdo->prepare("
            SELECT id
            FROM units
            WHERE id = ?
            LIMIT 1
        ");

        $unit_check->execute([$unit_id]);

        if (!$unit_check->fetch()) {
            $errors[] = 'Selected unit is invalid.';
        }
    }

    if (!$errors) {

        $sku_check = $pdo->prepare("
            SELECT id
            FROM products
            WHERE LOWER(sku) = LOWER(?)
            AND id != ?
            LIMIT 1
        ");

        $sku_check->execute([
            $sku,
            $id
        ]);

        if ($sku_check->fetch()) {
            $errors[] = 'Another product with this SKU already exists.';
        }
    }

    if (!$errors) {

        $old_values = [
            'sku' => $product['sku'],
            'name' => $product['name'],
            'category_id' => (int) $product['category_id'],
            'unit_id' => (int) $product['unit_id'],
            'cost_price' => (float) $product['cost_price'],
            'selling_price' => (float) $product['selling_price'],
            'status' => $product['status']
        ];

        $new_values = [
            'sku' => $sku,
            'name' => $name,
            'category_id' => $category_id,
            'unit_id' => $unit_id,
            'cost_price' => (float) $cost_price,
            'selling_price' => (float) $selling_price,
            'status' => $status
        ];

        $sku_changed = $product['sku'] !== $sku;
        $name_changed = $product['name'] !== $name;
        $category_changed = (int) $product['category_id'] !== $category_id;
        $unit_changed = (int) $product['unit_id'] !== $unit_id;
        $cost_changed = (float) $product['cost_price'] !== (float) $cost_price;
        $selling_changed = (float) $product['selling_price'] !== (float) $selling_price;
        $status_changed = $product['status'] !== $status;

        $changed = (
            $sku_changed ||
            $name_changed ||
            $category_changed ||
            $unit_changed ||
            $cost_changed ||
            $selling_changed ||
            $status_changed
        );

        if (!$changed) {
            header('Location: ' . APP_URL . '/products/');
            exit;
        }

        if ($status_changed && !$sku_changed && !$name_changed && !$category_changed && !$unit_changed && !$cost_changed && !$selling_changed) {

            $action = $status === 'ACTIVE'
                ? 'PRODUCT_ACTIVATED'
                : 'PRODUCT_DEACTIVATED';

        } elseif ($sku_changed && !$name_changed && !$category_changed && !$unit_changed && !$cost_changed && !$selling_changed && !$status_changed) {

            $action = 'PRODUCT_SKU_UPDATED';

        } elseif ($name_changed && !$sku_changed && !$category_changed && !$unit_changed && !$cost_changed && !$selling_changed && !$status_changed) {

            $action = 'PRODUCT_NAME_UPDATED';

        } elseif ($category_changed && !$sku_changed && !$name_changed && !$unit_changed && !$cost_changed && !$selling_changed && !$status_changed) {

            $action = 'PRODUCT_CATEGORY_UPDATED';

        } elseif ($unit_changed && !$sku_changed && !$name_changed && !$category_changed && !$cost_changed && !$selling_changed && !$status_changed) {

            $action = 'PRODUCT_UNIT_UPDATED';

        } elseif (($cost_changed || $selling_changed) && !$sku_changed && !$name_changed && !$category_changed && !$unit_changed && !$status_changed) {

            $action = 'PRODUCT_PRICE_UPDATED';

        } else {

            $action = 'PRODUCT_UPDATED';
        }

        try {

            $pdo->beginTransaction();

            $update = $pdo->prepare("
                UPDATE products
                SET
                    sku = ?,
                    name = ?,
                    category_id = ?,
                    unit_id = ?,
                    cost_price = ?,
                    selling_price = ?,
                    status = ?
                WHERE id = ?
            ");

            $update->execute([
                $sku,
                $name,
                $category_id,
                $unit_id,
                (float) $cost_price,
                (float) $selling_price,
                $status,
                $id
            ]);

            $user = current_user();

            $audit = $pdo->prepare("
                INSERT INTO audit_logs
                (
                    user_id,
                    branch_id,
                    action,
                    module,
                    record_id,
                    old_values,
                    new_values,
                    ip_address,
                    user_agent
                )
                VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $audit->execute([
                $user['id'],
                $user['branch_id'] ?: null,
                $action,
                'products',
                $id,
                json_encode($old_values),
                json_encode($new_values),
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);

            $pdo->commit();

            header('Location: ' . APP_URL . '/products/');
            exit;

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $errors[] = 'Unable to update product. Please try again.';
        }
    }
}

include __DIR__ . '/../includes/header.php';
?>

<style>

.product-form-wrapper {
    max-width:900px;
    margin:0 auto;
}

.product-form-card {
    background:#fff;
    border:1px solid #eee;
    border-radius:18px;
    box-shadow:0 10px 30px rgba(0,0,0,.06);
    overflow:hidden;
}

.product-form-header {
    padding:24px 28px;
    border-bottom:1px solid #eee;
    background:#fffdf8;
}

.product-form-header h3 {
    margin:0 0 6px;
    color:#64131f;
    font-size:20px;
}

.product-form-header p {
    margin:0;
    color:#777;
    font-size:14px;
}

.product-form-body {
    padding:28px;
}

.product-errors {
    margin-bottom:24px;
    padding:15px 18px;
    border-radius:12px;
    background:#fdecec;
    border:1px solid #f5caca;
    color:#9b1c1c;
}

.product-errors strong {
    display:block;
    margin-bottom:8px;
}

.product-errors ul {
    margin:0;
    padding-left:20px;
}

.product-errors li {
    margin-bottom:4px;
    font-size:13px;
}

.product-errors li:last-child {
    margin-bottom:0;
}

.product-section {
    margin-bottom:28px;
}

.product-section:last-of-type {
    margin-bottom:0;
}

.product-section-title {
    display:flex;
    align-items:center;
    gap:9px;
    margin-bottom:18px;
    padding-bottom:10px;
    border-bottom:1px solid #eee;
    color:#64131f;
    font-size:15px;
    font-weight:700;
}

.product-section-title i {
    color:#f2b84b;
}

.product-form-grid {
    display:grid;
    grid-template-columns:repeat(2, minmax(0, 1fr));
    gap:20px;
}

.product-field {
    margin-bottom:0;
}

.product-field.full {
    grid-column:1 / -1;
}

.product-field label {
    display:block;
    margin-bottom:8px;
    font-size:13px;
    font-weight:700;
    color:#4d0e17;
}

.product-field label span {
    color:#b42318;
}

.product-field input,
.product-field select {
    width:100%;
    padding:13px 14px;
    border:1px solid #ddd;
    border-radius:10px;
    background:#fff;
    color:#333;
    font-size:14px;
    outline:none;
    transition:.2s;
    box-sizing:border-box;
}

.product-field input:focus,
.product-field select:focus {
    border-color:#64131f;
    box-shadow:0 0 0 3px rgba(100,19,31,.08);
}

.product-field input::placeholder {
    color:#aaa;
}

.product-help {
    margin-top:7px;
    font-size:12px;
    color:#888;
}

.product-price-wrap {
    position:relative;
}

.product-price-prefix {
    position:absolute;
    left:14px;
    top:50%;
    transform:translateY(-50%);
    font-size:12px;
    font-weight:700;
    color:#777;
    pointer-events:none;
}

.product-price-wrap input {
    padding-left:55px;
}

.product-status-options {
    display:flex;
    gap:12px;
}

.product-status-option {
    flex:1;
    position:relative;
}

.product-status-option input {
    position:absolute;
    opacity:0;
    pointer-events:none;
}

.product-status-option label {
    display:flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    padding:13px 15px;
    border:1px solid #ddd;
    border-radius:10px;
    cursor:pointer;
    color:#555;
    background:#fff;
    transition:.2s;
    margin:0;
}

.product-status-option input:checked + label {
    border-color:#64131f;
    background:#fff4c7;
    color:#64131f;
}

.product-current-info {
    margin-bottom:24px;
    padding:16px 18px;
    border-radius:12px;
    background:#f8f5f2;
    border:1px solid #eee;
}

.product-current-info strong {
    display:block;
    color:#64131f;
    margin-bottom:5px;
}

.product-current-info span {
    font-size:13px;
    color:#777;
}

.product-form-actions {
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    margin-top:28px;
    padding-top:24px;
    border-top:1px solid #eee;
}

.product-cancel {
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    padding:12px 18px;
    border:1px solid #ddd;
    border-radius:10px;
    background:#fff;
    color:#555;
    text-decoration:none;
    font-size:14px;
    font-weight:600;
}

.product-cancel:hover {
    background:#f7f7f7;
}

.product-submit {
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    padding:12px 20px;
    border:0;
    border-radius:10px;
    background:#64131f;
    color:#fff;
    font-size:14px;
    font-weight:700;
    cursor:pointer;
    transition:.2s;
}

.product-submit:hover {
    background:#4d0e17;
}

@media(max-width:700px) {

    .product-form-body {
        padding:20px;
    }

    .product-form-header {
        padding:20px;
    }

    .product-form-grid {
        grid-template-columns:1fr;
    }

    .product-field.full {
        grid-column:auto;
    }

    .product-status-options {
        flex-direction:column;
    }

    .product-form-actions {
        flex-direction:column-reverse;
        align-items:stretch;
    }

    .product-cancel,
    .product-submit {
        width:100%;
        box-sizing:border-box;
    }

}

</style>

<div class="page-header">

    <div>
        <p class="page-kicker">Haven Mart</p>

        <h2>Edit Product</h2>

        <p>
            Update product information, pricing and status.
        </p>
    </div>

</div>

<div class="product-form-wrapper">

    <div class="product-form-card">

        <div class="product-form-header">

            <h3>
                <i class="fas fa-pen-to-square"></i>
                Product Details
            </h3>

            <p>
                Make the required changes and save the product.
            </p>

        </div>

        <div class="product-form-body">

            <?php if ($errors): ?>

                <div class="product-errors">

                    <strong>
                        <i class="fas fa-circle-exclamation"></i>
                        Please correct the following:
                    </strong>

                    <ul>

                        <?php foreach ($errors as $error): ?>

                            <li><?= e($error) ?></li>

                        <?php endforeach; ?>

                    </ul>

                </div>

            <?php endif; ?>

            <div class="product-current-info">

                <strong>
                    <i class="fas fa-box"></i>
                    Product ID: <?= (int) $product['id'] ?>
                </strong>

                <span>
                    Current product: <?= e($product['name']) ?>
                    · SKU: <?= e($product['sku']) ?>
                </span>

            </div>

            <form method="POST">

                <?= csrf_field() ?>

                <div class="product-section">

                    <div class="product-section-title">
                        <i class="fas fa-tag"></i>
                        Basic Information
                    </div>

                    <div class="product-form-grid">

                        <div class="product-field">

                            <label for="sku">
                                SKU <span>*</span>
                            </label>

                            <input
                                type="text"
                                id="sku"
                                name="sku"
                                value="<?= e($sku) ?>"
                                maxlength="50"
                                required
                            >

                            <div class="product-help">
                                The SKU must be unique across Haven Mart.
                            </div>

                        </div>

                        <div class="product-field">

                            <label for="name">
                                Product Name <span>*</span>
                            </label>

                            <input
                                type="text"
                                id="name"
                                name="name"
                                value="<?= e($name) ?>"
                                maxlength="150"
                                required
                            >

                        </div>

                        <div class="product-field">

                            <label for="category_id">
                                Category <span>*</span>
                            </label>

                            <select
                                id="category_id"
                                name="category_id"
                                required
                            >

                                <option value="">
                                    Select Category
                                </option>

                                <?php foreach ($categories as $category): ?>

                                    <option
                                        value="<?= (int) $category['id'] ?>"
                                        <?= $category_id === (int) $category['id'] ? 'selected' : '' ?>
                                        <?= $category['status'] === 'INACTIVE' && $category_id !== (int) $category['id'] ? 'disabled' : '' ?>
                                    >
                                        <?= e($category['name']) ?>
                                        <?= $category['status'] === 'INACTIVE' ? ' (Inactive)' : '' ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                        <div class="product-field">

                            <label for="unit_id">
                                Unit <span>*</span>
                            </label>

                            <select
                                id="unit_id"
                                name="unit_id"
                                required
                            >

                                <option value="">
                                    Select Unit
                                </option>

                                <?php foreach ($units as $unit): ?>

                                    <option
                                        value="<?= (int) $unit['id'] ?>"
                                        <?= $unit_id === (int) $unit['id'] ? 'selected' : '' ?>
                                    >
                                        <?= e($unit['name']) ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                    </div>

                </div>

                <div class="product-section">

                    <div class="product-section-title">
                        <i class="fas fa-money-bill-wave"></i>
                        Pricing
                    </div>

                    <div class="product-form-grid">

                        <div class="product-field">

                            <label for="cost_price">
                                Cost Price <span>*</span>
                            </label>

                            <div class="product-price-wrap">

                                <span class="product-price-prefix">
                                    KES
                                </span>

                                <input
                                    type="number"
                                    id="cost_price"
                                    name="cost_price"
                                    value="<?= e($cost_price) ?>"
                                    min="0"
                                    step="0.01"
                                    required
                                >

                            </div>

                            <div class="product-help">
                                Purchase or acquisition cost per unit.
                            </div>

                        </div>

                        <div class="product-field">

                            <label for="selling_price">
                                Selling Price <span>*</span>
                            </label>

                            <div class="product-price-wrap">

                                <span class="product-price-prefix">
                                    KES
                                </span>

                                <input
                                    type="number"
                                    id="selling_price"
                                    name="selling_price"
                                    value="<?= e($selling_price) ?>"
                                    min="0"
                                    step="0.01"
                                    required
                                >

                            </div>

                            <div class="product-help">
                                Default selling price used by the POS.
                            </div>

                        </div>

                    </div>

                </div>

                <div class="product-section">

                    <div class="product-section-title">
                        <i class="fas fa-toggle-on"></i>
                        Product Status
                    </div>

                    <div class="product-status-options">

                        <div class="product-status-option">

                            <input
                                type="radio"
                                id="status_active"
                                name="status"
                                value="ACTIVE"
                                <?= $status === 'ACTIVE' ? 'checked' : '' ?>
                            >

                            <label for="status_active">
                                <i class="fas fa-circle-check"></i>
                                Active
                            </label>

                        </div>

                        <div class="product-status-option">

                            <input
                                type="radio"
                                id="status_inactive"
                                name="status"
                                value="INACTIVE"
                                <?= $status === 'INACTIVE' ? 'checked' : '' ?>
                            >

                            <label for="status_inactive">
                                <i class="fas fa-circle-xmark"></i>
                                Inactive
                            </label>

                        </div>

                    </div>

                    <div class="product-help">
                        Inactive products cannot be selected for normal POS operations.
                    </div>

                </div>

                <div class="product-form-actions">

                    <a
                        href="<?= APP_URL ?>/products/"
                        class="product-cancel"
                    >
                        <i class="fas fa-arrow-left"></i>
                        Cancel
                    </a>

                    <button
                        type="submit"
                        class="product-submit"
                    >
                        <i class="fas fa-floppy-disk"></i>
                        Save Changes
                    </button>

                </div>

            </form>

        </div>

    </div>

</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>