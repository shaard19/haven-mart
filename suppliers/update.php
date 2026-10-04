<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_permission('suppliers.edit');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . APP_URL . '/suppliers/');
    exit;
}

verify_csrf($_POST['csrf_token'] ?? '');

$supplierId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

$name = trim($_POST['name'] ?? '');
$contact_person = trim($_POST['contact_person'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$email = trim($_POST['email'] ?? '');
$address = trim($_POST['address'] ?? '');
$tax_number = trim($_POST['tax_number'] ?? '');
$status = strtoupper(trim($_POST['status'] ?? 'ACTIVE'));

$errors = [];

if (!$supplierId) {
    $errors[] = 'Invalid supplier ID.';
}

if ($name === '') {
    $errors[] = 'Supplier name is required.';
}

if (mb_strlen($name) > 150) {
    $errors[] = 'Supplier name cannot exceed 150 characters.';
}

if ($contact_person !== '' && mb_strlen($contact_person) > 150) {
    $errors[] = 'Contact person cannot exceed 150 characters.';
}

if ($phone !== '' && mb_strlen($phone) > 50) {
    $errors[] = 'Phone number cannot exceed 50 characters.';
}

if ($email !== '') {
    if (mb_strlen($email) > 150) {
        $errors[] = 'Email address cannot exceed 150 characters.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
}

if ($address !== '' && mb_strlen($address) > 255) {
    $errors[] = 'Address cannot exceed 255 characters.';
}

if ($tax_number !== '' && mb_strlen($tax_number) > 100) {
    $errors[] = 'Tax number cannot exceed 100 characters.';
}

if (!in_array($status, ['ACTIVE', 'INACTIVE'], true)) {
    $errors[] = 'Invalid supplier status.';
}

if (!empty($errors)) {
    $_SESSION['error'] = implode(' ', $errors);

    header('Location: ' . APP_URL . '/suppliers/edit.php?id=' . (int) $supplierId);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT id
        FROM suppliers
        WHERE LOWER(name) = LOWER(:name)
        AND id != :id
        LIMIT 1
    ");

    $stmt->execute([
        ':name' => $name,
        ':id' => $supplierId
    ]);

    if ($stmt->fetch()) {
        $_SESSION['error'] = 'Another supplier with this name already exists.';

        header('Location: ' . APP_URL . '/suppliers/edit.php?id=' . (int) $supplierId);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT id
        FROM suppliers
        WHERE id = :id
        LIMIT 1
    ");

    $stmt->execute([
        ':id' => $supplierId
    ]);

    if (!$stmt->fetch()) {
        $_SESSION['error'] = 'Supplier not found.';

        header('Location: ' . APP_URL . '/suppliers/');
        exit;
    }

    $stmt = $pdo->prepare("
        UPDATE suppliers
        SET
            name = :name,
            contact_person = :contact_person,
            phone = :phone,
            email = :email,
            address = :address,
            tax_number = :tax_number,
            status = :status
        WHERE id = :id
    ");

    $stmt->execute([
        ':name' => $name,
        ':contact_person' => $contact_person !== '' ? $contact_person : null,
        ':phone' => $phone !== '' ? $phone : null,
        ':email' => $email !== '' ? $email : null,
        ':address' => $address !== '' ? $address : null,
        ':tax_number' => $tax_number !== '' ? $tax_number : null,
        ':status' => $status,
        ':id' => $supplierId
    ]);

    $_SESSION['success'] = 'Supplier "' . $name . '" was updated successfully.';

    header('Location: ' . APP_URL . '/suppliers/view.php?id=' . $supplierId);
    exit;
} catch (PDOException $e) {
    $_SESSION['error'] = 'Unable to update the supplier. Please try again.';

    header('Location: ' . APP_URL . '/suppliers/edit.php?id=' . (int) $supplierId);
    exit;
}