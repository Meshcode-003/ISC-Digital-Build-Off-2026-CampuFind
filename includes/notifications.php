<?php

declare(strict_types=1);

function createNotification(
    PDO $pdo,
    int $userId,
    string $title,
    string $message,
    string $notificationType = 'system',
    ?string $notificationUrl = null,
    ?string $dedupeKey = null,
    ?int $relatedItemId = null,
    ?int $relatedClaimId = null,
    ?int $relatedReportId = null,
    bool $refreshExisting = false
): void {
    $validTypes = [
        'system',
        'approval',
        'rejection',
        'claim',
        'recovery',
        'notice',
        'mentorship',
        'message',
        'admin',
        'registration',
    ];
    if ($userId < 1 || !in_array($notificationType, $validTypes, true)) {
        throw new InvalidArgumentException('A valid notification recipient and type are required.');
    }
    if (trim($title) === '') {
        throw new InvalidArgumentException('Notification titles cannot be empty.');
    }
    if ($dedupeKey !== null && ($dedupeKey === '' || strlen($dedupeKey) > 191)) {
        throw new InvalidArgumentException('Notification deduplication keys must be 1 to 191 bytes.');
    }
    if ($notificationUrl !== null && !notificationUrlIsLocal($notificationUrl)) {
        throw new InvalidArgumentException('Notification destinations must remain within CampusFind.');
    }

    $statement = $pdo->prepare(
        'INSERT INTO notification (
            user_id, title, message, notification_type, notification_url,
            dedupe_key, related_item_id, related_claim_id, related_report_id
         ) VALUES (
            :user_id, :title, :message, :notification_type, :notification_url,
            :dedupe_key, :related_item_id, :related_claim_id, :related_report_id
         )
         ON DUPLICATE KEY UPDATE
            title = VALUES(title),
            message = VALUES(message),
            notification_type = VALUES(notification_type),
            notification_url = VALUES(notification_url),
            related_item_id = VALUES(related_item_id),
            related_claim_id = VALUES(related_claim_id),
            related_report_id = VALUES(related_report_id),
            is_read = IF(VALUES(dedupe_key) IS NULL OR :refresh_read = 0, is_read, 0),
            read_at = IF(VALUES(dedupe_key) IS NULL OR :refresh_read_at = 0, read_at, NULL),
            created_at = IF(VALUES(dedupe_key) IS NULL OR :refresh_created = 0, created_at, CURRENT_TIMESTAMP)'
    );
    $statement->execute([
        'user_id' => $userId,
        'title' => mb_substr($title, 0, 150),
        'message' => $message,
        'notification_type' => $notificationType,
        'notification_url' => $notificationUrl,
        'dedupe_key' => $dedupeKey,
        'related_item_id' => $relatedItemId,
        'related_claim_id' => $relatedClaimId,
        'related_report_id' => $relatedReportId,
        'refresh_read' => $refreshExisting ? 1 : 0,
        'refresh_read_at' => $refreshExisting ? 1 : 0,
        'refresh_created' => $refreshExisting ? 1 : 0,
    ]);
}

function notificationUrlIsLocal(string $url): bool
{
    $basePath = defined('APP_BASE_PATH') ? APP_BASE_PATH : '/campusfind';

    return str_starts_with($url, $basePath . '/')
        && !str_starts_with($url, '//')
        && filter_var($url, FILTER_VALIDATE_URL) === false
        && !str_contains($url, '\\')
        && !str_contains($url, "\r")
        && !str_contains($url, "\n");
}

function notifyModuleAdministrators(
    PDO $pdo,
    string $moduleKey,
    string $title,
    string $message,
    string $notificationUrl,
    string $dedupeKey,
    bool $includeSuperAdmins = false,
    ?int $excludeUserId = null
): void {
    if (!in_array($moduleKey, ['lost_items', 'notices', 'mentorship'], true)) {
        throw new InvalidArgumentException('Unknown administrator module.');
    }

    $superAdminClause = $includeSuperAdmins ? "OR r.role_name = 'super_admin'" : '';
    $statement = $pdo->prepare(
        "SELECT DISTINCT u.id
         FROM users u
         INNER JOIN roles r ON r.id = u.role_id
         LEFT JOIN module_admin_assignments maa
           ON maa.user_id = u.id AND maa.module_key = :module_key
         WHERE u.account_status = 'active'
           AND (
                (r.role_name = 'admin' AND maa.id IS NOT NULL)
                {$superAdminClause}
           )"
    );
    $statement->execute(['module_key' => $moduleKey]);
    foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $userId) {
        if ($excludeUserId !== null && (int) $userId === $excludeUserId) {
            continue;
        }
        createNotification(
            $pdo,
            (int) $userId,
            $title,
            $message,
            'admin',
            $notificationUrl,
            $dedupeKey
        );
    }
}

