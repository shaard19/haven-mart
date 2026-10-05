<?php
require_once __DIR__ . '/../includes/auth.php';

require_login();
require_permission('pos.access');

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

    $stock[(int)$row['branch_id']][(int)$row['product_id']] = max(0, $available);
}

$page_title = 'POS';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<style>
.pos-page {
    max-width: 1500px;
    margin: 0 auto;
}

.pos-page .pos-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    margin-bottom: 22px;
}

.pos-page .pos-header h1 {
    margin: 0 0 5px;
    color: #64131f;
    font-size: 28px;
    font-weight: 800;
}

.pos-page .pos-header p {
    margin: 0;
    color: #777;
}

.pos-page .pos-layout {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 390px;
    gap: 20px;
    align-items: start;
}

.pos-page .card {
    background: #fff;
    border: 1px solid #e8e2dc;
    border-radius: 14px;
    box-shadow: 0 5px 18px rgba(70, 35, 20, .06);
    overflow: visible;
}

.pos-page .card-header {
    padding: 17px 20px;
    border-bottom: 1px solid #eee7e0;
    background: linear-gradient(to right, #fffdf9, #fff);
}

.pos-page .card-header h2 {
    margin: 0 0 4px;
    color: #4d0e17;
    font-size: 17px;
    font-weight: 800;
}

.pos-page .card-header p {
    margin: 0;
    color: #888;
    font-size: 12px;
}

.pos-page .card-body {
    padding: 20px;
}

.pos-page .sale-card {
    min-width: 0;
}

.pos-page .branch-row {
    display: grid;
    grid-template-columns: 260px minmax(0, 1fr);
    gap: 15px;
    margin-bottom: 18px;
}

.pos-page .form-group {
    position: relative;
}

.pos-page label {
    display: block;
    margin-bottom: 7px;
    color: #4d0e17;
    font-size: 12px;
    font-weight: 800;
}

.pos-page .form-control {
    width: 100%;
    min-height: 44px;
    padding: 10px 13px;
    border: 1px solid #d9d1ca;
    border-radius: 8px;
    background: #fff;
    color: #333;
    font-size: 14px;
    outline: none;
    box-sizing: border-box;
    transition: .2s;
}

.pos-page .form-control:focus {
    border-color: #8b1e2d;
    box-shadow: 0 0 0 3px rgba(139, 30, 45, .09);
}

.pos-page .search-wrapper {
    position: relative;
}

.pos-page .search-icon {
    position: absolute;
    left: 14px;
    top: 37px;
    color: #9b8d84;
    pointer-events: none;
    z-index: 2;
}

.pos-page #product_search {
    padding-left: 40px;
}

.pos-page .search-results {
    position: absolute;
    left: 0;
    right: 0;
    top: 100%;
    z-index: 1000;
    display: none;
    margin-top: 5px;
    background: #fff;
    border: 1px solid #ded6cf;
    border-radius: 10px;
    box-shadow: 0 14px 35px rgba(0, 0, 0, .16);
    max-height: 420px;
    overflow-y: auto;
}

.pos-page .search-result {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
    padding: 13px 15px;
    border: 0;
    border-bottom: 1px solid #f0ebe7;
    background: #fff;
    text-align: left;
    cursor: pointer;
}

.pos-page .search-result:last-child {
    border-bottom: 0;
}

.pos-page .search-result:hover {
    background: #fff8e6;
}

