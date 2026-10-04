<?php

require_once __DIR__ . '/../includes/auth.php';

require_login();

$currentPage = 'purchases';

$search = trim((string) ($_GET['search'] ?? ''));
$status = strtoupper(trim((string) ($_GET['status'] ?? '')));
$branchId = filter_var($_GET['branch_id'] ?? null, FILTER_VALIDATE_INT);
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));

$allowedStatuses = [
    'DRAFT',
    'RECEIVED',
    'CANCELLED'
];

if (!in_array($status, $allowedStatuses, true)) {
    $status = '';
}

if ($branchId === false || $branchId < 1) {
    $branchId = null;
}

if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = '';
}

if ($dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = '';
}

try {
    $branchesStmt = $pdo->query(
        "SELECT id, name, code
         FROM branches
         WHERE status = 'ACTIVE'
         ORDER BY name ASC"
    );

    $branches = $branchesStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Haven purchases branch lookup failed: ' . $e->getMessage());
    $branches = [];
}

$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(
        p.reference_number LIKE :search
        OR s.name LIKE :search
    )";
    $params[':search'] = '%' . $search . '%';
}

if ($status !== '') {
    $where[] = "p.status = :status";
    $params[':status'] = $status;
}

if ($branchId !== null) {
    $where[] = "p.branch_id = :branch_id";
    $params[':branch_id'] = $branchId;
}

if ($dateFrom !== '') {
    $where[] = "p.purchase_date >= :date_from";
    $params[':date_from'] = $dateFrom;
}

if ($dateTo !== '') {
    $where[] = "p.purchase_date <= :date_to";
    $params[':date_to'] = $dateTo;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

try {
    $countStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM purchases p
         LEFT JOIN suppliers s ON s.id = p.supplier_id
         $whereSql"
    );

    $countStmt->execute($params);
    $totalPurchases = (int) $countStmt->fetchColumn();

    $summaryStmt = $pdo->prepare(
        "SELECT
            COUNT(*) AS purchase_count,
            COALESCE(SUM(p.total), 0) AS total_value,
            COALESCE(SUM(CASE WHEN p.status = 'RECEIVED' THEN p.total ELSE 0 END), 0) AS received_value,
            COALESCE(SUM(CASE WHEN p.status = 'DRAFT' THEN p.total ELSE 0 END), 0) AS draft_value
         FROM purchases p
         LEFT JOIN suppliers s ON s.id = p.supplier_id
         $whereSql"
    );

    $summaryStmt->execute($params);

    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [
        'purchase_count' => 0,
        'total_value' => 0,
        'received_value' => 0,
        'draft_value' => 0
    ];
} catch (Throwable $e) {
    error_log('Haven purchases summary failed: ' . $e->getMessage());

    $totalPurchases = 0;

    $summary = [
        'purchase_count' => 0,
        'total_value' => 0,
        'received_value' => 0,
        'draft_value' => 0
    ];
}

$perPage = 15;

$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);

if ($page === false || $page < 1) {
    $page = 1;
}

