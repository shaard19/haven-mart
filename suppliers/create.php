<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_permission('suppliers.create');

$current_page = 'suppliers';
$page_title = 'Add Supplier';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<main class="main-content">
    <div class="page-header">
        <div>
            <h1><i class="fas fa-truck-field"></i> Add Supplier</h1>
            <p>Create a new supplier record for Haven Mart.</p>
        </div>

        <a href="<?= APP_URL ?>/suppliers/" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i>
            Back to Suppliers
        </a>
    </div>

    <div class="supplier-form-card">
        <form action="<?= APP_URL ?>/suppliers/store.php" method="POST">
            <?= csrf_field() ?>

            <div class="form-section">
                <div class="section-heading">
                    <div class="section-icon">
                        <i class="fas fa-building"></i>
                    </div>
                    <div>
                        <h2>Supplier Information</h2>
                        <p>Enter the supplier's basic business details.</p>
                    </div>
                </div>

                <div class="form-grid">
                    <div class="form-group full-width">
                        <label for="name">
                            Supplier Name <span class="required">*</span>
                        </label>
                        <input
                            type="text"
                            id="name"
                            name="name"
                            maxlength="150"
                            required
                            autocomplete="organization"
                            placeholder="Enter supplier or company name"
                        >
                    </div>

                    <div class="form-group">
                        <label for="contact_person">Contact Person</label>
                        <input
                            type="text"
                            id="contact_person"
                            name="contact_person"
                            maxlength="150"
                            autocomplete="name"
                            placeholder="Enter contact person's name"
                        >
                    </div>

                    <div class="form-group">
                        <label for="phone">Phone Number</label>
                        <input
                            type="text"
                            id="phone"
                            name="phone"
                            maxlength="50"
                            autocomplete="tel"
                            placeholder="e.g. 0712345678"
                        >
                    </div>

                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input
                            type="email"
                            id="email"
                            name="email"
                            maxlength="150"
                            autocomplete="email"
                            placeholder="supplier@example.com"
                        >
                    </div>

                    <div class="form-group">
                        <label for="tax_number">Tax Number</label>
                        <input
                            type="text"
                            id="tax_number"
                            name="tax_number"
                            maxlength="100"
                            placeholder="e.g. PIN / Tax Number"
                        >
                    </div>

                    <div class="form-group full-width">
                        <label for="address">Address</label>
                        <textarea
                            id="address"
                            name="address"
                            maxlength="255"
                            rows="4"
                            placeholder="Enter supplier physical or postal address"
                        ></textarea>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <div class="section-heading">
                    <div class="section-icon">
                        <i class="fas fa-toggle-on"></i>
                    </div>
                    <div>
                        <h2>Supplier Status</h2>
                        <p>Choose whether this supplier should be available for transactions.</p>
                    </div>
                </div>

                <div class="status-options">
                    <label class="status-option">
                        <input type="radio" name="status" value="ACTIVE" checked>
                        <span class="status-card active-status">
                            <span class="status-radio"></span>
                            <span>
                                <strong>Active</strong>
                                <small>Supplier is available for purchases.</small>
                            </span>
                        </span>
                    </label>

                    <label class="status-option">
                        <input type="radio" name="status" value="INACTIVE">
                        <span class="status-card inactive-status">
                            <span class="status-radio"></span>
                            <span>
                                <strong>Inactive</strong>
                                <small>Supplier will not be available for new transactions.</small>
                            </span>
                        </span>
                    </label>
                </div>
            </div>

            <div class="form-actions">
                <a href="<?= APP_URL ?>/suppliers/" class="btn btn-secondary">
                    <i class="fas fa-times"></i>
                    Cancel
                </a>

                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i>
                    Save Supplier
                </button>
            </div>
        </form>
    </div>
</main>

<style>
.supplier-form-card {
    max-width: 1000px;
    margin: 0 auto;
    background: #fff;
    border: 1px solid #e7e1dc;
    border-radius: 16px;
    box-shadow: 0 8px 30px rgba(100, 19, 31, 0.08);
    overflow: hidden;
}

