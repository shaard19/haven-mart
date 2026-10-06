<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_login();
require_permission('expenses.manage');

$user = current_user();

if (!$user) {
    header('Location: ' . APP_URL . '/auth/login.php');
    exit;
}

$isGlobal = in_array($user['role_name'], ['Administrator', 'Owner'], true);

$id = isset($_GET['id']) ? (int) $_GET['id'] : (int) ($_POST['id'] ?? 0);

if ($id <= 0) {
    header('Location: ' . APP_URL . '/expenses/');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        exit('Invalid security token.');
    }

    if ($isGlobal) {
        $stmt = $pdo->prepare("
            SELECT
                e.*,
                ec.name AS category_name,
                b.name AS branch_name,
                u.full_name AS created_by_name
            FROM expenses e
            INNER JOIN expense_categories ec ON ec.id = e.category_id
            INNER JOIN branches b ON b.id = e.branch_id
            INNER JOIN users u ON u.id = e.created_by
            WHERE e.id = ?
            LIMIT 1
        ");

        $stmt->execute([$id]);
    } else {
        $stmt = $pdo->prepare("
            SELECT
                e.*,
                ec.name AS category_name,
                b.name AS branch_name,
                u.full_name AS created_by_name
            FROM expenses e
            INNER JOIN expense_categories ec ON ec.id = e.category_id
            INNER JOIN branches b ON b.id = e.branch_id
            INNER JOIN users u ON u.id = e.created_by
            WHERE e.id = ?
            AND e.branch_id = ?
            LIMIT 1
        ");

        $stmt->execute([
            $id,
            (int) $user['branch_id']
        ]);
    }

    $expense = $stmt->fetch();

    if (!$expense) {
        http_response_code(404);
        exit('Expense not found or access denied.');
    }

    try {
        $pdo->beginTransaction();

        $oldValues = [
            'id' => (int) $expense['id'],
            'branch_id' => (int) $expense['branch_id'],
            'branch_name' => $expense['branch_name'],
            'category_id' => (int) $expense['category_id'],
            'category_name' => $expense['category_name'],
            'amount' => $expense['amount'],
            'description' => $expense['description'],
            'reference_number' => $expense['reference_number'],
            'expense_date' => $expense['expense_date'],
            'created_by' => (int) $expense['created_by'],
            'created_by_name' => $expense['created_by_name'],
            'created_at' => $expense['created_at'],
            'updated_at' => $expense['updated_at']
        ];

        $auditStmt = $pdo->prepare("
            INSERT INTO audit_logs (
                user_id,
                branch_id,
                action,
                module,
                record_type,
                record_id,
                old_values,
                new_values,
                ip_address,
                user_agent
            ) VALUES (
                :user_id,
                :branch_id,
                :action,
                :module,
                :record_type,
                :record_id,
                :old_values,
                NULL,
                :ip_address,
                :user_agent
            )
        ");

        $auditStmt->execute([
            ':user_id' => (int) $user['id'],
            ':branch_id' => (int) $expense['branch_id'],
            ':action' => 'EXPENSE_DELETED',
            ':module' => 'Expenses',
            ':record_type' => 'expense',
            ':record_id' => (int) $expense['id'],
            ':old_values' => json_encode($oldValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            ':user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500)
        ]);

        $deleteStmt = $pdo->prepare("
            DELETE FROM expenses
            WHERE id = ?
        ");

        $deleteStmt->execute([$id]);

        if ($deleteStmt->rowCount() !== 1) {
            throw new RuntimeException('Expense could not be deleted.');
        }

        $pdo->commit();

        header('Location: ' . APP_URL . '/expenses/?deleted=1');
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        http_response_code(500);
        exit('Unable to delete the expense. Please try again.');
    }
}

if ($isGlobal) {
    $stmt = $pdo->prepare("
        SELECT
            e.*,
            ec.name AS category_name,
            b.name AS branch_name,
            u.full_name AS created_by_name
        FROM expenses e
        INNER JOIN expense_categories ec ON ec.id = e.category_id
        INNER JOIN branches b ON b.id = e.branch_id
        INNER JOIN users u ON u.id = e.created_by
        WHERE e.id = ?
        LIMIT 1
    ");

    $stmt->execute([$id]);
} else {
    $stmt = $pdo->prepare("
        SELECT
            e.*,
            ec.name AS category_name,
            b.name AS branch_name,
            u.full_name AS created_by_name
        FROM expenses e
        INNER JOIN expense_categories ec ON ec.id = e.category_id
        INNER JOIN branches b ON b.id = e.branch_id
        INNER JOIN users u ON u.id = e.created_by
        WHERE e.id = ?
        AND e.branch_id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $id,
        (int) $user['branch_id']
    ]);
}

$expense = $stmt->fetch();

if (!$expense) {
    http_response_code(404);
    exit('Expense not found or access denied.');
}

require_once __DIR__ . '/../includes/header.php';
?>

<div style="max-width:720px;margin:30px auto;">
    <div style="background:#fff;border-radius:14px;padding:28px;box-shadow:0 4px 18px rgba(0,0,0,.08);border-top:5px solid #64131f;">
        <div style="display:flex;align-items:center;gap:14px;margin-bottom:22px;">
            <div style="width:48px;height:48px;border-radius:50%;background:#fff1f1;color:#b42318;display:flex;align-items:center;justify-content:center;font-size:22px;">
                <i class="fa-solid fa-trash"></i>
            </div>
            <div>
                <h2 style="margin:0;color:#64131f;">Delete Expense</h2>
                <p style="margin:4px 0 0;color:#777;">Please confirm this action.</p>
            </div>
        </div>

        <div style="background:#faf8f6;border:1px solid #eadfd9;border-radius:10px;padding:18px;margin-bottom:22px;">
            <div style="display:grid;grid-template-columns:150px 1fr;gap:10px 16px;font-size:14px;">
                <strong>Branch</strong>
                <span><?= e($expense['branch_name']) ?></span>

                <strong>Category</strong>
                <span><?= e($expense['category_name']) ?></span>

                <strong>Amount</strong>
                <span style="font-weight:700;color:#64131f;">
                    KES <?= number_format((float) $expense['amount'], 2) ?>
                </span>

                <strong>Description</strong>
                <span><?= e($expense['description']) ?></span>

                <strong>Reference</strong>
                <span><?= e($expense['reference_number'] ?: '—') ?></span>

                <strong>Expense Date</strong>
                <span><?= e($expense['expense_date']) ?></span>

                <strong>Created By</strong>
                <span><?= e($expense['created_by_name']) ?></span>
            </div>
        </div>

        <div style="background:#fff8e1;border:1px solid #f2c94c;border-radius:10px;padding:14px 16px;margin-bottom:24px;color:#6b4423;font-size:14px;">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <strong> Warning:</strong>
            This expense will be permanently removed from the expense records.
            The deletion will remain recorded in the system audit log.
        </div>

        <form method="post" action="<?= APP_URL ?>/expenses/delete.php?id=<?= (int) $expense['id'] ?>">
            <input type="hidden" name="id" value="<?= (int) $expense['id'] ?>">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <a href="<?= APP_URL ?>/expenses/"
                   style="display:inline-flex;align-items:center;gap:7px;padding:10px 18px;border-radius:8px;text-decoration:none;background:#eee;color:#444;font-weight:600;">
                    <i class="fa-solid fa-arrow-left"></i>
                    Cancel
                </a>

                <button type="submit"
                        onclick="return confirm('Are you sure you want to permanently delete this expense?');"
                        style="border:0;cursor:pointer;display:inline-flex;align-items:center;gap:7px;padding:10px 18px;border-radius:8px;background:#b42318;color:#fff;font-weight:600;">
                    <i class="fa-solid fa-trash"></i>
                    Delete Expense
                </button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>