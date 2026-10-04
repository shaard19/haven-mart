<?php
require_once __DIR__ . '/../includes/auth.php';

require_login();

$currentPage = 'purchases';
$pageTitle = 'Edit Purchase';

$purchaseId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$purchaseId || $purchaseId < 1) {
    header('Location: index.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT
        p.id,
        p.branch_id,
        p.supplier_id,
        p.reference_number,
        p.purchase_date,
        p.subtotal,
        p.discount,
        p.total,
        p.status,
        p.notes
    FROM purchases p
    WHERE p.id = ?
    LIMIT 1
");

$stmt->execute([$purchaseId]);
$purchase = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$purchase) {
    header('Location: index.php?error=not_found');
    exit;
}

if ($purchase['status'] !== 'DRAFT') {
    header('Location: view.php?id=' . $purchaseId . '&error=not_editable');
    exit;
}

$branchStmt = $pdo->query("
    SELECT
        id,
        name,
        code
    FROM branches
    WHERE status = 'ACTIVE'
    ORDER BY name ASC
");

$branches = $branchStmt->fetchAll(PDO::FETCH_ASSOC);

$supplierStmt = $pdo->query("
    SELECT
        id,
        name,
        contact_person
    FROM suppliers
    WHERE status = 'ACTIVE'
    ORDER BY name ASC
");

$suppliers = $supplierStmt->fetchAll(PDO::FETCH_ASSOC);

$itemStmt = $pdo->prepare("
    SELECT
        pi.id,
        pi.product_id,
        pi.quantity,
        pi.unit_cost,
        pi.total,
        pr.name AS product_name,
        pr.sku AS product_sku,
        pr.barcode AS product_barcode,
        pr.status AS product_status
    FROM purchase_items pi
    INNER JOIN products pr ON pr.id = pi.product_id
    WHERE pi.purchase_id = ?
    ORDER BY pi.id ASC
");

$itemStmt->execute([$purchaseId]);
$purchaseItems = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

$productStmt = $pdo->query("
    SELECT
        id,
        name,
        sku,
        barcode,
        cost_price,
        status
    FROM products
    WHERE status = 'ACTIVE'
    ORDER BY name ASC
");

$products = $productStmt->fetchAll(PDO::FETCH_ASSOC);

$activeProductIds = [];

foreach ($products as $product) {
    $activeProductIds[(int) $product['id']] = true;
}

foreach ($purchaseItems as $item) {
    $productId = (int) $item['product_id'];

    if (!isset($activeProductIds[$productId])) {
        $products[] = [
            'id' => $productId,
            'name' => $item['product_name'],
            'sku' => $item['product_sku'],
            'barcode' => $item['product_barcode'],
            'cost_price' => $item['unit_cost'],
            'status' => 'INACTIVE'
        ];

        $activeProductIds[$productId] = true;
    }
}

$error = $_GET['error'] ?? '';

function editMoney($value): string
{
    return number_format((float) $value, 2, '.', '');
}

function editQuantity($value): string
{
    return number_format((float) $value, 3, '.', '');
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<style>
.purchase-edit-page{
    max-width:1400px;
    margin:0 auto;
    padding-bottom:40px;
}

.purchase-edit-page .page-header{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:20px;
    margin-bottom:24px;
}

.purchase-edit-page .page-title{
    display:flex;
    align-items:center;
    gap:10px;
    color:#4d0e17;
    font-size:27px;
    font-weight:700;
}

.purchase-edit-page .page-title i{
    color:#8b1e2d;
}

.purchase-edit-page .page-subtitle{
    margin-top:6px;
    color:#777;
    font-size:14px;
}

.purchase-edit-page .page-actions{
    display:flex;
    align-items:center;
    gap:10px;
}

.purchase-edit-page .card{
    background:#fff;
    border:1px solid #eadfda;
    border-radius:14px;
    box-shadow:0 4px 18px rgba(77,14,23,.06);
    margin-bottom:22px;
    overflow:hidden;
}

.purchase-edit-page .card-header{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:20px;
    padding:20px 22px;
    background:linear-gradient(135deg,#fffaf7,#fff);
    border-bottom:1px solid #eee4df;
}

.purchase-edit-page .card-header h3{
    display:flex;
    align-items:center;
    gap:9px;
    margin:0 0 5px;
    color:#4d0e17;
    font-size:18px;
}

.purchase-edit-page .card-header h3 i{
    color:#8b1e2d;
}

.purchase-edit-page .text-muted{
    color:#888;
    font-size:13px;
}

.purchase-edit-page .card-body{
    padding:24px;
}

.purchase-edit-page .form-grid{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:20px;
}

.purchase-edit-page .form-group{
    display:flex;
    flex-direction:column;
    gap:8px;
}

.purchase-edit-page .form-group-full{
    grid-column:1/-1;
}

.purchase-edit-page label{
    color:#4d0e17;
    font-size:13px;
    font-weight:700;
}

.purchase-edit-page .required{
    color:#b42318;
}

.purchase-edit-page input,
.purchase-edit-page select,
.purchase-edit-page textarea{
    width:100%;
    box-sizing:border-box;
    border:1px solid #d9cfca;
    border-radius:9px;
    background:#fff;
    color:#333;
    padding:11px 13px;
    font-family:inherit;
    font-size:14px;
    outline:none;
    transition:.2s ease;
}

.purchase-edit-page input:focus,
.purchase-edit-page select:focus,
.purchase-edit-page textarea:focus{
    border-color:#8b1e2d;
    box-shadow:0 0 0 3px rgba(139,30,45,.09);
}

.purchase-edit-page textarea{
    resize:vertical;
    min-height:95px;
}

.purchase-edit-page .status-badge{
    display:inline-flex;
    align-items:center;
    gap:6px;
    padding:7px 13px;
    border-radius:20px;
    background:#fff5d6;
    color:#8a6200;
    font-size:12px;
    font-weight:700;
}

.purchase-edit-page .table-responsive{
    width:100%;
    overflow-x:auto;
}

.purchase-edit-page .purchase-items-table{
    width:100%;
    min-width:850px;
    border-collapse:collapse;
}

.purchase-edit-page .purchase-items-table th{
    background:#f8f3f0;
    color:#4d0e17;
    padding:13px 14px;
    text-align:left;
    font-size:12px;
    text-transform:uppercase;
    letter-spacing:.35px;
    white-space:nowrap;
}

.purchase-edit-page .purchase-items-table td{
    padding:11px 14px;
    border-top:1px solid #eee6e2;
    vertical-align:middle;
}

.purchase-edit-page .purchase-items-table tbody tr:hover{
    background:#fffaf7;
}

.purchase-edit-page .purchase-items-table input,
.purchase-edit-page .purchase-items-table select{
    min-width:100px;
}

.purchase-edit-page .purchase-items-table .product-select{
    min-width:320px;
}

.purchase-edit-page .line-total{
    color:#4d0e17;
    font-weight:700;
    white-space:nowrap;
}

.purchase-edit-page .remove-item-btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    width:36px;
    height:36px;
    border:0;
    border-radius:8px;
    background:#fde8e8;
    color:#a61b1b;
    cursor:pointer;
    transition:.2s ease;
}

.purchase-edit-page .remove-item-btn:hover{
    background:#f7cccc;
    transform:translateY(-1px);
}

.purchase-edit-page .inactive-product{
    color:#9a6700;
}

.purchase-edit-page .purchase-footer{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:40px;
    padding:22px;
    border-top:1px solid #eee6e2;
    background:#fffdfa;
}

.purchase-edit-page .discount-area{
    width:270px;
}

.purchase-edit-page .discount-area label{
    display:block;
    margin-bottom:8px;
}

.purchase-edit-page .totals-area{
    width:360px;
}

.purchase-edit-page .total-line{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:25px;
    padding:9px 0;
    color:#666;
    font-size:14px;
}

.purchase-edit-page .total-line strong{
    color:#4d0e17;
}

.purchase-edit-page .grand-total-line{
    margin-top:8px;
    padding-top:15px;
    border-top:2px solid #f2c94c;
    color:#4d0e17;
    font-size:18px;
}

.purchase-edit-page .grand-total-line strong{
    font-size:22px;
}

.purchase-edit-page .form-actions{
    display:flex;
    justify-content:flex-end;
    align-items:center;
    gap:10px;
    margin-top:4px;
}

.purchase-edit-page .btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    border:0;
    border-radius:9px;
    padding:10px 16px;
    font-family:inherit;
    font-size:13px;
    font-weight:700;
    text-decoration:none;
    cursor:pointer;
    transition:.2s ease;
}

.purchase-edit-page .btn:hover{
    transform:translateY(-1px);
}

.purchase-edit-page .btn-primary{
    background:#64131f;
    color:#fff;
}

.purchase-edit-page .btn-primary:hover{
    background:#4d0e17;
}

.purchase-edit-page .btn-secondary{
    background:#f0ece9;
    color:#4d0e17;
}

.purchase-edit-page .btn-secondary:hover{
    background:#e5ddda;
}

.purchase-edit-page .btn:disabled{
    opacity:.65;
    cursor:not-allowed;
    transform:none;
}

.purchase-edit-page .alert{
    display:flex;
    align-items:flex-start;
    gap:12px;
    margin-bottom:22px;
    padding:15px 17px;
    border-radius:10px;
    background:#fff0f0;
    border:1px solid #f1caca;
    color:#8f1c13;
    font-size:14px;
}

.purchase-edit-page .alert i{
    margin-top:2px;
}

.purchase-edit-page .add-item-area{
    display:flex;
    justify-content:flex-end;
    padding:15px 20px;
    border-top:1px solid #eee6e2;
    background:#fffdfa;
}

@media(max-width:900px){
    .purchase-edit-page .form-grid{
        grid-template-columns:1fr;
    }

    .purchase-edit-page .form-group-full{
        grid-column:auto;
    }

    .purchase-edit-page .purchase-footer{
        flex-direction:column;
    }

    .purchase-edit-page .discount-area,
    .purchase-edit-page .totals-area{
        width:100%;
    }
}

@media(max-width:700px){
    .purchase-edit-page .page-header{
        align-items:flex-start;
        flex-direction:column;
    }

    .purchase-edit-page .page-actions{
        width:100%;
    }

    .purchase-edit-page .page-actions .btn{
        width:100%;
    }

    .purchase-edit-page .card-header{
        align-items:flex-start;
        flex-direction:column;
    }

    .purchase-edit-page .card-header .btn{
        width:100%;
    }

    .purchase-edit-page .card-body{
        padding:18px;
    }

    .purchase-edit-page .form-actions{
        flex-direction:column-reverse;
    }

    .purchase-edit-page .form-actions .btn{
        width:100%;
    }
}
</style>

<main class="main-content purchase-edit-page">

    <div class="page-header">
        <div>
            <div class="page-title">
                <i class="fa-solid fa-pen-to-square"></i>
                Edit Purchase
            </div>

            <div class="page-subtitle">
                Update draft purchase
                <strong><?= e($purchase['reference_number']) ?></strong>
            </div>
        </div>

        <div class="page-actions">
            <a
                href="view.php?id=<?= (int) $purchase['id'] ?>"
                class="btn btn-secondary"
            >
                <i class="fa-solid fa-arrow-left"></i>
                Back to Purchase
            </a>
        </div>
    </div>

    <?php if ($error === 'duplicate_reference'): ?>
        <div class="alert">
            <i class="fa-solid fa-circle-exclamation"></i>
            <div>
                The reference number already exists. Please use a different reference number.
            </div>
        </div>
    <?php elseif ($error === 'invalid_data'): ?>
        <div class="alert">
            <i class="fa-solid fa-circle-exclamation"></i>
            <div>
                Some of the submitted information is invalid. Please check the form and try again.
            </div>
        </div>
    <?php elseif ($error === 'save_failed'): ?>
        <div class="alert">
            <i class="fa-solid fa-circle-exclamation"></i>
            <div>
                The purchase could not be updated. Please try again.
            </div>
        </div>
    <?php endif; ?>

    <form method="POST" action="update.php" id="purchaseForm">

        <input
            type="hidden"
            name="csrf_token"
            value="<?= e(csrf_token()) ?>"
        >

        <input
            type="hidden"
            name="purchase_id"
            value="<?= (int) $purchase['id'] ?>"
        >

        <div class="card">

            <div class="card-header">
                <div>
                    <h3>
                        <i class="fa-solid fa-file-invoice"></i>
                        Purchase Information
                    </h3>

                    <span class="text-muted">
                        Draft purchases can be modified before receiving.
                    </span>
                </div>

                <span class="status-badge">
                    <i class="fa-solid fa-pen"></i>
                    DRAFT
                </span>
            </div>

            <div class="card-body">

                <div class="form-grid">

                    <div class="form-group">
                        <label for="branch_id">
                            Branch
                            <span class="required">*</span>
                        </label>

                        <select
                            name="branch_id"
                            id="branch_id"
                            required
                        >
                            <option value="">Select branch</option>

                            <?php foreach ($branches as $branch): ?>
                                <option
                                    value="<?= (int) $branch['id'] ?>"
                                    <?= (int) $purchase['branch_id'] === (int) $branch['id'] ? 'selected' : '' ?>
                                >
                                    <?= e($branch['name']) ?>

                                    <?php if (!empty($branch['code'])): ?>
                                        - <?= e($branch['code']) ?>
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="supplier_id">
                            Supplier
                        </label>

                        <select
                            name="supplier_id"
                            id="supplier_id"
                        >
                            <option value="">No supplier</option>

                            <?php foreach ($suppliers as $supplier): ?>
                                <option
                                    value="<?= (int) $supplier['id'] ?>"
                                    <?= $purchase['supplier_id'] !== null && (int) $purchase['supplier_id'] === (int) $supplier['id'] ? 'selected' : '' ?>
                                >
                                    <?= e($supplier['name']) ?>

                                    <?php if (!empty($supplier['contact_person'])): ?>
                                        - <?= e($supplier['contact_person']) ?>
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="reference_number">
                            Reference Number
                            <span class="required">*</span>
                        </label>

                        <input
                            type="text"
                            name="reference_number"
                            id="reference_number"
                            maxlength="100"
                            value="<?= e($purchase['reference_number']) ?>"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="purchase_date">
                            Purchase Date
                            <span class="required">*</span>
                        </label>

                        <input
                            type="date"
                            name="purchase_date"
                            id="purchase_date"
                            value="<?= e($purchase['purchase_date']) ?>"
                            required
                        >
                    </div>

                    <div class="form-group form-group-full">
                        <label for="notes">
                            Notes
                        </label>

                        <textarea
                            name="notes"
                            id="notes"
                            rows="4"
                            maxlength="5000"
                            placeholder="Optional purchase notes..."
                        ><?= e((string) ($purchase['notes'] ?? '')) ?></textarea>
                    </div>

                </div>

            </div>
        </div>

        <div class="card">

            <div class="card-header">
                <div>
                    <h3>
                        <i class="fa-solid fa-boxes-stacked"></i>
                        Purchase Items
                    </h3>

                    <span class="text-muted">
                        Update products, quantities and unit costs.
                    </span>
                </div>

                <button
                    type="button"
                    class="btn btn-secondary"
                    id="addItemBtn"
                >
                    <i class="fa-solid fa-plus"></i>
                    Add Item
                </button>
            </div>

            <div class="table-responsive">

                <table class="purchase-items-table">

                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Quantity</th>
                            <th>Unit Cost</th>
                            <th>Line Total</th>
                            <th></th>
                        </tr>
                    </thead>

                    <tbody id="itemsBody">

                        <?php foreach ($purchaseItems as $index => $item): ?>

                            <tr class="purchase-item-row">

                                <td>
                                    <select
                                        name="items[<?= $index ?>][product_id]"
                                        class="product-select"
                                        required
                                    >
                                        <option value="">
                                            Select product
                                        </option>

                                        <?php foreach ($products as $product): ?>

                                            <option
                                                value="<?= (int) $product['id'] ?>"
                                                data-cost="<?= e(editMoney($product['cost_price'])) ?>"
                                                <?= (int) $item['product_id'] === (int) $product['id'] ? 'selected' : '' ?>
                                            >
                                                <?= e($product['name']) ?>

                                                <?php if (!empty($product['sku'])): ?>
                                                    - <?= e($product['sku']) ?>
                                                <?php endif; ?>

                                                <?php if (($product['status'] ?? 'ACTIVE') !== 'ACTIVE'): ?>
                                                    (Inactive)
                                                <?php endif; ?>
                                            </option>

                                        <?php endforeach; ?>

                                    </select>
                                </td>

                                <td>
                                    <input
                                        type="number"
                                        name="items[<?= $index ?>][quantity]"
                                        class="quantity-input"
                                        min="0.001"
                                        step="0.001"
                                        value="<?= e(editQuantity($item['quantity'])) ?>"
                                        required
                                    >
                                </td>

                                <td>
                                    <input
                                        type="number"
                                        name="items[<?= $index ?>][unit_cost]"
                                        class="cost-input"
                                        min="0"
                                        step="0.01"
                                        value="<?= e(editMoney($item['unit_cost'])) ?>"
                                        required
                                    >
                                </td>

                                <td>
                                    <div class="line-total">
                                        KSh <?= e(editMoney($item['total'])) ?>
                                    </div>
                                </td>

                                <td>
                                    <button
                                        type="button"
                                        class="remove-item-btn"
                                        title="Remove item"
                                    >
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

            <div class="add-item-area">
                <button
                    type="button"
                    class="btn btn-secondary"
                    id="bottomAddItemBtn"
                >
                    <i class="fa-solid fa-plus"></i>
                    Add Another Item
                </button>
            </div>

            <div class="purchase-footer">

                <div class="discount-area">

                    <label for="discount">
                        Discount
                    </label>

                    <input
                        type="number"
                        name="discount"
                        id="discount"
                        min="0"
                        step="0.01"
                        value="<?= e(editMoney($purchase['discount'])) ?>"
                    >

                </div>

                <div class="totals-area">

                    <div class="total-line">
                        <span>Subtotal</span>
                        <strong id="subtotalDisplay">
                            KSh <?= e(editMoney($purchase['subtotal'])) ?>
                        </strong>
                    </div>

                    <div class="total-line">
                        <span>Discount</span>
                        <strong id="discountDisplay">
                            KSh <?= e(editMoney($purchase['discount'])) ?>
                        </strong>
                    </div>

                    <div class="total-line grand-total-line">
                        <span>Grand Total</span>
                        <strong id="totalDisplay">
                            KSh <?= e(editMoney($purchase['total'])) ?>
                        </strong>
                    </div>

                </div>

            </div>

        </div>

        <div class="form-actions">

            <a
                href="view.php?id=<?= (int) $purchase['id'] ?>"
                class="btn btn-secondary"
            >
                <i class="fa-solid fa-xmark"></i>
                Cancel
            </a>

            <button
                type="submit"
                class="btn btn-primary"
                id="updatePurchaseBtn"
            >
                <i class="fa-solid fa-floppy-disk"></i>
                Update Purchase
            </button>

        </div>

    </form>

</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('purchaseForm');
    const itemsBody = document.getElementById('itemsBody');
    const addItemBtn = document.getElementById('addItemBtn');
    const bottomAddItemBtn = document.getElementById('bottomAddItemBtn');
    const discountInput = document.getElementById('discount');
    const subtotalDisplay = document.getElementById('subtotalDisplay');
    const discountDisplay = document.getElementById('discountDisplay');
    const totalDisplay = document.getElementById('totalDisplay');
    const updatePurchaseBtn = document.getElementById('updatePurchaseBtn');

    let itemIndex = <?= count($purchaseItems) ?>;

    const productOptions = `
        <option value="">Select product</option>

        <?php foreach ($products as $product): ?>
            <option
                value="<?= (int) $product['id'] ?>"
                data-cost="<?= e(editMoney($product['cost_price'])) ?>"
            >
                <?= e($product['name']) ?>
                <?php if (!empty($product['sku'])): ?>
                    - <?= e($product['sku']) ?>
                <?php endif; ?>
                <?php if (($product['status'] ?? 'ACTIVE') !== 'ACTIVE'): ?>
                    (Inactive)
                <?php endif; ?>
            </option>
        <?php endforeach; ?>
    `;

    function money(value) {
        return Number(value || 0).toLocaleString('en-KE', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function updateTotals() {
        let subtotal = 0;

        itemsBody.querySelectorAll('.purchase-item-row').forEach(function (row) {
            const quantity = parseFloat(
                row.querySelector('.quantity-input')?.value || 0
            );

            const cost = parseFloat(
                row.querySelector('.cost-input')?.value || 0
            );

            const lineTotal =
                Math.max(quantity, 0) *
                Math.max(cost, 0);

            const lineTotalElement =
                row.querySelector('.line-total');

            if (lineTotalElement) {
                lineTotalElement.textContent =
                    'KSh ' + money(lineTotal);
            }

            subtotal += lineTotal;
        });

        let discount = parseFloat(
            discountInput.value || 0
        );

        if (!Number.isFinite(discount) || discount < 0) {
            discount = 0;
        }

        if (discount > subtotal) {
            discount = subtotal;
            discountInput.value =
                discount.toFixed(2);
        }

        const total =
            Math.max(subtotal - discount, 0);

        subtotalDisplay.textContent =
            'KSh ' + money(subtotal);

        discountDisplay.textContent =
            'KSh ' + money(discount);

        totalDisplay.textContent =
            'KSh ' + money(total);
    }

    function bindRow(row) {
        const productSelect =
            row.querySelector('.product-select');

        const quantityInput =
            row.querySelector('.quantity-input');

        const costInput =
            row.querySelector('.cost-input');

        const removeButton =
            row.querySelector('.remove-item-btn');

        productSelect.addEventListener(
            'change',
            function () {
                const selected =
                    productSelect.options[
                        productSelect.selectedIndex
                    ];

                if (
                    selected &&
                    selected.dataset.cost !== undefined &&
                    (
                        !costInput.value ||
                        parseFloat(costInput.value) === 0
                    )
                ) {
                    costInput.value =
                        selected.dataset.cost;
                }

                updateTotals();
            }
        );

        quantityInput.addEventListener(
            'input',
            updateTotals
        );

        costInput.addEventListener(
            'input',
            updateTotals
        );

        removeButton.addEventListener(
            'click',
            function () {
                const rows =
                    itemsBody.querySelectorAll(
                        '.purchase-item-row'
                    );

                if (rows.length <= 1) {
                    alert(
                        'A purchase must contain at least one item.'
                    );
                    return;
                }

                row.remove();
                updateTotals();
            }
        );
    }

    function addItem() {
        const row =
            document.createElement('tr');

        row.className =
            'purchase-item-row';

        row.innerHTML = `
            <td>
                <select
                    name="items[${itemIndex}][product_id]"
                    class="product-select"
                    required
                >
                    ${productOptions}
                </select>
            </td>

            <td>
                <input
                    type="number"
                    name="items[${itemIndex}][quantity]"
                    class="quantity-input"
                    min="0.001"
                    step="0.001"
                    value="1.000"
                    required
                >
            </td>

            <td>
                <input
                    type="number"
                    name="items[${itemIndex}][unit_cost]"
                    class="cost-input"
                    min="0"
                    step="0.01"
                    value="0.00"
                    required
                >
            </td>

            <td>
                <div class="line-total">
                    KSh 0.00
                </div>
            </td>

            <td>
                <button
                    type="button"
                    class="remove-item-btn"
                    title="Remove item"
                >
                    <i class="fa-solid fa-trash"></i>
                </button>
            </td>
        `;

        itemsBody.appendChild(row);

        bindRow(row);

        itemIndex++;

        updateTotals();

        row.querySelector('.product-select')?.focus();
    }

    itemsBody
        .querySelectorAll('.purchase-item-row')
        .forEach(bindRow);

    addItemBtn.addEventListener(
        'click',
        addItem
    );

    bottomAddItemBtn.addEventListener(
        'click',
        addItem
    );

    discountInput.addEventListener(
        'input',
        updateTotals
    );

    form.addEventListener(
        'submit',
        function (event) {
            const rows =
                itemsBody.querySelectorAll(
                    '.purchase-item-row'
                );

            if (rows.length === 0) {
                event.preventDefault();

                alert(
                    'Please add at least one purchase item.'
                );

                return;
            }

            const selectedProducts = [];

            for (const row of rows) {
                const product =
                    row.querySelector(
                        '.product-select'
                    );

                const quantity =
                    row.querySelector(
                        '.quantity-input'
                    );

                const cost =
                    row.querySelector(
                        '.cost-input'
                    );

                if (!product.value) {
                    event.preventDefault();

                    alert(
                        'Please select a product for every purchase item.'
                    );

                    product.focus();

                    return;
                }

                if (
                    selectedProducts.includes(
                        product.value
                    )
                ) {
                    event.preventDefault();

                    alert(
                        'The same product cannot appear more than once in a purchase.'
                    );

                    product.focus();

                    return;
                }

                selectedProducts.push(
                    product.value
                );

                const quantityValue =
                    parseFloat(quantity.value);

                const costValue =
                    parseFloat(cost.value);

                if (
                    !Number.isFinite(quantityValue) ||
                    quantityValue <= 0
                ) {
                    event.preventDefault();

                    alert(
                        'All quantities must be greater than zero.'
                    );

                    quantity.focus();

                    return;
                }

                if (
                    !Number.isFinite(costValue) ||
                    costValue < 0
                ) {
                    event.preventDefault();

                    alert(
                        'All unit costs must be zero or greater.'
                    );

                    cost.focus();

                    return;
                }
            }

            updateTotals();

            updatePurchaseBtn.disabled = true;

            updatePurchaseBtn.innerHTML =
                '<i class="fa-solid fa-spinner fa-spin"></i> Updating...';
        }
    );

    updateTotals();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>