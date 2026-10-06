<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/bootstrap.php';

require_login();
require_permission('audit.view');

$user = $_SESSION['user'] ?? [];

$roleName = (string)($user['role_name'] ?? $user['role'] ?? '');
$branchId = isset($user['branch_id']) && (int)$user['branch_id'] > 0
    ? (int)$user['branch_id']
    : null;

$allBranches = in_array(
    strtolower($roleName),
    ['administrator', 'owner'],
    true
);

$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));
$action = trim((string)($_GET['action'] ?? ''));
$module = trim((string)($_GET['module'] ?? ''));
$userId = isset($_GET['user_id']) && (int)$_GET['user_id'] > 0
    ? (int)$_GET['user_id']
    : null;
$search = trim((string)($_GET['search'] ?? ''));

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;

$validDate = static function (string $date): bool {
    $d = DateTime::createFromFormat('Y-m-d', $date);

    return $d !== false && $d->format('Y-m-d') === $date;
};

if ($from !== '' && !$validDate($from)) {
    $from = '';
}

if ($to !== '' && !$validDate($to)) {
    $to = '';
}

if ($from !== '' && $to !== '' && $from > $to) {
    [$from, $to] = [$to, $from];
}

$where = [];
$params = [];

if ($from !== '') {
    $where[] = 'a.created_at >= :from_date';
    $params[':from_date'] = $from . ' 00:00:00';
}

if ($to !== '') {
    $where[] = 'a.created_at <= :to_date';
    $params[':to_date'] = $to . ' 23:59:59';
}

if ($action !== '') {
    $where[] = 'a.action = :action';
    $params[':action'] = $action;
}

if ($module !== '') {
    $where[] = 'a.module = :module';
    $params[':module'] = $module;
}

if ($userId !== null) {
    $where[] = 'a.user_id = :user_id';
    $params[':user_id'] = $userId;
}

if ($search !== '') {
    $where[] = '
        (
            a.action LIKE :search_action
            OR a.module LIKE :search_module
            OR a.record_type LIKE :search_record_type
            OR CAST(a.record_id AS CHAR) LIKE :search_record_id
            OR u.full_name LIKE :search_full_name
            OR u.username LIKE :search_username
            OR b.name LIKE :search_branch_name
            OR b.code LIKE :search_branch_code
            OR a.ip_address LIKE :search_ip
        )
    ';

    $searchValue = '%' . $search . '%';

    $params[':search_action'] = $searchValue;
    $params[':search_module'] = $searchValue;
    $params[':search_record_type'] = $searchValue;
    $params[':search_record_id'] = $searchValue;
    $params[':search_full_name'] = $searchValue;
    $params[':search_username'] = $searchValue;
    $params[':search_branch_name'] = $searchValue;
    $params[':search_branch_code'] = $searchValue;
    $params[':search_ip'] = $searchValue;
}

if (!$allBranches && $branchId !== null) {
    $where[] = 'a.branch_id = :branch_id';
    $params[':branch_id'] = $branchId;
}

$whereSql = $where
    ? ' WHERE ' . implode(' AND ', $where)
    : '';

$countStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM audit_logs a
    LEFT JOIN users u
        ON u.id = a.user_id
    LEFT JOIN branches b
        ON b.id = a.branch_id
    {$whereSql}
");

