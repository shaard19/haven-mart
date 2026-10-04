<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_permission('suppliers.view');

$current_page = 'suppliers';
$page_title = 'Supplier Details';

$supplierId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$supplierId) {
    $_SESSION['error'] = 'Invalid supplier ID.';
    header('Location: ' . APP_URL . '/suppliers/');
    exit;
}

$stmt = $pdo->prepare("
    SELECT
        id,
        name,
        contact_person,
        phone,
        email,
        address,
        tax_number,
        status,
        created_at,
        updated_at
    FROM suppliers
    WHERE id = :id
    LIMIT 1
");

$stmt->execute([
    ':id' => $supplierId
]);

$supplier = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$supplier) {
    $_SESSION['error'] = 'Supplier not found.';
    header('Location: ' . APP_URL . '/suppliers/');
    exit;
}

$initials = '';

if (!empty($supplier['name'])) {
    $words = preg_split('/\s+/', trim($supplier['name']));

    if (count($words) >= 2) {
        $initials = mb_substr($words[0], 0, 1) . mb_substr($words[1], 0, 1);
    } else {
        $initials = mb_substr($supplier['name'], 0, 2);
    }
}

$initials = strtoupper($initials);

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<main class="main-content">
    <div class="page-header">
        <div>
            <h1>
                <i class="fas fa-truck-field"></i>
                Supplier Details
            </h1>
            <p>View supplier information and account status.</p>
        </div>

        <div class="header-actions">
            <a href="<?= APP_URL ?>/suppliers/" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i>
                Back to Suppliers
            </a>

            <?php if (has_permission('suppliers.edit')): ?>
                <a href="<?= APP_URL ?>/suppliers/edit.php?id=<?= (int) $supplier['id'] ?>" class="btn btn-primary">
                    <i class="fas fa-pen"></i>
                    Edit Supplier
                </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="supplier-layout">
        <section class="supplier-profile-card">
            <div class="profile-top">
                <div class="supplier-avatar">
                    <?= e($initials) ?>
                </div>

                <div class="supplier-heading">
                    <h2><?= e($supplier['name']) ?></h2>

                    <?php if ($supplier['status'] === 'ACTIVE'): ?>
                        <span class="status-badge active">
                            <i class="fas fa-circle"></i>
                            Active
                        </span>
                    <?php else: ?>
                        <span class="status-badge inactive">
                            <i class="fas fa-circle"></i>
                            Inactive
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="profile-divider"></div>

            <div class="profile-meta">
                <div class="meta-item">
                    <span class="meta-label">Supplier ID</span>
                    <strong>#<?= str_pad((string) $supplier['id'], 5, '0', STR_PAD_LEFT) ?></strong>
                </div>

                <div class="meta-item">
                    <span class="meta-label">Created</span>
                    <strong><?= date('d M Y, H:i', strtotime($supplier['created_at'])) ?></strong>
                </div>

                <div class="meta-item">
                    <span class="meta-label">Last Updated</span>
                    <strong><?= date('d M Y, H:i', strtotime($supplier['updated_at'])) ?></strong>
                </div>
            </div>
        </section>

        <section class="details-card">
            <div class="card-header">
                <div>
                    <h2>Contact Information</h2>
                    <p>Supplier communication details.</p>
                </div>

                <div class="card-icon">
                    <i class="fas fa-address-book"></i>
                </div>
            </div>

            <div class="details-grid">
                <div class="detail-item">
                    <span class="detail-label">
                        <i class="fas fa-user"></i>
                        Contact Person
                    </span>

                    <span class="detail-value">
                        <?= $supplier['contact_person'] !== null && $supplier['contact_person'] !== ''
                            ? e($supplier['contact_person'])
                            : '<span class="muted">Not provided</span>' ?>
                    </span>
                </div>

                <div class="detail-item">
                    <span class="detail-label">
                        <i class="fas fa-phone"></i>
                        Phone Number
                    </span>

                    <span class="detail-value">
                        <?php if (!empty($supplier['phone'])): ?>
                            <a href="tel:<?= e($supplier['phone']) ?>">
                                <?= e($supplier['phone']) ?>
                            </a>
                        <?php else: ?>
                            <span class="muted">Not provided</span>
                        <?php endif; ?>
                    </span>
                </div>

                <div class="detail-item">
                    <span class="detail-label">
                        <i class="fas fa-envelope"></i>
                        Email Address
                    </span>

                    <span class="detail-value">
                        <?php if (!empty($supplier['email'])): ?>
                            <a href="mailto:<?= e($supplier['email']) ?>">
                                <?= e($supplier['email']) ?>
                            </a>
                        <?php else: ?>
                            <span class="muted">Not provided</span>
                        <?php endif; ?>
                    </span>
                </div>

                <div class="detail-item">
                    <span class="detail-label">
                        <i class="fas fa-location-dot"></i>
                        Address
                    </span>

                    <span class="detail-value">
                        <?= $supplier['address'] !== null && $supplier['address'] !== ''
                            ? nl2br(e($supplier['address']))
                            : '<span class="muted">Not provided</span>' ?>
                    </span>
                </div>

                <div class="detail-item">
                    <span class="detail-label">
                        <i class="fas fa-file-invoice"></i>
                        Tax Number
                    </span>

                    <span class="detail-value">
                        <?= $supplier['tax_number'] !== null && $supplier['tax_number'] !== ''
                            ? e($supplier['tax_number'])
                            : '<span class="muted">Not provided</span>' ?>
                    </span>
                </div>

                <div class="detail-item">
                    <span class="detail-label">
                        <i class="fas fa-toggle-on"></i>
                        Account Status
                    </span>

                    <span class="detail-value">
                        <?php if ($supplier['status'] === 'ACTIVE'): ?>
                            <span class="inline-status active">
                                <i class="fas fa-circle"></i>
                                Active
                            </span>
                        <?php else: ?>
                            <span class="inline-status inactive">
                                <i class="fas fa-circle"></i>
                                Inactive
                            </span>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
        </section>
    </div>

    <section class="activity-card">
        <div class="card-header">
            <div>
                <h2>Supplier Overview</h2>
                <p>Additional supplier information.</p>
            </div>

            <div class="card-icon">
                <i class="fas fa-chart-line"></i>
            </div>
        </div>

        <div class="overview-grid">
            <div class="overview-item">
                <div class="overview-icon">
                    <i class="fas fa-truck"></i>
                </div>

                <div>
                    <span>Supplier Status</span>
                    <strong><?= $supplier['status'] === 'ACTIVE' ? 'Available' : 'Inactive' ?></strong>
                </div>
            </div>

            <div class="overview-item">
                <div class="overview-icon">
                    <i class="fas fa-calendar-plus"></i>
                </div>

                <div>
                    <span>Supplier Since</span>
                    <strong><?= date('d M Y', strtotime($supplier['created_at'])) ?></strong>
                </div>
            </div>

            <div class="overview-item">
                <div class="overview-icon">
                    <i class="fas fa-clock"></i>
                </div>

                <div>
                    <span>Last Updated</span>
                    <strong><?= date('d M Y', strtotime($supplier['updated_at'])) ?></strong>
                </div>
            </div>
        </div>
    </section>

    <div class="bottom-actions">
        <a href="<?= APP_URL ?>/suppliers/" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i>
            Back to Suppliers
        </a>

        <?php if (has_permission('suppliers.edit')): ?>
            <a href="<?= APP_URL ?>/suppliers/edit.php?id=<?= (int) $supplier['id'] ?>" class="btn btn-primary">
                <i class="fas fa-pen"></i>
                Edit Supplier
            </a>
        <?php endif; ?>
    </div>