.pos-page .search-product {
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.pos-page .search-product strong {
    color: #4d0e17;
    font-size: 13px;
}

.pos-page .search-product small {
    color: #888;
    font-size: 11px;
}

.pos-page .search-stock {
    flex-shrink: 0;
    padding: 5px 9px;
    border-radius: 20px;
    background: #f7f0df;
    color: #6b4423;
    font-size: 11px;
    font-weight: 800;
}

.pos-page .search-message {
    padding: 18px;
    color: #888;
    text-align: center;
    font-size: 12px;
}

.pos-page .cart-wrap {
    overflow-x: auto;
}

.pos-page .cart-table {
    width: 100%;
    min-width: 760px;
    border-collapse: collapse;
}

.pos-page .cart-table th {
    padding: 11px 12px;
    border-bottom: 1px solid #e8e1db;
    background: #faf7f3;
    color: #6b5b51;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: .04em;
    text-align: left;
    text-transform: uppercase;
    white-space: nowrap;
}

.pos-page .cart-table td {
    padding: 12px;
    border-bottom: 1px solid #f0ebe7;
    color: #444;
    font-size: 12px;
    vertical-align: middle;
}

.pos-page .cart-table tbody tr:hover {
    background: #fffdf9;
}

.pos-page .product-name {
    display: block;
    color: #4d0e17;
    font-weight: 800;
}

.pos-page .product-meta {
    display: block;
    margin-top: 3px;
    color: #999;
    font-size: 10px;
}

.pos-page .qty-input,
.pos-page .discount-input {
    width: 82px;
    min-height: 36px;
    padding: 6px 8px;
    border: 1px solid #d9d1ca;
    border-radius: 7px;
    background: #fff;
    font-size: 12px;
    text-align: right;
    outline: none;
}

.pos-page .qty-input:focus,
.pos-page .discount-input:focus {
    border-color: #8b1e2d;
    box-shadow: 0 0 0 3px rgba(139, 30, 45, .08);
}

.pos-page .stock-badge {
    display: inline-flex;
    padding: 5px 8px;
    border-radius: 20px;
    background: #f6f0e8;
    color: #6b4423;
    font-size: 10px;
    font-weight: 800;
    white-space: nowrap;
}

.pos-page .line-total {
    color: #64131f;
    font-weight: 800;
    white-space: nowrap;
}

.pos-page .remove-btn {
    width: 32px;
    height: 32px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid #f0c8c8;
    border-radius: 7px;
    background: #fff5f5;
    color: #b42318;
    cursor: pointer;
}

.pos-page .remove-btn:hover {
    background: #b42318;
    color: #fff;
}

.pos-page .empty-cart {
    padding: 60px 20px !important;
    color: #aaa;
    text-align: center;
}

.pos-page .empty-cart i {
    display: block;
    margin-bottom: 12px;
    color: #d4c8c0;
    font-size: 38px;
}

.pos-page .empty-cart strong {
    display: block;
    color: #6b5b51;
    font-size: 14px;
}

.pos-page .empty-cart span {
    display: block;
    margin-top: 5px;
    font-size: 11px;
}

.pos-page .summary-card {
    position: sticky;
    top: 20px;
}

.pos-page .summary-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
    padding: 11px 0;
    color: #555;
    font-size: 13px;
}

.pos-page .summary-row + .summary-row {
    border-top: 1px solid #f0ebe7;
}

.pos-page .summary-row strong {
    color: #4d0e17;
    font-size: 16px;
}

.pos-page .summary-input {
    width: 130px;
    min-height: 39px;
    text-align: right;
}

.pos-page .grand-total {
    margin-top: 8px;
    padding: 17px 0;
    border-top: 2px solid #eee3da;
}

.pos-page .grand-total span {
    color: #4d0e17;
    font-size: 15px;
    font-weight: 800;
}

.pos-page .grand-total strong {
    color: #64131f;
    font-size: 25px;
}

.pos-page .payment-section {
    margin-top: 15px;
    padding-top: 17px;
    border-top: 1px solid #eee7e0;
}

.pos-page .payment-section h3 {
    margin: 0 0 12px;
    color: #4d0e17;
    font-size: 14px;
}

.pos-page .payment-methods {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 9px;
}

.pos-page .method-option {
    position: relative;
}

.pos-page .method-option input {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}

.pos-page .method-label {
    display: flex;
    align-items: center;
    gap: 8px;
    min-height: 55px;
    padding: 9px 10px;
    margin: 0;
    border: 1px solid #ddd5ce;
    border-radius: 9px;
    background: #fff;
    cursor: pointer;
    transition: .2s;
}

.pos-page .method-label:hover {
    border-color: #b9944c;
    background: #fffaf0;
}

.pos-page .method-option input:checked + .method-label {
    border-color: #64131f;
    background: #fff8e8;
    box-shadow: 0 0 0 2px rgba(100, 19, 31, .08);
}

.pos-page .method-icon {
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    border-radius: 8px;
    background: #f6efe4;
    color: #64131f;
}

