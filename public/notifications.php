<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/notifications.php';

requireRole(['student', 'staff', 'admin', 'super_admin']);
$userId = (int) $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $action = $_POST['action'] ?? '';
    if ($action === 'mark_all') {
        markAllNotificationsAsRead($pdo, $userId);
        header('Location: ' . BASE_URL . '/notifications.php');
        exit;
    }
    if ($action !== 'open') {
        http_response_code(400);
        exit('Unsupported notification action.');
    }

    $notificationId = filter_var($_POST['notification_id'] ?? null, FILTER_VALIDATE_INT);
    if (!is_int($notificationId) || $notificationId < 1) {
        http_response_code(400);
        exit('A valid notification is required.');
    }
    $statement = $pdo->prepare(
        'SELECT notification_url, related_item_id, related_claim_id, related_report_id
         FROM notification
         WHERE id = :notification_id AND user_id = :user_id
         LIMIT 1'
    );
    $statement->execute([
        'notification_id' => $notificationId,
        'user_id' => $userId,
    ]);
    $notification = $statement->fetch();
    if (!$notification) {
        http_response_code(404);
        exit('Notification not found.');
    }
    markNotificationAsRead($pdo, $notificationId, $userId);
    $isAdministrator = in_array($_SESSION['role_name'], ['admin', 'super_admin'], true);
    if (is_string($notification['notification_url'])
        && notificationUrlIsLocal($notification['notification_url'])) {
        $destination = $notification['notification_url'];
    } elseif (!empty($notification['related_claim_id'])) {
        $destination = APP_BASE_PATH . ($isAdministrator
            ? '/lostandfound/admin/claims.php'
            : '/lostandfound/claimant/my-claims.php');
    } elseif (!empty($notification['related_report_id'])) {
        $destination = APP_BASE_PATH . ($isAdministrator
            ? '/lostandfound/admin/manage-items.php'
            : '/lostandfound/');
    } elseif (!empty($notification['related_item_id'])) {
        $destination = APP_BASE_PATH . ($isAdministrator
            ? '/lostandfound/admin/manage-items.php'
            : '/lostandfound/lost-items.php');
    } else {
        $destination = BASE_URL . '/notifications.php';
    }
    header('Location: ' . $destination);
    exit;
}

$notifications = getUserNotifications($pdo, $userId, 100);
$escape = static fn (string $value): string => htmlspecialchars(
    $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Notifications | <?= $escape(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= $escape(BASE_URL) ?>/modules.css">
    <script src="<?= $escape(BASE_URL) ?>/campusfind-theme.js"></script>
    <link rel="stylesheet" href="<?= $escape(BASE_URL) ?>/campusfind-theme.css">
</head>
<body>
    <header class="module-header">
        <a class="brand" href="<?= $escape(BASE_URL) ?>/dashboard.php">CampusFind</a>
        <div class="account">
            <?php renderNotificationBell($pdo, $userId); ?>
            <span><?= $escape(trim((string) ($_SESSION['first_name'] ?? '') . ' ' . (string) ($_SESSION['last_name'] ?? ''))) ?></span>
        </div>
    </header>
    <main class="mentorship-main">
        <p><a class="mentor-link" href="<?= $escape(BASE_URL) ?>/dashboard.php">← All services</a></p>
        <h1>Notifications</h1>
        <section class="cf-notification-page">
            <?php if (!$notifications): ?>
                <p class="cf-notification-empty">You have no notifications yet.</p>
            <?php else: ?>
                <ul>
                    <?php foreach ($notifications as $notification): ?>
                        <li class="<?= (int) $notification['is_read'] === 0 ? 'is-unread' : '' ?>">
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?= $escape(generateCsrfToken()) ?>">
                                <input type="hidden" name="action" value="open">
                                <input type="hidden" name="notification_id" value="<?= (int) $notification['id'] ?>">
                                <button type="submit">
                                    <span class="cf-notification-icon" aria-hidden="true"><?= notificationIcon((string) $notification['notification_type']) ?></span>
                                    <span class="cf-notification-copy">
                                        <strong><?= $escape((string) $notification['title']) ?></strong>
                                        <span><?= $escape((string) $notification['message']) ?></span>
                                        <time><?= $escape((string) $notification['created_at']) ?></time>
                                    </span>
                                </button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
