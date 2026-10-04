<?php

require_once __DIR__ . '/../includes/auth.php';

require_login();
require_permission('suppliers.view');

$currentPage = 'suppliers';

$search = trim((string) ($_GET['search'] ?? ''));
$status = strtoupper(trim((string) ($_GET['status'] ?? '')));

$allowedStatuses = ['ACTIVE', 'INACTIVE'];

if (!in_array($status, $allowedStatuses, true)) {
    $status = '';
}

$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(
        s.name LIKE :search_name
        OR s.contact_person LIKE :search_contact
        OR s.phone LIKE :search_phone
        OR s.email LIKE :search_email
        OR s.tax_number LIKE :search_tax
    )";

    $term = '%' . $search . '%';

    $params[':search_name'] = $term;
    $params[':search_contact'] = $term;
    $params[':search_phone'] = $term;
    $params[':search_email'] = $term;
    $params[':search_tax'] = $term;
}

if ($status !== '') {
    $where[] = 's.status = :status';
    $params[':status'] = $status;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$summary = [
    'total' => 0,
    'active' => 0,
    'inactive' => 0
];

$totalSuppliers = 0;
$suppliers = [];
$loadError = false;

try {
    $summaryStmt = $pdo->query(
        "SELECT
            COUNT(*) AS total,
            COALESCE(SUM(CASE WHEN status = 'ACTIVE' THEN 1 ELSE 0 END), 0) AS active,
            COALESCE(SUM(CASE WHEN status = 'INACTIVE' THEN 1 ELSE 0 END), 0) AS inactive
         FROM suppliers"
    );

    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: $summary;
} catch (Throwable $e) {
    error_log('Haven suppliers summary failed: ' . $e->getMessage());
}

$perPage = 15;

$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);

if ($page === false || $page < 1) {
    $page = 1;
}

