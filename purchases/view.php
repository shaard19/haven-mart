<?php
require_once __DIR__ . '/../includes/auth.php';

require_login();

$currentPage = 'purchases';
$pageTitle = 'Purchase Details';

$purchaseId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT
);

if ($purchaseId === false || $purchaseId === null || $purchaseId < 1) {
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
        p.notes,
        p.created_by,
        p.created_at,
        b.name AS branch_name,
        b.code AS branch_code,
        s.name AS supplier_name,
        s.contact_person AS supplier_contact,
        s.phone AS supplier_phone,
        s.email AS supplier_email,
        u.full_name AS created_by_name
    FROM purchases p
    INNER JOIN branches b ON b.id = p.branch_id
    LEFT JOIN suppliers s ON s.id = p.supplier_id
    INNER JOIN users u ON u.id = p.created_by
    WHERE p.id = ?
    LIMIT 1
");

$stmt->execute([
    $purchaseId
]);

$purchase = $stmt->fetch(
    PDO::FETCH_ASSOC
);

if (!$purchase) {
    header('Location: index.php?error=not_found');
    exit;
}

$itemStmt = $pdo->prepare("
    SELECT
        pi.id,
        pi.product_id,
        pi.quantity,
        pi.unit_cost,
        pi.total,
        pr.name AS product_name,
        pr.sku AS product_sku,
        pr.barcode AS product_barcode
    FROM purchase_items pi
    INNER JOIN products pr ON pr.id = pi.product_id
    WHERE pi.purchase_id = ?
    ORDER BY pi.id ASC
");

$itemStmt->execute([
    $purchaseId
]);

$items = $itemStmt->fetchAll(
    PDO::FETCH_ASSOC
);

$success = isset($_GET['success'])
    ? (string) $_GET['success']
    : '';

$error = isset($_GET['error'])
    ? (string) $_GET['error']
    : '';

$status = strtoupper(
    (string) $purchase['status']
);

$statusClass = match ($status) {
    'RECEIVED' => 'status-success',
    'CANCELLED' => 'status-danger',
    default => 'status-warning'
};

$csrfToken = csrf_token();

function moneyValue($value): string
{
    return number_format(
        (float) $value,
        2
    );
}

function quantityValue($value): string
{
    $formatted = number_format(
        (float) $value,
        3,
        '.',
        ''
    );

    return rtrim(
        rtrim($formatted, '0'),
        '.'
    );
}

include __DIR__ . '/../includes/header.php';
?>

<div class="app-layout">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="main-content purchase-view-page">
        <div class="page-header">
            <div>
                <div class="page-title">
                    <i class="fa-solid fa-file-invoice"></i>
                    Purchase Details
                </div>

                <div class="page-subtitle">
                    View purchase information and purchased items
                </div>
            </div>

            <div class="page-actions">
                <a href="index.php" class="btn btn-secondary">
                    <i class="fa-solid fa-arrow-left"></i>
                    Back to Purchases
                </a>

                <?php if ($status === 'DRAFT'): ?>
                    <a
                        href="edit.php?id=<?= (int) $purchase['id'] ?>"
                        class="btn btn-primary"
                    >
                        <i class="fa-solid fa-pen"></i>
                        Edit
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($success === 'created'): ?>
            <div class="alert alert-success">
                <i class="fa-solid fa-circle-check"></i>
                Purchase created successfully.
            </div>
        <?php elseif ($success === 'updated'): ?>
            <div class="alert alert-success">
                <i class="fa-solid fa-circle-check"></i>
                Purchase updated successfully.
            </div>
        <?php elseif ($success === 'received'): ?>
            <div class="alert alert-success">
                <i class="fa-solid fa-circle-check"></i>
                Purchase received successfully and stock was updated.
            </div>
        <?php elseif ($success === 'cancelled'): ?>
            <div class="alert alert-success">
                <i class="fa-solid fa-circle-check"></i>
                Purchase cancelled successfully.
            </div>
        <?php endif; ?>

        <?php if ($error === 'not_found'): ?>
            <div class="alert alert-danger">
                <i class="fa-solid fa-circle-exclamation"></i>
                The requested purchase could not be found.
            </div>
        <?php elseif ($error === 'already_received'): ?>
            <div class="alert alert-danger">
                <i class="fa-solid fa-circle-exclamation"></i>
                This purchase has already been received.
            </div>
        <?php elseif ($error === 'cannot_cancel'): ?>
            <div class="alert alert-danger">
                <i class="fa-solid fa-circle-exclamation"></i>
                This purchase cannot be cancelled in its current status.
            </div>
        <?php elseif ($error === 'not_editable'): ?>
            <div class="alert alert-danger">
                <i class="fa-solid fa-circle-exclamation"></i>
                This purchase can no longer be edited.
            </div>
        <?php endif; ?>

        <div class="card purchase-summary-card">
            <div class="card-header">
                <div>
                    <h3>
                        <i class="fa-solid fa-receipt"></i>
                        <?= e($purchase['reference_number']) ?>
                    </h3>

                    <span class="text-muted">
                        Purchase #<?= (int) $purchase['id'] ?>
                    </span>
                </div>

                <span class="status-badge <?= e($statusClass) ?>">
                    <?= e($status) ?>
                </span>
            </div>

            <div class="card-body">
                <div class="details-grid">
                    <div class="detail-item">
                        <span class="detail-label">
                            Branch
                        </span>

                        <strong>
                            <?= e($purchase['branch_name']) ?>

                            <?php if (!empty($purchase['branch_code'])): ?>
                                <small>
                                    (<?= e($purchase['branch_code']) ?>)
                                </small>
                            <?php endif; ?>
                        </strong>
                    </div>

                    <div class="detail-item">
                        <span class="detail-label">
                            Purchase Date
                        </span>

                        <strong>
                            <?= e(
                                date(
                                    'd M Y',
                                    strtotime($purchase['purchase_date'])
                                )
                            ) ?>
                        </strong>
                    </div>

                    <div class="detail-item">
                        <span class="detail-label">
                            Supplier
                        </span>

                        <strong>
                            <?= !empty($purchase['supplier_name'])
                                ? e($purchase['supplier_name'])
                                : 'No supplier specified' ?>
                        </strong>

                        <?php if (!empty($purchase['supplier_contact'])): ?>
                            <small>
                                <?= e($purchase['supplier_contact']) ?>
                            </small>
                        <?php endif; ?>
                    </div>

                    <div class="detail-item">
                        <span class="detail-label">
                            Created By
                        </span>

                        <strong>
                            <?= e($purchase['created_by_name']) ?>
                        </strong>
                    </div>

                    <div class="detail-item">
                        <span class="detail-label">
                            Created At
                        </span>

                        <strong>
                            <?= e(
                                date(
                                    'd M Y H:i',
                                    strtotime($purchase['created_at'])
                                )
                            ) ?>
                        </strong>
                    </div>

                    <?php if (!empty($purchase['supplier_phone'])): ?>
                        <div class="detail-item">
                            <span class="detail-label">
                                Supplier Phone
                            </span>

                            <strong>
                                <?= e($purchase['supplier_phone']) ?>
                            </strong>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($purchase['supplier_email'])): ?>
                        <div class="detail-item">
                            <span class="detail-label">
                                Supplier Email
                            </span>

                            <strong>
                                <?= e($purchase['supplier_email']) ?>
                            </strong>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="card purchase-items-card">
            <div class="card-header">
                <div>
                    <h3>
                        <i class="fa-solid fa-boxes-stacked"></i>
                        Purchased Items
                    </h3>

                    <span class="text-muted">
                        <?= count($items) ?>
                        item<?= count($items) === 1 ? '' : 's' ?>
                    </span>
                </div>
            </div>

            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Product</th>
                            <th>SKU</th>
                            <th>Quantity</th>
                            <th>Unit Cost</th>
                            <th>Total</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (!$items): ?>
                            <tr>
                                <td
                                    colspan="6"
                                    class="empty-state"
                                >
                                    <i class="fa-solid fa-box-open"></i>
                                    <div>
                                        No purchase items found.
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($items as $index => $item): ?>
                                <tr>
                                    <td>
                                        <?= $index + 1 ?>
                                    </td>

                                    <td>
                                        <strong>
                                            <?= e(
                                                $item['product_name']
                                            ) ?>
                                        </strong>

                                        <?php if (!empty($item['product_barcode'])): ?>
                                            <div class="table-subtext">
                                                Barcode:
                                                <?= e(
                                                    $item['product_barcode']
                                                ) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?= e(
                                            $item['product_sku']
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= e(
                                            quantityValue(
                                                $item['quantity']
                                            )
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= moneyValue(
                                            $item['unit_cost']
                                        ) ?>
                                    </td>

                                    <td>
                                        <strong>
                                            <?= moneyValue(
                                                $item['total']
                                            ) ?>
                                        </strong>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="purchase-total-section">
                <div class="totals-box">
                    <div class="total-row">
                        <span>Subtotal</span>

                        <strong>
                            <?= moneyValue(
                                $purchase['subtotal']
                            ) ?>
                        </strong>
                    </div>

                    <div class="total-row">
                        <span>Discount</span>

                        <strong>
                            <?= moneyValue(
                                $purchase['discount']
                            ) ?>
                        </strong>
                    </div>

                    <div class="total-row grand-total">
                        <span>Grand Total</span>

                        <strong>
                            <?= moneyValue(
                                $purchase['total']
                            ) ?>
                        </strong>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($purchase['notes'])): ?>
            <div class="card">
                <div class="card-header">
                    <h3>
                        <i class="fa-solid fa-note-sticky"></i>
                        Notes
                    </h3>
                </div>

                <div class="card-body">
                    <div class="purchase-notes">
                        <?= nl2br(
                            e($purchase['notes'])
                        ) ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($status === 'DRAFT'): ?>
            <div class="card purchase-actions-card">
                <div class="card-header">
                    <h3>
                        <i class="fa-solid fa-gears"></i>
                        Purchase Actions
                    </h3>
                </div>

                <div class="card-body">
                    <div class="action-warning">
                        <i class="fa-solid fa-circle-info"></i>

                        <div>
                            <strong>
                                This purchase is still a draft.
                            </strong>

                            <p>
                                Receiving the purchase will update
                                stock quantities for this branch.
                            </p>
                        </div>
                    </div>

                    <div class="purchase-action-buttons">
                        <a
                            href="edit.php?id=<?= (int) $purchase['id'] ?>"
                            class="btn btn-primary"
                        >
                            <i class="fa-solid fa-pen"></i>
                            Edit Purchase
                        </a>

                        <form
                            method="POST"
                            action="receive.php"
                            class="inline-form"
                            onsubmit="return confirm('Receive this purchase and update stock? This action cannot be undone automatically.');"
                        >
                            <input
                                type="hidden"
                                name="csrf_token"
                                value="<?= e($csrfToken) ?>"
                            >

                            <input
                                type="hidden"
                                name="purchase_id"
                                value="<?= (int) $purchase['id'] ?>"
                            >

                            <button
                                type="submit"
                                class="btn btn-success"
                            >
                                <i class="fa-solid fa-boxes-stacked"></i>
                                Receive Purchase
                            </button>
                        </form>

                        <form
                            method="POST"
                            action="cancel.php"
                            class="inline-form"
                            onsubmit="return confirm('Cancel this purchase?');"
                        >
                            <input
                                type="hidden"
                                name="csrf_token"
                                value="<?= e($csrfToken) ?>"
                            >

                            <input
                                type="hidden"
                                name="purchase_id"
                                value="<?= (int) $purchase['id'] ?>"
                            >

                            <button
                                type="submit"
                                class="btn btn-danger"
                            >
                                <i class="fa-solid fa-ban"></i>
                                Cancel Purchase
                            </button>
                        </form>
                    </div>
                </div>
            </div>

        <?php elseif ($status === 'RECEIVED'): ?>

            <div class="card">
                <div class="card-body">
                    <div class="action-success">
                        <i class="fa-solid fa-circle-check"></i>

                        <div>
                            <strong>
                                Purchase Received
                            </strong>

                            <p>
                                This purchase has been received
                                and its stock has been processed.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

        <?php elseif ($status === 'CANCELLED'): ?>

            <div class="card">
                <div class="card-body">
                    <div class="action-cancelled">
                        <i class="fa-solid fa-ban"></i>

                        <div>
                            <strong>
                                Purchase Cancelled
                            </strong>

                            <p>
                                This purchase is cancelled
                                and cannot be received.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

        <?php endif; ?>
    </main>
</div>

<style>
.purchase-view-page .purchase-summary-card .card-header,
.purchase-view-page .purchase-actions-card .card-header {
    align-items: center;
}

.purchase-view-page .details-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 20px;
}

.purchase-view-page .detail-item {
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.purchase-view-page .detail-label {
    font-size: 12px;
    color: #777;
    text-transform: uppercase;
    letter-spacing: .5px;
    font-weight: 700;
}

.purchase-view-page .detail-item strong {
    color: #333;
}

.purchase-view-page .detail-item small {
    color: #777;
    font-weight: 400;
}

.purchase-view-page .status-badge {
    display: inline-flex;
    align-items: center;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
}

.purchase-view-page .status-success {
    background: #e8f7ee;
    color: #177245;
}

.purchase-view-page .status-warning {
    background: #fff5d6;
    color: #8a6200;
}

.purchase-view-page .status-danger {
    background: #fde8e8;
    color: #a61b1b;
}

.purchase-view-page .table-subtext {
    margin-top: 3px;
    font-size: 11px;
    color: #777;
}

.purchase-view-page .purchase-total-section {
    display: flex;
    justify-content: flex-end;
    padding: 20px;
    border-top: 1px solid #eee;
}

.purchase-view-page .totals-box {
    width: 320px;
}

.purchase-view-page .total-row {
    display: flex;
    justify-content: space-between;
    padding: 9px 0;
    color: #555;
}

.purchase-view-page .total-row.grand-total {
    margin-top: 8px;
    padding-top: 14px;
    border-top: 2px solid #64131f;
    color: #64131f;
    font-size: 18px;
}

.purchase-view-page .purchase-notes {
    line-height: 1.7;
    color: #444;
}

.purchase-view-page .purchase-action-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.purchase-view-page .inline-form {
    display: inline-flex;
    margin: 0;
}

.purchase-view-page .action-warning,
.purchase-view-page .action-success,
.purchase-view-page .action-cancelled {
    display: flex;
    align-items: flex-start;
    gap: 14px;
    padding: 15px;
    border-radius: 8px;
}

.purchase-view-page .action-warning {
    background: #fff8df;
    color: #795c00;
    margin-bottom: 18px;
}

.purchase-view-page .action-success {
    background: #e8f7ee;
    color: #177245;
}

.purchase-view-page .action-cancelled {
    background: #fde8e8;
    color: #a61b1b;
}

.purchase-view-page .action-warning i,
.purchase-view-page .action-success i,
.purchase-view-page .action-cancelled i {
    font-size: 20px;
    margin-top: 2px;
}

.purchase-view-page .action-warning p,
.purchase-view-page .action-success p,
.purchase-view-page .action-cancelled p {
    margin: 4px 0 0;
    color: inherit;
}

.purchase-view-page .empty-state {
    text-align: center;
    padding: 40px !important;
    color: #777;
}

.purchase-view-page .empty-state i {
    display: block;
    font-size: 30px;
    margin-bottom: 10px;
}

@media (max-width: 900px) {
    .purchase-view-page .details-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 600px) {
    .purchase-view-page .details-grid {
        grid-template-columns: 1fr;
    }

    .purchase-view-page .purchase-total-section {
        padding: 15px;
    }

    .purchase-view-page .totals-box {
        width: 100%;
    }

    .purchase-view-page .purchase-action-buttons {
        flex-direction: column;
    }

    .purchase-view-page .purchase-action-buttons .btn,
    .purchase-view-page .inline-form {
        width: 100%;
    }
}
</style>

<?php include __DIR__ . '/../includes/footer.php'; ?>