function notifySuperAdministrators(
    PDO $pdo,
    string $title,
    string $message,
    string $notificationUrl,
    string $dedupeKey,
    ?int $excludeUserId = null,
    string $notificationType = 'admin'
): void {
    $sql = "SELECT u.id
            FROM users u
            INNER JOIN roles r ON r.id = u.role_id
            WHERE r.role_name = 'super_admin'
              AND u.account_status = 'active'";
    $params = [];
    if ($excludeUserId !== null) {
        $sql .= ' AND u.id <> :exclude_user_id';
        $params['exclude_user_id'] = $excludeUserId;
    }
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $userId) {
        createNotification(
            $pdo,
            (int) $userId,
            $title,
            $message,
            $notificationType,
            $notificationUrl,
            $dedupeKey
        );
    }
}

function getUserNotifications(PDO $pdo, int $userId, int $limit = 20): array
{
    if ($userId < 1) {
        throw new InvalidArgumentException('A valid notification user ID is required.');
    }
    $limit = max(1, min($limit, 100));
    $statement = $pdo->prepare(
        "SELECT id, title, message, notification_type, notification_url,
                is_read, read_at, created_at
         FROM notification
         WHERE user_id = :user_id
         ORDER BY created_at DESC, id DESC
         LIMIT {$limit}"
    );
    $statement->execute(['user_id' => $userId]);

    return $statement->fetchAll();
}

function getUnreadNotificationCount(PDO $pdo, int $userId): int
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM notification WHERE user_id = :user_id AND is_read = 0'
    );
    $statement->execute(['user_id' => $userId]);

    return (int) $statement->fetchColumn();
}

function markNotificationAsRead(PDO $pdo, int $notificationId, int $userId): bool
{
    $statement = $pdo->prepare(
        'UPDATE notification
         SET is_read = 1, read_at = CURRENT_TIMESTAMP
         WHERE id = :notification_id AND user_id = :user_id AND is_read = 0'
    );
    $statement->execute([
        'notification_id' => $notificationId,
        'user_id' => $userId,
    ]);

    return $statement->rowCount() > 0;
}

function markAllNotificationsAsRead(PDO $pdo, int $userId): void
{
    $statement = $pdo->prepare(
        'UPDATE notification
         SET is_read = 1, read_at = CURRENT_TIMESTAMP
         WHERE user_id = :user_id AND is_read = 0'
    );
    $statement->execute(['user_id' => $userId]);
}

function markNotificationByDedupeKeyAsRead(PDO $pdo, int $userId, string $dedupeKey): void
{
    $statement = $pdo->prepare(
        'UPDATE notification
         SET is_read = 1, read_at = CURRENT_TIMESTAMP
         WHERE user_id = :user_id AND dedupe_key = :dedupe_key AND is_read = 0'
    );
    $statement->execute([
        'user_id' => $userId,
        'dedupe_key' => $dedupeKey,
    ]);
}

function notifyNoticeAudience(int $noticeId): void
{
    global $pdo;

    if (!$pdo->inTransaction()) {
        throw new LogicException('Notice notifications must run within the notice transaction.');
    }

    $noticeStatement = $pdo->prepare(
        "SELECT id, title, created_by,
                COALESCE(NULLIF(summary, ''), body) AS description
         FROM notices
         WHERE id = :notice_id
           AND status = 'published'
           AND published_at IS NOT NULL
           AND published_at <= CURRENT_TIMESTAMP
           AND (expires_at IS NULL OR expires_at >= CURRENT_TIMESTAMP)
         FOR UPDATE"
    );
    $noticeStatement->execute(['notice_id' => $noticeId]);
    $notice = $noticeStatement->fetch();
    if (!$notice) {
        return;
    }

    $statement = $pdo->prepare(
        "SELECT u.id AS user_id
         FROM notices n
         INNER JOIN users u ON u.account_status = 'active'
         INNER JOIN roles r ON r.id = u.role_id
         WHERE n.id = :notice_id
           AND n.status = 'published'
           AND n.published_at IS NOT NULL
           AND n.published_at <= CURRENT_TIMESTAMP
           AND (n.expires_at IS NULL OR n.expires_at >= CURRENT_TIMESTAMP)
           AND (
                (r.role_name = 'student' AND n.audience IN ('all', 'students'))
                OR (r.role_name = 'staff' AND n.audience IN ('all', 'staff'))
           )
           AND " . noticeTargetingSql('n', 'u')
    );
    $statement->execute(['notice_id' => $noticeId]);
    $description = noticeExcerpt((string) $notice['description'], 180);
    foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $recipientId) {
        createNotification(
            $pdo,
            (int) $recipientId,
            (string) $notice['title'],
            $description,
            'notice',
            NOTICES_URL . '/view.php?id=' . $noticeId,
            'notice:' . $noticeId
        );
    }

    notifySuperAdministrators(
        $pdo,
        'Campus notice published',
        'A notice was published: ' . (string) $notice['title'],
        NOTICES_URL . '/admin/',
        'notice-management:' . $noticeId,
        isset($_SESSION['user_id'])
            ? (int) $_SESSION['user_id']
            : null
    );
}

