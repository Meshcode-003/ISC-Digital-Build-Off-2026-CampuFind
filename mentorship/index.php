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
    <title>Mentorship | <?= $escape(APP_NAME) ?></title>
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
            <span class="module-icon mentor-icon" aria-hidden="true">
                <svg viewBox="0 0 32 32" fill="none">
                    <circle cx="12" cy="11" r="4" stroke="currentColor" stroke-width="1.8"/>
                    <path d="M4.5 25c.5-4.3 2.9-6.5 7.5-6.5s7 2.2 7.5 6.5M21 10.5a3.5 3.5 0 0 1 0 6.8M22 19c3.4.5 5.1 2.5 5.5 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                    <path d="m22 5 1.2 2.5L26 8l-2 1.8.5 2.7-2.5-1.3-2.5 1.3.5-2.7-2-1.8 2.8-.5L22 5Z" fill="currentColor"/>
                </svg>
            </span>
            <p class="eyebrow">CAMPUSFIND MODULE</p>
            <h1 id="module-title">Find a Mentor</h1>
            <p class="intro">Discover campus mentors and build meaningful connections.</p>
            <div class="status-panel">
                <span class="status-dot"></span>
                <div>
                    <strong>Module foundation</strong>
                    <p>This service is set up as a separate module and uses your CampusFind account. Mentor profiles and connection requests will be added here later.</p>
                </div>
            </div>
            <a class="home-link" href="../">Back to CampusFind services</a>
        </section>
    </main>
</body>
</html>