$totalPages = max(1, (int) ceil($totalPurchases / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $perPage;

try {
    $stmt = $pdo->prepare(
        "SELECT
            p.id,
            p.branch_id,
            p.supplier_id,
            p.reference_number,
            p.purchase_date,
            p.subtotal,
            p.discount,
            p.total,
            p.status,
            p.created_at,
            b.name AS branch_name,
            b.code AS branch_code,
            s.name AS supplier_name,
            u.full_name AS created_by_name,
            (
                SELECT COUNT(*)
                FROM purchase_items pi
                WHERE pi.purchase_id = p.id
            ) AS item_count
         FROM purchases p
         LEFT JOIN branches b ON b.id = p.branch_id
         LEFT JOIN suppliers s ON s.id = p.supplier_id
         LEFT JOIN users u ON u.id = p.created_by
         $whereSql
         ORDER BY p.purchase_date DESC, p.id DESC
         LIMIT :limit OFFSET :offset"
    );

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }

    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

    $stmt->execute();

    $purchases = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Haven purchases list failed: ' . $e->getMessage());
    $purchases = [];
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<style>
.purchases-page{padding-bottom:30px}
.purchases-page .page-header{display:flex;align-items:center;justify-content:space-between;gap:20px;margin-bottom:24px}
.purchases-page .page-header h1{margin:0;font-size:28px;font-weight:800;color:#4d0e17;letter-spacing:-.4px}
.purchases-page .page-header p{margin:6px 0 0;color:#777;font-size:14px}
.purchases-page .page-header .btn{display:inline-flex;align-items:center;gap:8px;padding:11px 18px;border-radius:9px;font-weight:700;text-decoration:none}
.purchases-page .stats-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-bottom:22px}
.purchases-page .stat-card{background:#fff;border:1px solid #eee7e4;border-radius:14px;padding:18px;display:flex;align-items:center;gap:14px;box-shadow:0 5px 18px rgba(77,14,23,.05);transition:.2s ease}
.purchases-page .stat-card:hover{transform:translateY(-2px);box-shadow:0 8px 22px rgba(77,14,23,.08)}
.purchases-page .stat-icon{width:46px;height:46px;min-width:46px;border-radius:12px;background:#fff4c7;color:#64131f;display:flex;align-items:center;justify-content:center;font-size:18px}
.purchases-page .stat-label{display:block;color:#777;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px}
.purchases-page .stat-card strong{font-size:18px;color:#4d0e17;font-weight:800}
.purchases-page .card{background:#fff;border:1px solid #eee7e4;border-radius:14px;box-shadow:0 5px 18px rgba(77,14,23,.05);margin-bottom:22px;overflow:hidden}
.purchases-page .filter-card{padding:18px}
.purchases-page .filter-form{display:grid;grid-template-columns:2fr 1.2fr 1.2fr 1fr 1fr auto;align-items:end;gap:14px}
.purchases-page .form-group{min-width:0}
.purchases-page .form-group label{display:block;margin-bottom:7px;color:#4d0e17;font-size:12px;font-weight:700}
.purchases-page .form-group input,.purchases-page .form-group select{width:100%;height:42px;padding:0 12px;border:1px solid #ddd5d2;border-radius:8px;background:#fff;color:#333;font-size:13px;outline:none;transition:border-color .2s,box-shadow .2s}
.purchases-page .form-group input:focus,.purchases-page .form-group select:focus{border-color:#8b1e2d;box-shadow:0 0 0 3px rgba(139,30,45,.08)}
.purchases-page .filter-actions{display:flex;gap:8px}
.purchases-page .filter-actions .btn{height:42px;white-space:nowrap}
.purchases-page .btn{border:0;cursor:pointer}
.purchases-page .btn-primary{background:#64131f;color:#fff}
.purchases-page .btn-primary:hover{background:#4d0e17}
.purchases-page .btn-secondary{background:#f2efed;color:#4d0e17;border:1px solid #e3ddda}
.purchases-page .btn-secondary:hover{background:#e8e2df}
.purchases-page .card-header{display:flex;align-items:center;justify-content:space-between;padding:18px 20px;border-bottom:1px solid #eee7e4}
.purchases-page .card-header h2{margin:0;color:#4d0e17;font-size:17px;font-weight:800}
.purchases-page .card-header p{margin:4px 0 0;color:#888;font-size:12px}
.purchases-page .table-responsive{width:100%;overflow-x:auto}
.purchases-page .data-table{width:100%;border-collapse:collapse;min-width:1050px}
.purchases-page .data-table th{padding:13px 16px;background:#faf8f7;color:#756b68;font-size:11px;text-transform:uppercase;letter-spacing:.55px;font-weight:800;text-align:left;border-bottom:1px solid #e9e3e0;white-space:nowrap}
.purchases-page .data-table td{padding:14px 16px;border-bottom:1px solid #f0ecea;color:#4a4543;font-size:13px;vertical-align:middle}
.purchases-page .data-table tbody tr{transition:background .15s ease}
.purchases-page .data-table tbody tr:hover{background:#fffaf8}
.purchases-page .data-table td strong{color:#4d0e17;font-weight:750}
.purchases-page .data-table td small{display:block;margin-top:3px;color:#999;font-size:10px;font-weight:600}
.purchases-page .badge{display:inline-flex;align-items:center;justify-content:center;padding:5px 9px;border-radius:20px;font-size:10px;font-weight:800;letter-spacing:.3px}
.purchases-page .badge-success{background:#e8f6ed;color:#217342}
.purchases-page .badge-warning{background:#fff5d7;color:#8a6410}
.purchases-page .badge-danger{background:#fde9eb;color:#a52635}
.purchases-page .btn-sm{width:34px;height:34px;padding:0;display:inline-flex;align-items:center;justify-content:center;border-radius:8px;text-decoration:none;font-size:12px}
.purchases-page .empty-state{text-align:center;padding:55px 20px;color:#777}
.purchases-page .empty-state>i{width:64px;height:64px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 15px;background:#fff4c7;color:#64131f;font-size:24px}
.purchases-page .empty-state h3{margin:0 0 7px;color:#4d0e17;font-size:18px}
.purchases-page .empty-state p{margin:0 0 18px;font-size:13px}
.purchases-page .empty-state .btn{display:inline-flex;align-items:center;gap:8px;padding:10px 16px;border-radius:8px;text-decoration:none}
.purchases-page .pagination{display:flex;align-items:center;justify-content:center;gap:12px;padding:17px;border-top:1px solid #eee7e4}
.purchases-page .pagination-link{width:34px;height:34px;border-radius:8px;border:1px solid #e1d9d6;color:#64131f;background:#fff;display:flex;align-items:center;justify-content:center;text-decoration:none;font-size:12px}
.purchases-page .pagination-link:hover{background:#fff4c7}
.purchases-page .pagination-info{font-size:12px;color:#777;font-weight:700}
@media(max-width:1100px){
.purchases-page .stats-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
.purchases-page .filter-form{grid-template-columns:repeat(2,minmax(0,1fr))}
.purchases-page .filter-actions{grid-column:1/-1}
}
@media(max-width:650px){
.purchases-page .page-header{align-items:flex-start;flex-direction:column}
.purchases-page .page-header .btn{width:100%;justify-content:center}
.purchases-page .stats-grid{grid-template-columns:1fr}
.purchases-page .filter-form{grid-template-columns:1fr}
.purchases-page .filter-actions{grid-column:auto}
.purchases-page .filter-actions .btn{flex:1}
.purchases-page .card-header{padding:16px}
.purchases-page .data-table{min-width:950px}
}
</style>

<main class="main-content purchases-page">
    <div class="page-header">
        <div>
            <h1>Purchases</h1>
            <p>Manage supplier purchases and stock receipts.</p>
        </div>

        <a href="create.php" class="btn btn-primary">
            <i class="fa-solid fa-plus"></i>
            New Purchase
        </a>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon">
                <i class="fa-solid fa-cart-shopping"></i>
            </div>
            <div>
                <span class="stat-label">Purchases</span>
                <strong><?= number_format((int) $summary['purchase_count']) ?></strong>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">
                <i class="fa-solid fa-money-bill-transfer"></i>
            </div>
            <div>
                <span class="stat-label">Total Value</span>
                <strong>KSh <?= number_format((float) $summary['total_value'], 2) ?></strong>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">
                <i class="fa-solid fa-box-open"></i>
            </div>
            <div>
                <span class="stat-label">Received</span>
                <strong>KSh <?= number_format((float) $summary['received_value'], 2) ?></strong>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">
                <i class="fa-solid fa-file-pen"></i>
            </div>
            <div>
                <span class="stat-label">Drafts</span>
                <strong>KSh <?= number_format((float) $summary['draft_value'], 2) ?></strong>
            </div>
        </div>
    </div>

    <div class="card filter-card">
        <form method="get" action="index.php" class="filter-form">
            <div class="form-group">
                <label for="search">Search</label>
                <input type="search" id="search" name="search" value="<?= e($search) ?>" placeholder="Reference or supplier...">
            </div>

            <div class="form-group">
                <label for="branch_id">Branch</label>
                <select id="branch_id" name="branch_id">
                    <option value="">All branches</option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?= (int) $branch['id'] ?>" <?= $branchId === (int) $branch['id'] ? 'selected' : '' ?>>
                            <?= e($branch['name']) ?> (<?= e($branch['code']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="status">Status</label>
                <select id="status" name="status">
                    <option value="">All statuses</option>
                    <?php foreach ($allowedStatuses as $purchaseStatus): ?>
                        <option value="<?= e($purchaseStatus) ?>" <?= $status === $purchaseStatus ? 'selected' : '' ?>>
                            <?= e($purchaseStatus) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="date_from">From</label>
                <input type="date" id="date_from" name="date_from" value="<?= e($dateFrom) ?>">
            </div>

            <div class="form-group">
                <label for="date_to">To</label>
                <input type="date" id="date_to" name="date_to" value="<?= e($dateTo) ?>">
            </div>

            <div class="filter-actions">
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-filter"></i>
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
                <h2>Purchase Register</h2>
                <p>
                    <?= number_format($totalPurchases) ?>
                    purchase<?= $totalPurchases === 1 ? '' : 's' ?>
                    found
                </p>
            </div>
        </div>

        <?php if (!$purchases): ?>

            <div class="empty-state">
                <i class="fa-solid fa-cart-shopping"></i>
                <h3>No purchases found</h3>
                <p>There are no purchases matching the selected filters.</p>

                <a href="create.php" class="btn btn-primary">
                    <i class="fa-solid fa-plus"></i>
                    Create Purchase
                </a>
            </div>

        <?php else: ?>

            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Date</th>
                            <th>Supplier</th>
                            <th>Branch</th>
                            <th>Items</th>
                            <th>Total</th>
                            <th>Status</th>
                            <th>Created By</th>
                            <th></th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($purchases as $purchase): ?>

                            <?php
                            $purchaseStatus = (string) $purchase['status'];

                            $statusClass = match ($purchaseStatus) {
                                'RECEIVED' => 'badge-success',
                                'CANCELLED' => 'badge-danger',
                                default => 'badge-warning'
                            };
                            ?>

                            <tr>
                                <td>
                                    <strong><?= e($purchase['reference_number']) ?></strong>
                                </td>

                                <td>
                                    <?= e(date('d M Y', strtotime((string) $purchase['purchase_date']))) ?>
                                </td>

                                <td>
                                    <?= e($purchase['supplier_name'] ?: 'Walk-in Supplier') ?>
                                </td>

                                <td>
                                    <span><?= e($purchase['branch_name'] ?: 'Unknown') ?></span>

                                    <?php if (!empty($purchase['branch_code'])): ?>
                                        <small><?= e($purchase['branch_code']) ?></small>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?= number_format((int) $purchase['item_count']) ?>
                                </td>

                                <td>
                                    <strong>
                                        KSh <?= number_format((float) $purchase['total'], 2) ?>
                                    </strong>
                                </td>

                                <td>
                                    <span class="badge <?= $statusClass ?>">
                                        <?= e($purchaseStatus) ?>
                                    </span>
                                </td>

                                <td>
                                    <?= e($purchase['created_by_name'] ?: 'Unknown') ?>
                                </td>

                                <td>
                                    <a href="view.php?id=<?= (int) $purchase['id'] ?>" class="btn btn-sm btn-secondary" title="View purchase">
                                        <i class="fa-solid fa-eye"></i>
                                    </a>
                                </td>
                            </tr>

                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPages > 1): ?>

                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" class="pagination-link">
                            <i class="fa-solid fa-chevron-left"></i>
                        </a>
                    <?php endif; ?>

                    <span class="pagination-info">
                        Page <?= $page ?> of <?= $totalPages ?>
                    </span>

                    <?php if ($page < $totalPages): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" class="pagination-link">
                            <i class="fa-solid fa-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                </div>

            <?php endif; ?>

        <?php endif; ?>
    </div>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>