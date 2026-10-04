<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();
require_permission('branches.view');

$page_title = 'Branches';

$stmt = $pdo->query("
    SELECT
        b.id,
        b.code,
        b.name,
        b.location,
        b.phone,
        b.email,
        b.status,
        b.created_at,
        COUNT(u.id) AS user_count
    FROM branches b
    LEFT JOIN users u ON u.branch_id = b.id
    GROUP BY
        b.id,
        b.code,
        b.name,
        b.location,
        b.phone,
        b.email,
        b.status,
        b.created_at
    ORDER BY b.name ASC
");

$branches = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <p class="page-kicker">Haven Mart</p>
        <h2>Branch Management</h2>
        <p>Manage all Haven Mart branches and their operational status.</p>
    </div>

    <?php if (has_permission('branches.manage')): ?>
        <a href="<?= APP_URL ?>/branches/create.php" class="button button-primary">
            <i class="fas fa-plus"></i>
            Add Branch
        </a>
    <?php endif; ?>
</div>

<div class="content-card">
    <div class="content-card-header">
        <div>
            <h3>Branches</h3>
            <span><?= count($branches) ?> branch<?= count($branches) === 1 ? '' : 'es' ?></span>
        </div>
    </div>

    <?php if (!$branches): ?>

        <div class="empty-state">
            <div class="empty-state-icon">
                <i class="fas fa-store"></i>
            </div>

            <h3>No branches found</h3>

            <p>
                Create the first Haven Mart branch to begin managing
                branch-based users, stock and sales.
            </p>

            <?php if (has_permission('branches.manage')): ?>
                <a href="<?= APP_URL ?>/branches/create.php" class="button button-primary">
                    <i class="fas fa-plus"></i>
                    Create First Branch
                </a>
            <?php endif; ?>
        </div>

    <?php else: ?>

        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Branch</th>
                        <th>Code</th>
                        <th>Location</th>
                        <th>Contact</th>
                        <th>Users</th>
                        <th>Status</th>
                        <th>Created</th>
                        <?php if (has_permission('branches.manage')): ?>
                            <th>Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($branches as $branch): ?>
                        <tr>
                            <td>
                                <div class="table-primary">
                                    <?= e($branch['name']) ?>
                                </div>
                            </td>

                            <td>
                                <span class="code-badge">
                                    <?= e($branch['code']) ?>
                                </span>
                            </td>

                            <td>
                                <?= $branch['location']
                                    ? e($branch['location'])
                                    : '<span class="muted">Not set</span>' ?>
                            </td>

                            <td>
                                <?php if ($branch['phone']): ?>
                                    <div><?= e($branch['phone']) ?></div>
                                <?php endif; ?>

                                <?php if ($branch['email']): ?>
                                    <div class="muted"><?= e($branch['email']) ?></div>
                                <?php endif; ?>

                                <?php if (!$branch['phone'] && !$branch['email']): ?>
                                    <span class="muted">Not set</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <span class="count-badge">
                                    <?= (int) $branch['user_count'] ?>
                                </span>
                            </td>

                            <td>
                                <?php if ($branch['status'] === 'ACTIVE'): ?>
                                    <span class="status-badge status-active">
                                        <i class="fas fa-circle"></i>
                                        Active
                                    </span>
                                <?php else: ?>
                                    <span class="status-badge status-inactive">
                                        <i class="fas fa-circle"></i>
                                        Inactive
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?= e(date('d M Y', strtotime($branch['created_at']))) ?>
                            </td>

                            <?php if (has_permission('branches.manage')): ?>
                                <td>
                                    <div class="table-actions">
                                        <a
                                            href="<?= APP_URL ?>/branches/edit.php?id=<?= (int) $branch['id'] ?>"
                                            class="icon-button"
                                            title="Edit branch"
                                        >
                                            <i class="fas fa-pen"></i>
                                        </a>

                                        <form
                                            method="POST"
                                            action="<?= APP_URL ?>/branches/toggle.php"
                                            onsubmit="return confirm('Are you sure you want to change this branch status?');"
                                        >
                                            <?= csrf_field() ?>

                                            <input
                                                type="hidden"
                                                name="id"
                                                value="<?= (int) $branch['id'] ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="icon-button <?= $branch['status'] === 'ACTIVE' ? 'danger' : 'success' ?>"
                                                title="<?= $branch['status'] === 'ACTIVE' ? 'Deactivate branch' : 'Activate branch' ?>"
                                            >
                                                <i class="fas <?= $branch['status'] === 'ACTIVE' ? 'fa-toggle-off' : 'fa-toggle-on' ?>"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>