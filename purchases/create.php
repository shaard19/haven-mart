<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$currentPage = 'purchases';

$userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);

if ($userId === false || $userId === null || $userId < 1) {
    http_response_code(403);
    exit('Invalid user session.');
}

$errors = [];
$branches = [];
$suppliers = [];
$products = [];

try {
    $stmt = $pdo->query(
        "SELECT id, name, code
         FROM branches
         WHERE status = 'ACTIVE'
         ORDER BY name ASC"
    );
    $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Haven purchase branch lookup failed: ' . $e->getMessage());
    $errors[] = 'Unable to load branches.';
}

try {
    $stmt = $pdo->query(
        "SELECT id, name, contact_person
         FROM suppliers
         WHERE status = 'ACTIVE'
         ORDER BY name ASC"
    );
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Haven purchase supplier lookup failed: ' . $e->getMessage());
    $errors[] = 'Unable to load suppliers.';
}

try {
    $stmt = $pdo->query(
        "SELECT id, name, sku
         FROM products
         WHERE status = 'ACTIVE'
         ORDER BY name ASC"
    );
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Haven purchase product lookup failed: ' . $e->getMessage());
    $errors[] = 'Unable to load products.';
}

$defaultBranchId = '';

if (count($branches) === 1) {
    $defaultBranchId = (string) $branches[0]['id'];
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<style>
.purchase-create-page{
    max-width:1400px;
    margin:0 auto;
    padding-bottom:40px;
}
.purchase-create-page .page-header{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:20px;
    margin-bottom:24px;
}
.purchase-create-page .page-header h1{
    margin:0 0 6px;
    color:#4d0e17;
    font-size:28px;
    font-weight:700;
}
.purchase-create-page .page-header p{
    margin:0;
    color:#777;
    font-size:14px;
}
.purchase-create-page .card{
    background:#fff;
    border:1px solid #eadfda;
    border-radius:14px;
    box-shadow:0 4px 18px rgba(77,14,23,.06);
    margin-bottom:22px;
    overflow:hidden;
}
.purchase-create-page .card-header{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:15px;
    padding:20px 22px;
    background:linear-gradient(135deg,#fffaf7,#fff);
    border-bottom:1px solid #eee4df;
}
.purchase-create-page .card-header h2{
    margin:0 0 5px;
    color:#4d0e17;
    font-size:18px;
}
.purchase-create-page .card-header p{
    margin:0;
    color:#888;
    font-size:13px;
}
.purchase-create-page .form-grid{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:20px;
    padding:24px;
}
.purchase-create-page .form-group{
    display:flex;
    flex-direction:column;
    gap:8px;
}
.purchase-create-page .form-group-full{
    grid-column:1/-1;
}
.purchase-create-page label{
    color:#4d0e17;
    font-size:13px;
    font-weight:700;
}
.purchase-create-page .required{
    color:#c62828;
}
.purchase-create-page input,
.purchase-create-page select,
.purchase-create-page textarea{
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
.purchase-create-page input:focus,
.purchase-create-page select:focus,
.purchase-create-page textarea:focus{
    border-color:#8b1e2d;
    box-shadow:0 0 0 3px rgba(139,30,45,.09);
}
.purchase-create-page textarea{
    resize:vertical;
    min-height:90px;
}
.purchase-create-page .table-responsive{
    width:100%;
    overflow-x:auto;
}
.purchase-create-page .purchase-items-table{
    width:100%;
    border-collapse:collapse;
}
.purchase-create-page .purchase-items-table th{
    background:#f8f3f0;
    color:#4d0e17;
    padding:13px 15px;
    text-align:left;
    font-size:12px;
    text-transform:uppercase;
    letter-spacing:.35px;
    white-space:nowrap;
}
.purchase-create-page .purchase-items-table td{
    padding:12px 15px;
    border-top:1px solid #eee6e2;
    vertical-align:middle;
}
.purchase-create-page .purchase-items-table input,
.purchase-create-page .purchase-items-table select{
    min-width:110px;
}
.purchase-create-page .purchase-items-table td:first-child select{
    min-width:260px;
}
.purchase-create-page .item-total-display{
    display:inline-block;
    min-width:110px;
    color:#4d0e17;
    font-size:14px;
}
.purchase-create-page .btn{
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
.purchase-create-page .btn:hover{
    transform:translateY(-1px);
}
.purchase-create-page .btn-primary{
    background:#64131f;
    color:#fff;
}
.purchase-create-page .btn-primary:hover{
    background:#4d0e17;
}
.purchase-create-page .btn-secondary{
    background:#f0ece9;
    color:#4d0e17;
}
.purchase-create-page .btn-secondary:hover{
    background:#e5ddda;
}
.purchase-create-page .btn-danger{
    background:#b42318;
    color:#fff;
}
.purchase-create-page .btn-danger:hover{
    background:#8f1c13;
}
.purchase-create-page .btn-sm{
    padding:8px 10px;
    font-size:12px;
}
.purchase-create-page .empty-state{
    padding:42px 20px;
    text-align:center;
    color:#777;
}
.purchase-create-page .empty-state i{
    display:block;
    margin-bottom:12px;
    color:#c9b6ad;
    font-size:38px;
}
.purchase-create-page .empty-state h3{
    margin:0 0 6px;
    color:#4d0e17;
    font-size:17px;
}
.purchase-create-page .empty-state p{
    margin:0 0 18px;
    font-size:13px;
}
.purchase-create-page .purchase-summary-card{
    margin-left:auto;
    max-width:470px;
}
.purchase-create-page .purchase-summary{
    padding:22px;
}
.purchase-create-page .summary-row{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:20px;
    padding:11px 0;
    color:#666;
    font-size:14px;
}
.purchase-create-page .summary-row strong{
    color:#4d0e17;
}
.purchase-create-page .summary-discount{
    width:150px;
}
.purchase-create-page .summary-discount input{
    text-align:right;
}
.purchase-create-page .summary-total{
    margin-top:8px;
    padding-top:18px;
    border-top:2px solid #f2c94c;
    color:#4d0e17;
    font-size:17px;
}
.purchase-create-page .summary-total strong{
    font-size:22px;
}
.purchase-create-page .form-actions{
    display:flex;
    justify-content:flex-end;
    align-items:center;
    gap:10px;
    margin-top:4px;
}
.purchase-create-page .alert{
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
.purchase-create-page .alert>i{
    margin-top:2px;
}
.purchase-create-page .alert div div+div{
    margin-top:5px;
}
@media(max-width:900px){
    .purchase-create-page .form-grid{
        grid-template-columns:1fr;
    }
    .purchase-create-page .form-group-full{
        grid-column:auto;
    }
    .purchase-create-page .purchase-summary-card{
        max-width:none;
    }
}
@media(max-width:700px){
    .purchase-create-page .page-header{
        align-items:flex-start;
        flex-direction:column;
    }
    .purchase-create-page .page-header .btn{
        width:100%;
    }
    .purchase-create-page .card-header{
        align-items:flex-start;
        flex-direction:column;
    }
    .purchase-create-page .card-header .btn{
        width:100%;
    }
    .purchase-create-page .form-grid{
        padding:18px;
        gap:16px;
    }
    .purchase-create-page .purchase-summary{
        padding:18px;
    }
    .purchase-create-page .form-actions{
        flex-direction:column-reverse;
    }
    .purchase-create-page .form-actions .btn{
        width:100%;
    }
}
</style>

<main class="main-content purchase-create-page">

    <div class="page-header">
        <div>
            <h1>New Purchase</h1>
            <p>Create a supplier purchase and add the products received.</p>
        </div>

        <a href="index.php" class="btn btn-secondary">
            <i class="fa-solid fa-arrow-left"></i>
            Back to Purchases
        </a>
    </div>

    <?php if ($errors): ?>
        <div class="alert">
            <i class="fa-solid fa-circle-exclamation"></i>

            <div>
                <?php foreach ($errors as $error): ?>
                    <div><?= e($error) ?></div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <form method="post" action="store.php" id="purchaseForm" autocomplete="off">

        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

        <div class="card">
            <div class="card-header">
                <div>
                    <h2>Purchase Information</h2>
                    <p>Enter the basic details for this supplier purchase.</p>
                </div>
            </div>

            <div class="form-grid">

                <div class="form-group">
                    <label for="branch_id">
                        Branch <span class="required">*</span>
                    </label>

                    <select name="branch_id" id="branch_id" required>
                        <option value="">Select branch</option>

                        <?php foreach ($branches as $branch): ?>
                            <option
                                value="<?= (int) $branch['id'] ?>"
                                <?= $defaultBranchId === (string) $branch['id'] ? 'selected' : '' ?>
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
                    <label for="supplier_id">Supplier</label>

                    <select name="supplier_id" id="supplier_id">
                        <option value="">Select supplier</option>

                        <?php foreach ($suppliers as $supplier): ?>
                            <option value="<?= (int) $supplier['id'] ?>">
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
                        Reference Number <span class="required">*</span>
                    </label>

                    <input
                        type="text"
                        name="reference_number"
                        id="reference_number"
                        maxlength="100"
                        required
                        placeholder="e.g. PUR-00001"
                    >
                </div>

                <div class="form-group">
                    <label for="purchase_date">
                        Purchase Date <span class="required">*</span>
                    </label>

                    <input
                        type="date"
                        name="purchase_date"
                        id="purchase_date"
                        value="<?= date('Y-m-d') ?>"
                        required
                    >
                </div>

                <div class="form-group form-group-full">
                    <label for="notes">Notes</label>

                    <textarea
                        name="notes"
                        id="notes"
                        rows="3"
                        maxlength="5000"
                        placeholder="Optional purchase notes..."
                    ></textarea>
                </div>

            </div>
        </div>

        <div class="card">

            <div class="card-header">
                <div>
                    <h2>Purchase Items</h2>
                    <p>Add the products included in this purchase.</p>
                </div>

                <button type="button" class="btn btn-primary" id="addItemBtn">
                    <i class="fa-solid fa-plus"></i>
                    Add Item
                </button>
            </div>

            <div class="table-responsive">
                <table class="data-table purchase-items-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Quantity</th>
                            <th>Unit Cost</th>
                            <th>Total</th>
                            <th></th>
                        </tr>
                    </thead>

                    <tbody id="purchaseItemsBody"></tbody>
                </table>
            </div>

            <div id="emptyItems" class="empty-state">
                <i class="fa-solid fa-box-open"></i>
                <h3>No items added</h3>
                <p>Add products to this purchase.</p>

                <button
                    type="button"
                    class="btn btn-primary"
                    id="emptyAddItemBtn"
                >
                    <i class="fa-solid fa-plus"></i>
                    Add First Item
                </button>
            </div>

        </div>

        <div class="card purchase-summary-card">

            <div class="purchase-summary">

                <div class="summary-row">
                    <span>Subtotal</span>
                    <strong id="subtotalDisplay">KSh 0.00</strong>
                </div>

                <div class="summary-row">
                    <span>Discount</span>

                    <div class="summary-discount">
                        <input
                            type="number"
                            name="discount"
                            id="discount"
                            min="0"
                            step="0.01"
                            value="0.00"
                        >
                    </div>
                </div>

                <div class="summary-row summary-total">
                    <span>Total</span>
                    <strong id="totalDisplay">KSh 0.00</strong>
                </div>

            </div>
        </div>

        <div class="form-actions">
            <a href="index.php" class="btn btn-secondary">
                Cancel
            </a>

            <button
                type="submit"
                class="btn btn-primary"
                id="savePurchaseBtn"
            >
                <i class="fa-solid fa-floppy-disk"></i>
                Save Purchase
            </button>
        </div>

    </form>
</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('purchaseForm');
    const tbody = document.getElementById('purchaseItemsBody');
    const emptyItems = document.getElementById('emptyItems');
    const addItemBtn = document.getElementById('addItemBtn');
    const emptyAddItemBtn = document.getElementById('emptyAddItemBtn');
    const discountInput = document.getElementById('discount');
    const subtotalDisplay = document.getElementById('subtotalDisplay');
    const totalDisplay = document.getElementById('totalDisplay');
    const savePurchaseBtn = document.getElementById('savePurchaseBtn');

    const products = <?= json_encode(
        $products,
        JSON_HEX_TAG |
        JSON_HEX_AMP |
        JSON_HEX_APOS |
        JSON_HEX_QUOT
    ) ?>;

    let itemIndex = 0;

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatMoney(value) {
        return Number(value || 0).toLocaleString('en-KE', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function productOptions() {
        let options = '<option value="">Select product</option>';

        products.forEach(function (product) {
            const name = escapeHtml(product.name || '');
            const sku = escapeHtml(product.sku || '');

            options += '<option value="' + Number(product.id) + '">' + name;

            if (sku !== '') {
                options += ' - ' + sku;
            }

            options += '</option>';
        });

        return options;
    }

    function updateEmptyState() {
        const rows = tbody.querySelectorAll('tr');
        emptyItems.style.display = rows.length === 0 ? '' : 'none';
    }

    function calculateTotals() {
        let subtotal = 0;

        tbody.querySelectorAll('tr').forEach(function (row) {
            const quantity = parseFloat(
                row.querySelector('.item-quantity')?.value || 0
            );

            const unitCost = parseFloat(
                row.querySelector('.item-unit-cost')?.value || 0
            );

            const lineTotal =
                Math.max(quantity, 0) *
                Math.max(unitCost, 0);

            const totalInput = row.querySelector('.item-total');
            const totalDisplay = row.querySelector('.item-total-display');

            if (totalInput) {
                totalInput.value = lineTotal.toFixed(2);
            }

            if (totalDisplay) {
                totalDisplay.textContent =
                    'KSh ' + formatMoney(lineTotal);
            }

            subtotal += lineTotal;
        });

        let discount = Math.max(
            parseFloat(discountInput.value || 0),
            0
        );

        if (discount > subtotal) {
            discount = subtotal;
            discountInput.value = subtotal.toFixed(2);
        }

        const total = Math.max(subtotal - discount, 0);

        subtotalDisplay.textContent =
            'KSh ' + formatMoney(subtotal);

        totalDisplay.textContent =
            'KSh ' + formatMoney(total);
    }

    function addItem() {
        const index = itemIndex++;
        const row = document.createElement('tr');

        row.dataset.index = index;

        row.innerHTML = `
            <td>
                <select
                    name="items[${index}][product_id]"
                    class="item-product"
                    required
                >
                    ${productOptions()}
                </select>
            </td>

            <td>
                <input
                    type="number"
                    name="items[${index}][quantity]"
                    class="item-quantity"
                    min="0.001"
                    step="0.001"
                    value="1.000"
                    required
                >
            </td>

            <td>
                <input
                    type="number"
                    name="items[${index}][unit_cost]"
                    class="item-unit-cost"
                    min="0"
                    step="0.01"
                    value="0.00"
                    required
                >
            </td>

            <td>
                <input
                    type="hidden"
                    name="items[${index}][total]"
                    class="item-total"
                    value="0.00"
                >

                <strong class="item-total-display">
                    KSh 0.00
                </strong>
            </td>

            <td>
                <button
                    type="button"
                    class="btn btn-sm btn-danger remove-item"
                    title="Remove item"
                >
                    <i class="fa-solid fa-trash"></i>
                </button>
            </td>
        `;

        tbody.appendChild(row);
        updateEmptyState();
        calculateTotals();

        row.querySelector('.item-product')?.focus();
    }

    addItemBtn.addEventListener('click', addItem);
    emptyAddItemBtn.addEventListener('click', addItem);

    tbody.addEventListener('input', function (event) {
        if (
            event.target.classList.contains('item-quantity') ||
            event.target.classList.contains('item-unit-cost')
        ) {
            calculateTotals();
        }
    });

    tbody.addEventListener('change', function (event) {
        if (
            event.target.classList.contains('item-quantity') ||
            event.target.classList.contains('item-unit-cost')
        ) {
            calculateTotals();
        }
    });

    tbody.addEventListener('click', function (event) {
        const button = event.target.closest('.remove-item');

        if (!button) {
            return;
        }

        const row = button.closest('tr');

        if (row) {
            row.remove();
        }

        updateEmptyState();
        calculateTotals();
    });

    discountInput.addEventListener('input', calculateTotals);

    form.addEventListener('submit', function (event) {
        const rows = tbody.querySelectorAll('tr');

        if (rows.length === 0) {
            event.preventDefault();
            alert('Please add at least one product to the purchase.');
            return;
        }

        let valid = true;

        rows.forEach(function (row) {
            const product = row.querySelector('.item-product');
            const quantity = row.querySelector('.item-quantity');
            const unitCost = row.querySelector('.item-unit-cost');

            if (
                !product?.value ||
                parseFloat(quantity?.value || 0) <= 0 ||
                parseFloat(unitCost?.value || 0) < 0
            ) {
                valid = false;
            }
        });

        if (!valid) {
            event.preventDefault();
            alert('Please complete all purchase item details correctly.');
            return;
        }

        savePurchaseBtn.disabled = true;

        savePurchaseBtn.innerHTML =
            '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';
    });

    updateEmptyState();
    calculateTotals();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>