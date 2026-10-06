<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_login();
require_permission('expenses.manage');

global $pdo;

$user = current_user();
$isGlobal = in_array($user['role_name'], ['Administrator', 'Owner'], true);

$errors = [];

$branches = [];
if ($isGlobal) {
    $branchStmt = $pdo->query("
        SELECT id, name, code
        FROM branches
        WHERE status = 'ACTIVE'
        ORDER BY name ASC
    ");
    $branches = $branchStmt->fetchAll();
}

$categoryStmt = $pdo->query("
    SELECT id, name, description
    FROM expense_categories
    WHERE status = 'ACTIVE'
    ORDER BY name ASC
");

$categories = $categoryStmt->fetchAll();

$branchId = $isGlobal ? (int) ($_POST['branch_id'] ?? 0) : (int) $user['branch_id'];
$categoryId = (int) ($_POST['category_id'] ?? 0);
$amount = trim($_POST['amount'] ?? '');
$description = trim($_POST['description'] ?? '');
$referenceNumber = trim($_POST['reference_number'] ?? '');
$expenseDate = trim($_POST['expense_date'] ?? date('Y-m-d'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session has expired. Please try again.';
    }

    if ($isGlobal) {
        if ($branchId <= 0) {
            $errors[] = 'Please select a branch.';
        }
    } else {
        $branchId = (int) $user['branch_id'];

        if ($branchId <= 0) {
            $errors[] = 'Your account is not assigned to a branch.';
        }
    }

    if ($categoryId <= 0) {
        $errors[] = 'Please select an expense category.';
    }

    if ($amount === '' || !is_numeric($amount)) {
        $errors[] = 'Please enter a valid expense amount.';
    } elseif ((float) $amount <= 0) {
        $errors[] = 'Expense amount must be greater than zero.';
    } elseif ((float) $amount > 999999999999.99) {
        $errors[] = 'Expense amount is too large.';
    }

    if ($description === '') {
        $errors[] = 'Please enter an expense description.';
    } elseif (mb_strlen($description) > 255) {
        $errors[] = 'Description must not exceed 255 characters.';
    }

    if ($referenceNumber !== '' && mb_strlen($referenceNumber) > 100) {
        $errors[] = 'Reference number must not exceed 100 characters.';
    }

    $dateObject = DateTime::createFromFormat('Y-m-d', $expenseDate);

    if (
        !$dateObject ||
        $dateObject->format('Y-m-d') !== $expenseDate
    ) {
        $errors[] = 'Please enter a valid expense date.';
    }

    if (!$errors) {
        $branchCheck = $pdo->prepare("
            SELECT id
            FROM branches
            WHERE id = ?
            AND status = 'ACTIVE'
            LIMIT 1
        ");

        $branchCheck->execute([$branchId]);

        if (!$branchCheck->fetchColumn()) {
            $errors[] = 'The selected branch is invalid or inactive.';
        }
    }

    if (!$errors) {
        $categoryCheck = $pdo->prepare("
            SELECT id
            FROM expense_categories
            WHERE id = ?
            AND status = 'ACTIVE'
            LIMIT 1
        ");

        $categoryCheck->execute([$categoryId]);

        if (!$categoryCheck->fetchColumn()) {
            $errors[] = 'The selected expense category is invalid or inactive.';
        }
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $insertStmt = $pdo->prepare("
                INSERT INTO expenses (
                    branch_id,
                    category_id,
                    amount,
                    description,
                    reference_number,
                    expense_date,
                    created_by
                ) VALUES (
                    :branch_id,
                    :category_id,
                    :amount,
                    :description,
                    :reference_number,
                    :expense_date,
                    :created_by
                )
            ");

            $insertStmt->execute([
                ':branch_id' => $branchId,
                ':category_id' => $categoryId,
                ':amount' => number_format((float) $amount, 2, '.', ''),
                ':description' => $description,
                ':reference_number' => $referenceNumber !== '' ? $referenceNumber : null,
                ':expense_date' => $expenseDate,
                ':created_by' => (int) $user['id']
            ]);

            $expenseId = (int) $pdo->lastInsertId();

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
                    :new_values,
                    :ip_address,
                    :user_agent
                )
            ");

            $auditStmt->execute([
                ':user_id' => (int) $user['id'],
                ':branch_id' => $branchId,
                ':action' => 'EXPENSE_CREATED',
                ':module' => 'Expenses',
                ':record_type' => 'expense',
                ':record_id' => $expenseId,
                ':old_values' => null,
                ':new_values' => json_encode([
                    'branch_id' => $branchId,
                    'category_id' => $categoryId,
                    'amount' => number_format((float) $amount, 2, '.', ''),
                    'description' => $description,
                    'reference_number' => $referenceNumber !== '' ? $referenceNumber : null,
                    'expense_date' => $expenseDate
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                ':user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500)
            ]);

            $pdo->commit();

            header('Location: ' . APP_URL . '/expenses/?created=1');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $errors[] = 'Unable to save the expense. Please try again.';
        }
    }
}

$pageTitle = 'Add Expense';

require_once __DIR__ . '/../includes/header.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;gap:20px;margin-bottom:24px;flex-wrap:wrap;">
    <div>
        <h1 style="margin:0;color:#64131f;">Add Expense</h1>
        <p style="margin:6px 0 0;color:#666;">Record a new Haven Mart operating expense.</p>
    </div>

    <a href="<?= e(APP_URL) ?>/expenses/"
       style="display:inline-flex;align-items:center;gap:8px;background:#eee;color:#333;padding:11px 17px;border-radius:8px;text-decoration:none;font-weight:600;">
        <i class="fa-solid fa-arrow-left"></i>
        Back to Expenses
    </a>
</div>

<?php if ($errors): ?>
    <div style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:14px 16px;border-radius:8px;margin-bottom:20px;">
        <strong>Please correct the following:</strong>
        <ul style="margin:8px 0 0 20px;padding:0;">
            <?php foreach ($errors as $error): ?>
                <li><?= e($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div style="max-width:850px;background:#fff;border:1px solid #eee;border-radius:10px;padding:24px;">
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:18px;">

            <?php if ($isGlobal): ?>
                <div>
                    <label for="branch_id" style="display:block;font-size:14px;font-weight:600;margin-bottom:7px;">
                        Branch <span style="color:#b42318;">*</span>
                    </label>

                    <select
                        id="branch_id"
                        name="branch_id"
                        required
                        style="width:100%;padding:11px;border:1px solid #ccc;border-radius:7px;background:#fff;"
                    >
                        <option value="">Select branch</option>

                        <?php foreach ($branches as $branch): ?>
                            <option
                                value="<?= (int) $branch['id'] ?>"
                                <?= $branchId === (int) $branch['id'] ? 'selected' : '' ?>
                            >
                                <?= e($branch['name']) ?>
                                <?php if (!empty($branch['code'])): ?>
                                    (<?= e($branch['code']) ?>)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php else: ?>
                <div>
                    <label style="display:block;font-size:14px;font-weight:600;margin-bottom:7px;">
                        Branch
                    </label>

                    <div style="padding:11px 12px;background:#f5f5f5;border:1px solid #ddd;border-radius:7px;">
                        <?= e($user['branch_name'] ?? 'Assigned Branch') ?>
                    </div>
                </div>
            <?php endif; ?>

            <div>
                <label for="category_id" style="display:block;font-size:14px;font-weight:600;margin-bottom:7px;">
                    Expense Category <span style="color:#b42318;">*</span>
                </label>

                <select
                    id="category_id"
                    name="category_id"
                    required
                    style="width:100%;padding:11px;border:1px solid #ccc;border-radius:7px;background:#fff;"
                >
                    <option value="">Select category</option>

                    <?php foreach ($categories as $category): ?>
                        <option
                            value="<?= (int) $category['id'] ?>"
                            <?= $categoryId === (int) $category['id'] ? 'selected' : '' ?>
                        >
                            <?= e($category['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <div id="categoryDescription" style="font-size:12px;color:#777;margin-top:6px;"></div>
            </div>

            <div>
                <label for="amount" style="display:block;font-size:14px;font-weight:600;margin-bottom:7px;">
                    Amount (KES) <span style="color:#b42318;">*</span>
                </label>

                <input
                    type="number"
                    id="amount"
                    name="amount"
                    value="<?= e($amount) ?>"
                    min="0.01"
                    step="0.01"
                    required
                    placeholder="0.00"
                    style="width:100%;padding:11px;border:1px solid #ccc;border-radius:7px;"
                >
            </div>

            <div>
                <label for="expense_date" style="display:block;font-size:14px;font-weight:600;margin-bottom:7px;">
                    Expense Date <span style="color:#b42318;">*</span>
                </label>

                <input
                    type="date"
                    id="expense_date"
                    name="expense_date"
                    value="<?= e($expenseDate) ?>"
                    required
                    style="width:100%;padding:11px;border:1px solid #ccc;border-radius:7px;"
                >
            </div>

            <div style="grid-column:1/-1;">
                <label for="description" style="display:block;font-size:14px;font-weight:600;margin-bottom:7px;">
                    Description <span style="color:#b42318;">*</span>
                </label>

                <textarea
                    id="description"
                    name="description"
                    rows="3"
                    maxlength="255"
                    required
                    placeholder="Describe what the expense was for..."
                    style="width:100%;padding:11px;border:1px solid #ccc;border-radius:7px;resize:vertical;"
                ><?= e($description) ?></textarea>
            </div>

            <div>
                <label for="reference_number" style="display:block;font-size:14px;font-weight:600;margin-bottom:7px;">
                    Reference Number
                </label>

                <input
                    type="text"
                    id="reference_number"
                    name="reference_number"
                    value="<?= e($referenceNumber) ?>"
                    maxlength="100"
                    placeholder="Receipt, invoice or reference"
                    style="width:100%;padding:11px;border:1px solid #ccc;border-radius:7px;"
                >
            </div>

        </div>

        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:24px;padding-top:20px;border-top:1px solid #eee;">
            <a href="<?= e(APP_URL) ?>/expenses/"
               style="display:inline-flex;align-items:center;padding:11px 18px;border-radius:7px;background:#eee;color:#333;text-decoration:none;">
                Cancel
            </a>

            <button
                type="submit"
                style="border:0;background:#64131f;color:#fff;padding:11px 20px;border-radius:7px;cursor:pointer;font-weight:600;"
            >
                <i class="fa-solid fa-save"></i>
                Save Expense
            </button>
        </div>
    </form>
</div>

<script>
const categoryData = <?= json_encode(
    array_column($categories, 'description', 'id'),
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) ?>;

const categorySelect = document.getElementById('category_id');
const categoryDescription = document.getElementById('categoryDescription');

function updateCategoryDescription() {
    const id = categorySelect.value;
    categoryDescription.textContent = id && categoryData[id] ? categoryData[id] : '';
}

categorySelect.addEventListener('change', updateCategoryDescription);
updateCategoryDescription();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>