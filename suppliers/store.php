<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_permission('suppliers.create');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . APP_URL . '/suppliers/');
    exit;
}

verify_csrf($_POST['csrf_token'] ?? '');

$name = trim($_POST['name'] ?? '');
$contact_person = trim($_POST['contact_person'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$email = trim($_POST['email'] ?? '');
$address = trim($_POST['address'] ?? '');
$tax_number = trim($_POST['tax_number'] ?? '');
$status = strtoupper(trim($_POST['status'] ?? 'ACTIVE'));

$errors = [];

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
    $_SESSION['old_supplier'] = $_POST;

    header('Location: ' . APP_URL . '/suppliers/create.php');
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT id
        FROM suppliers
        WHERE LOWER(name) = LOWER(:name)
        LIMIT 1
    ");

    $stmt->execute([
        ':name' => $name
    ]);

    if ($stmt->fetch()) {
        $_SESSION['error'] = 'A supplier with this name already exists.';
        $_SESSION['old_supplier'] = $_POST;

        header('Location: ' . APP_URL . '/suppliers/create.php');
        exit;
    }

    $stmt = $pdo->prepare("
        INSERT INTO suppliers (
            name,
            contact_person,
            phone,
            email,
            address,
            tax_number,
            status
        ) VALUES (
            :name,
            :contact_person,
            :phone,
            :email,
            :address,
            :tax_number,
            :status
        )
    ");

    $stmt->execute([
        ':name' => $name,
        ':contact_person' => $contact_person !== '' ? $contact_person : null,
        ':phone' => $phone !== '' ? $phone : null,
        ':email' => $email !== '' ? $email : null,
        ':address' => $address !== '' ? $address : null,
        ':tax_number' => $tax_number !== '' ? $tax_number : null,
        ':status' => $status
    ]);

    $supplierId = (int) $pdo->lastInsertId();

    $_SESSION['success'] = 'Supplier "' . $name . '" was added successfully.';

    header('Location: ' . APP_URL . '/suppliers/view.php?id=' . $supplierId);
    exit;
} catch (PDOException $e) {
    $_SESSION['error'] = 'Unable to save the supplier. Please try again.';
    $_SESSION['old_supplier'] = $_POST;

    header('Location: ' . APP_URL . '/suppliers/create.php');
    exit;
}