.pos-page .method-text {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.pos-page .method-text strong {
    color: #4d0e17;
    font-size: 11px;
}

.pos-page .method-text small {
    color: #888;
    font-size: 9px;
}

.pos-page .cash-fields,
.pos-page .mpesa-fields {
    display: none;
    margin-top: 13px;
}

.pos-page .cash-fields.active,
.pos-page .mpesa-fields.active {
    display: block;
}

.pos-page .change-box {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 10px;
    padding: 12px;
    border-radius: 9px;
    background: #f7f4ef;
}

.pos-page .change-box span {
    color: #6b5b51;
    font-size: 11px;
    font-weight: 700;
}

.pos-page .change-box strong {
    color: #247a45;
    font-size: 17px;
}

.pos-page .reference-help {
    margin-top: 5px;
    color: #888;
    font-size: 10px;
}

.pos-page .complete-sale {
    width: 100%;
    min-height: 48px;
    margin-top: 18px;
    border: 0;
    border-radius: 9px;
    background: #64131f;
    color: #fff;
    font-size: 14px;
    font-weight: 800;
    cursor: pointer;
    box-shadow: 0 4px 12px rgba(100, 19, 31, .2);
    transition: .2s;
}

.pos-page .complete-sale:hover {
    background: #4d0e17;
    transform: translateY(-1px);
}

.pos-page .complete-sale:disabled {
    opacity: .55;
    cursor: not-allowed;
    transform: none;
}

.pos-page .pos-note {
    margin-top: 12px;
    color: #999;
    font-size: 10px;
    line-height: 1.5;
    text-align: center;
}

.pos-page .keyboard-hint {
    margin-top: 12px;
    color: #999;
    font-size: 10px;
    text-align: center;
}

.pos-page kbd {
    padding: 2px 5px;
    border: 1px solid #d9d1ca;
    border-radius: 4px;
    background: #faf7f3;
    color: #6b5b51;
    font-size: 9px;
}

@media (max-width: 1050px) {
    .pos-page .pos-layout {
        grid-template-columns: 1fr;
    }

    .pos-page .summary-card {
        position: static;
    }
}

@media (max-width: 700px) {
    .pos-page .branch-row {
        grid-template-columns: 1fr;
    }

    .pos-page .pos-header {
        align-items: flex-start;
        flex-direction: column;
    }

    .pos-page .payment-methods {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="pos-page">

    <div class="pos-header">
        <div>
            <h1><i class="fas fa-cash-register"></i> Haven Mart POS</h1>
            <p>Fast and secure point-of-sale checkout.</p>
        </div>

        <div class="page-actions">
            <a href="<?= APP_URL ?>/sales/" class="btn btn-secondary">
                <i class="fas fa-receipt"></i>
                Sales
            </a>
        </div>
    </div>

    <form method="POST" action="<?= APP_URL ?>/pos/checkout.php" id="posForm">

        <?= csrf_field() ?>

        <div class="pos-layout">

            <div class="card sale-card">

                <div class="card-header">
                    <h2><i class="fas fa-cart-shopping"></i> Current Sale</h2>
                    <p>Select a branch and add products to the cart.</p>
                </div>

                <div class="card-body">

                    <div class="branch-row">

                        <div class="form-group">
                            <label for="branch_id">Branch</label>

                            <select
                                name="branch_id"
                                id="branch_id"
                                class="form-control"
                                required
                            >
                                <option value="">Select branch</option>

                                <?php foreach ($branches as $branch): ?>
                                    <option value="<?= (int)$branch['id'] ?>">
                                        <?= e($branch['name']) ?>
                                        <?= $branch['code'] ? ' (' . e($branch['code']) . ')' : '' ?>
                                    </option>
                                <?php endforeach; ?>

                            </select>
                        </div>

                        <div class="form-group search-wrapper">

                            <label for="product_search">
                                Product
                            </label>

                            <i class="fas fa-search search-icon"></i>

                            <input
                                type="text"
                                id="product_search"
                                class="form-control"
                                placeholder="Search product, SKU or scan barcode..."
                                autocomplete="off"
                                disabled
                            >

                            <div
                                id="searchResults"
                                class="search-results"
                            ></div>

                        </div>

                    </div>

                    <div class="cart-wrap">

                        <table class="cart-table">

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

                            <tbody id="cartBody">

                                <tr>
                                    <td colspan="8" class="empty-cart">
                                        <i class="fas fa-cart-shopping"></i>
                                        <strong>Cart is empty</strong>
                                        <span>Select a branch, then search or scan a product.</span>
                                    </td>
                                </tr>

                            </tbody>

                        </table>

                    </div>

                </div>

            </div>

            <div class="card summary-card">

                <div class="card-header">
                    <h2><i class="fas fa-calculator"></i> Checkout</h2>
                    <p>Review the sale and receive payment.</p>
                </div>

                <div class="card-body">

                    <div class="summary-row">
                        <span>Items</span>
                        <strong id="itemCount">0</strong>
                    </div>

                    <div class="summary-row">
                        <span>Subtotal</span>
                        <strong><?= CURRENCY ?> <span id="subtotal">0.00</span></strong>
                    </div>

                    <div class="summary-row">

                        <label for="overall_discount">
                            Discount
                        </label>

                        <input
                            type="number"
                            id="overall_discount"
                            name="discount"
                            class="form-control summary-input"
                            value="0"
                            min="0"
                            step="0.01"
                        >

                    </div>

                    <div class="summary-row grand-total">
                        <span>Total</span>
                        <strong><?= CURRENCY ?> <span id="grandTotal">0.00</span></strong>
                    </div>

                    <div class="payment-section">

                        <h3>
                            <i class="fas fa-money-bill-wave"></i>
                            Payment
                        </h3>

                        <div class="payment-methods">

                            <div class="method-option">

                                <input
                                    type="radio"
                                    name="payment_method"
                                    id="cash"
                                    value="CASH"
                                    checked
                                >

                                <label for="cash" class="method-label">

                                    <span class="method-icon">
                                        <i class="fas fa-money-bill"></i>
                                    </span>

                                    <span class="method-text">
                                        <strong>Cash</strong>
                                        <small>Customer pays cash</small>
                                    </span>

                                </label>

                            </div>

                            <div class="method-option">

                                <input
                                    type="radio"
                                    name="payment_method"
                                    id="mpesa"
                                    value="MPESA"
                                >

                                <label for="mpesa" class="method-label">

                                    <span class="method-icon">
                                        <i class="fas fa-mobile-screen-button"></i>
                                    </span>

                                    <span class="method-text">
                                        <strong>M-Pesa</strong>
                                        <small>Manual confirmation</small>
                                    </span>

                                </label>

                            </div>

                        </div>

                        <div id="cashFields" class="cash-fields active">

                            <label for="cash_received">
                                Cash Received
                            </label>

                            <input
                                type="number"
                                id="cash_received"
                                name="cash_received"
                                class="form-control"
                                min="0"
                                step="0.01"
                                value="0"
                            >

                            <div class="change-box">
                                <span>Change</span>
                                <strong>
                                    <?= CURRENCY ?> <span id="changeAmount">0.00</span>
                                </strong>
                            </div>

                        </div>

                        <div id="mpesaFields" class="mpesa-fields">

                            <label for="mpesa_reference">
                                M-Pesa Transaction Code
                            </label>

                            <input
                                type="text"
                                id="mpesa_reference"
                                name="mpesa_reference"
                                class="form-control"
                                maxlength="100"
                                placeholder="e.g. QGH7K8L9P2"
                                autocomplete="off"
                            >

                            <div class="reference-help">
                                Enter the transaction code exactly as received.
                            </div>

                        </div>

                    </div>

                    <div id="saleItemsInputs"></div>

                    <button
                        type="submit"
                        class="complete-sale"
                        id="completeSale"
                        disabled
                    >
                        <i class="fas fa-check-circle"></i>
                        Complete Sale
                    </button>

                    <div class="keyboard-hint">
                        Search products quickly and use <kbd>Enter</kbd> to select a result.
                    </div>

                    <div class="pos-note">
                        Payment and inventory are finalized together after checkout validation.
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
const searchResults = document.getElementById('searchResults');
const cartBody = document.getElementById('cartBody');
const saleItemsInputs = document.getElementById('saleItemsInputs');
const overallDiscount = document.getElementById('overall_discount');
const itemCount = document.getElementById('itemCount');
const subtotalDisplay = document.getElementById('subtotal');
const grandTotalDisplay = document.getElementById('grandTotal');
const cashReceived = document.getElementById('cash_received');
const changeAmount = document.getElementById('changeAmount');
const cashMethod = document.getElementById('cash');
const mpesaMethod = document.getElementById('mpesa');
const cashFields = document.getElementById('cashFields');
const mpesaFields = document.getElementById('mpesaFields');
const mpesaReference = document.getElementById('mpesa_reference');
const completeSale = document.getElementById('completeSale');
const posForm = document.getElementById('posForm');

let cart = [];

function money(value) {
    return Number(value || 0).toFixed(2);
}

function getProduct(productId) {
    return products.find(product => Number(product.id) === Number(productId));
}

function getAvailableStock(productId) {
    const branchId = branchSelect.value;

    if (!branchId) {
        return 0;
    }

    return Number(stock[branchId]?.[productId] || 0);
}

function renderSearchResults() {

    const term = productSearch.value.trim().toLowerCase();

    searchResults.innerHTML = '';

    if (!term) {
        searchResults.style.display = 'none';
        return;
    }

    if (!branchSelect.value) {
        searchResults.innerHTML = `
            <div class="search-message">
                Select a branch first.
            </div>
        `;

        searchResults.style.display = 'block';
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
        .slice(0, 12);

    if (!matches.length) {

        searchResults.innerHTML = `
            <div class="search-message">
                No matching products found.
            </div>
        `;

        searchResults.style.display = 'block';
        return;
    }

    matches.forEach(product => {

        const available = getAvailableStock(product.id);

        const button = document.createElement('button');

        button.type = 'button';
        button.className = 'search-result';

        button.innerHTML = `
            <span class="search-product">
                <strong>${escapeHtml(product.name)}</strong>
                <small>
                    SKU: ${escapeHtml(product.sku)}
                    ${product.barcode ? ' · Barcode: ' + escapeHtml(product.barcode) : ''}
                </small>
            </span>

            <span class="search-stock">
                ${money(available)} ${escapeHtml(product.unit_name || '')}
            </span>
        `;

        button.addEventListener('click', () => {
            addToCart(product.id);
        });

        searchResults.appendChild(button);

    });

    searchResults.style.display = 'block';
}

function addToCart(productId) {

    if (!branchSelect.value) {
        alert('Please select a branch first.');
        branchSelect.focus();
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

    const existing = cart.find(
        item => Number(item.product_id) === Number(productId)
    );

    if (existing) {

        if (existing.quantity + 1 > available) {
            alert('The requested quantity exceeds available stock.');
            return;
        }

        existing.quantity += 1;

    } else {

        cart.push({
            product_id: Number(product.id),
            quantity: 1,
            unit_price: Number(product.selling_price),
            discount: 0
        });

    }

    productSearch.value = '';
    searchResults.innerHTML = '';
    searchResults.style.display = 'none';

    renderCart();
    productSearch.focus();
}

function removeFromCart(productId) {

    cart = cart.filter(
        item => Number(item.product_id) !== Number(productId)
    );

    renderCart();
}

function updateQuantity(productId, value) {

    const item = cart.find(
        cartItem => Number(cartItem.product_id) === Number(productId)
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

    renderCart();
}

function updateDiscount(productId, value) {

    const item = cart.find(
        cartItem => Number(cartItem.product_id) === Number(productId)
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

    renderCart();
}

function calculateSubtotal() {

    return cart.reduce((total, item) => {

        const gross = item.quantity * item.unit_price;

        return total + Math.max(0, gross - item.discount);

    }, 0);
}

function calculateGrandTotal() {

    const subtotal = calculateSubtotal();

    let discount = Number(overallDiscount.value || 0);

    if (!Number.isFinite(discount) || discount < 0) {
        discount = 0;
    }

    discount = Math.min(discount, subtotal);

    return Math.max(0, subtotal - discount);
}

function renderCart() {

    cartBody.innerHTML = '';

    itemCount.textContent = cart.length;

    if (!cart.length) {

        cartBody.innerHTML = `
            <tr>
                <td colspan="8" class="empty-cart">
                    <i class="fas fa-cart-shopping"></i>
                    <strong>Cart is empty</strong>
                    <span>Select a branch, then search or scan a product.</span>
                </td>
            </tr>
        `;

    } else {

        cart.forEach(item => {

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

                    <span class="product-meta">
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
                        class="qty-input"
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
                        class="discount-input"
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
                        class="remove-btn"
                        data-product-id="${item.product_id}"
                        title="Remove"
                    >
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
            `;

            cartBody.appendChild(row);

        });

    }

    document.querySelectorAll('.qty-input').forEach(input => {

        input.addEventListener('change', event => {

            updateQuantity(
                Number(event.target.dataset.productId),
                event.target.value
            );

        });

    });

    document.querySelectorAll('.discount-input').forEach(input => {

        input.addEventListener('change', event => {

            updateDiscount(
                Number(event.target.dataset.productId),
                event.target.value
            );

        });

    });

    document.querySelectorAll('.remove-btn').forEach(button => {

        button.addEventListener('click', event => {

            removeFromCart(
                Number(event.currentTarget.dataset.productId)
            );

        });

    });

    updateSummary();
}

function updateSummary() {

    const subtotal = calculateSubtotal();

    let discount = Number(overallDiscount.value || 0);

    if (!Number.isFinite(discount) || discount < 0) {
        discount = 0;
    }

    if (discount > subtotal) {
        discount = subtotal;
        overallDiscount.value = money(discount);
    }

    const total = Math.max(0, subtotal - discount);

    subtotalDisplay.textContent = money(subtotal);
    grandTotalDisplay.textContent = money(total);

    updateChange(total);
    updatePaymentFields();
    renderHiddenItems();

    completeSale.disabled = cart.length === 0 || total <= 0;
}

function updateChange(total = calculateGrandTotal()) {

    const received = Number(cashReceived.value || 0);

    const change = Math.max(0, received - total);

    changeAmount.textContent = money(change);
}

function updatePaymentFields() {

    if (cashMethod.checked) {

        cashFields.classList.add('active');
        mpesaFields.classList.remove('active');

        mpesaReference.required = false;

    } else {

        cashFields.classList.remove('active');
        mpesaFields.classList.add('active');

        mpesaReference.required = true;

    }

}

function renderHiddenItems() {

    saleItemsInputs.innerHTML = '';

    cart.forEach((item, index) => {

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

    cart = [];

    productSearch.value = '';

    searchResults.innerHTML = '';
    searchResults.style.display = 'none';

    productSearch.disabled = !branchSelect.value;

    renderCart();

    if (branchSelect.value) {
        productSearch.focus();
    }

});

productSearch.addEventListener('input', renderSearchResults);

productSearch.addEventListener('keydown', event => {

    if (event.key === 'Enter') {

        const firstResult = searchResults.querySelector('.search-result');

        if (firstResult) {
            event.preventDefault();
            firstResult.click();
        }

    }

});

overallDiscount.addEventListener('input', updateSummary);

cashReceived.addEventListener('input', () => {
    updateChange();
});

cashMethod.addEventListener('change', updatePaymentFields);
mpesaMethod.addEventListener('change', updatePaymentFields);

document.addEventListener('click', event => {

    if (
        !event.target.closest('#product_search') &&
        !event.target.closest('#searchResults')
    ) {
        searchResults.style.display = 'none';
    }

});

posForm.addEventListener('submit', event => {

    if (!branchSelect.value) {
        event.preventDefault();
        alert('Please select a branch.');
        branchSelect.focus();
        return;
    }

    if (!cart.length) {
        event.preventDefault();
        alert('Please add at least one product.');
        productSearch.focus();
        return;
    }

    const total = calculateGrandTotal();

    if (total <= 0) {
        event.preventDefault();
        alert('Sale total must be greater than zero.');
        return;
    }

    if (cashMethod.checked) {

        const received = Number(cashReceived.value || 0);

        if (received < total) {
            event.preventDefault();
            alert('Cash received cannot be less than the sale total.');
            cashReceived.focus();
            return;
        }

    }

    if (mpesaMethod.checked) {

        if (!mpesaReference.value.trim()) {
            event.preventDefault();
            alert('Enter the M-Pesa transaction code.');
            mpesaReference.focus();
            return;
        }

    }

    completeSale.disabled = true;
    completeSale.innerHTML = `
        <i class="fas fa-spinner fa-spin"></i>
        Processing Sale...
    `;

});

renderCart();
updatePaymentFields();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>