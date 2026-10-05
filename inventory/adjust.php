<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_login();
require_permission('inventory.view');

$product_id = (int)($_GET['product_id'] ?? 0);
$branch_id = (int)($_GET['branch_id'] ?? 0);

if ($product_id <= 0) {
    header('Location: ' . APP_URL . '/inventory/');
    exit;
}

$productStmt = $pdo->prepare("
    SELECT
        p.id,
        p.name,
        p.sku,
        p.barcode,
        p.reorder_level,
        p.status,
        c.name AS category_name,
        u.name AS unit_name
    FROM products p
    INNER JOIN categories c ON c.id = p.category_id
    INNER JOIN units u ON u.id = p.unit_id
    WHERE p.id = ?
    LIMIT 1
");
$productStmt->execute([$product_id]);
$product = $productStmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    header('Location: ' . APP_URL . '/inventory/');
    exit;
}

$branchesStmt = $pdo->query("
    SELECT id, name, code, status
    FROM branches
    WHERE status = 'ACTIVE'
    ORDER BY name ASC
");
$branches = $branchesStmt->fetchAll(PDO::FETCH_ASSOC);

$selectedStock = null;

if ($branch_id > 0) {
    $stockStmt = $pdo->prepare("
        SELECT
            bs.id,
            bs.branch_id,
            bs.quantity,
            bs.reserved_quantity,
            b.name AS branch_name,
            b.code AS branch_code
        FROM branch_stock bs
        INNER JOIN branches b ON b.id = bs.branch_id
        WHERE bs.product_id = ?
          AND bs.branch_id = ?
        LIMIT 1
    ");
    $stockStmt->execute([$product_id, $branch_id]);
    $selectedStock = $stockStmt->fetch(PDO::FETCH_ASSOC);
}

$selectedBranchId = $branch_id;
$currentQuantity = $selectedStock ? (float)$selectedStock['quantity'] : 0;
$reservedQuantity = $selectedStock ? (float)$selectedStock['reserved_quantity'] : 0;
$availableQuantity = max(0, $currentQuantity - $reservedQuantity);

$pageTitle = 'Adjust Stock';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<style>
.adjust-page {
    max-width: 1280px;
    margin: 0 auto;
}

.adjust-page .page-header {
    margin-bottom: 24px;
}

.adjust-page .page-header h1 {
    display: flex;
    align-items: center;
    gap: 10px;
}

.adjust-page .page-header h1 i {
    color: #f2c94c;
}

.adjust-layout {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(380px, 0.9fr);
    gap: 24px;
    align-items: start;
}

.adjust-card {
    background: #fff;
    border: 1px solid #eadfdf;
    border-radius: 14px;
    overflow: hidden;
    box-shadow: 0 6px 22px rgba(100, 19, 31, 0.07);
}

.adjust-card-header {
    background: linear-gradient(135deg, #64131f, #8b1e2d);
    color: #fff;
    padding: 18px 22px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
}

.adjust-card-header h2 {
    margin: 0;
    font-size: 17px;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 10px;
}

.adjust-card-header h2 i {
    color: #f2c94c;
}

.adjust-card-body {
    padding: 24px;
}

.product-identity {
    display: flex;
    align-items: center;
    gap: 16px;
    margin-bottom: 24px;
    padding-bottom: 20px;
    border-bottom: 1px solid #eee5e5;
}

.product-icon {
    width: 58px;
    height: 58px;
    flex: 0 0 58px;
    border-radius: 14px;
    background: #fff4c7;
    color: #64131f;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
}

.product-identity h3 {
    margin: 0 0 5px;
    color: #64131f;
    font-size: 21px;
}

.product-identity p {
    margin: 0;
    color: #777;
    font-size: 13px;
}

.product-details {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
}

.product-detail {
    background: #faf8f7;
    border: 1px solid #eee7e4;
    border-radius: 10px;
    padding: 14px;
}

.product-detail-label {
    display: block;
    color: #8a6040;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .05em;
    margin-bottom: 6px;
}

.product-detail-value {
    color: #333;
    font-weight: 700;
    font-size: 14px;
    word-break: break-word;
}

.product-detail-value.muted {
    color: #777;
}

.stock-summary {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 10px;
    margin: 18px 0 22px;
}

.stock-summary-item {
    background: #faf8f7;
    border: 1px solid #eee7e4;
    border-radius: 10px;
    padding: 13px 10px;
    text-align: center;
}

.stock-summary-item span {
    display: block;
    color: #777;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    margin-bottom: 7px;
}

.stock-summary-item strong {
    display: block;
    color: #64131f;
    font-size: 18px;
}

.stock-summary-item.available strong {
    color: #2e7d32;
}

.stock-summary-item.reserved strong {
    color: #8a6040;
}

.adjust-form .form-group {
    margin-bottom: 18px;
}

.adjust-form label {
    display: block;
    margin-bottom: 7px;
    color: #3d3030;
    font-size: 13px;
    font-weight: 700;
}

.adjust-form .required {
    color: #8b1e2d;
}

.adjust-form .form-control {
    width: 100%;
    min-height: 44px;
    border: 1px solid #d9cdca;
    border-radius: 9px;
    background: #fff;
    color: #333;
    padding: 10px 12px;
    font-size: 14px;
    outline: none;
    transition: border-color .2s ease, box-shadow .2s ease;
    box-sizing: border-box;
}

.adjust-form textarea.form-control {
    min-height: 110px;
    resize: vertical;
}

.adjust-form .form-control:focus {
    border-color: #8b1e2d;
    box-shadow: 0 0 0 3px rgba(139, 30, 45, .10);
}

.adjust-type-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}

.adjust-type-option {
    position: relative;
}

.adjust-type-option input {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}

.adjust-type-option label {
    min-height: 72px;
    margin: 0;
    padding: 12px 14px;
    border: 1px solid #ddd2cf;
    border-radius: 10px;
    display: flex;
    align-items: center;
    gap: 12px;
    cursor: pointer;
    background: #fff;
    transition: all .2s ease;
}

.adjust-type-option label i {
    width: 38px;
    height: 38px;
    border-radius: 9px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #f5f1ef;
    color: #64131f;
    font-size: 16px;
}

.adjust-type-option label strong {
    display: block;
    color: #3d3030;
    font-size: 13px;
    margin-bottom: 3px;
}

.adjust-type-option label small {
    color: #888;
    font-size: 11px;
    font-weight: 400;
}

.adjust-type-option input:checked + label {
    border-color: #8b1e2d;
    background: #fff9f9;
    box-shadow: 0 0 0 2px rgba(139, 30, 45, .08);
}

.adjust-type-option input:checked + label i {
    background: #64131f;
    color: #f2c94c;
}

.adjust-preview {
    margin-top: 18px;
    padding: 15px;
    border-radius: 10px;
    background: #fff8e1;
    border: 1px solid #f2df9c;
    display: none;
}

.adjust-preview-title {
    color: #64131f;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    margin-bottom: 8px;
}

.adjust-preview-value {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}

.adjust-preview-value span {
    color: #777;
    font-size: 13px;
}

.adjust-preview-value strong {
    color: #64131f;
    font-size: 20px;
}

.alert-warning {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    background: #fff8e1;
    border: 1px solid #f2df9c;
    color: #654f00;
    border-radius: 10px;
    padding: 14px;
    margin: 20px 0;
    font-size: 13px;
    line-height: 1.5;
}

.alert-warning i {
    color: #b28a00;
    margin-top: 2px;
}

.form-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    padding-top: 6px;
}

.adjust-page .btn {
    min-height: 42px;
    border-radius: 9px;
    padding: 10px 17px;
    font-size: 13px;
    font-weight: 700;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    cursor: pointer;
    border: 1px solid transparent;
    transition: all .2s ease;
}

.adjust-page .btn-primary {
    background: #64131f;
    color: #fff;
    border-color: #64131f;
}

.adjust-page .btn-primary:hover {
    background: #4d0e17;
    border-color: #4d0e17;
    transform: translateY(-1px);
}

.adjust-page .btn-secondary {
    background: #f5f3f0;
    color: #64131f;
    border-color: #ded6d1;
}

.adjust-page .btn-secondary:hover {
    background: #ebe6e1;
}

.branch-note {
    display: flex;
    align-items: center;
    gap: 8px;
    color: #777;
    font-size: 12px;
    margin-top: 7px;
}

.branch-note i {
    color: #8a6040;
}

@media (max-width: 950px) {
    .adjust-layout {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 600px) {
    .adjust-card-body {
        padding: 18px;
    }

    .product-details,
    .stock-summary,
    .adjust-type-grid {
        grid-template-columns: 1fr;
    }

    .form-actions {
        flex-direction: column-reverse;
    }

    .form-actions .btn {
        width: 100%;
    }

    .product-identity {
        align-items: flex-start;
    }
}
</style>

<div class="page-content adjust-page">
    <div class="page-header">
        <div>
            <h1>
                <i class="fas fa-sliders-h"></i>
                Adjust Stock
            </h1>
            <p>Increase or decrease inventory for a specific branch.</p>
        </div>

        <div class="page-actions">
            <a
                href="<?= APP_URL ?>/inventory/view.php?product_id=<?= $product_id ?><?= $branch_id > 0 ? '&branch_id=' . $branch_id : '' ?>"
                class="btn btn-secondary"
            >
                <i class="fas fa-arrow-left"></i>
                Back to Product
            </a>
        </div>
    </div>

    <div class="adjust-layout">

        <div class="adjust-card">
            <div class="adjust-card-header">
                <h2>
                    <i class="fas fa-box"></i>
                    Product Information
                </h2>
            </div>

            <div class="adjust-card-body">
                <div class="product-identity">
                    <div class="product-icon">
                        <i class="fas fa-box-open"></i>
                    </div>

                    <div>
                        <h3><?= e($product['name']) ?></h3>
                        <p><?= e($product['category_name']) ?> · <?= e($product['unit_name']) ?></p>
                    </div>
                </div>

                <div class="product-details">
                    <div class="product-detail">
                        <span class="product-detail-label">SKU</span>
                        <span class="product-detail-value">
                            <?= e($product['sku']) ?>
                        </span>
                    </div>

                    <div class="product-detail">
                        <span class="product-detail-label">Barcode</span>
                        <span class="product-detail-value <?= empty($product['barcode']) ? 'muted' : '' ?>">
                            <?= e($product['barcode'] ?: 'Not assigned') ?>
                        </span>
                    </div>

                    <div class="product-detail">
                        <span class="product-detail-label">Category</span>
                        <span class="product-detail-value">
                            <?= e($product['category_name']) ?>
                        </span>
                    </div>

                    <div class="product-detail">
                        <span class="product-detail-label">Unit</span>
                        <span class="product-detail-value">
                            <?= e($product['unit_name']) ?>
                        </span>
                    </div>

                    <div class="product-detail">
                        <span class="product-detail-label">Reorder Level</span>
                        <span class="product-detail-value">
                            <?= number_format((float)$product['reorder_level'], 3) ?>
                        </span>
                    </div>

                    <div class="product-detail">
                        <span class="product-detail-label">Status</span>
                        <span class="product-detail-value">
                            <?= e($product['status']) ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <div class="adjust-card">
            <div class="adjust-card-header">
                <h2>
                    <i class="fas fa-sliders"></i>
                    Stock Adjustment
                </h2>
            </div>

            <div class="adjust-card-body">
                <form
                    action="<?= APP_URL ?>/inventory/adjust-save.php"
                    method="POST"
                    class="adjust-form"
                    id="adjust-form"
                >
                    <?= csrf_field() ?>

                    <input
                        type="hidden"
                        name="product_id"
                        value="<?= $product_id ?>"
                    >

                    <div class="form-group">
                        <label for="branch_id">
                            Branch <span class="required">*</span>
                        </label>

                        <select
                            name="branch_id"
                            id="branch_id"
                            class="form-control"
                            required
                        >
                            <option value="">Select branch</option>

                            <?php foreach ($branches as $branch): ?>
                                <option
                                    value="<?= (int)$branch['id'] ?>"
                                    <?= $selectedBranchId === (int)$branch['id'] ? 'selected' : '' ?>
                                >
                                    <?= e($branch['name']) ?>
                                    <?= $branch['code'] ? ' (' . e($branch['code']) . ')' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <div class="branch-note">
                            <i class="fas fa-circle-info"></i>
                            Select the branch where the stock change should occur.
                        </div>
                    </div>

                    <div
                        id="stock-summary"
                        class="stock-summary"
                        <?= $selectedStock ? '' : 'style="display:none;"' ?>
                    >
                        <div class="stock-summary-item">
                            <span>Current</span>
                            <strong id="current-stock">
                                <?= number_format($currentQuantity, 3) ?>
                            </strong>
                        </div>

                        <div class="stock-summary-item reserved">
                            <span>Reserved</span>
                            <strong id="reserved-stock">
                                <?= number_format($reservedQuantity, 3) ?>
                            </strong>
                        </div>

                        <div class="stock-summary-item available">
                            <span>Available</span>
                            <strong id="available-stock">
                                <?= number_format($availableQuantity, 3) ?>
                            </strong>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Adjustment Type <span class="required">*</span></label>

                        <div class="adjust-type-grid">
                            <div class="adjust-type-option">
                                <input
                                    type="radio"
                                    name="adjustment_type"
                                    id="adjustment-in"
                                    value="IN"
                                    required
                                >

                                <label for="adjustment-in">
                                    <i class="fas fa-arrow-trend-up"></i>

                                    <span>
                                        <strong>Add Stock</strong>
                                        <small>Increase available quantity</small>
                                    </span>
                                </label>
                            </div>

                            <div class="adjust-type-option">
                                <input
                                    type="radio"
                                    name="adjustment_type"
                                    id="adjustment-out"
                                    value="OUT"
                                >

                                <label for="adjustment-out">
                                    <i class="fas fa-arrow-trend-down"></i>

                                    <span>
                                        <strong>Remove Stock</strong>
                                        <small>Decrease available quantity</small>
                                    </span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="quantity">
                            Quantity <span class="required">*</span>
                        </label>

                        <input
                            type="number"
                            name="quantity"
                            id="quantity"
                            class="form-control"
                            min="0.001"
                            step="0.001"
                            placeholder="Enter quantity"
                            required
                        >

                        <div class="branch-note">
                            <i class="fas fa-calculator"></i>
                            Enter the exact quantity to add or remove.
                        </div>
                    </div>

                    <div id="adjust-preview" class="adjust-preview">
                        <div class="adjust-preview-title">
                            Stock After Adjustment
                        </div>

                        <div class="adjust-preview-value">
                            <span id="preview-label">New quantity</span>
                            <strong id="preview-value">0.000</strong>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="reason">
                            Reason <span class="required">*</span>
                        </label>

                        <textarea
                            name="reason"
                            id="reason"
                            class="form-control"
                            rows="4"
                            maxlength="500"
                            placeholder="Explain why this stock adjustment is being made..."
                            required
                        ></textarea>
                    </div>

                    <div class="alert-warning">
                        <i class="fas fa-triangle-exclamation"></i>

                        <div>
                            <strong>Please verify the adjustment.</strong><br>
                            This change will immediately update the branch stock and will be recorded in the inventory movement history.
                        </div>
                    </div>

                    <div class="form-actions">
                        <a
                            href="<?= APP_URL ?>/inventory/view.php?product_id=<?= $product_id ?><?= $branch_id > 0 ? '&branch_id=' . $branch_id : '' ?>"
                            class="btn btn-secondary"
                        >
                            Cancel
                        </a>

                        <button
                            type="submit"
                            class="btn btn-primary"
                        >
                            <i class="fas fa-check"></i>
                            Save Adjustment
                        </button>
                    </div>
                </form>
            </div>
        </div>

    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const branchSelect = document.getElementById('branch_id');
    const stockSummary = document.getElementById('stock-summary');
    const currentStock = document.getElementById('current-stock');
    const reservedStock = document.getElementById('reserved-stock');
    const availableStock = document.getElementById('available-stock');
    const quantityInput = document.getElementById('quantity');
    const adjustmentInputs = document.querySelectorAll('input[name="adjustment_type"]');
    const preview = document.getElementById('adjust-preview');
    const previewLabel = document.getElementById('preview-label');
    const previewValue = document.getElementById('preview-value');

    let currentQuantity = <?= json_encode($currentQuantity) ?>;
    let availableQuantity = <?= json_encode($availableQuantity) ?>;

    function formatQuantity(value) {
        return Number(value).toFixed(3);
    }

    function updatePreview() {
        const quantity = parseFloat(quantityInput.value) || 0;
        const selected = document.querySelector('input[name="adjustment_type"]:checked');

        if (!selected || quantity <= 0) {
            preview.style.display = 'none';
            return;
        }

        let newQuantity = currentQuantity;

        if (selected.value === 'IN') {
            newQuantity = currentQuantity + quantity;
            previewLabel.textContent = 'Stock after adding';
        } else {
            newQuantity = currentQuantity - quantity;
            previewLabel.textContent = 'Stock after removing';
        }

        previewValue.textContent = formatQuantity(Math.max(0, newQuantity));
        preview.style.display = 'block';
    }

    adjustmentInputs.forEach(function (input) {
        input.addEventListener('change', updatePreview);
    });

    quantityInput.addEventListener('input', updatePreview);

    branchSelect.addEventListener('change', function () {
        const branchId = this.value;

        if (!branchId) {
            stockSummary.style.display = 'none';
            currentStock.textContent = '0.000';
            reservedStock.textContent = '0.000';
            availableStock.textContent = '0.000';
            currentQuantity = 0;
            availableQuantity = 0;
            updatePreview();
            return;
        }

        window.location.href =
            '<?= APP_URL ?>/inventory/adjust.php?product_id=<?= $product_id ?>&branch_id=' +
            encodeURIComponent(branchId);
    });

    document.getElementById('adjust-form').addEventListener('submit', function (event) {
        const selected = document.querySelector('input[name="adjustment_type"]:checked');
        const quantity = parseFloat(quantityInput.value) || 0;

        if (
            selected &&
            selected.value === 'OUT' &&
            quantity > availableQuantity
        ) {
            event.preventDefault();

            alert(
                'Cannot remove more stock than is available.\n\n' +
                'Available: ' + formatQuantity(availableQuantity)
            );

            quantityInput.focus();
        }
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>