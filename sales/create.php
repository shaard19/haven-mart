<?php
require_once __DIR__ . '/../includes/auth.php';

require_login();
require_permission('sales.view');

$branches = $pdo->query("
    SELECT id, name, code
    FROM branches
    WHERE status = 'ACTIVE'
    ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$products = $pdo->query("
    SELECT
        p.id,
        p.name,
        p.sku,
        p.barcode,
        p.selling_price,
        p.status,
        c.name AS category_name,
        u.name AS unit_name
    FROM products p
    LEFT JOIN categories c ON c.id = p.category_id
    LEFT JOIN units u ON u.id = p.unit_id
    WHERE p.status = 'ACTIVE'
    ORDER BY p.name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$stockRows = $pdo->query("
    SELECT
        product_id,
        branch_id,
        SUM(quantity) AS quantity,
        SUM(reserved_quantity) AS reserved_quantity
    FROM branch_stock
    GROUP BY product_id, branch_id
")->fetchAll(PDO::FETCH_ASSOC);

$stock = [];

foreach ($stockRows as $row) {
    $available = (float)$row['quantity'] - (float)$row['reserved_quantity'];
    $stock[$row['branch_id']][$row['product_id']] = max(0, $available);
}

$page_title = 'Create Sale';

require_once __DIR__ . '/../includes/header.php';
?>

<style>
.sales-create-page {
    max-width: 1400px;
    margin: 0 auto;
}

.sales-create-page .page-header {
    margin-bottom: 24px;
}

.sales-create-page .page-header h1 {
    margin-bottom: 6px;
    color: #64131f;
    font-size: 28px;
    font-weight: 700;
}

.sales-create-page .page-subtitle {
    margin: 0;
    color: #777;
}

.sales-create-page .content-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 390px;
    gap: 22px;
    align-items: start;
}

.sales-create-page .sale-details-card,
.sales-create-page .items-card,
.sales-create-page .summary-card {
    background: #fff;
    border: 1px solid #e8e2dc;
    border-radius: 14px;
    box-shadow: 0 5px 18px rgba(70, 35, 20, .06);
    overflow: visible;
}

.sales-create-page .sale-details-card {
    grid-column: 1 / -1;
}

.sales-create-page .card-header {
    padding: 18px 20px;
    border-bottom: 1px solid #eee7e0;
    background: linear-gradient(to right, #fffdf9, #fff);
}

.sales-create-page .card-header h2 {
    margin: 0 0 4px;
    color: #4d0e17;
    font-size: 18px;
    font-weight: 700;
}

.sales-create-page .card-subtitle {
    margin: 0;
    color: #888;
    font-size: 13px;
}

.sales-create-page .card-body {
    padding: 20px;
}

.sales-create-page .form-grid {
    display: grid;
    grid-template-columns: 280px minmax(0, 1fr);
    gap: 20px;
}

.sales-create-page .form-group {
    position: relative;
}

.sales-create-page .form-group label {
    display: block;
    margin-bottom: 8px;
    color: #4d0e17;
    font-size: 13px;
    font-weight: 700;
}

.sales-create-page .form-control {
    width: 100%;
    min-height: 44px;
    padding: 10px 13px;
    border: 1px solid #d9d1ca;
    border-radius: 8px;
    background: #fff;
    color: #333;
    font-size: 14px;
    outline: none;
    transition: border-color .2s, box-shadow .2s;
}

.sales-create-page .form-control:focus {
    border-color: #8b1e2d;
    box-shadow: 0 0 0 3px rgba(139, 30, 45, .09);
}

.sales-create-page .product-search-wrapper {
    position: relative;
}

.sales-create-page #product_search {
    padding-left: 42px;
    background-image: none;
}

.sales-create-page .search-icon {
    position: absolute;
    left: 15px;
    top: 37px;
    color: #9b8d84;
    pointer-events: none;
    z-index: 2;
}

.sales-create-page .product-search-results {
    position: absolute;
    left: 0;
    right: 0;
    top: 100%;
    z-index: 100;
    display: none;
    margin-top: 6px;
    background: #fff;
    border: 1px solid #e1d8d0;
    border-radius: 10px;
    box-shadow: 0 12px 30px rgba(0, 0, 0, .13);
    max-height: 390px;
    overflow-y: auto;
}

.sales-create-page .product-search-item {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 18px;
    padding: 14px 16px;
    border: 0;
    border-bottom: 1px solid #f0ebe7;
    background: #fff;
    text-align: left;
    cursor: pointer;
    transition: background .15s;
}

.sales-create-page .product-search-item:last-child {
    border-bottom: 0;
}

.sales-create-page .product-search-item:hover {
    background: #fff8e5;
}

.sales-create-page .product-search-main {
    display: flex;
    flex-direction: column;
    gap: 5px;
    min-width: 0;
}

.sales-create-page .product-search-main strong {
    color: #3f171c;
    font-size: 14px;
}

.sales-create-page .product-search-main small {
    color: #8b817a;
    font-size: 12px;
}

.sales-create-page .product-search-stock {
    flex-shrink: 0;
    padding: 5px 9px;
    border-radius: 20px;
    background: #f8f1df;
    color: #6b4423;
    font-size: 12px;
    font-weight: 700;
}

.sales-create-page .search-message {
    padding: 18px;
    color: #888;
    text-align: center;
    font-size: 13px;
}

.sales-create-page .items-card {
    min-width: 0;
}

.sales-create-page .table-wrap {
    width: 100%;
    overflow-x: auto;
}

.sales-create-page .sale-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 850px;
}

.sales-create-page .sale-table th {
    padding: 12px 14px;
    border-bottom: 1px solid #e8e1db;
    background: #faf7f3;
    color: #6b5b51;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: .04em;
    text-align: left;
    text-transform: uppercase;
    white-space: nowrap;
}

.sales-create-page .sale-table td {
    padding: 13px 14px;
    border-bottom: 1px solid #f0ebe7;
    color: #444;
    font-size: 13px;
    vertical-align: middle;
}

.sales-create-page .sale-table tbody tr:hover {
    background: #fffdf9;
}

.sales-create-page .sale-table td:first-child {
    min-width: 190px;
}

.sales-create-page .product-name {
    display: block;
    color: #4d0e17;
    font-weight: 700;
}

.sales-create-page .table-muted {
    display: block;
    margin-top: 3px;
    color: #999;
    font-size: 11px;
}

.sales-create-page .stock-badge {
    display: inline-flex;
    align-items: center;
    padding: 5px 9px;
    border-radius: 20px;
    background: #f5f1ec;
    color: #6b4423;
    font-size: 12px;
    font-weight: 700;
}

.sales-create-page .table-input {
    width: 95px;
    min-height: 38px;
    padding: 7px 9px;
    border: 1px solid #d9d1ca;
    border-radius: 7px;
    background: #fff;
    font-size: 13px;
    text-align: right;
    outline: none;
}

.sales-create-page .table-input:focus {
    border-color: #8b1e2d;
    box-shadow: 0 0 0 3px rgba(139, 30, 45, .08);
}

.sales-create-page .line-total {
    color: #64131f;
    font-weight: 800;
    white-space: nowrap;
}

.sales-create-page .remove-item {
    width: 34px;
    height: 34px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0;
    border: 1px solid #f0c8c8;
    border-radius: 7px;
    background: #fff5f5;
    color: #b42318;
    cursor: pointer;
    transition: .2s;
}

.sales-create-page .remove-item:hover {
    background: #b42318;
    color: #fff;
    border-color: #b42318;
}

.sales-create-page .empty-state {
    padding: 55px 20px !important;
    color: #999;
    text-align: center;
}

.sales-create-page .empty-state i {
    display: block;
    margin-bottom: 10px;
    color: #d2c5bc;
    font-size: 36px;
}

.sales-create-page .empty-state p {
    margin: 0;
    font-size: 13px;
}

.sales-create-page .summary-card {
    position: sticky;
    top: 20px;
}

.sales-create-page .summary-body {
    padding: 20px;
}

.sales-create-page .summary-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
    padding: 12px 0;
    color: #555;
    font-size: 14px;
}

