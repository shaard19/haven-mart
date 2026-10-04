<?php
require_once __DIR__ . '/../includes/auth.php';

require_login();
require_permission('sales.view');

$saleId = (int)($_GET['sale_id'] ?? 0);

if ($saleId <= 0) {
    header('Location: ' . APP_URL . '/sales/');
    exit;
}

$saleStmt = $pdo->prepare("
    SELECT
        s.id,
        s.invoice_number,
        s.branch_id,
        s.cashier_id,
        s.subtotal,
        s.discount,
        s.total,
        s.payment_status,
        s.sale_status,
        s.sale_date,
        b.name AS branch_name,
        b.code AS branch_code,
        u.full_name AS cashier_name
    FROM sales s
    INNER JOIN branches b ON b.id = s.branch_id
    INNER JOIN users u ON u.id = s.cashier_id
    WHERE s.id = ?
    LIMIT 1
");

$saleStmt->execute([$saleId]);
$sale = $saleStmt->fetch(PDO::FETCH_ASSOC);

if (!$sale) {
    header(
        'Location: ' .
        APP_URL .
        '/sales/?error=' .
        urlencode('Sale not found.')
    );
    exit;
}

if ($sale['sale_status'] !== 'COMPLETED') {
    header(
        'Location: ' .
        APP_URL .
        '/sales/view.php?id=' .
        $saleId .
        '&error=' .
        urlencode('Payments can only be recorded for completed sales.')
    );
    exit;
}

$paymentStmt = $pdo->prepare("
    SELECT
        id,
        payment_method,
        amount,
        reference_number,
        payment_status,
        paid_at,
        recorded_by
    FROM payments
    WHERE sale_id = ?
      AND payment_status = 'COMPLETED'
    ORDER BY paid_at DESC, id DESC
");

$paymentStmt->execute([$saleId]);
$payments = $paymentStmt->fetchAll(PDO::FETCH_ASSOC);

$paidStmt = $pdo->prepare("
    SELECT COALESCE(SUM(amount), 0)
    FROM payments
    WHERE sale_id = ?
      AND payment_status = 'COMPLETED'
");

$paidStmt->execute([$saleId]);
$totalPaid = (float)$paidStmt->fetchColumn();

$saleTotal = (float)$sale['total'];
$outstanding = max(0, $saleTotal - $totalPaid);

$page_title = 'Record Payment';

require_once __DIR__ . '/../includes/header.php';
?>

<style>
.payment-page {
    max-width: 1180px;
    margin: 0 auto;
}

.payment-page .page-header {
    margin-bottom: 24px;
}

.payment-page .page-header h1 {
    margin-bottom: 6px;
    color: #64131f;
    font-size: 28px;
    font-weight: 700;
}

.payment-page .page-subtitle {
    margin: 0;
    color: #777;
}

.payment-page .payment-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 390px;
    gap: 22px;
    align-items: start;
}

.payment-page .card {
    background: #fff;
    border: 1px solid #e8e2dc;
    border-radius: 14px;
    box-shadow: 0 5px 18px rgba(70, 35, 20, .06);
    overflow: hidden;
}

.payment-page .card-header {
    padding: 18px 20px;
    border-bottom: 1px solid #eee7e0;
    background: linear-gradient(to right, #fffdf9, #fff);
}

.payment-page .card-header h2 {
    margin: 0 0 4px;
    color: #4d0e17;
    font-size: 18px;
}

.payment-page .card-subtitle {
    margin: 0;
    color: #888;
    font-size: 13px;
}

.payment-page .card-body {
    padding: 20px;
}

.payment-page .sale-banner {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
    padding: 18px 20px;
    margin-bottom: 20px;
    border: 1px solid #eadfc7;
    border-radius: 12px;
    background: #fff9e8;
}

.payment-page .sale-banner-main {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.payment-page .invoice-number {
    color: #64131f;
    font-size: 20px;
    font-weight: 800;
}

.payment-page .sale-meta {
    color: #7c716a;
    font-size: 13px;
}

.payment-page .balance-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
    margin-bottom: 22px;
}

.payment-page .balance-box {
    padding: 16px;
    border: 1px solid #eee6df;
    border-radius: 10px;
    background: #fff;
}

.payment-page .balance-label {
    display: block;
    margin-bottom: 6px;
    color: #888;
    font-size: 12px;
    font-weight: 600;
}

.payment-page .balance-value {
    color: #4d0e17;
    font-size: 20px;
    font-weight: 800;
}

.payment-page .balance-box.outstanding {
    background: #fff8e7;
    border-color: #f1dfac;
}

.payment-page .balance-box.outstanding .balance-value {
    color: #8a5a00;
}

.payment-page .form-group {
    margin-bottom: 18px;
}

.payment-page .form-group label {
    display: block;
    margin-bottom: 8px;
    color: #4d0e17;
    font-size: 13px;
    font-weight: 700;
}

.payment-page .form-control {
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

.payment-page .form-control:focus {
    border-color: #8b1e2d;
    box-shadow: 0 0 0 3px rgba(139, 30, 45, .09);
}

.payment-page .payment-methods {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
}

.payment-page .method-option {
    position: relative;
}

.payment-page .method-option input {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}

.payment-page .method-label {
    display: flex;
    align-items: center;
    gap: 12px;
    min-height: 70px;
    padding: 13px 15px;
    border: 1px solid #ddd5ce;
    border-radius: 10px;
    background: #fff;
    cursor: pointer;
    transition: .2s;
}

.payment-page .method-label:hover {
    border-color: #b9944c;
    background: #fffaf0;
}

.payment-page .method-option input:checked + .method-label {
    border-color: #64131f;
    background: #fff8e8;
    box-shadow: 0 0 0 2px rgba(100, 19, 31, .08);
}

.payment-page .method-icon {
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 9px;
    background: #f6efe4;
    color: #64131f;
    font-size: 17px;
}

.payment-page .method-text {
    display: flex;
    flex-direction: column;
    gap: 3px;
}

.payment-page .method-text strong {
    color: #4d0e17;
    font-size: 14px;
}

.payment-page .method-text small {
    color: #888;
    font-size: 11px;
}

.payment-page .mpesa-field {
    display: none;
}

.payment-page .mpesa-field.active {
    display: block;
}

.payment-page .reference-help {
    margin-top: 6px;
    color: #888;
    font-size: 11px;
}

.payment-page .quick-amounts {
    display: flex;
    flex-wrap: wrap;
    gap: 7px;
    margin-top: 9px;
}

.payment-page .quick-amount {
    padding: 6px 10px;
    border: 1px solid #ded4cc;
    border-radius: 20px;
    background: #faf7f3;
    color: #64131f;
    font-size: 11px;
    font-weight: 700;
    cursor: pointer;
}

.payment-page .quick-amount:hover {
    background: #fff3d0;
    border-color: #d7b866;
}

.payment-page .form-actions {
    display: flex;
    gap: 10px;
    margin-top: 22px;
}

.payment-page .form-actions .btn {
    flex: 1;
    min-height: 45px;
    justify-content: center;
}

.payment-page .btn-record {
    border: 0;
    background: #64131f;
    color: #fff;
    box-shadow: 0 4px 10px rgba(100, 19, 31, .18);
}

.payment-page .btn-record:hover {
    background: #4d0e17;
}

.payment-page .history-table {
    width: 100%;
    border-collapse: collapse;
}

.payment-page .history-table th {
    padding: 11px 12px;
    background: #faf7f3;
    border-bottom: 1px solid #e8e1db;
    color: #6b5b51;
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: .04em;
    text-align: left;
}

.payment-page .history-table td {
    padding: 12px;
    border-bottom: 1px solid #f0ebe7;
    color: #555;
    font-size: 12px;
}

.payment-page .method-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 8px;
    border-radius: 15px;
    background: #f7f1e8;
    color: #64131f;
    font-size: 10px;
    font-weight: 800;
}

.payment-page .amount-cell {
    color: #4d0e17;
    font-weight: 800;
    white-space: nowrap;
}

.payment-page .no-payments {
    padding: 35px 20px;
    color: #999;
    text-align: center;
}

.payment-page .no-payments i {
    display: block;
    margin-bottom: 10px;
    color: #d2c5bc;
    font-size: 30px;
}

.payment-page .status-paid {
    color: #247a45;
    font-weight: 800;
}

.payment-page .status-partial {
    color: #9a6a00;
    font-weight: 800;
}

@media (max-width: 950px) {
    .payment-page .payment-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 650px) {
    .payment-page .balance-grid {
        grid-template-columns: 1fr;
    }

    .payment-page .payment-methods {
        grid-template-columns: 1fr;
    }

    .payment-page .sale-banner {
        align-items: flex-start;
        flex-direction: column;
    }

    .payment-page .form-actions {
        flex-direction: column;
    }
}
</style>

<div class="payment-page">

    <div class="page-header">
        <div>
            <h1>Record Payment</h1>
            <p class="page-subtitle">
                Record cash or manual M-Pesa payment for this sale.
            </p>
        </div>

        <div class="page-actions">
            <a
                href="<?= APP_URL ?>/sales/view.php?id=<?= $saleId ?>"
                class="btn btn-secondary"
            >
                <i class="fas fa-arrow-left"></i>
                Back to Sale
            </a>
        </div>
    </div>

    <div class="sale-banner">
        <div class="sale-banner-main">
            <span class="invoice-number">
                <?= e($sale['invoice_number']) ?>
            </span>

            <span class="sale-meta">
                <?= e($sale['branch_name']) ?>
                <?= $sale['branch_code'] ? ' · ' . e($sale['branch_code']) : '' ?>
                · <?= date('d M Y, H:i', strtotime($sale['sale_date'])) ?>
            </span>
        </div>

        <?php if ($sale['payment_status'] === 'PAID'): ?>
            <span class="status-paid">
                <i class="fas fa-circle-check"></i>
                PAID
            </span>
        <?php elseif ($sale['payment_status'] === 'PARTIAL'): ?>
            <span class="status-partial">
                <i class="fas fa-clock"></i>
                PARTIAL
            </span>
        <?php else: ?>
            <span>
                <i class="fas fa-circle-exclamation"></i>
                UNPAID
            </span>
        <?php endif; ?>
    </div>

    <div class="payment-grid">

        <div>

            <div class="card">

                <div class="card-header">
                    <h2>
                        <i class="fas fa-money-bill-wave"></i>
                        Payment Details
                    </h2>

                    <p class="card-subtitle">
                        Choose the payment method and enter the amount received.
                    </p>
                </div>

                <div class="card-body">

                    <div class="balance-grid">

                        <div class="balance-box">
                            <span class="balance-label">Sale Total</span>
                            <span class="balance-value">
                                <?= number_format($saleTotal, 2) ?>
                            </span>
                        </div>

                        <div class="balance-box">
                            <span class="balance-label">Already Paid</span>
                            <span class="balance-value">
                                <?= number_format($totalPaid, 2) ?>
                            </span>
                        </div>

                        <div class="balance-box outstanding">
                            <span class="balance-label">Outstanding</span>
                            <span class="balance-value">
                                <?= number_format($outstanding, 2) ?>
                            </span>
                        </div>

                    </div>

                    <?php if ($outstanding > 0): ?>

                        <form
                            method="POST"
                            action="<?= APP_URL ?>/payments/store.php"
                            id="paymentForm"
                        >

                            <?= csrf_field() ?>

                            <input
                                type="hidden"
                                name="sale_id"
                                value="<?= $saleId ?>"
                            >

                            <div class="form-group">

                                <label>
                                    Payment Method <span class="required">*</span>
                                </label>

                                <div class="payment-methods">

                                    <div class="method-option">

                                        <input
                                            type="radio"
                                            name="payment_method"
                                            id="method_cash"
                                            value="CASH"
                                            checked
                                        >

                                        <label
                                            for="method_cash"
                                            class="method-label"
                                        >
                                            <span class="method-icon">
                                                <i class="fas fa-money-bill"></i>
                                            </span>

                                            <span class="method-text">
                                                <strong>Cash</strong>
                                                <small>Cash received from customer</small>
                                            </span>
                                        </label>

                                    </div>

                                    <div class="method-option">

                                        <input
                                            type="radio"
                                            name="payment_method"
                                            id="method_mpesa"
                                            value="MPESA"
                                        >

                                        <label
                                            for="method_mpesa"
                                            class="method-label"
                                        >
                                            <span class="method-icon">
                                                <i class="fas fa-mobile-screen-button"></i>
                                            </span>

                                            <span class="method-text">
                                                <strong>M-Pesa</strong>
                                                <small>Manual transaction entry</small>
                                            </span>
                                        </label>

                                    </div>

                                </div>

                            </div>

                            <div class="form-group">

                                <label for="amount">
                                    Amount Received <span class="required">*</span>
                                </label>

                                <input
                                    type="number"
                                    name="amount"
                                    id="amount"
                                    class="form-control"
                                    min="0.01"
                                    max="<?= e(number_format($outstanding, 2, '.', '')) ?>"
                                    step="0.01"
                                    value="<?= e(number_format($outstanding, 2, '.', '')) ?>"
                                    required
                                >

                                <div class="quick-amounts">

                                    <button
                                        type="button"
                                        class="quick-amount"
                                        data-amount="<?= e(number_format($outstanding, 2, '.', '')) ?>"
                                    >
                                        Full Balance
                                    </button>

                                    <?php if ($outstanding > 100): ?>
                                        <button
                                            type="button"
                                            class="quick-amount"
                                            data-amount="100"
                                        >
                                            100
                                        </button>
                                    <?php endif; ?>

                                    <?php if ($outstanding > 500): ?>
                                        <button
                                            type="button"
                                            class="quick-amount"
                                            data-amount="500"
                                        >
                                            500
                                        </button>
                                    <?php endif; ?>

                                    <?php if ($outstanding > 1000): ?>
                                        <button
                                            type="button"
                                            class="quick-amount"
                                            data-amount="1000"
                                        >
                                            1,000
                                        </button>
                                    <?php endif; ?>

                                </div>

                            </div>

                            <div
                                class="form-group mpesa-field"
                                id="mpesaField"
                            >

                                <label for="reference_number">
                                    M-Pesa Transaction Code
                                    <span class="required">*</span>
                                </label>

                                <input
                                    type="text"
                                    name="reference_number"
                                    id="reference_number"
                                    class="form-control"
                                    maxlength="100"
                                    placeholder="e.g. QGH7K8L9P2"
                                    autocomplete="off"
                                >

                                <div class="reference-help">
                                    Enter the transaction code exactly as received.
                                </div>

                            </div>

                            <div class="form-actions">

                                <a
                                    href="<?= APP_URL ?>/sales/view.php?id=<?= $saleId ?>"
                                    class="btn btn-secondary"
                                >
                                    Cancel
                                </a>

                                <button
                                    type="submit"
                                    class="btn btn-record"
                                >
                                    <i class="fas fa-check-circle"></i>
                                    Record Payment
                                </button>

                            </div>

                        </form>

                    <?php else: ?>

                        <div class="no-payments">
                            <i class="fas fa-circle-check"></i>
                            <strong>This sale is fully paid.</strong>
                            <p>No outstanding balance remains.</p>
                        </div>

                    <?php endif; ?>

                </div>

            </div>

        </div>

        <div class="card">

            <div class="card-header">
                <h2>
                    <i class="fas fa-clock-rotate-left"></i>
                    Payment History
                </h2>

                <p class="card-subtitle">
                    Completed payments recorded against this sale.
                </p>
            </div>

            <?php if ($payments): ?>

                <div style="overflow-x:auto;">

                    <table class="history-table">

                        <thead>
                            <tr>
                                <th>Method</th>
                                <th>Amount</th>
                                <th>Reference</th>
                                <th>Date</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php foreach ($payments as $payment): ?>

                                <tr>

                                    <td>
                                        <?php if ($payment['payment_method'] === 'MPESA'): ?>

                                            <span class="method-badge">
                                                <i class="fas fa-mobile-screen-button"></i>
                                                M-Pesa
                                            </span>

                                        <?php else: ?>

                                            <span class="method-badge">
                                                <i class="fas fa-money-bill"></i>
                                                Cash
                                            </span>

                                        <?php endif; ?>
                                    </td>

                                    <td class="amount-cell">
                                        <?= number_format((float)$payment['amount'], 2) ?>
                                    </td>

                                    <td>
                                        <?= $payment['reference_number']
                                            ? e($payment['reference_number'])
                                            : '—'
                                        ?>
                                    </td>

                                    <td>
                                        <?= $payment['paid_at']
                                            ? date('d M Y H:i', strtotime($payment['paid_at']))
                                            : '—'
                                        ?>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php else: ?>

                <div class="no-payments">
                    <i class="fas fa-receipt"></i>
                    No payments recorded yet.
                </div>

            <?php endif; ?>

        </div>

    </div>

</div>

<script>
const cashMethod = document.getElementById('method_cash');
const mpesaMethod = document.getElementById('method_mpesa');
const mpesaField = document.getElementById('mpesaField');
const referenceInput = document.getElementById('reference_number');
const amountInput = document.getElementById('amount');
const paymentForm = document.getElementById('paymentForm');

function updatePaymentMethod() {

    if (!mpesaField) {
        return;
    }

    if (mpesaMethod.checked) {

        mpesaField.classList.add('active');
        referenceInput.required = true;

    } else {

        mpesaField.classList.remove('active');
        referenceInput.required = false;
        referenceInput.value = '';

    }
}

if (cashMethod && mpesaMethod) {

    cashMethod.addEventListener('change', updatePaymentMethod);
    mpesaMethod.addEventListener('change', updatePaymentMethod);

    updatePaymentMethod();

}

document.querySelectorAll('.quick-amount').forEach(button => {

    button.addEventListener('click', () => {

        amountInput.value = button.dataset.amount;
        amountInput.focus();

    });

});

if (paymentForm) {

    paymentForm.addEventListener('submit', event => {

        const amount = Number(amountInput.value || 0);
        const max = Number(amountInput.max || 0);

        if (amount <= 0) {
            event.preventDefault();
            alert('Payment amount must be greater than zero.');
            amountInput.focus();
            return;
        }

        if (amount > max) {
            event.preventDefault();
            alert('Payment cannot exceed the outstanding balance.');
            amountInput.focus();
            return;
        }

        if (mpesaMethod.checked && !referenceInput.value.trim()) {
            event.preventDefault();
            alert('Enter the M-Pesa transaction code.');
            referenceInput.focus();
        }

    });

}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>