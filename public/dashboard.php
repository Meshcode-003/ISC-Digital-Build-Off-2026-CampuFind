<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';

requireLogin();

$escape = static fn (string $value): string => htmlspecialchars(
    $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My account | <?= $escape(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/auth.css">
</head>
<body>
    <main class="auth-card dashboard-card">
        <p class="eyebrow">CAMPUSFIND</p>
        <h1>You're signed in</h1>
        <p class="intro">Welcome, <?= $escape($name !== '' ? $name : ($_SESSION['username'] ?? 'user')) ?>.</p>
        <p class="account-detail">Account type: <?= $escape($_SESSION['role_name'] ?? 'user') ?></p>
        <form method="post" action="<?= BASE_URL ?>/logout.php">
            <input type="hidden" name="csrf_token" value="<?= $escape(generateCsrfToken()) ?>">
            <button type="submit">Sign out</button>
        </form>
    </main>
</body>
</html>
