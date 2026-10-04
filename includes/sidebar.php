<?php

$current_page = basename($_SERVER['PHP_SELF']);
$current_path = $_SERVER['REQUEST_URI'] ?? '';

function nav_active($path)
{
    global $current_path;

    return strpos($current_path, $path) !== false ? 'active' : '';
}
?>

<aside class="sidebar" id="sidebar">

    <div class="brand">
        <div class="brand-icon">
            <i class="fas fa-store"></i>
        </div>

        <div>
            <strong>Haven Mart</strong>
            <span>Retail Management</span>
        </div>
    </div>

    <nav class="navigation">

        <a href="<?= APP_URL ?>/dashboard/" class="nav-item <?= nav_active('/dashboard/') ?>">
            <i class="fas fa-chart-line"></i>
            <span>Dashboard</span>
        </a>

        <?php if (has_permission('pos.access')): ?>
        <a href="<?= APP_URL ?>/pos/" class="nav-item <?= nav_active('/pos/') ?>">
            <i class="fas fa-cash-register"></i>
            <span>POS</span>
        </a>
        <?php endif; ?>

        <?php if (has_permission('products.view')): ?>
        <a href="<?= APP_URL ?>/products/" class="nav-item <?= nav_active('/products/') ?>">
            <i class="fas fa-box"></i>
            <span>Products</span>
        </a>
        <?php endif; ?>

        <?php if (has_permission('products.view')): ?>
        <a href="<?= APP_URL ?>/categories/" class="nav-item <?= nav_active('/categories/') ?>">
            <i class="fas fa-layer-group"></i>
            <span>Categories</span>
        </a>
        <?php endif; ?>

        <?php if (has_permission('suppliers.view')): ?>
        <a href="<?= APP_URL ?>/suppliers/" class="nav-item <?= nav_active('/suppliers/') ?>">
            <i class="fas fa-truck-field"></i>
            <span>Suppliers</span>
        </a>
        <?php endif; ?>

        <?php if (has_permission('inventory.view')): ?>
        <a href="<?= APP_URL ?>/inventory/" class="nav-item <?= nav_active('/inventory/') ?>">
            <i class="fas fa-warehouse"></i>
            <span>Inventory</span>
        </a>
        <?php endif; ?>

        <?php if (has_permission('purchases.view')): ?>
        <a href="<?= APP_URL ?>/purchases/" class="nav-item <?= nav_active('/purchases/') ?>">
            <i class="fas fa-truck-loading"></i>
            <span>Purchases</span>
        </a>
        <?php endif; ?>

        <?php if (has_permission('sales.view')): ?>
        <a href="<?= APP_URL ?>/sales/" class="nav-item <?= nav_active('/sales/') ?>">
            <i class="fas fa-receipt"></i>
            <span>Sales</span>
        </a>
        <?php endif; ?>

        <?php if (has_permission('reports.view')): ?>
        <a href="<?= APP_URL ?>/reports/" class="nav-item <?= nav_active('/reports/') ?>">
            <i class="fas fa-chart-pie"></i>
            <span>Reports</span>
        </a>
        <?php endif; ?>

        <?php if (has_permission('expenses.view')): ?>
        <a href="<?= APP_URL ?>/expenses/" class="nav-item <?= nav_active('/expenses/') ?>">
            <i class="fas fa-money-bill-wave"></i>
            <span>Expenses</span>
        </a>
        <?php endif; ?>

        <?php if (has_permission('branches.view')): ?>
        <a href="<?= APP_URL ?>/branches/" class="nav-item <?= nav_active('/branches/') ?>">
            <i class="fas fa-code-branch"></i>
            <span>Branches</span>
        </a>
        <?php endif; ?>

        <?php if (has_permission('users.view')): ?>
        <a href="<?= APP_URL ?>/users/" class="nav-item <?= nav_active('/users/') ?>">
            <i class="fas fa-users"></i>
            <span>Users</span>
        </a>
        <?php endif; ?>

        <?php if (has_permission('audit.view')): ?>
        <a href="<?= APP_URL ?>/audit/" class="nav-item <?= nav_active('/audit/') ?>">
            <i class="fas fa-shield-halved"></i>
            <span>Audit Logs</span>
        </a>
        <?php endif; ?>

        <?php if (has_permission('settings.manage')): ?>
        <a href="<?= APP_URL ?>/settings/" class="nav-item <?= nav_active('/settings/') ?>">
            <i class="fas fa-gear"></i>
            <span>Settings</span>
        </a>
        <?php endif; ?>

    </nav>

    <div class="sidebar-bottom">
        <a href="<?= APP_URL ?>/auth/logout.php" class="nav-item logout-link">
            <i class="fas fa-right-from-bracket"></i>
            <span>Logout</span>
        </a>
    </div>

</aside>