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
$isAdmin = in_array($_SESSION['role_name'] ?? '', ['admin', 'super_admin'], true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Lost &amp; Found | <?= $escape(APP_NAME) ?></title>
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
            <span class="module-icon notice-icon" aria-hidden="true">⌕</span>
            <p class="eyebrow">CAMPUSFIND MODULE</p>
            <h1 id="module-title">Lost &amp; Found</h1>
            <p class="intro">Help campus belongings find their way back to their people.</p>
            <div class="status-panel">
                <span class="status-dot"></span>
                <div>
                    <strong>Lost &amp; Found service</strong>
                    <p>The Lost &amp; Found administration pages are organized in this module and share the CampusFind database and account session.</p>
                </div>
            </div>
            <?php if ($isAdmin): ?>
                <a class="home-link" href="admin/dashboard.php">Open Lost &amp; Found administration</a>
            <?php else: ?>
                <a class="home-link" href="../public/dashboard.php">Go to my CampusFind account</a>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