$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($totalRows / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $perPage;

$logsStmt = $pdo->prepare("
    SELECT
        a.id,
        a.user_id,
        a.branch_id,
        a.action,
        a.module,
        a.record_type,
        a.record_id,
        a.old_values,
        a.new_values,
        a.ip_address,
        a.user_agent,
        a.created_at,
        u.full_name,
        u.username,
        b.name AS branch_name,
        b.code AS branch_code
    FROM audit_logs a
    LEFT JOIN users u
        ON u.id = a.user_id
    LEFT JOIN branches b
        ON b.id = a.branch_id
    {$whereSql}
    ORDER BY a.id DESC
    LIMIT {$perPage} OFFSET {$offset}
");

$logsStmt->execute($params);
$logs = $logsStmt->fetchAll(PDO::FETCH_ASSOC);

$actionStmt = $pdo->query("
    SELECT DISTINCT action
    FROM audit_logs
    WHERE action IS NOT NULL
      AND action <> ''
    ORDER BY action ASC
");

$actions = $actionStmt->fetchAll(PDO::FETCH_COLUMN);

$moduleStmt = $pdo->query("
    SELECT DISTINCT module
    FROM audit_logs
    WHERE module IS NOT NULL
      AND module <> ''
    ORDER BY module ASC
");

$modules = $moduleStmt->fetchAll(PDO::FETCH_COLUMN);

$users = [];

if ($allBranches) {
    $usersStmt = $pdo->query("
        SELECT
            id,
            username,
            full_name
        FROM users
        ORDER BY full_name ASC, username ASC
    ");

    $users = $usersStmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($branchId !== null) {
    $usersStmt = $pdo->prepare("
        SELECT
            id,
            username,
            full_name
        FROM users
        WHERE branch_id = :branch_id
        ORDER BY full_name ASC, username ASC
    ");

    $usersStmt->execute([
        ':branch_id' => $branchId
    ]);

    $users = $usersStmt->fetchAll(PDO::FETCH_ASSOC);
}

$buildPageUrl = static function (
    int $targetPage
) use (
    $from,
    $to,
    $action,
    $module,
    $userId,
    $search
): string {
    $query = [
        'page' => $targetPage
    ];

    if ($from !== '') {
        $query['from'] = $from;
    }

    if ($to !== '') {
        $query['to'] = $to;
    }

    if ($action !== '') {
        $query['action'] = $action;
    }

    if ($module !== '') {
        $query['module'] = $module;
    }

    if ($userId !== null) {
        $query['user_id'] = $userId;
    }

    if ($search !== '') {
        $query['search'] = $search;
    }

    return APP_URL . '/audit/?' . http_build_query($query);
};

$formatJson = static function (?string $value): string {
    if ($value === null || trim($value) === '') {
        return 'No data recorded';
    }

    $decoded = json_decode($value, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        return $value;
    }

    return json_encode(
        $decoded,
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_SLASHES |
        JSON_UNESCAPED_UNICODE
    ) ?: $value;
};

$pageTitle = 'Audit Logs';

require_once __DIR__ . '/../includes/header.php';
?>

<style>
.audit-page {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.audit-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: end;
    gap: 16px;
    flex-wrap: wrap;
}

.audit-filters {
    display: flex;
    align-items: end;
    gap: 12px;
    flex-wrap: wrap;
}

.audit-field {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.audit-field label {
    font-size: 13px;
    font-weight: 700;
}

.audit-field input,
.audit-field select {
    min-width: 155px;
}

.audit-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.audit-nav {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.audit-nav a {
    text-decoration: none;
}

.audit-table-wrap {
    overflow-x: auto;
}

.audit-table {
    width: 100%;
    border-collapse: collapse;
}

.audit-table th,
.audit-table td {
    padding: 12px 10px;
    border-bottom: 1px solid #eee;
    text-align: left;
    vertical-align: top;
    white-space: nowrap;
}

.audit-table th {
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: .04em;
}

.audit-action {
    display: inline-flex;
    align-items: center;
    padding: 5px 9px;
    border-radius: 999px;
    background: #f5f3f0;
    color: #64131f;
    font-size: 11px;
    font-weight: 800;
}

.audit-module {
    font-weight: 700;
    color: #64131f;
}

.audit-record {
    font-size: 12px;
    color: #666;
}

.audit-user {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.audit-user strong {
    font-size: 13px;
}

.audit-user small {
    color: #777;
}

.audit-ip {
    font-family: monospace;
    font-size: 12px;
}

.audit-time {
    font-size: 12px;
    white-space: nowrap;
}

.audit-details {
    width: 100%;
    max-width: 420px;
}

.audit-details summary {
    cursor: pointer;
    color: #64131f;
    font-weight: 700;
}

.audit-details pre {
    margin: 10px 0 0;
    padding: 12px;
    background: #f7f7f7;
    border: 1px solid #e5e5e5;
    border-radius: 6px;
    white-space: pre-wrap;
    word-break: break-word;
    font-size: 11px;
    line-height: 1.5;
    max-height: 260px;
    overflow: auto;
}

.audit-pagination {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    margin-top: 16px;
}

.audit-pages {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
}

.audit-page-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 36px;
    height: 36px;
    padding: 0 10px;
    border: 1px solid #ddd;
    border-radius: 6px;
    text-decoration: none;
    color: #64131f;
    background: #fff;
    font-size: 13px;
    font-weight: 700;
}

.audit-page-link.active {
    background: #64131f;
    color: #fff;
    border-color: #64131f;
}

.audit-page-link.disabled {
    color: #aaa;
    pointer-events: none;
    background: #f7f7f7;
}

.audit-empty {
    text-align: center !important;
    padding: 30px !important;
    color: #777;
}

.audit-summary {
    color: #666;
    font-size: 13px;
}

@media print {
    .audit-toolbar,
    .audit-nav,
    .sidebar,
    .topbar,
    .no-print,
    footer,
    .audit-pagination,
    .audit-details summary {
        display: none !important;
    }

    .main-content {
        margin: 0 !important;
        padding: 0 !important;
    }

    .panel {
        box-shadow: none !important;
        border: 1px solid #ddd !important;
    }

    .audit-details {
        max-width: none;
    }

    .audit-details pre {
        max-height: none;
    }
}
</style>

<div class="audit-page">

    <div class="welcome-card">
        <div>
            <h1>Audit Logs</h1>
            <p>
                Track important system actions, users, records and changes.
            </p>
        </div>
    </div>

    <div class="panel audit-toolbar no-print">

        <form method="get" class="audit-filters">

            <div class="audit-field">
                <label for="from">From</label>
                <input
                    type="date"
                    id="from"
                    name="from"
                    value="<?= e($from) ?>"
                    class="form-control"
                >
            </div>

            <div class="audit-field">
                <label for="to">To</label>
                <input
                    type="date"
                    id="to"
                    name="to"
                    value="<?= e($to) ?>"
                    class="form-control"
                >
            </div>

            <div class="audit-field">
                <label for="module">Module</label>
                <select
                    id="module"
                    name="module"
                    class="form-control"
                >
                    <option value="">All Modules</option>

                    <?php foreach ($modules as $moduleName): ?>
                        <option
                            value="<?= e($moduleName) ?>"
                            <?= $module === $moduleName ? 'selected' : '' ?>
                        >
                            <?= e(ucfirst($moduleName)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="audit-field">
                <label for="action">Action</label>
                <select
                    id="action"
                    name="action"
                    class="form-control"
                >
                    <option value="">All Actions</option>

                    <?php foreach ($actions as $actionName): ?>
                        <option
                            value="<?= e($actionName) ?>"
                            <?= $action === $actionName ? 'selected' : '' ?>
                        >
                            <?= e(str_replace('_', ' ', $actionName)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="audit-field">
                <label for="user_id">User</label>
                <select
                    id="user_id"
                    name="user_id"
                    class="form-control"
                >
                    <option value="">All Users</option>

                    <?php foreach ($users as $auditUser): ?>
                        <option
                            value="<?= (int)$auditUser['id'] ?>"
                            <?= $userId === (int)$auditUser['id'] ? 'selected' : '' ?>
                        >
                            <?= e($auditUser['full_name']) ?>
                            (<?= e($auditUser['username']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="audit-field">
                <label for="search">Search</label>
                <input
                    type="text"
                    id="search"
                    name="search"
                    value="<?= e($search) ?>"
                    class="form-control"
                    placeholder="Action, user, record, IP..."
                >
            </div>

            <button type="submit" class="btn btn-primary">
                Filter
            </button>

            <a
                href="<?= e(APP_URL) ?>/audit/"
                class="btn btn-secondary"
            >
                Reset
            </a>

        </form>

        <div class="audit-actions">
            <button
                type="button"
                class="btn btn-secondary"
                onclick="window.print()"
            >
                Print
            </button>
        </div>

    </div>

    <div class="audit-nav no-print">
        <a
            href="<?= e(APP_URL) ?>/reports/"
            class="btn btn-secondary"
        >
            Reports
        </a>

        <a
            href="<?= e(APP_URL) ?>/dashboard/"
            class="btn btn-secondary"
        >
            Dashboard
        </a>
    </div>

    <div class="panel">

        <div class="panel-header">
            <div>
                <h2>System Activity</h2>
                <p class="audit-summary">
                    Showing
                    <?= $totalRows > 0 ? $offset + 1 : 0 ?>
                    -
                    <?= min($offset + $perPage, $totalRows) ?>
                    of
                    <?= number_format($totalRows) ?>
                    audit records
                </p>
            </div>
        </div>

        <div class="audit-table-wrap">

            <table class="audit-table">

                <thead>
                    <tr>
                        <th>Date & Time</th>
                        <th>User</th>
                        <th>Branch</th>
                        <th>Action</th>
                        <th>Module</th>
                        <th>Record</th>
                        <th>IP Address</th>
                        <th>Changes</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (!$logs): ?>

                    <tr>
                        <td colspan="8" class="audit-empty">
                            No audit records found for the selected filters.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($logs as $log): ?>

                        <tr>

                            <td class="audit-time">
                                <?= e($log['created_at']) ?>
                            </td>

                            <td>
                                <div class="audit-user">

                                    <strong>
                                        <?= e(
                                            (string)(
                                                $log['full_name']
                                                ?? 'System / Unknown'
                                            )
                                        ) ?>
                                    </strong>

                                    <?php if (!empty($log['username'])): ?>
                                        <small>
                                            <?= e($log['username']) ?>
                                        </small>
                                    <?php endif; ?>

                                </div>
                            </td>

                            <td>
                                <?php if (!empty($log['branch_name'])): ?>

                                    <strong>
                                        <?= e($log['branch_name']) ?>
                                    </strong>

                                    <small>
                                        (<?= e($log['branch_code']) ?>)
                                    </small>

                                <?php else: ?>

                                    <span>System / Global</span>

                                <?php endif; ?>
                            </td>

                            <td>
                                <span class="audit-action">
                                    <?= e(
                                        str_replace(
                                            '_',
                                            ' ',
                                            $log['action']
                                        )
                                    ) ?>
                                </span>
                            </td>

                            <td>
                                <span class="audit-module">
                                    <?= e($log['module']) ?>
                                </span>
                            </td>

                            <td>

                                <?php if ($log['record_type'] !== null): ?>

                                    <div class="audit-record">
                                        <?= e($log['record_type']) ?>

                                        <?php if ($log['record_id'] !== null): ?>
                                            #<?= e(
                                                (string)$log['record_id']
                                            ) ?>
                                        <?php endif; ?>
                                    </div>

                                <?php else: ?>

                                    <span>—</span>

                                <?php endif; ?>

                            </td>

                            <td>
                                <span class="audit-ip">
                                    <?= e(
                                        (string)(
                                            $log['ip_address'] ?? '—'
                                        )
                                    ) ?>
                                </span>
                            </td>

                            <td>

                                <?php
                                $hasOld = $log['old_values'] !== null
                                    && trim((string)$log['old_values']) !== '';

                                $hasNew = $log['new_values'] !== null
                                    && trim((string)$log['new_values']) !== '';
                                ?>

                                <?php if ($hasOld || $hasNew): ?>

                                    <details class="audit-details">

                                        <summary>
                                            View changes
                                        </summary>

                                        <?php if ($hasOld): ?>

                                            <strong>Previous</strong>

                                            <pre><?= e(
                                                $formatJson(
                                                    $log['old_values']
                                                )
                                            ) ?></pre>

                                        <?php endif; ?>

                                        <?php if ($hasNew): ?>

                                            <strong>New</strong>

                                            <pre><?= e(
                                                $formatJson(
                                                    $log['new_values']
                                                )
                                            ) ?></pre>

                                        <?php endif; ?>

                                    </details>

                                <?php else: ?>

                                    <span>—</span>

                                <?php endif; ?>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

        <?php if ($totalPages > 1): ?>

            <div class="audit-pagination no-print">

                <div class="audit-summary">
                    Page
                    <?= number_format($page) ?>
                    of
                    <?= number_format($totalPages) ?>
                </div>

                <div class="audit-pages">

                    <?php if ($page > 1): ?>

                        <a
                            href="<?= e($buildPageUrl($page - 1)) ?>"
                            class="audit-page-link"
                        >
                            Previous
                        </a>

                    <?php else: ?>

                        <span class="audit-page-link disabled">
                            Previous
                        </span>

                    <?php endif; ?>

                    <?php
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                    ?>

                    <?php for ($i = $startPage; $i <= $endPage; $i++): ?>

                        <a
                            href="<?= e($buildPageUrl($i)) ?>"
                            class="audit-page-link <?= $i === $page ? 'active' : '' ?>"
                        >
                            <?= $i ?>
                        </a>

                    <?php endfor; ?>

                    <?php if ($page < $totalPages): ?>

                        <a
                            href="<?= e($buildPageUrl($page + 1)) ?>"
                            class="audit-page-link"
                        >
                            Next
                        </a>

                    <?php else: ?>

                        <span class="audit-page-link disabled">
                            Next
                        </span>

                    <?php endif; ?>

                </div>

            </div>

        <?php endif; ?>

    </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>