try {
    $countStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM suppliers s
         $whereSql"
    );

    $countStmt->execute($params);
    $totalSuppliers = (int) $countStmt->fetchColumn();

    $totalPages = max(1, (int) ceil($totalSuppliers / $perPage));

    if ($page > $totalPages) {
        $page = $totalPages;
    }

    $offset = ($page - 1) * $perPage;

    $stmt = $pdo->prepare(
        "SELECT
            s.id,
            s.name,
            s.contact_person,
            s.phone,
            s.email,
            s.address,
            s.tax_number,
            s.status,
            s.created_at
         FROM suppliers s
         $whereSql
         ORDER BY s.name ASC, s.id DESC
         LIMIT :limit OFFSET :offset"
    );

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }

    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

    $stmt->execute();

    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Haven suppliers list failed: ' . $e->getMessage());
    $loadError = true;
    $totalPages = 1;
    $page = 1;
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<style>
.suppliers-page{padding-bottom:30px}
.suppliers-page .page-header{display:flex;align-items:center;justify-content:space-between;gap:20px;margin-bottom:24px}
.suppliers-page .page-header h1{margin:0;font-size:28px;font-weight:800;color:#4d0e17;letter-spacing:-.4px}
.suppliers-page .page-header p{margin:6px 0 0;color:#777;font-size:14px}
.suppliers-page .page-header .btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:11px 18px;border-radius:9px;font-weight:700;text-decoration:none}
.suppliers-page .stats-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;margin-bottom:22px}
.suppliers-page .stat-card{background:#fff;border:1px solid #eee7e4;border-radius:14px;padding:18px;display:flex;align-items:center;gap:14px;box-shadow:0 5px 18px rgba(77,14,23,.05);transition:.2s ease}
.suppliers-page .stat-card:hover{transform:translateY(-2px);box-shadow:0 8px 22px rgba(77,14,23,.08)}
.suppliers-page .stat-icon{width:46px;height:46px;min-width:46px;border-radius:12px;background:#fff4c7;color:#64131f;display:flex;align-items:center;justify-content:center;font-size:18px}
.suppliers-page .stat-icon.active-icon{background:#e8f6ed;color:#217342}
.suppliers-page .stat-icon.inactive-icon{background:#fde9eb;color:#a52635}
.suppliers-page .stat-label{display:block;color:#777;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px}
.suppliers-page .stat-card strong{font-size:22px;color:#4d0e17;font-weight:800}
.suppliers-page .card{background:#fff;border:1px solid #eee7e4;border-radius:14px;box-shadow:0 5px 18px rgba(77,14,23,.05);margin-bottom:22px;overflow:hidden}
.suppliers-page .filter-card{padding:18px}
.suppliers-page .filter-form{display:grid;grid-template-columns:2fr 1fr auto;align-items:end;gap:14px}
.suppliers-page .form-group{min-width:0}
.suppliers-page .form-group label{display:block;margin-bottom:7px;color:#4d0e17;font-size:12px;font-weight:700}
.suppliers-page .form-group input,.suppliers-page .form-group select{width:100%;height:42px;padding:0 12px;border:1px solid #ddd5d2;border-radius:8px;background:#fff;color:#333;font-size:13px;outline:none;transition:border-color .2s,box-shadow .2s}
.suppliers-page .form-group input:focus,.suppliers-page .form-group select:focus{border-color:#8b1e2d;box-shadow:0 0 0 3px rgba(139,30,45,.08)}
.suppliers-page .filter-actions{display:flex;gap:8px}
.suppliers-page .filter-actions .btn{height:42px;white-space:nowrap}
.suppliers-page .btn{border:0;cursor:pointer}
.suppliers-page .btn-primary{background:#64131f;color:#fff}
.suppliers-page .btn-primary:hover{background:#4d0e17}
.suppliers-page .btn-secondary{background:#f2efed;color:#4d0e17;border:1px solid #e3ddda}
.suppliers-page .btn-secondary:hover{background:#e8e2df}
.suppliers-page .card-header{display:flex;align-items:center;justify-content:space-between;padding:18px 20px;border-bottom:1px solid #eee7e4}
.suppliers-page .card-header h2{margin:0;color:#4d0e17;font-size:17px;font-weight:800}
.suppliers-page .card-header p{margin:4px 0 0;color:#888;font-size:12px}
.suppliers-page .table-responsive{width:100%;overflow-x:auto}
.suppliers-page .data-table{width:100%;border-collapse:collapse;min-width:1000px}
.suppliers-page .data-table th{padding:13px 16px;background:#faf8f7;color:#756b68;font-size:11px;text-transform:uppercase;letter-spacing:.55px;font-weight:800;text-align:left;border-bottom:1px solid #e9e3e0;white-space:nowrap}
.suppliers-page .data-table td{padding:14px 16px;border-bottom:1px solid #f0ecea;color:#4a4543;font-size:13px;vertical-align:middle}
.suppliers-page .data-table tbody tr{transition:background .15s ease}
.suppliers-page .data-table tbody tr:hover{background:#fffaf8}
.suppliers-page .data-table td strong{color:#4d0e17;font-weight:750}
.suppliers-page .supplier-name{display:flex;align-items:center;gap:11px;min-width:190px}
.suppliers-page .supplier-avatar{width:38px;height:38px;min-width:38px;border-radius:11px;background:#fff4c7;color:#64131f;display:flex;align-items:center;justify-content:center;font-size:15px;font-weight:800}
.suppliers-page .supplier-details strong{display:block}
.suppliers-page .supplier-details small{display:block;margin-top:4px;color:#999;font-size:11px}
.suppliers-page .contact-details{display:flex;flex-direction:column;gap:5px}
.suppliers-page .contact-details span{display:flex;align-items:center;gap:7px;white-space:nowrap}
.suppliers-page .contact-details i{width:13px;color:#8b1e2d;font-size:11px}
.suppliers-page .muted{color:#aaa}
.suppliers-page .badge{display:inline-flex;align-items:center;justify-content:center;padding:5px 9px;border-radius:20px;font-size:10px;font-weight:800;letter-spacing:.3px}
.suppliers-page .badge-success{background:#e8f6ed;color:#217342}
.suppliers-page .badge-danger{background:#fde9eb;color:#a52635}
.suppliers-page .btn-sm{width:34px;height:34px;padding:0;display:inline-flex;align-items:center;justify-content:center;border-radius:8px;text-decoration:none;font-size:12px}
.suppliers-page .empty-state{text-align:center;padding:55px 20px;color:#777}
.suppliers-page .empty-state>i{width:64px;height:64px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 15px;background:#fff4c7;color:#64131f;font-size:24px}
.suppliers-page .empty-state h3{margin:0 0 7px;color:#4d0e17;font-size:18px}
.suppliers-page .empty-state p{margin:0 0 18px;font-size:13px}
.suppliers-page .empty-state .btn{display:inline-flex;align-items:center;gap:8px;padding:10px 16px;border-radius:8px;text-decoration:none}
.suppliers-page .pagination{display:flex;align-items:center;justify-content:center;gap:12px;padding:17px;border-top:1px solid #eee7e4}
.suppliers-page .pagination-link{width:34px;height:34px;border-radius:8px;border:1px solid #e1d9d6;color:#64131f;background:#fff;display:flex;align-items:center;justify-content:center;text-decoration:none;font-size:12px}
.suppliers-page .pagination-link:hover{background:#fff4c7}
.suppliers-page .pagination-info{font-size:12px;color:#777;font-weight:700}
.suppliers-page .alert{padding:13px 16px;border-radius:9px;margin-bottom:18px;font-size:13px}
.suppliers-page .alert-danger{background:#fde9eb;color:#a52635;border:1px solid #f5cbd0}
@media(max-width:1000px){
.suppliers-page .stats-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
.suppliers-page .filter-form{grid-template-columns:1fr 1fr}
.suppliers-page .filter-actions{grid-column:1/-1}
}
@media(max-width:650px){
.suppliers-page .page-header{align-items:flex-start;flex-direction:column}
.suppliers-page .page-header .btn{width:100%}
.suppliers-page .stats-grid{grid-template-columns:1fr}
.suppliers-page .filter-form{grid-template-columns:1fr}
.suppliers-page .filter-actions{grid-column:auto}
.suppliers-page .filter-actions .btn{flex:1}
.suppliers-page .card-header{padding:16px}
.suppliers-page .data-table{min-width:900px}
}
</style>

<main class="main-content suppliers-page">

    <div class="page-header">
        <div>
            <h1>Suppliers</h1>
            <p>Manage supplier records and contact information.</p>
        </div>

        <?php if (has_permission('suppliers.create')): ?>
        <a href="create.php" class="btn btn-primary">
            <i class="fas fa-plus"></i>
            Add Supplier
        </a>
        <?php endif; ?>
    </div>

    <?php if ($loadError): ?>
        <div class="alert alert-danger">
            <i class="fas fa-circle-exclamation"></i>
            Suppliers could not be loaded. Please refresh the page or contact your administrator.
        </div>
    <?php endif; ?>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon">
                <i class="fas fa-truck-field"></i>
            </div>
            <div>
                <span class="stat-label">Total Suppliers</span>
                <strong><?= number_format((int) $summary['total']) ?></strong>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon active-icon">
                <i class="fas fa-circle-check"></i>
            </div>
            <div>
                <span class="stat-label">Active Suppliers</span>
                <strong><?= number_format((int) $summary['active']) ?></strong>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon inactive-icon">
                <i class="fas fa-circle-pause"></i>
            </div>
            <div>
                <span class="stat-label">Inactive Suppliers</span>
                <strong><?= number_format((int) $summary['inactive']) ?></strong>
            </div>
        </div>
    </div>

    <div class="card filter-card">
        <form method="get" action="index.php" class="filter-form">
            <div class="form-group">
                <label for="search">Search Suppliers</label>
                <input
                    type="search"
                    id="search"
                    name="search"
                    value="<?= e($search) ?>"
                    placeholder="Name, contact, phone, email or tax number..."
                >
            </div>

            <div class="form-group">
                <label for="status">Supplier Status</label>
                <select id="status" name="status">
                    <option value="">All statuses</option>
                    <?php foreach ($allowedStatuses as $supplierStatus): ?>
                        <option
                            value="<?= e($supplierStatus) ?>"
                            <?= $status === $supplierStatus ? 'selected' : '' ?>
                        >
                            <?= e($supplierStatus) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-actions">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-filter"></i>
                    Filter
                </button>

                <a href="index.php" class="btn btn-secondary">
                    Reset
                </a>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="card-header">
            <div>
                <h2>Supplier Register</h2>
                <p>
                    <?= number_format($totalSuppliers) ?>
                    supplier<?= $totalSuppliers === 1 ? '' : 's' ?> found
                </p>
            </div>
        </div>

        <?php if (!$suppliers && !$loadError): ?>

            <div class="empty-state">
                <i class="fas fa-truck-field"></i>
                <h3>No suppliers found</h3>
                <p>No suppliers match your current search or filters.</p>

                <?php if (has_permission('suppliers.create')): ?>
                <a href="create.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i>
                    Add First Supplier
                </a>
                <?php endif; ?>
            </div>

        <?php elseif ($suppliers): ?>

            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Supplier</th>
                            <th>Contact Person</th>
                            <th>Contact Details</th>
                            <th>Tax Number</th>
                            <th>Registered</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($suppliers as $supplier): ?>
                            <?php
                            $supplierStatus = (string) $supplier['status'];
                            $statusClass = $supplierStatus === 'ACTIVE'
                                ? 'badge-success'
                                : 'badge-danger';

                            $supplierName = (string) $supplier['name'];
                            $initial = function_exists('mb_substr')
                                ? mb_strtoupper(mb_substr($supplierName, 0, 1, 'UTF-8'), 'UTF-8')
                                : strtoupper(substr($supplierName, 0, 1));
                            ?>

                            <tr>
                                <td>
                                    <div class="supplier-name">
                                        <div class="supplier-avatar">
                                            <?= e($initial) ?>
                                        </div>

                                        <div class="supplier-details">
                                            <strong><?= e($supplierName) ?></strong>
                                            <small>
                                                <?= e($supplier['address'] ?: 'Address not provided') ?>
                                            </small>
                                        </div>
                                    </div>
                                </td>

                                <td>
                                    <?= e($supplier['contact_person'] ?: '—') ?>
                                </td>

                                <td>
                                    <div class="contact-details">
                                        <span>
                                            <i class="fas fa-phone"></i>
                                            <?= e($supplier['phone'] ?: 'No phone') ?>
                                        </span>

                                        <span>
                                            <i class="fas fa-envelope"></i>
                                            <?= e($supplier['email'] ?: 'No email') ?>
                                        </span>
                                    </div>
                                </td>

                                <td>
                                    <?= e($supplier['tax_number'] ?: '—') ?>
                                </td>

                                <td>
                                    <?= !empty($supplier['created_at'])
                                        ? e(date('d M Y', strtotime((string) $supplier['created_at'])))
                                        : '—' ?>
                                </td>

                                <td>
                                    <span class="badge <?= $statusClass ?>">
                                        <?= e($supplierStatus) ?>
                                    </span>
                                </td>

                                <td>
                                    <?php if (has_permission('suppliers.edit')): ?>
                                    <a
                                        href="edit.php?id=<?= (int) $supplier['id'] ?>"
                                        class="btn btn-sm btn-secondary"
                                        title="Edit supplier"
                                    >
                                        <i class="fas fa-pen"></i>
                                    </a>
                                    <?php endif; ?>
                                </td>
                            </tr>

                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a
                            href="?<?= e(http_build_query(array_merge($_GET, ['page' => $page - 1]))) ?>"
                            class="pagination-link"
                            aria-label="Previous page"
                        >
                            <i class="fas fa-chevron-left"></i>
                        </a>
                    <?php endif; ?>

                    <span class="pagination-info">
                        Page <?= number_format($page) ?> of <?= number_format($totalPages) ?>
                    </span>

                    <?php if ($page < $totalPages): ?>
                        <a
                            href="?<?= e(http_build_query(array_merge($_GET, ['page' => $page + 1]))) ?>"
                            class="pagination-link"
                            aria-label="Next page"
                        >
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        <?php endif; ?>
    </div>

</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>