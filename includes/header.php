<?php

require_once __DIR__ . '/auth.php';

require_login();

$user = current_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($page_title ?? APP_NAME) ?> - <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/app.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
</head>
<body>
<div class="app">
    <?php require __DIR__ . '/sidebar.php'; ?>

    <div class="main-area">
        <header class="topbar">
            <div class="topbar-left">
                <button class="menu-toggle" id="menuToggle">
                    <i class="fas fa-bars"></i>
                </button>

                <div>
                    <h1><?= e($page_title ?? 'Dashboard') ?></h1>
                </div>
            </div>

            <div class="user-area">
                <div class="user-info">
                    <strong><?= e($user['full_name']) ?></strong>
                    <span><?= e($user['role_name']) ?></span>
                </div>

                <a href="<?= APP_URL ?>/auth/logout.php" class="logout-button">
                    <i class="fas fa-right-from-bracket"></i>
                </a>
            </div>
        </header>

        <main class="content">