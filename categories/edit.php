<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();
require_permission('categories.manage');

$id = (int) ($_GET['id'] ?? 0);

if ($id <= 0) {
    header('Location: ' . APP_URL . '/categories/');
    exit;
}

$stmt = $pdo->prepare("
    SELECT
        id,
        name,
        status
    FROM categories
    WHERE id = ?
    LIMIT 1
");

$stmt->execute([$id]);

$category = $stmt->fetch();

if (!$category) {
    header('Location: ' . APP_URL . '/categories/');
    exit;
}

$page_title = 'Edit Category';

$name = $category['name'];
$status = $category['status'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Invalid security token.');
    }

    $name = trim($_POST['name'] ?? '');
    $status = $_POST['status'] ?? 'ACTIVE';

    if ($name === '') {
        $errors[] = 'Category name is required.';
    }

    if (strlen($name) > 100) {
        $errors[] = 'Category name cannot exceed 100 characters.';
    }

    if (!in_array($status, ['ACTIVE', 'INACTIVE'], true)) {
        $errors[] = 'Invalid category status.';
    }

    if (!$errors) {

        $check = $pdo->prepare("
            SELECT id
            FROM categories
            WHERE LOWER(name) = LOWER(?)
            AND id != ?
            LIMIT 1
        ");

        $check->execute([
            $name,
            $id
        ]);

        if ($check->fetch()) {
            $errors[] = 'Another category with this name already exists.';
        }
    }

    if (!$errors) {

        $old_values = [
            'name' => $category['name'],
            'status' => $category['status']
        ];

        $new_values = [
            'name' => $name,
            'status' => $status
        ];

        $name_changed = $category['name'] !== $name;
        $status_changed = $category['status'] !== $status;

        if (!$name_changed && !$status_changed) {
            header('Location: ' . APP_URL . '/categories/');
            exit;
        }

        if ($name_changed && $status_changed) {
            $action = 'CATEGORY_UPDATED';
        } elseif ($name_changed) {
            $action = 'CATEGORY_NAME_UPDATED';
        } else {
            $action = $status === 'ACTIVE'
                ? 'CATEGORY_ACTIVATED'
                : 'CATEGORY_DEACTIVATED';
        }

        try {

            $pdo->beginTransaction();

            $update = $pdo->prepare("
                UPDATE categories
                SET
                    name = ?,
                    status = ?
                WHERE id = ?
            ");

            $update->execute([
                $name,
                $status,
                $id
            ]);

            $user = current_user();

            $audit = $pdo->prepare("
                INSERT INTO audit_logs
                (
                    user_id,
                    branch_id,
                    action,
                    module,
                    record_id,
                    old_values,
                    new_values,
                    ip_address,
                    user_agent
                )
                VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $audit->execute([
                $user['id'],
                $user['branch_id'] ?: null,
                $action,
                'categories',
                $id,
                json_encode($old_values),
                json_encode($new_values),
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);

            $pdo->commit();

            header('Location: ' . APP_URL . '/categories/');
            exit;

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $errors[] = 'Unable to update category. Please try again.';
        }
    }
}

include __DIR__ . '/../includes/header.php';
?>

<style>

.category-form-wrapper {
    max-width:760px;
    margin:0 auto;
}

.category-form-card {
    background:#fff;
    border:1px solid #eee;
    border-radius:18px;
    box-shadow:0 10px 30px rgba(0,0,0,.06);
    overflow:hidden;
}

.category-form-header {
    padding:24px 28px;
    border-bottom:1px solid #eee;
    background:#fffdf8;
}

.category-form-header h3 {
    margin:0 0 6px;
    color:#64131f;
    font-size:20px;
}

.category-form-header p {
    margin:0;
    color:#777;
    font-size:14px;
}

.category-form-body {
    padding:28px;
}

.category-field {
    margin-bottom:22px;
}

.category-field label {
    display:block;
    margin-bottom:8px;
    font-size:13px;
    font-weight:700;
    color:#4d0e17;
}

.category-field label span {
    color:#b42318;
}

.category-field input,
.category-field select {
    width:100%;
    padding:13px 14px;
    border:1px solid #ddd;
    border-radius:10px;
    background:#fff;
    color:#333;
    font-size:14px;
    outline:none;
    transition:.2s;
    box-sizing:border-box;
}

.category-field input:focus,
.category-field select:focus {
    border-color:#64131f;
    box-shadow:0 0 0 3px rgba(100,19,31,.08);
}

.category-help {
    margin-top:7px;
    font-size:12px;
    color:#888;
}

.category-status-options {
    display:flex;
    gap:12px;
}

.category-status-option {
    flex:1;
    position:relative;
}

.category-status-option input {
    position:absolute;
    opacity:0;
    pointer-events:none;
}

.category-status-option label {
    display:flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    padding:13px 15px;
    border:1px solid #ddd;
    border-radius:10px;
    cursor:pointer;
    color:#555;
    background:#fff;
    transition:.2s;
    margin:0;
}

.category-status-option input:checked + label {
    border-color:#64131f;
    background:#fff4c7;
    color:#64131f;
}

.category-form-actions {
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    padding-top:8px;
}

.category-cancel {
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    padding:12px 18px;
    border:1px solid #ddd;
    border-radius:10px;
    background:#fff;
    color:#555;
    text-decoration:none;
    font-size:14px;
    font-weight:600;
}

.category-cancel:hover {
    background:#f7f7f7;
}

.category-submit {
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    padding:12px 20px;
    border:0;
    border-radius:10px;
    background:#64131f;
    color:#fff;
    font-size:14px;
    font-weight:700;
    cursor:pointer;
    transition:.2s;
}

.category-submit:hover {
    background:#4d0e17;
}

.category-errors {
    margin-bottom:22px;
    padding:15px 18px;
    border-radius:12px;
    background:#fdecec;
    border:1px solid #f5caca;
    color:#9b1c1c;
}

.category-errors strong {
    display:block;
    margin-bottom:8px;
}

.category-errors ul {
    margin:0;
    padding-left:20px;
}

.category-errors li {
    margin-bottom:4px;
    font-size:13px;
}

.category-errors li:last-child {
    margin-bottom:0;
}

.category-current-info {
    margin-bottom:24px;
    padding:16px 18px;
    border-radius:12px;
    background:#f8f5f2;
    border:1px solid #eee;
}

.category-current-info strong {
    display:block;
    color:#64131f;
    margin-bottom:5px;
}

.category-current-info span {
    font-size:13px;
    color:#777;
}

@media(max-width:600px) {

    .category-form-body {
        padding:20px;
    }

    .category-form-header {
        padding:20px;
    }

    .category-status-options {
        flex-direction:column;
    }

    .category-form-actions {
        flex-direction:column-reverse;
        align-items:stretch;
    }

    .category-cancel,
    .category-submit {
        width:100%;
        box-sizing:border-box;
    }

}

</style>

<div class="page-header">

    <div>
        <p class="page-kicker">Haven Mart</p>
        <h2>Edit Category</h2>
        <p>Update category information and status.</p>
    </div>

</div>

<div class="category-form-wrapper">

    <div class="category-form-card">

        <div class="category-form-header">

            <h3>
                <i class="fas fa-pen-to-square"></i>
                Category Details
            </h3>

            <p>
                Make the required changes and save the category.
            </p>

        </div>

        <div class="category-form-body">

            <?php if ($errors): ?>

                <div class="category-errors">

                    <strong>
                        <i class="fas fa-circle-exclamation"></i>
                        Please correct the following:
                    </strong>

                    <ul>

                        <?php foreach ($errors as $error): ?>

                            <li><?= e($error) ?></li>

                        <?php endforeach; ?>

                    </ul>

                </div>

            <?php endif; ?>

            <div class="category-current-info">

                <strong>
                    <i class="fas fa-hashtag"></i>
                    Category ID: <?= (int) $category['id'] ?>
                </strong>

                <span>
                    Existing category: <?= e($category['name']) ?>
                </span>

            </div>

            <form method="POST">

                <?= csrf_field() ?>

                <div class="category-field">

                    <label for="name">
                        Category Name <span>*</span>
                    </label>

                    <input
                        type="text"
                        id="name"
                        name="name"
                        value="<?= e($name) ?>"
                        maxlength="100"
                        required
                        autofocus
                    >

                    <div class="category-help">
                        Category names should be clear and easy to identify when managing products.
                    </div>

                </div>

                <div class="category-field">

                    <label>
                        Status
                    </label>

                    <div class="category-status-options">

                        <div class="category-status-option">

                            <input
                                type="radio"
                                id="status_active"
                                name="status"
                                value="ACTIVE"
                                <?= $status === 'ACTIVE' ? 'checked' : '' ?>
                            >

                            <label for="status_active">
                                <i class="fas fa-circle-check"></i>
                                Active
                            </label>

                        </div>

                        <div class="category-status-option">

                            <input
                                type="radio"
                                id="status_inactive"
                                name="status"
                                value="INACTIVE"
                                <?= $status === 'INACTIVE' ? 'checked' : '' ?>
                            >

                            <label for="status_inactive">
                                <i class="fas fa-circle-xmark"></i>
                                Inactive
                            </label>

                        </div>

                    </div>

                </div>

                <div class="category-form-actions">

                    <a
                        href="<?= APP_URL ?>/categories/"
                        class="category-cancel"
                    >
                        <i class="fas fa-arrow-left"></i>
                        Cancel
                    </a>

                    <button
                        type="submit"
                        class="category-submit"
                    >
                        <i class="fas fa-floppy-disk"></i>
                        Save Changes
                    </button>

                </div>

            </form>

        </div>

    </div>

</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>