</main>

<style>
.supplier-layout {
    display: grid;
    grid-template-columns: 330px minmax(0, 1fr);
    gap: 22px;
    margin-bottom: 22px;
}

.supplier-profile-card,
.details-card,
.activity-card {
    background: #fff;
    border: 1px solid #e7e1dc;
    border-radius: 16px;
    box-shadow: 0 8px 30px rgba(100, 19, 31, 0.07);
}

.supplier-profile-card {
    padding: 28px;
}

.profile-top {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
}

.supplier-avatar {
    width: 92px;
    height: 92px;
    border-radius: 24px;
    background: #64131f;
    color: #f2c94c;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
    font-weight: 800;
    letter-spacing: 1px;
    margin-bottom: 18px;
}

.supplier-heading h2 {
    margin: 0 0 10px;
    color: #4d0e17;
    font-size: 21px;
    line-height: 1.3;
}

.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 11px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
}

.status-badge i {
    font-size: 6px;
}

.status-badge.active {
    background: #e9f8ef;
    color: #18794e;
}

.status-badge.inactive {
    background: #f3eeee;
    color: #777;
}

.profile-divider {
    height: 1px;
    background: #eee8e3;
    margin: 25px 0;
}

.profile-meta {
    display: flex;
    flex-direction: column;
    gap: 18px;
}