.sales-create-page .summary-row + .summary-row {
    border-top: 1px solid #f0ebe7;
}

.sales-create-page .summary-row strong {
    color: #4d0e17;
    font-size: 16px;
}

.sales-create-page .summary-discount {
    width: 140px;
    min-height: 40px;
    text-align: right;
}

.sales-create-page .grand-total {
    margin-top: 8px;
    padding: 18px 0 4px;
    border-top: 2px solid #eee3da;
}

.sales-create-page .grand-total span {
    color: #4d0e17;
    font-size: 15px;
    font-weight: 700;
}

.sales-create-page .grand-total strong {
    color: #64131f;
    font-size: 26px;
}

.sales-create-page .form-actions {
    display: flex;
    gap: 10px;
    margin-top: 20px;
}

.sales-create-page .form-actions .btn {
    flex: 1;
    min-height: 45px;
    justify-content: center;
}

.sales-create-page .btn-save-sale {
    border: 0;
    background: #64131f;
    color: #fff;
    box-shadow: 0 4px 10px rgba(100, 19, 31, .18);
}

.sales-create-page .btn-save-sale:hover {
    background: #4d0e17;
    transform: translateY(-1px);
}

.sales-create-page .required {
    color: #b42318;
}

.sales-create-page .sale-items-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 25px;
    height: 25px;
    margin-left: 6px;
    padding: 0 7px;
    border-radius: 20px;
    background: #f2c94c;
    color: #4d0e17;
    font-size: 11px;
    font-weight: 800;
    vertical-align: middle;
}

