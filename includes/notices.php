<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/auth.php';

if (!function_exists('requireModuleAdmin')) {
    function requireModuleAdmin(string $moduleKey): void
    {
        if (!in_array($moduleKey, ['lost_items', 'notices', 'mentorship'], true)) {
            throw new InvalidArgumentException('Unknown administrator module.');
        }

        requireRole(['admin', 'super_admin']);

        if ($_SESSION['role_name'] === 'super_admin') {
            return;
        }

        global $pdo;

        $statement = $pdo->prepare(
            'SELECT 1
             FROM module_admin_assignments
             WHERE user_id = :user_id
               AND module_key = :module_key
             LIMIT 1'
        );
        $statement->execute([
            'user_id' => (int) $_SESSION['user_id'],
            'module_key' => $moduleKey,
        ]);

        if (!$statement->fetchColumn()) {
            http_response_code(403);
            exit('You do not have permission to access this module.');
        }
    }
}

if (!defined('NOTICES_URL')) {
    define('NOTICES_URL', rtrim(dirname(BASE_URL), '/') . '/notices');
}

function noticeEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function noticeFormatDateTime(?string $value, string $format): string
{
    if ($value === null || $value === '') {
        return '';
    }

    $date = DateTimeImmutable::createFromFormat(
        '!Y-m-d H:i:s',
        $value,
        new DateTimeZone('UTC')
    );

    return $date ? $date->format($format) : $value;
}

function noticeParseLocalDateTime(string $value): ?string
{
    foreach (['Y-m-d\TH:i:s', 'Y-m-d\TH:i'] as $format) {
        $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
        $errors = DateTimeImmutable::getLastErrors();
        if (
            $date !== false
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $date->format($format) === $value
        ) {
            return $date->format('Y-m-d H:i:s');
        }
    }

    return null;
}

function noticeViewer(): array
{
    global $pdo;

    $statement = $pdo->prepare(
        'SELECT school_id, department_id
         FROM users
         WHERE id = :user_id
           AND account_status = \'active\'
         LIMIT 1'
    );
    $statement->execute(['user_id' => (int) $_SESSION['user_id']]);
    $viewer = $statement->fetch();

    if (!$viewer) {
        http_response_code(403);
        exit('Your account is unavailable.');
    }

    return $viewer;
}

function noticeVisibilitySql(): string
{
    return "n.status = 'published'
        AND n.published_at IS NOT NULL
        AND n.published_at <= CURRENT_TIMESTAMP
        AND (n.expires_at IS NULL OR n.expires_at >= CURRENT_TIMESTAMP)
        AND (n.audience = 'all' OR n.audience = :notice_audience)
        AND " . noticeTargetingSql('n', 'viewer', 'feed');
}

function noticeTargetingSql(
    string $noticeAlias,
    string $userAlias,
    ?string $parameterPrefix = null
): string {
    $schoolId = $parameterPrefix === null
        ? $userAlias . '.school_id'
        : ':' . $parameterPrefix . '_school_id';
    $departmentId = $parameterPrefix === null
        ? $userAlias . '.department_id'
        : ':' . $parameterPrefix . '_department_id';

    return "
        (
            NOT EXISTS (
                SELECT 1 FROM notice_schools ns
                WHERE ns.notice_id = {$noticeAlias}.id
            )
            OR EXISTS (
                SELECT 1 FROM notice_schools ns
                WHERE ns.notice_id = {$noticeAlias}.id
                  AND ns.school_id = {$schoolId}
            )
        )
        AND (
            NOT EXISTS (
                SELECT 1 FROM notice_departments nd
                WHERE nd.notice_id = {$noticeAlias}.id
            )
            OR EXISTS (
                SELECT 1 FROM notice_departments nd
                WHERE nd.notice_id = {$noticeAlias}.id
                  AND nd.department_id = {$departmentId}
            )
        )
    ";
}

function noticeAudienceSql(string $noticeAlias, string $roleAlias): string
{
    return "(
        ({$roleAlias}.role_name = 'student' AND {$noticeAlias}.audience IN ('all', 'students'))
        OR ({$roleAlias}.role_name = 'staff' AND {$noticeAlias}.audience IN ('all', 'staff'))
    )";
}

function noticeVisibilityParams(array $viewer): array
{
    $role = $_SESSION['role_name'] ?? '';

    return [
        'notice_audience' => $role === 'student' ? 'students' : 'staff',
        'feed_school_id' => $viewer['school_id'],
        'feed_department_id' => $viewer['department_id'],
    ];
}

function noticeExcerpt(string $text, int $length = 180): string
{
    $text = trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?? '');
    if (strlen($text) <= $length) {
        return $text;
    }

    return rtrim(substr($text, 0, $length - 1)) . '…';
}

function noticePriorityLabel(string $priority): string
{
    return match ($priority) {
        'urgent' => 'Urgent',
        'high' => 'Important',
        'low' => 'Low priority',
        default => 'Normal',
    };
}

function notifyNoticeAudience(int $noticeId): void
{
    global $pdo;

    if (!$pdo->inTransaction()) {
        throw new LogicException('Notice notifications must run within the notice transaction.');
    }

    $lock = $pdo->prepare('SELECT id FROM notices WHERE id = :id FOR UPDATE');
    $lock->execute(['id' => $noticeId]);
    if (!$lock->fetchColumn()) {
        return;
    }

    $eligibleNotice = "n.id = :notice_id
        AND n.status = 'published'
        AND n.published_at IS NOT NULL
        AND n.published_at <= CURRENT_TIMESTAMP
        AND (n.expires_at IS NULL OR n.expires_at >= CURRENT_TIMESTAMP)
        AND n.priority IN ('high', 'urgent')";
    $eligibleRecipient = "u.account_status = 'active'
        AND " . noticeAudienceSql('n', 'r') . "
        AND " . noticeTargetingSql('n', 'u');
    $noticeMarker = "CONCAT('%(CampusFind notice #', n.id, ').%')";

    $removeIneligible = $pdo->prepare(
        "DELETE existing
         FROM notification existing
         WHERE existing.notification_type = 'system'
           AND existing.message LIKE CONCAT('%(CampusFind notice #', :marker_notice_id, ').%')
           AND NOT EXISTS (
               SELECT 1
               FROM notices n
               INNER JOIN users u ON u.id = existing.user_id
               INNER JOIN roles r ON r.id = u.role_id
               WHERE {$eligibleNotice}
                 AND {$eligibleRecipient}
           )"
    );
    $removeIneligible->execute([
        'marker_notice_id' => $noticeId,
        'notice_id' => $noticeId,
    ]);

    $insert = $pdo->prepare(
        "INSERT INTO notification (user_id, title, message, notification_type)
         SELECT u.id,
                CASE n.priority
                    WHEN 'urgent' THEN 'Urgent campus notice'
                    ELSE 'Important campus notice'
                END,
                CONCAT(
                    'A ', n.priority, ' campus notice was published: ',
                    n.title, '. Read it at /campusfind/notices/view.php?id=',
                    n.id, ' (CampusFind notice #', n.id, ').'
                ),
                'system'
         FROM notices n
         INNER JOIN users u ON u.account_status = 'active'
         INNER JOIN roles r ON r.id = u.role_id
         WHERE {$eligibleNotice}
           AND {$eligibleRecipient}
           AND NOT EXISTS (
               SELECT 1
               FROM notification existing
               WHERE existing.user_id = u.id
                 AND existing.notification_type = 'system'
                 AND existing.message LIKE {$noticeMarker}
           )"
    );
    $insert->execute(['notice_id' => $noticeId]);
}
