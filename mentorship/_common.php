<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';

requireRole(['student', 'staff', 'admin', 'super_admin']);

$mentorshipUserId = (int) $_SESSION['user_id'];
$mentorshipUserStatement = $pdo->prepare(
    'SELECT u.id, u.first_name, u.last_name, u.username, u.email, u.phone,
            u.account_status, r.role_name
     FROM users u
     INNER JOIN roles r ON r.id = u.role_id
     WHERE u.id = :user_id
       AND u.account_status = \'active\'
     LIMIT 1'
);
$mentorshipUserStatement->execute(['user_id' => $mentorshipUserId]);
$mentorshipUser = $mentorshipUserStatement->fetch();

if (!$mentorshipUser) {
    http_response_code(403);
    exit('Your account is not active.');
}

$mentorshipModulePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? APP_BASE_PATH . '/mentorship/index.php')), '/');
if (basename($mentorshipModulePath) === 'admin') {
    $mentorshipModulePath = dirname($mentorshipModulePath);
}

function mentorshipEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function mentorshipInputString(array $input, string $key): string
{
    $value = $input[$key] ?? '';

    return is_string($value) ? $value : '';
}

function mentorshipRedirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function mentorshipSetFlash(string $message, string $kind = 'success'): void
{
    $_SESSION['mentorship_flash'] = ['message' => $message, 'kind' => $kind];
}

function mentorshipRenderFlash(): void
{
    $flash = $_SESSION['mentorship_flash'] ?? null;
    unset($_SESSION['mentorship_flash']);

    if (!is_array($flash) || !isset($flash['message'], $flash['kind'])) {
        return;
    }

    $kind = $flash['kind'] === 'error' ? 'error' : 'success';
    echo '<div class="flash flash-' . $kind . '" role="status">'
        . mentorshipEscape((string) $flash['message'])
        . '</div>';
}

function mentorshipNotify(int $userId, string $title, string $message): void
{
    global $pdo;

    $statement = $pdo->prepare(
        'INSERT INTO notification (user_id, title, message, notification_type)
         VALUES (:user_id, :title, :message, \'system\')'
    );
    $statement->execute([
        'user_id' => $userId,
        'title' => $title,
        'message' => $message,
    ]);
}

function mentorshipNormalizePhone(?string $phone): ?string
{
    if ($phone === null) {
        return null;
    }

    $phone = trim($phone);
    if (str_starts_with($phone, '+')) {
        $phone = substr($phone, 1);
    } elseif (str_starts_with($phone, '00')) {
        $phone = substr($phone, 2);
    }
    $digits = preg_replace('/\D+/', '', $phone);

    if (!is_string($digits)) {
        return null;
    }
    if (strlen($digits) === 10 && str_starts_with($digits, '0')) {
        $digits = '254' . substr($digits, 1);
    }

    return preg_match('/^[1-9][0-9]{7,14}$/', $digits) === 1 ? $digits : null;
}

function mentorshipPageStart(string $title): void
{
    global $mentorshipUser;
    global $mentorshipModulePath;
    $displayName = trim($mentorshipUser['first_name'] . ' ' . $mentorshipUser['last_name']);
    $displayName = $displayName !== '' ? $displayName : $mentorshipUser['username'];
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= mentorshipEscape($title) ?> | <?= mentorshipEscape(APP_NAME) ?></title>
        <link rel="stylesheet" href="<?= mentorshipEscape(BASE_URL . '/modules.css') ?>">
        <link rel="stylesheet" href="<?= mentorshipEscape($mentorshipModulePath . '/mentorship.css') ?>">
    </head>
    <body>
    <header class="module-header">
        <a class="brand" href="<?= mentorshipEscape($mentorshipModulePath . '/../') ?>">CampusFind</a>
        <div class="account">
            <span><?= mentorshipEscape($displayName) ?></span>
            <form method="post" action="<?= mentorshipEscape(BASE_URL . '/logout.php') ?>">
                <input type="hidden" name="csrf_token" value="<?= mentorshipEscape(generateCsrfToken()) ?>">
                <button class="text-button" type="submit">Sign out</button>
            </form>
        </div>
    </header>
    <nav class="module-nav" aria-label="Mentorship">
        <a href="<?= mentorshipEscape($mentorshipModulePath . '/index.php') ?>">Discover mentors</a>
        <a href="<?= mentorshipEscape($mentorshipModulePath . '/requests.php') ?>">Requests</a>
        <a href="<?= mentorshipEscape($mentorshipModulePath . '/profile.php') ?>">My mentor profile</a>
        <a href="<?= mentorshipEscape($mentorshipModulePath . '/apply.php') ?>">Become a mentor</a>
        <?php if (in_array($mentorshipUser['role_name'], ['admin', 'super_admin'], true)): ?>
            <a href="<?= mentorshipEscape($mentorshipModulePath . '/admin/applications.php') ?>">Review applications</a>
        <?php endif; ?>
        <a href="<?= mentorshipEscape($mentorshipModulePath . '/../') ?>">All services</a>
    </nav>
    <main class="mentorship-main">
        <?php mentorshipRenderFlash(); ?>
    <?php
}

function mentorshipPageEnd(): void
{
    ?>
    </main>
    </body>
    </html>
    <?php
}