function getUnreadMentorshipMessageCount(PDO $pdo, int $userId): int
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM mentorship_messages m
         INNER JOIN mentorship_conversations c ON c.id = m.conversation_id
         INNER JOIN mentorship_requests mr ON mr.id = c.mentorship_request_id
         WHERE m.read_at IS NULL
           AND m.sender_user_id <> :sender_user_id
           AND (mr.student_user_id = :student_user_id OR mr.mentor_user_id = :mentor_user_id)'
    );
    $statement->execute([
        'sender_user_id' => $userId,
        'student_user_id' => $userId,
        'mentor_user_id' => $userId,
    ]);

    return (int) $statement->fetchColumn();
}

function touchMentorshipConversationPresence(PDO $pdo, int $conversationId, int $userId): void
{
    $statement = $pdo->prepare(
        'INSERT INTO mentorship_conversation_presence (conversation_id, user_id, last_seen_at)
         VALUES (:conversation_id, :user_id, CURRENT_TIMESTAMP)
         ON DUPLICATE KEY UPDATE last_seen_at = CURRENT_TIMESTAMP'
    );
    $statement->execute([
        'conversation_id' => $conversationId,
        'user_id' => $userId,
    ]);
}

function mentorshipConversationIsActive(PDO $pdo, int $conversationId, int $userId): bool
{
    $statement = $pdo->prepare(
        'SELECT 1
         FROM mentorship_conversation_presence
         WHERE conversation_id = :conversation_id
           AND user_id = :user_id
           AND last_seen_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 30 SECOND)'
    );
    $statement->execute([
        'conversation_id' => $conversationId,
        'user_id' => $userId,
    ]);

    return (bool) $statement->fetchColumn();
}

function renderNotificationBell(PDO $pdo, int $userId): void
{
    $notifications = getUserNotifications($pdo, $userId, 5);
    $unreadCount = getUnreadNotificationCount($pdo, $userId);
    $escape = static fn (string $value): string => htmlspecialchars(
        $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
    ?>
    <div class="cf-notification">
        <details class="cf-notification-menu">
            <summary aria-label="Notifications">
                <span aria-hidden="true">🔔</span>
                <span>Notifications</span>
                <?php if ($unreadCount > 0): ?>
                    <span class="cf-notification-count"><?= $unreadCount > 99 ? '99+' : $unreadCount ?></span>
                <?php endif; ?>
            </summary>
            <section class="cf-notification-panel" aria-label="Recent notifications">
                <header>
                    <strong>Notifications</strong>
                    <a href="<?= htmlspecialchars(BASE_URL . '/notifications.php', ENT_QUOTES, 'UTF-8') ?>">View all</a>
                </header>
                <?php if ($unreadCount > 0): ?>
                    <form method="post" action="<?= htmlspecialchars(BASE_URL . '/notifications.php', ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="csrf_token" value="<?= $escape(generateCsrfToken()) ?>">
                        <input type="hidden" name="action" value="mark_all">
                        <button class="cf-notification-mark-all" type="submit">Mark all as read</button>
                    </form>
                <?php endif; ?>
                <?php if (!$notifications): ?>
                    <p class="cf-notification-empty">You’re all caught up.</p>
                <?php else: ?>
                    <ul>
                        <?php foreach ($notifications as $notification): ?>
                            <li class="<?= (int) $notification['is_read'] === 0 ? 'is-unread' : '' ?>">
                                <form method="post" action="<?= htmlspecialchars(BASE_URL . '/notifications.php', ENT_QUOTES, 'UTF-8') ?>">
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
        </details>
    </div>
    <?php
}

function notificationIcon(string $type): string
{
    return match ($type) {
        'notice' => '📢',
        'mentorship', 'message' => '🎓',
        'approval' => '✓',
        'rejection' => '!',
        'claim' => '🔎',
        'recovery' => '↩',
        'registration' => '👤',
        'admin' => '⚙',
        default => '•',
    };
}
