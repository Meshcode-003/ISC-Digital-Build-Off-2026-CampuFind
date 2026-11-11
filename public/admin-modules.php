<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/notifications.php';

requireRole(['admin']);

$moduleOptions = [
    'lost_items' => [
        'label' => 'Lost Items Administration',
        'description' => 'Manage Lost & Found items, reports, and claims.',
        'url' => APP_BASE_PATH . '/lostandfound/admin/dashboard.php',
    ],
    'notices' => [
        'label' => 'Campus Notices',
        'description' => 'Open the Campus Notices module.',
        'url' => APP_BASE_PATH . '/notices/',
    ],
    'mentorship' => [
        'label' => 'Mentorship Administration',
        'description' => 'Review mentor applications and manage mentorship.',
        'url' => APP_BASE_PATH . '/mentorship/admin/applications.php',
    ],
];

$statement = $pdo->prepare(
    'SELECT module_key
     FROM module_admin_assignments
     WHERE user_id = :user_id
     ORDER BY module_key'
);
$statement->execute(['user_id' => (int) $_SESSION['user_id']]);
$modules = $statement->fetchAll(PDO::FETCH_COLUMN);

if (count($modules) === 1 && isset($moduleOptions[$modules[0]])) {
    header('Location: ' . $moduleOptions[$modules[0]]['url']);
    exit;
}

$escape = static fn (string $value): string => htmlspecialchars(
    $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$displayName = trim(
    (string) ($_SESSION['first_name'] ?? '') . ' '
    . (string) ($_SESSION['last_name'] ?? '')
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Choose an admin module | <?= $escape(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/auth.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/notifications.css">
    <style>
        .admin-notification-header { display: flex; justify-content: flex-end; width: min(460px, calc(100% - 32px)); margin: 20px auto 0; }
        .module-options { display: grid; gap: 12px; margin: 24px 0; }
        .module-option {
            display: block; padding: 16px; border: 1px solid #dfe7df;
            border-radius: 10px; color: inherit; text-decoration: none;
        }
        .module-option:hover { border-color: #2e694d; background: #f7faf7; }
        .module-option strong { display: block; margin-bottom: 5px; }
        .module-option span { color: #6f7d73; font-size: .9rem; }
        .notice-error { padding: 14px; border-radius: 8px; color: #7d2929; background: #fbecec; }
        .signout { margin-top: 20px; }
        .signout button { width: auto; }
    </style>
    <script src="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/campusfind-theme.js"></script>
    <link rel="stylesheet" href="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/campusfind-theme.css">
</head>
<body>
    <header class="admin-notification-header">
        <?php renderNotificationBell($pdo, (int) $_SESSION['user_id']); ?>
    </header>
    <main class="auth-card">
        <p class="eyebrow">CAMPUSFIND ADMINISTRATION</p>
        <?php if ($modules): ?>
            <h1>Choose a module</h1>
            <p class="intro">Welcome, <?= $escape($displayName !== '' ? $displayName : 'Administrator') ?>. Choose an assigned module to continue.</p>
            <nav class="module-options" aria-label="Assigned admin modules">
                <?php foreach ($modules as $module): ?>
                    <?php if (isset($moduleOptions[$module])): ?>
                        <a class="module-option" href="<?= $escape($moduleOptions[$module]['url']) ?>">
                            <strong><?= $escape($moduleOptions[$module]['label']) ?></strong>
                            <span><?= $escape($moduleOptions[$module]['description']) ?></span>
                        </a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </nav>
        <?php else: ?>
            <h1>No admin modules assigned</h1>
            <p class="notice-error" role="alert">Your administrator account has no module assignments. Contact a Super Admin to request access.</p>
        <?php endif; ?>
        <form class="signout" method="post" action="<?= BASE_URL ?>/logout.php">
            <input type="hidden" name="csrf_token" value="<?= $escape(generateCsrfToken()) ?>">
            <button type="submit">Sign out</button>
        </form>
    </main>
</body>
</html>