@media (max-width: 1050px) {
    .sales-create-page .content-grid {
        grid-template-columns: 1fr;
    }

    .sales-create-page .summary-card {
        position: static;
        max-width: none;
    }
}

@media (max-width: 700px) {
    .sales-create-page .form-grid {
        grid-template-columns: 1fr;
    }

    .sales-create-page .page-header {
        align-items: flex-start;
    }

    .sales-create-page .page-header h1 {
        font-size: 23px;
    }

    .sales-create-page .product-search-item {
        align-items: flex-start;
        flex-direction: column;
        gap: 8px;
    }

    .sales-create-page .form-actions {
        flex-direction: column;
    }
}
</style>

<div class="sales-create-page">

    <div class="page-header">
        <div>
            <h1>Create Sale</h1>
            <p class="page-subtitle">Create a new customer sale and update branch inventory.</p>
        </div>

        <div class="page-actions">
            <a href="<?= APP_URL ?>/sales/" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i>
                Back to Sales
            </a>
        </div>
    </div>

    <form method="POST" action="<?= APP_URL ?>/sales/store.php" id="saleForm">

        <?= csrf_field() ?>

        <div class="content-grid">

            <div class="sale-details-card">

                <div class="card-header">
                    <h2>
                        <i class="fas fa-receipt"></i>
                        Sale Details
                    </h2>
                    <p class="card-subtitle">Select the branch and search for products to add.</p>
                </div>

                <div class="card-body">

                    <div class="form-grid">

                        <div class="form-group">
                            <label for="branch_id">
                                Branch <span class="required">*</span>
                            </label>

                            <select name="branch_id" id="branch_id" class="form-control" required>
                                <option value="">Select branch</option>

                                <?php foreach ($branches as $branch): ?>
                                    <option value="<?= (int)$branch['id'] ?>">
                                        <?= e($branch['name']) ?>
                                        <?= $branch['code'] ? ' (' . e($branch['code']) . ')' : '' ?>
                                    </option>
                                <?php endforeach; ?>

                            </select>
                        </div>

                        <div class="form-group product-search-wrapper">

                            <label for="product_search">
                                Add Product
                            </label>

                            <i class="fas fa-search search-icon"></i>

                            <input
                                type="text"
                                id="product_search"
                                class="form-control"
                                placeholder="Search product, SKU or barcode..."
                                autocomplete="off"
                            >

                            <div id="productResults" class="product-search-results"></div>

                        </div>

                    </div>

                </div>

            </div>

            <div class="items-card">

                <div class="card-header">
                    <h2>
                        <i class="fas fa-cart-shopping"></i>
                        Sale Items
                        <span id="itemsCount" class="sale-items-count">0</span>
                    </h2>

                    <p class="card-subtitle">Products added to this sale.</p>
                </div>

                <div class="table-wrap">

                    <table class="sale-table" id="saleItemsTable">

                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>SKU</th>
                                <th>Available</th>
                                <th>Qty</th>
                                <th>Price</th>
                                <th>Discount</th>
                                <th>Total</th>
                                <th></th>
                            </tr>
                        </thead>

                        <tbody id="saleItemsBody">

                            <tr id="emptySaleRow">

                                <td colspan="8" class="empty-state">

                                    <i class="fas fa-cart-shopping"></i>

                                    <p>No products added yet.</p>

                                </td>

                            </tr>

                        </tbody>

                    </table>

                </div>

            </div>

            <div class="summary-card">

                <div class="card-header">

                    <h2>
                        <i class="fas fa-calculator"></i>
                        Sale Summary
                    </h2>

                    <p class="card-subtitle">Review the totals before saving.</p>

                </div>

                <div class="summary-body">

                    <div class="summary-row">
                        <span>Subtotal</span>
                        <strong id="subtotalDisplay">0.00</strong>
                    </div>

                    <div class="summary-row">

                        <label for="overall_discount">
                            Overall Discount
                        </label>

                        <input
                            type="number"
                            name="discount"
                            id="overall_discount"
                            class="form-control summary-discount"
                            value="0"
                            min="0"
                            step="0.01"
                        >

                    </div>

                    <div class="summary-row grand-total">

                        <span>Grand Total</span>

                        <strong id="grandTotalDisplay">
                            0.00
                        </strong>

                    </div>

                    <div id="saleItemsInputs"></div>

                    <div class="form-actions">

                        <a href="<?= APP_URL ?>/sales/" class="btn btn-secondary">
                            Cancel
                        </a>

                        <button
                            type="submit"
                            class="btn btn-save-sale"
                            id="saveSaleButton"
                        >
                            <i class="fas fa-check-circle"></i>
                            Save Sale
                        </button>

                    </div>

                </div>

            </div>

        </div>

    </form>

