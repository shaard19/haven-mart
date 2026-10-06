<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_login();
require_permission('expenses.view');

global $pdo;

$user = current_user();
$isGlobal = in_array($user['role_name'], ['Administrator', 'Owner'], true);

$fromDate = trim($_GET['from_date'] ?? '');
$toDate = trim($_GET['to_date'] ?? '');
$categoryId = (int) ($_GET['category_id'] ?? 0);
$search = trim($_GET['search'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];

if (!$isGlobal) {
    $where[] = 'e.branch_id = :branch_id';
    $params[':branch_id'] = (int) $user['branch_id'];
}

if ($fromDate !== '') {
    $where[] = 'e.expense_date >= :from_date';
    $params[':from_date'] = $fromDate;
}

if ($toDate !== '') {
    $where[] = 'e.expense_date <= :to_date';
    $params[':to_date'] = $toDate;
}

if ($categoryId > 0) {
    $where[] = 'e.category_id = :category_id';
    $params[':category_id'] = $categoryId;
}

if ($search !== '') {
    $searchValue = '%' . $search . '%';

    $where[] = '
        (
            e.description LIKE :search_description
            OR e.reference_number LIKE :search_reference
            OR ec.name LIKE :search_category
            OR u.full_name LIKE :search_user
            OR b.name LIKE :search_branch
            OR b.code LIKE :search_branch_code
        )
    ';

    $params[':search_description'] = $searchValue;
    $params[':search_reference'] = $searchValue;
    $params[':search_category'] = $searchValue;
    $params[':search_user'] = $searchValue;
    $params[':search_branch'] = $searchValue;
    $params[':search_branch_code'] = $searchValue;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM expenses e
    INNER JOIN expense_categories ec ON ec.id = e.category_id
    INNER JOIN users u ON u.id = e.created_by
    INNER JOIN branches b ON b.id = e.branch_id
    {$whereSql}
");

$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$listStmt = $pdo->prepare("
    SELECT
        e.id,
        e.branch_id,
        e.category_id,
        e.amount,
        e.description,
        e.reference_number,
        e.expense_date,
        e.created_by,
        e.created_at,
        e.updated_at,
        ec.name AS category_name,
        u.full_name AS created_by_name,
        b.name AS branch_name,
        b.code AS branch_code
    FROM expenses e
    INNER JOIN expense_categories ec ON ec.id = e.category_id
    INNER JOIN users u ON u.id = e.created_by
    INNER JOIN branches b ON b.id = e.branch_id
    {$whereSql}
    ORDER BY e.expense_date DESC, e.id DESC
    LIMIT {$perPage} OFFSET {$offset}
");

$listStmt->execute($params);
$expenses = $listStmt->fetchAll();

$totalStmt = $pdo->prepare("
    SELECT COALESCE(SUM(e.amount), 0)
    FROM expenses e
    INNER JOIN expense_categories ec ON ec.id = e.category_id
    INNER JOIN users u ON u.id = e.created_by
    INNER JOIN branches b ON b.id = e.branch_id
    {$whereSql}
");

$totalStmt->execute($params);
$filteredTotal = (float) $totalStmt->fetchColumn();

$categoriesStmt = $pdo->query("
    SELECT id, name
    FROM expense_categories
    WHERE status = 'ACTIVE'
    ORDER BY name ASC
");

$categories = $categoriesStmt->fetchAll();

$pageTitle = 'Expenses';

require_once __DIR__ . '/../includes/header.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;gap:20px;margin-bottom:24px;flex-wrap:wrap;">
    <div>
        <h1 style="margin:0;color:#64131f;">Expenses</h1>
        <p style="margin:6px 0 0;color:#666;">Track and manage Haven Mart operating expenses.</p>
    </div>

    <?php if (has_permission('expenses.manage')): ?>
        <a href="<?= e(APP_URL) ?>/expenses/create.php"
           style="display:inline-flex;align-items:center;gap:8px;background:#64131f;color:#fff;padding:11px 17px;border-radius:8px;text-decoration:none;font-weight:600;">
            <i class="fa-solid fa-plus"></i>
            Add Expense
        </a>
    <?php endif; ?>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:15px;margin-bottom:22px;">
    <div style="background:#fff;border:1px solid #eee;border-radius:10px;padding:18px;">
        <div style="font-size:13px;color:#777;">Filtered Expenses</div>
        <div style="font-size:26px;font-weight:700;color:#64131f;margin-top:5px;">
            <?= number_format($totalRows) ?>
        </div>
    </div>

    <div style="background:#fff;border:1px solid #eee;border-radius:10px;padding:18px;">
        <div style="font-size:13px;color:#777;">Filtered Total</div>
        <div style="font-size:26px;font-weight:700;color:#64131f;margin-top:5px;">
            KES <?= number_format($filteredTotal, 2) ?>
        </div>
    </div>
</div>

<div style="background:#fff;border:1px solid #eee;border-radius:10px;padding:18px;margin-bottom:22px;">
    <form method="get" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px;align-items:end;">
        <div>
            <label style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;">From Date</label>
            <input type="date"
                   name="from_date"
                   value="<?= e($fromDate) ?>"
                   style="width:100%;padding:10px;border:1px solid #ccc;border-radius:7px;">
        </div>

        <div>
            <label style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;">To Date</label>
            <input type="date"
                   name="to_date"
                   value="<?= e($toDate) ?>"
                   style="width:100%;padding:10px;border:1px solid #ccc;border-radius:7px;">
        </div>

        <div>
            <label style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;">Category</label>
            <select name="category_id"
                    style="width:100%;padding:10px;border:1px solid #ccc;border-radius:7px;">
                <option value="0">All Categories</option>
                <?php foreach ($categories as $category): ?>
                    <option value="<?= (int) $category['id'] ?>"
                        <?= $categoryId === (int) $category['id'] ? 'selected' : '' ?>>
                        <?= e($category['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;">Search</label>
            <input type="text"
                   name="search"
                   value="<?= e($search) ?>"
                   placeholder="Description, reference..."
                   style="width:100%;padding:10px;border:1px solid #ccc;border-radius:7px;">
        </div>

        <div style="display:flex;gap:8px;">
            <button type="submit"
                    style="border:0;background:#64131f;color:#fff;padding:10px 16px;border-radius:7px;cursor:pointer;font-weight:600;">
                <i class="fa-solid fa-filter"></i>
                Filter
            </button>

            <a href="<?= e(APP_URL) ?>/expenses/"
               style="display:inline-flex;align-items:center;background:#eee;color:#333;padding:10px 16px;border-radius:7px;text-decoration:none;">
                Reset
            </a>
        </div>
    </form>
</div>

<div style="background:#fff;border:1px solid #eee;border-radius:10px;overflow:hidden;">
    <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;min-width:950px;">
            <thead>
                <tr style="background:#64131f;color:#fff;">
                    <th style="padding:12px;text-align:left;">Date</th>
                    <?php if ($isGlobal): ?>
                        <th style="padding:12px;text-align:left;">Branch</th>
                    <?php endif; ?>
                    <th style="padding:12px;text-align:left;">Category</th>
                    <th style="padding:12px;text-align:left;">Description</th>
                    <th style="padding:12px;text-align:left;">Reference</th>
                    <th style="padding:12px;text-align:right;">Amount</th>
                    <th style="padding:12px;text-align:left;">Recorded By</th>
                    <?php if (has_permission('expenses.manage')): ?>
                        <th style="padding:12px;text-align:center;">Actions</th>
                    <?php endif; ?>
                </tr>
            </thead>

            <tbody>
                <?php if (!$expenses): ?>
                    <tr>
                        <td colspan="<?= $isGlobal ? 8 : 7 ?>" style="padding:35px;text-align:center;color:#777;">
                            <i class="fa-solid fa-receipt" style="font-size:30px;margin-bottom:10px;"></i>
                            <div>No expenses found.</div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($expenses as $expense): ?>
                        <tr style="border-bottom:1px solid #eee;">
                            <td style="padding:12px;">
                                <?= e(date('d M Y', strtotime($expense['expense_date']))) ?>
                            </td>

                            <?php if ($isGlobal): ?>
                                <td style="padding:12px;">
                                    <?= e($expense['branch_name']) ?>
                                    <?php if (!empty($expense['branch_code'])): ?>
                                        <div style="font-size:12px;color:#888;">
                                            <?= e($expense['branch_code']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>

                            <td style="padding:12px;">
                                <?= e($expense['category_name']) ?>
                            </td>

                            <td style="padding:12px;">
                                <?= e($expense['description']) ?>
                            </td>

                            <td style="padding:12px;">
                                <?= $expense['reference_number'] !== null && $expense['reference_number'] !== ''
                                    ? e($expense['reference_number'])
                                    : '<span style="color:#999;">—</span>' ?>
                            </td>

                            <td style="padding:12px;text-align:right;font-weight:700;">
                                KES <?= number_format((float) $expense['amount'], 2) ?>
                            </td>

                            <td style="padding:12px;">
                                <?= e($expense['created_by_name']) ?>
                            </td>

                            <?php if (has_permission('expenses.manage')): ?>
                                <td style="padding:12px;text-align:center;white-space:nowrap;">
                                    <a href="<?= e(APP_URL) ?>/expenses/edit.php?id=<?= (int) $expense['id'] ?>"
                                       style="color:#64131f;text-decoration:none;margin-right:10px;"
                                       title="Edit">
                                        <i class="fa-solid fa-pen-to-square"></i>
                                    </a>

                                    <a href="<?= e(APP_URL) ?>/expenses/delete.php?id=<?= (int) $expense['id'] ?>"
                                       style="color:#b42318;text-decoration:none;"
                                       title="Delete"
                                       onclick="return confirm('Delete this expense? This action cannot be undone.');">
                                        <i class="fa-solid fa-trash"></i>
                                    </a>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($totalPages > 1): ?>
    <div style="display:flex;justify-content:center;gap:7px;margin-top:20px;flex-wrap:wrap;">
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <?php
            $query = $_GET;
            $query['page'] = $i;
            $url = APP_URL . '/expenses/?' . http_build_query($query);
            ?>
            <a href="<?= e($url) ?>"
               style="padding:8px 12px;border-radius:6px;text-decoration:none;
               <?= $i === $page
                   ? 'background:#64131f;color:#fff;'
                   : 'background:#eee;color:#333;' ?>">
                <?= $i ?>
            </a>
        <?php endfor; ?>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>