.meta-item {
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.meta-label {
    color: #888;
    font-size: 12px;
}

.meta-item strong {
    color: #4d0e17;
    font-size: 14px;
}

.details-card,
.activity-card {
    overflow: hidden;
}

.card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 24px 26px;
    border-bottom: 1px solid #eee8e3;
}

.card-header h2 {
    margin: 0 0 5px;
    color: #4d0e17;
    font-size: 18px;
}

.card-header p {
    margin: 0;
    color: #888;
    font-size: 13px;
}

.card-icon {
    width: 42px;
    height: 42px;
    border-radius: 11px;
    background: #fff4c7;
    color: #64131f;
    display: flex;
    align-items: center;
    justify-content: center;
}

.details-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

.detail-item {
    min-height: 105px;
    padding: 22px 26px;
    border-bottom: 1px solid #eee8e3;
}

.detail-item:nth-child(odd) {
    border-right: 1px solid #eee8e3;
}

.detail-label {
    display: flex;
    align-items: center;
    gap: 8px;
    color: #888;
    font-size: 12px;
    margin-bottom: 9px;
}

.detail-label i {
    color: #8b1e2d;
    width: 14px;
}

.detail-value {
    color: #333;
    font-size: 14px;
    line-height: 1.6;
    word-break: break-word;
}

.detail-value a {
    color: #64131f;
    text-decoration: none;
    font-weight: 600;
}

.detail-value a:hover {
    text-decoration: underline;
}

.muted {
    color: #aaa;
    font-style: italic;
}

.inline-status {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    font-weight: 700;
}

.inline-status i {
    font-size: 7px;
}

.inline-status.active {
    color: #18794e;
}

.inline-status.inactive {
    color: #777;
}

.activity-card {
    margin-bottom: 22px;
}

.overview-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
}

.overview-item {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 24px 26px;
    border-right: 1px solid #eee8e3;
}

.overview-item:last-child {
    border-right: none;
}

.overview-icon {
    width: 44px;
    height: 44px;
    border-radius: 11px;
    background: #f8f1ec;
    color: #64131f;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.overview-item span {
    display: block;
    color: #888;
    font-size: 12px;
    margin-bottom: 5px;
}

.overview-item strong {
    display: block;
    color: #4d0e17;
    font-size: 14px;
}

.header-actions,
.bottom-actions {
    display: flex;
    align-items: center;
    gap: 10px;
}

.bottom-actions {
    justify-content: flex-end;
}

.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    min-height: 42px;
    padding: 10px 18px;
    border-radius: 8px;
    text-decoration: none;
    border: 1px solid transparent;
    font-size: 14px;
    font-weight: 600;
    font-family: inherit;
    cursor: pointer;
    transition: all .2s;
}

.btn-primary {
    background: #64131f;
    color: #fff;
}

.btn-primary:hover {
    background: #4d0e17;
}

.btn-secondary {
    background: #fff;
    color: #64131f;
    border-color: #d8d0ca;
}

.btn-secondary:hover {
    background: #f7f3ef;
    border-color: #bdb4ad;
}

@media (max-width: 900px) {
    .supplier-layout {
        grid-template-columns: 1fr;
    }

    .supplier-profile-card {
        padding: 24px;
    }
}

@media (max-width: 700px) {
    .details-grid,
    .overview-grid {
        grid-template-columns: 1fr;
    }

    .detail-item:nth-child(odd) {
        border-right: none;
    }

    .overview-item {
        border-right: none;
        border-bottom: 1px solid #eee8e3;
    }

    .overview-item:last-child {
        border-bottom: none;
    }

    .header-actions {
        width: 100%;
        flex-direction: column;
    }

    .header-actions .btn {
        width: 100%;
    }

    .bottom-actions {
        flex-direction: column;
    }

    .bottom-actions .btn {
        width: 100%;
    }
}
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>