.form-section {
    padding: 28px 30px;
    border-bottom: 1px solid #eee8e3;
}

.section-heading {
    display: flex;
    align-items: center;
    gap: 14px;
    margin-bottom: 26px;
}

.section-icon {
    width: 46px;
    height: 46px;
    border-radius: 12px;
    background: #fff4c7;
    color: #64131f;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
}

.section-heading h2 {
    margin: 0 0 4px;
    color: #4d0e17;
    font-size: 18px;
}

.section-heading p {
    margin: 0;
    color: #777;
    font-size: 13px;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 20px;
}

.form-group {
    display: flex;
    flex-direction: column;
}

.form-group.full-width {
    grid-column: 1 / -1;
}

.form-group label {
    margin-bottom: 8px;
    color: #4d0e17;
    font-size: 14px;
    font-weight: 600;
}

.required {
    color: #b42318;
}

.form-group input,
.form-group textarea {
    width: 100%;
    box-sizing: border-box;
    border: 1px solid #d9d2cc;
    border-radius: 9px;
    padding: 12px 14px;
    background: #fff;
    color: #333;
    font-size: 14px;
    font-family: inherit;
    outline: none;
    transition: border-color .2s, box-shadow .2s;
}

.form-group input {
    height: 46px;
}

.form-group textarea {
    resize: vertical;
    min-height: 105px;
}

.form-group input:focus,
.form-group textarea:focus {
    border-color: #8b1e2d;
    box-shadow: 0 0 0 3px rgba(139, 30, 45, 0.10);
}

.form-group input::placeholder,
.form-group textarea::placeholder {
    color: #aaa;
}

.status-options {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
}

.status-option {
    cursor: pointer;
}

.status-option input {
    display: none;
}

.status-card {
    display: flex;
    align-items: center;
    gap: 14px;
    min-height: 76px;
    padding: 15px 18px;
    border: 1px solid #ddd6d0;
    border-radius: 12px;
    background: #fff;
    transition: all .2s;
}

.status-option input:checked + .status-card {
    border-color: #8b1e2d;
    background: #fff9ed;
    box-shadow: 0 0 0 2px rgba(139, 30, 45, 0.08);
}

.status-radio {
    width: 18px;
    height: 18px;
    border: 2px solid #bbb;
    border-radius: 50%;
    position: relative;
    flex-shrink: 0;
}

.status-option input:checked + .status-card .status-radio {
    border-color: #8b1e2d;
}

.status-option input:checked + .status-card .status-radio::after {
    content: "";
    position: absolute;
    width: 8px;
    height: 8px;
    background: #8b1e2d;
    border-radius: 50%;
    top: 3px;
    left: 3px;
}

.status-card strong {
    display: block;
    color: #4d0e17;
    font-size: 14px;
    margin-bottom: 4px;
}

.status-card small {
    display: block;
    color: #777;
    font-size: 12px;
    line-height: 1.4;
}

.form-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 12px;
    padding: 22px 30px;
    background: #faf8f6;
}

.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    min-height: 42px;
    padding: 10px 18px;
    border-radius: 8px;
    text-decoration: none;
    border: 1px solid transparent;
    font-size: 14px;
    font-weight: 600;
    font-family: inherit;
    cursor: pointer;
    transition: all .2s;
}

.btn-primary {
    background: #64131f;
    color: #fff;
}

.btn-primary:hover {
    background: #4d0e17;
}

.btn-secondary {
    background: #fff;
    color: #64131f;
    border-color: #d8d0ca;
}

.btn-secondary:hover {
    background: #f7f3ef;
    border-color: #bdb4ad;
}

@media (max-width: 700px) {
    .form-section {
        padding: 22px 18px;
    }

    .form-grid,
    .status-options {
        grid-template-columns: 1fr;
    }

    .form-group.full-width {
        grid-column: auto;
    }

    .form-actions {
        padding: 18px;
        flex-direction: column-reverse;
    }

    .form-actions .btn {
        width: 100%;
    }

    .page-header {
        gap: 15px;
        align-items: flex-start;
    }
}
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>