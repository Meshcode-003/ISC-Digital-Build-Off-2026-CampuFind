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
    <title>Campus Notices | <?= $escape(APP_NAME) ?></title>
    <link rel="stylesheet" href="../public/modules.css">
</head>
<body>
    <header class="module-header">
        <a class="brand" href="../">CampusFind</a>
        <div class="account">
            <span><?= $escape($name !== '' ? $name : ($_SESSION['username'] ?? 'CampusFind user')) ?></span>
            <form method="post" action="../public/logout.php">
                <input type="hidden" name="csrf_token" value="<?= $escape(generateCsrfToken()) ?>">
                <button class="text-button" type="submit">Sign out</button>
            </form>
        </div>
    </header>

    <main class="module-main">
        <a class="back-link" href="../">← All CampusFind services</a>
        <section class="module-card" aria-labelledby="module-title">
            <span class="module-icon notice-icon" aria-hidden="true">
                <svg viewBox="0 0 32 32" fill="none">
                    <path d="M7 22.5V10a2 2 0 0 1 2-2h10l6 6v8.5a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
                    <path d="M19 8v6h6M11 18h10m-10 4h7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </span>
            <p class="eyebrow">CAMPUSFIND MODULE</p>
            <h1 id="module-title">Campus Notices</h1>
            <p class="intro">A shared place for clear, organized campus announcements.</p>
            <div class="status-panel">
                <span class="status-dot"></span>
                <div>
                    <strong>Module foundation</strong>
                    <p>This service is set up as a separate module and uses your CampusFind account. Notice browsing and publishing will be added here later.</p>
                </div>
            </div>
            <a class="home-link" href="../">Back to CampusFind services</a>
        </section>
    </main>
</body>
</html>
