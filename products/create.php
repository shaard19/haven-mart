<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();
require_permission('products.manage');

$page_title = 'Add Product';

$sku = '';
$name = '';
$category_id = '';
$unit_id = '';
$cost_price = '';
$selling_price = '';
$status = 'ACTIVE';

$errors = [];

$categories_stmt = $pdo->query("
    SELECT
        id,
        name
    FROM categories
    WHERE status = 'ACTIVE'
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
            SELECT id
            FROM categories
            WHERE id = ?
            AND status = 'ACTIVE'
            LIMIT 1
        ");

        $category_check->execute([$category_id]);

        if (!$category_check->fetch()) {
            $errors[] = 'Selected category is invalid or inactive.';
        }

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
            LIMIT 1
        ");

        $sku_check->execute([$sku]);

        if ($sku_check->fetch()) {
            $errors[] = 'A product with this SKU already exists.';
        }
    }

    if (!$errors) {

        try {

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO products
                (
                    sku,
                    name,
                    category_id,
                    unit_id,
                    cost_price,
                    selling_price,
                    status
                )
                VALUES
                (?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $sku,
                $name,
                $category_id,
                $unit_id,
                (float) $cost_price,
                (float) $selling_price,
                $status
            ]);

            $product_id = (int) $pdo->lastInsertId();

            $user = current_user();

            $new_values = json_encode([
                'sku' => $sku,
                'name' => $name,
                'category_id' => $category_id,
                'unit_id' => $unit_id,
                'cost_price' => (float) $cost_price,
                'selling_price' => (float) $selling_price,
                'status' => $status
            ]);

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
                'PRODUCT_CREATED',
                'products',
                $product_id,
                null,
                $new_values,
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

            $errors[] = 'Unable to create product. Please try again.';
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

        <h2>Add Product</h2>

        <p>
            Create a product and define its category, unit and pricing.
        </p>
    </div>

</div>

<div class="product-form-wrapper">

    <div class="product-form-card">

        <div class="product-form-header">

            <h3>
                <i class="fas fa-box-open"></i>
                Product Details
            </h3>

            <p>
                Enter the product information below.
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
                                placeholder="e.g. HM-001"
                                required
                                autofocus
                            >

                            <div class="product-help">
                                Use a unique product code for stock and sales tracking.
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
                                placeholder="e.g. Sunlight Dishwashing Liquid"
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
                                        <?= (int) $category_id === (int) $category['id'] ? 'selected' : '' ?>
                                    >
                                        <?= e($category['name']) ?>
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
                                        <?= (int) $unit_id === (int) $unit['id'] ? 'selected' : '' ?>
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
                                    placeholder="0.00"
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
                                    placeholder="0.00"
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
                        Save Product
                    </button>

                </div>

            </form>

        </div>

    </div>

</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>