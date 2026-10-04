<?php
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
} else {
    $selectedStock = null;
}

$selectedBranchId = $branch_id;

$pageTitle = 'Adjust Stock';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="page-content">
    <div class="page-header">
        <div>
            <h1><i class="fas fa-sliders"></i> Adjust Stock</h1>
            <p>Manually increase or decrease stock for a specific branch.</p>
        </div>
        <div class="page-actions">
            <a href="<?= APP_URL ?>/inventory/view.php?product_id=<?= $product_id ?><?= $branch_id > 0 ? '&branch_id=' . $branch_id : '' ?>" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Product
            </a>
        </div>
    </div>

    <div class="content-grid">
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-box"></i> Product Information</h2>
            </div>

            <div class="card-body">
                <div class="info-grid">
                    <div class="info-item">
                        <span class="info-label">Product</span>
                        <strong><?= e($product['name']) ?></strong>
                    </div>

                    <div class="info-item">
                        <span class="info-label">SKU</span>
                        <strong><?= e($product['sku']) ?></strong>
                    </div>

                    <div class="info-item">
                        <span class="info-label">Barcode</span>
                        <strong><?= e($product['barcode'] ?: 'N/A') ?></strong>
                    </div>

                    <div class="info-item">
                        <span class="info-label">Category</span>
                        <strong><?= e($product['category_name']) ?></strong>
                    </div>

                    <div class="info-item">
                        <span class="info-label">Unit</span>
                        <strong><?= e($product['unit_name']) ?></strong>
                    </div>

                    <div class="info-item">
                        <span class="info-label">Reorder Level</span>
                        <strong><?= number_format((float)$product['reorder_level'], 3) ?></strong>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-sliders-h"></i> Stock Adjustment</h2>
            </div>

            <div class="card-body">
                <form action="<?= APP_URL ?>/inventory/adjust-save.php" method="POST">
                    <?= csrf_field() ?>

                    <input type="hidden" name="product_id" value="<?= $product_id ?>">

                    <div class="form-group">
                        <label for="branch_id">Branch <span class="required">*</span></label>
                        <select name="branch_id" id="branch_id" class="form-control" required>
                            <option value="">Select branch</option>
                            <?php foreach ($branches as $branch): ?>
                                <option value="<?= (int)$branch['id'] ?>" <?= $selectedBranchId === (int)$branch['id'] ? 'selected' : '' ?>>
                                    <?= e($branch['name']) ?><?= $branch['code'] ? ' (' . e($branch['code']) . ')' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div id="stock-summary" class="stock-summary" <?= $selectedStock ? '' : 'style="display:none;"' ?>>
                        <div class="stock-summary-item">
                            <span>Current Stock</span>
                            <strong id="current-stock">
                                <?= $selectedStock ? number_format((float)$selectedStock['quantity'], 3) : '0.000' ?>
                            </strong>
                        </div>

                        <div class="stock-summary-item">
                            <span>Reserved</span>
                            <strong id="reserved-stock">
                                <?= $selectedStock ? number_format((float)$selectedStock['reserved_quantity'], 3) : '0.000' ?>
                            </strong>
                        </div>

                        <div class="stock-summary-item">
                            <span>Available</span>
                            <strong id="available-stock">
                                <?= $selectedStock ? number_format(max(0, (float)$selectedStock['quantity'] - (float)$selectedStock['reserved_quantity']), 3) : '0.000' ?>
                            </strong>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="adjustment_type">Adjustment Type <span class="required">*</span></label>
                        <select name="adjustment_type" id="adjustment_type" class="form-control" required>
                            <option value="">Select adjustment type</option>
                            <option value="IN">Add Stock</option>
                            <option value="OUT">Remove Stock</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="quantity">Quantity <span class="required">*</span></label>
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
                    </div>

                    <div class="form-group">
                        <label for="reason">Reason <span class="required">*</span></label>
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

                    <div class="alert alert-warning">
                        <i class="fas fa-triangle-exclamation"></i>
                        <div>
                            <strong>Please verify the adjustment.</strong>
                            Stock changes made here will be recorded in the inventory movement history.
                        </div>
                    </div>

                    <div class="form-actions">
                        <a href="<?= APP_URL ?>/inventory/view.php?product_id=<?= $product_id ?><?= $branch_id > 0 ? '&branch_id=' . $branch_id : '' ?>" class="btn btn-secondary">
                            Cancel
                        </a>

                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-check"></i> Save Adjustment
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

    branchSelect.addEventListener('change', function () {
        const branchId = this.value;

        if (!branchId) {
            stockSummary.style.display = 'none';
            currentStock.textContent = '0.000';
            reservedStock.textContent = '0.000';
            availableStock.textContent = '0.000';
            return;
        }

        window.location.href = '<?= APP_URL ?>/inventory/adjust.php?product_id=<?= $product_id ?>&branch_id=' + encodeURIComponent(branchId);
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>