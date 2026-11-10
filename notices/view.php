<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/notices.php';
require_once dirname(__DIR__) . '/includes/csrf.php';

requireRole(['student', 'staff']);
$viewer = noticeViewer();
$noticeId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$noticeId || $noticeId < 1) {
    http_response_code(404);
    exit('Notice not found.');
}

$statement = $pdo->prepare(
    'SELECT n.*, c.category_name, u.first_name, u.last_name
     FROM notices n
     INNER JOIN notice_categories c ON c.id = n.category_id
     INNER JOIN users u ON u.id = n.created_by
     WHERE n.id = :notice_id AND ' . noticeVisibilitySql() . '
     LIMIT 1'
);
$statement->execute(
    ['notice_id' => $noticeId] + noticeVisibilityParams($viewer)
);
$notice = $statement->fetch();

if (!$notice) {
    http_response_code(404);
    exit('Notice not found.');
}

$readStatement = $pdo->prepare(
    'INSERT INTO notice_reads (notice_id, user_id, read_at)
     VALUES (:notice_id, :user_id, CURRENT_TIMESTAMP)
     ON DUPLICATE KEY UPDATE read_at = CURRENT_TIMESTAMP'
);
$readStatement->execute([
    'notice_id' => $noticeId,
    'user_id' => (int) $_SESSION['user_id'],
]);

$attachmentsStatement = $pdo->prepare(
    'SELECT id, original_filename, mime_type, file_size
     FROM notice_attachments
     WHERE notice_id = :notice_id
     ORDER BY id'
);
$attachmentsStatement->execute(['notice_id' => $noticeId]);
$attachments = $attachmentsStatement->fetchAll();
$author = trim($notice['first_name'] . ' ' . $notice['last_name']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= noticeEscape($notice['title']) ?> | Campus Notices</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/modules.css">
    <link rel="stylesheet" href="<?= noticeEscape(NOTICES_URL) ?>/notices.css">
</head>
<body>
    <header class="notice-header">
        <a class="brand" href="<?= noticeEscape(NOTICES_URL) ?>/">Campus Notices</a>
        <div class="header-actions">
            <span class="user-name"><?= noticeEscape($_SESSION['first_name'] ?? 'CampusFind user') ?></span>
            <form method="post" action="<?= BASE_URL ?>/logout.php">
                <input type="hidden" name="csrf_token" value="<?= noticeEscape(generateCsrfToken()) ?>">
                <button class="link-button" type="submit">Sign out</button>
            </form>
        </div>
    </header>
    <main class="notice-main detail-main">
        <a class="back-link" href="<?= noticeEscape(NOTICES_URL) ?>/">← All notices</a>
        <article class="detail-card">
            <div class="card-topline">
                <span class="category-pill"><?= noticeEscape($notice['category_name']) ?></span>
                <span class="priority-pill priority-<?= noticeEscape($notice['priority']) ?>">
                    <?= noticeEscape(noticePriorityLabel($notice['priority'])) ?>
                </span>
                <?php if ((int) $notice['is_pinned'] === 1): ?>
                    <span class="pinned-label">Pinned</span>
                <?php endif; ?>
            </div>
            <h1><?= noticeEscape($notice['title']) ?></h1>
            <?php if ($notice['summary']): ?>
                <p class="detail-summary"><?= noticeEscape($notice['summary']) ?></p>
            <?php endif; ?>
            <div class="notice-meta detail-meta">
                <span>Posted by <?= noticeEscape($author !== '' ? $author : 'CampusFind') ?></span>
                <span aria-hidden="true">·</span>
                <time datetime="<?= noticeEscape(noticeFormatDateTime($notice['published_at'], 'Y-m-d\TH:i:s')) ?>">
                    <?= noticeEscape(noticeFormatDateTime($notice['published_at'], 'F j, Y \a\t g:i A')) ?>
                </time>
                <?php if ($notice['expires_at']): ?>
                    <span aria-hidden="true">·</span>
                    <span>Deadline <?= noticeEscape(noticeFormatDateTime($notice['expires_at'], 'M j, Y')) ?></span>
                <?php endif; ?>
            </div>

            <div class="structured-details">
                <?php foreach ([
                    'What is happening?' => 'what_happening',
                    'Who is affected?' => 'who_affected',
                    'Action required' => 'action_required',
                    'Location' => 'location',
                    'Contact' => 'contact_info',
                ] as $label => $field): ?>
                    <?php if (!empty($notice[$field])): ?>
                        <section>
                            <h2><?= noticeEscape($label) ?></h2>
                            <p><?= nl2br(noticeEscape($notice[$field])) ?></p>
                        </section>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <div class="notice-body"><?= nl2br(noticeEscape($notice['body'])) ?></div>

            <?php if ($attachments): ?>
                <section class="attachment-section">
                    <h2>Attachments</h2>
                    <ul>
                        <?php foreach ($attachments as $attachment): ?>
                            <li>
                                <a href="<?= BASE_URL ?>/download-attachment.php?id=<?= (int) $attachment['id'] ?>">
                                    <?= noticeEscape($attachment['original_filename'] ?: 'Download attachment') ?>
                                </a>
                                <?php if ($attachment['file_size'] !== null): ?>
                                    <span><?= number_format((int) $attachment['file_size'] / 1024) ?> KB</span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endif; ?>
        </article>
    </main>
</body>
</html>
