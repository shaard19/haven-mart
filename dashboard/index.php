<?php

require_once __DIR__ . '/../includes/auth.php';

require_login();

$page_title = 'Dashboard';

require_once __DIR__ . '/../includes/header.php';
?>

<div class="welcome-card">
    <div>
        <span class="eyebrow">HAVEN MART</span>
        <h2>Welcome, <?= e($user['full_name']) ?></h2>
        <p>Manage your retail operations from one central dashboard.</p>
    </div>

    <div class="welcome-icon">
        <i class="fas fa-store"></i>
    </div>
</div>

<div class="stats-grid">

    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-cash-register"></i>
        </div>
        <div>
            <span>Today's Sales</span>
            <strong>KES 0.00</strong>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-box"></i>
        </div>
        <div>
            <span>Products</span>
            <strong>0</strong>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-warehouse"></i>
        </div>
        <div>
            <span>Low Stock</span>
            <strong>0</strong>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-users"></i>
        </div>
        <div>
            <span>Active Users</span>
            <strong>0</strong>
        </div>
    </div>

</div>

<div class="dashboard-grid">

    <section class="panel">
        <div class="panel-header">
            <div>
                <h3>Quick Actions</h3>
                <p>Common operations</p>
            </div>
        </div>

        <div class="quick-actions">

            <?php if (has_permission('pos.access')): ?>
            <a href="<?= APP_URL ?>/pos/" class="quick-action">
                <i class="fas fa-cash-register"></i>
                <span>Open POS</span>
            </a>
            <?php endif; ?>

            <?php if (has_permission('products.manage')): ?>
            <a href="<?= APP_URL ?>/products/" class="quick-action">
                <i class="fas fa-box"></i>
                <span>Manage Products</span>
            </a>
            <?php endif; ?>

            <?php if (has_permission('inventory.view')): ?>
            <a href="<?= APP_URL ?>/inventory/" class="quick-action">
                <i class="fas fa-warehouse"></i>
                <span>View Inventory</span>
            </a>
            <?php endif; ?>

            <?php if (has_permission('reports.view')): ?>
            <a href="<?= APP_URL ?>/reports/" class="quick-action">
                <i class="fas fa-chart-pie"></i>
                <span>View Reports</span>
            </a>
            <?php endif; ?>

        </div>
    </section>

    <section class="panel">
        <div class="panel-header">
            <div>
                <h3>System Status</h3>
                <p>Application information</p>
            </div>
        </div>

        <div class="system-list">
            <div>
                <span>Logged in as</span>
                <strong><?= e($user['role_name']) ?></strong>
            </div>

            <div>
                <span>Branch</span>
                <strong><?= e($user['branch_name'] ?? 'All Branches') ?></strong>
            </div>

            <div>
                <span>Currency</span>
                <strong><?= CURRENCY ?></strong>
            </div>

            <div>
                <span>System</span>
                <strong>Online</strong>
            </div>
        </div>
    </section>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>