</div>

<script>
const products = <?= json_encode($products, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const stock = <?= json_encode($stock, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

const branchSelect = document.getElementById('branch_id');
const productSearch = document.getElementById('product_search');
const productResults = document.getElementById('productResults');
const saleItemsBody = document.getElementById('saleItemsBody');
const saleItemsInputs = document.getElementById('saleItemsInputs');
const subtotalDisplay = document.getElementById('subtotalDisplay');
const grandTotalDisplay = document.getElementById('grandTotalDisplay');
const overallDiscount = document.getElementById('overall_discount');
const saleForm = document.getElementById('saleForm');
const itemsCount = document.getElementById('itemsCount');

let saleItems = [];

function money(value) {
    return Number(value || 0).toFixed(2);
}

function getAvailableStock(productId) {
    const branchId = branchSelect.value;

    if (!branchId) {
        return 0;
    }

    return Number(stock[branchId]?.[productId] || 0);
}

function getProduct(productId) {
    return products.find(product => Number(product.id) === Number(productId));
}

function renderSearchResults() {
    const term = productSearch.value.trim().toLowerCase();

    productResults.innerHTML = '';

    if (!term) {
        productResults.style.display = 'none';
        return;
    }

    if (!branchSelect.value) {
        productResults.innerHTML = `
            <div class="search-message">
                Select a branch first.
            </div>
        `;

        productResults.style.display = 'block';
        return;
    }

    const matches = products
        .filter(product => {
            const name = String(product.name || '').toLowerCase();
            const sku = String(product.sku || '').toLowerCase();
            const barcode = String(product.barcode || '').toLowerCase();

            return name.includes(term) ||
                   sku.includes(term) ||
                   barcode.includes(term);
        })
        .slice(0, 10);

    if (!matches.length) {
        productResults.innerHTML = `
            <div class="search-message">
                No matching products found.
            </div>
        `;

        productResults.style.display = 'block';
        return;
    }

    matches.forEach(product => {

        const available = getAvailableStock(product.id);

        const item = document.createElement('button');

        item.type = 'button';
        item.className = 'product-search-item';

        item.innerHTML = `
            <span class="product-search-main">
                <strong>${escapeHtml(product.name)}</strong>

                <small>
                    SKU: ${escapeHtml(product.sku)}
                    ${product.barcode ? ' · Barcode: ' + escapeHtml(product.barcode) : ''}
                </small>
            </span>

            <span class="product-search-stock">
                ${money(available)} ${escapeHtml(product.unit_name || '')}
            </span>
        `;

        item.addEventListener('click', () => {
            addProduct(product.id);
        });

        productResults.appendChild(item);
    });

    productResults.style.display = 'block';
}

function addProduct(productId) {

    if (!branchSelect.value) {
        alert('Please select a branch first.');
        return;
    }

    const product = getProduct(productId);

    if (!product) {
        return;
    }

    const available = getAvailableStock(productId);

    if (available <= 0) {
        alert('This product is out of stock in the selected branch.');
        return;
    }

    const existing = saleItems.find(
        item => Number(item.product_id) === Number(productId)
    );

    if (existing) {

        if (existing.quantity + 1 > available) {
            alert('The requested quantity exceeds available stock.');
            return;
        }

        existing.quantity += 1;

    } else {

        saleItems.push({
            product_id: Number(product.id),
            quantity: 1,
            unit_price: Number(product.selling_price),
            discount: 0
        });

    }

    productSearch.value = '';
    productResults.innerHTML = '';
    productResults.style.display = 'none';

    renderSaleItems();
}

function removeProduct(productId) {

    saleItems = saleItems.filter(
        item => Number(item.product_id) !== Number(productId)
    );

    renderSaleItems();
}

function updateQuantity(productId, value) {

    const item = saleItems.find(
        item => Number(item.product_id) === Number(productId)
    );

    if (!item) {
        return;
    }

    const available = getAvailableStock(productId);

    let quantity = Number(value);

    if (!Number.isFinite(quantity) || quantity <= 0) {
        quantity = 0.001;
    }

    if (quantity > available) {
        quantity = available;
        alert('Quantity cannot exceed available stock.');
    }

    item.quantity = quantity;

    renderSaleItems();
}

function updateItemDiscount(productId, value) {

    const item = saleItems.find(
        item => Number(item.product_id) === Number(productId)
    );

    if (!item) {
        return;
    }

    let discount = Number(value);

    if (!Number.isFinite(discount) || discount < 0) {
        discount = 0;
    }

    const gross = item.quantity * item.unit_price;

    if (discount > gross) {
        discount = gross;
    }

    item.discount = discount;

    renderSaleItems();
}

function calculateSubtotal() {

    return saleItems.reduce((total, item) => {
        return total + (
            (item.quantity * item.unit_price) -
            item.discount
        );
    }, 0);

}

function renderSaleItems() {

    saleItemsBody.innerHTML = '';

    itemsCount.textContent = saleItems.length;

    if (!saleItems.length) {

        saleItemsBody.innerHTML = `
            <tr>
                <td colspan="8" class="empty-state">
                    <i class="fas fa-cart-shopping"></i>
                    <p>No products added yet.</p>
                </td>
            </tr>
        `;

    }

    saleItems.forEach(item => {

        const product = getProduct(item.product_id);

        if (!product) {
            return;
        }

        const available = getAvailableStock(item.product_id);
        const gross = item.quantity * item.unit_price;
        const lineTotal = Math.max(0, gross - item.discount);

        const row = document.createElement('tr');

        row.innerHTML = `
            <td>
                <span class="product-name">
                    ${escapeHtml(product.name)}
                </span>

                <span class="table-muted">
                    ${escapeHtml(product.unit_name || '')}
                </span>
            </td>

            <td>
                ${escapeHtml(product.sku)}
            </td>

            <td>
                <span class="stock-badge">
                    ${money(available)}
                </span>
            </td>

            <td>
                <input
                    type="number"
                    class="table-input quantity-input"
                    min="0.001"
                    max="${available}"
                    step="0.001"
                    value="${item.quantity}"
                    data-product-id="${item.product_id}"
                >
            </td>

            <td>
                ${money(item.unit_price)}
            </td>

            <td>
                <input
                    type="number"
                    class="table-input discount-input"
                    min="0"
                    max="${gross}"
                    step="0.01"
                    value="${item.discount}"
                    data-product-id="${item.product_id}"
                >
            </td>

            <td>
                <span class="line-total">
                    ${money(lineTotal)}
                </span>
            </td>

            <td>
                <button
                    type="button"
                    class="remove-item"
                    data-product-id="${item.product_id}"
                    title="Remove product"
                >
                    <i class="fas fa-trash"></i>
                </button>
            </td>
        `;

        saleItemsBody.appendChild(row);

    });

    document.querySelectorAll('.quantity-input').forEach(input => {

        input.addEventListener('change', event => {

            updateQuantity(
                Number(event.target.dataset.productId),
                event.target.value
            );

        });

    });

    document.querySelectorAll('.discount-input').forEach(input => {

        input.addEventListener('change', event => {

            updateItemDiscount(
                Number(event.target.dataset.productId),
                event.target.value
            );

        });

    });

    document.querySelectorAll('.remove-item').forEach(button => {

        button.addEventListener('click', event => {

            removeProduct(
                Number(event.currentTarget.dataset.productId)
            );

        });

    });

    updateSummary();
}

function updateSummary() {

    const subtotal = calculateSubtotal();

    let discount = Number(overallDiscount.value);

    if (!Number.isFinite(discount) || discount < 0) {
        discount = 0;
    }

    if (discount > subtotal) {
        discount = subtotal;
        overallDiscount.value = money(discount);
    }

    const grandTotal = Math.max(0, subtotal - discount);

    subtotalDisplay.textContent = money(subtotal);
    grandTotalDisplay.textContent = money(grandTotal);

    saleItemsInputs.innerHTML = '';

    saleItems.forEach((item, index) => {

        saleItemsInputs.insertAdjacentHTML('beforeend', `
            <input
                type="hidden"
                name="items[${index}][product_id]"
                value="${item.product_id}"
            >

            <input
                type="hidden"
                name="items[${index}][quantity]"
                value="${item.quantity}"
            >

            <input
                type="hidden"
                name="items[${index}][unit_price]"
                value="${item.unit_price}"
            >

            <input
                type="hidden"
                name="items[${index}][discount]"
                value="${item.discount}"
            >
        `);

    });

}

function escapeHtml(value) {

    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

}

branchSelect.addEventListener('change', () => {

    saleItems = [];

    productSearch.value = '';

    productResults.innerHTML = '';
    productResults.style.display = 'none';

    renderSaleItems();

});

productSearch.addEventListener('input', renderSearchResults);

overallDiscount.addEventListener('input', updateSummary);

document.addEventListener('click', event => {

    if (
        !event.target.closest('#product_search') &&
        !event.target.closest('#productResults')
    ) {
        productResults.style.display = 'none';
    }

});

saleForm.addEventListener('submit', event => {

    if (!branchSelect.value) {
        event.preventDefault();
        alert('Please select a branch.');
        branchSelect.focus();
        return;
    }

    if (!saleItems.length) {
        event.preventDefault();
        alert('Please add at least one product to the sale.');
        productSearch.focus();
        return;
    }

    const subtotal = calculateSubtotal();
    const discount = Number(overallDiscount.value || 0);

    if (discount < 0 || discount > subtotal) {
        event.preventDefault();
        alert('Please enter a valid overall discount.');
        overallDiscount.focus();
        return;
    }

    updateSummary();

});

renderSaleItems();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>