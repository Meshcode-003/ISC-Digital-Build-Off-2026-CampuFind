<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/notices.php';
require_once dirname(__DIR__, 2) . '/includes/csrf.php';

requireModuleAdmin('notices');

$statusInput = $_GET['status'] ?? '';
$status = is_string($statusInput) ? $statusInput : '';
$statusConditions = [
    'draft' => "n.status = 'draft'",
    'published' => "n.status = 'published' AND n.published_at <= CURRENT_TIMESTAMP AND (n.expires_at IS NULL OR n.expires_at >= CURRENT_TIMESTAMP)",
    'scheduled' => "n.status = 'published' AND n.published_at > CURRENT_TIMESTAMP",
    'archived' => "n.status = 'archived'",
    'expired' => "n.status = 'published' AND n.expires_at < CURRENT_TIMESTAMP",
];
$condition = $statusConditions[$status] ?? '1 = 1';

$statement = $pdo->query(
    "SELECT n.id, n.title, n.status, n.priority, n.is_pinned,
            n.published_at, n.expires_at, n.created_at,
            CASE
                WHEN n.status = 'published' AND n.published_at > CURRENT_TIMESTAMP THEN 'scheduled'
                WHEN n.status = 'published' AND n.expires_at < CURRENT_TIMESTAMP THEN 'expired'
                ELSE n.status
            END AS display_status,
            c.category_name, u.first_name, u.last_name
     FROM notices n
     INNER JOIN notice_categories c ON c.id = n.category_id
     INNER JOIN users u ON u.id = n.created_by
     WHERE {$condition}
     ORDER BY n.created_at DESC, n.id DESC
     LIMIT 100"
);
$notices = $statement->fetchAll();
$categories = $pdo->query(
    'SELECT id, category_name, status
     FROM notice_categories
     ORDER BY status, category_name'
)->fetchAll();
$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Notices administration | CampusFind</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/modules.css">
    <link rel="stylesheet" href="<?= noticeEscape(NOTICES_URL) ?>/notices.css">
</head>
<body>
    <header class="notice-header">
        <a class="brand" href="<?= noticeEscape(NOTICES_URL) ?>/">Campus Notices</a>
        <nav class="header-actions">
            <a class="button button-outline" href="/campusfind/">CampusFind home</a>
            <form method="post" action="<?= BASE_URL ?>/logout.php">
                <input type="hidden" name="csrf_token" value="<?= noticeEscape($csrfToken) ?>">
                <button class="link-button" type="submit">Sign out</button>
            </form>
        </nav>
    </header>
    <main class="notice-main admin-main">
        <p class="eyebrow">CAMPUSFIND · NOTICES ADMINISTRATION</p>
        <div class="admin-title-row">
            <div>
                <h1>Manage notices</h1>
                <p>Create, schedule, publish, and archive campus updates.</p>
            </div>
            <a class="button button-primary" href="<?= noticeEscape(NOTICES_URL) ?>/admin/create-announcement.php">Create notice</a>
        </div>
        <nav class="status-tabs" aria-label="Filter notices">
            <?php foreach ([
                '' => 'All',
                'draft' => 'Drafts',
                'published' => 'Published',
                'scheduled' => 'Scheduled',
                'expired' => 'Expired',
                'archived' => 'Archived',
            ] as $key => $label): ?>
                <a class="<?= $status === $key ? 'active' : '' ?>"
                   href="<?= noticeEscape(NOTICES_URL) ?>/admin/<?= $key !== '' ? '?status=' . rawurlencode($key) : '' ?>">
                    <?= noticeEscape($label) ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <?php if (!$notices): ?>
            <div class="empty-state">
                <h2>No notices in this view</h2>
                <p>Create a notice or choose another status filter.</p>
            </div>
        <?php else: ?>
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th scope="col">Notice</th>
                            <th scope="col">Status</th>
                            <th scope="col">Priority</th>
                            <th scope="col">Published</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($notices as $notice): ?>
                            <?php
                            $rowStatus = $notice['display_status'];
                            ?>
                            <tr>
                                <td>
                                    <strong><?= noticeEscape($notice['title']) ?></strong>
                                    <span><?= noticeEscape($notice['category_name']) ?> · <?= noticeEscape(trim($notice['first_name'] . ' ' . $notice['last_name'])) ?></span>
                                </td>
                                <td><span class="status-pill status-<?= noticeEscape($rowStatus) ?>"><?= noticeEscape(ucfirst($rowStatus)) ?></span></td>
                                <td><?= noticeEscape(noticePriorityLabel($notice['priority'])) ?><?= (int) $notice['is_pinned'] === 1 ? ' · Pinned' : '' ?></td>
                                <td><?= $notice['published_at'] ? noticeEscape(noticeFormatDateTime($notice['published_at'], 'M j, Y H:i')) : '—' ?></td>
                                <td>
                                    <div class="action-row">
                                        <a class="small-action" href="<?= noticeEscape(NOTICES_URL) ?>/admin/edit-announcement.php?id=<?= (int) $notice['id'] ?>">Edit</a>
                                        <form method="post" action="<?= noticeEscape(NOTICES_URL) ?>/admin/action.php">
                                            <input type="hidden" name="csrf_token" value="<?= noticeEscape($csrfToken) ?>">
                                            <input type="hidden" name="notice_id" value="<?= (int) $notice['id'] ?>">
                                            <input type="hidden" name="action" value="<?= (int) $notice['is_pinned'] === 1 ? 'unpin' : 'pin' ?>">
                                            <button class="small-action" type="submit"><?= (int) $notice['is_pinned'] === 1 ? 'Unpin' : 'Pin' ?></button>
                                        </form>
                                        <?php if ($notice['status'] === 'draft'): ?>
                                            <form method="post" action="<?= noticeEscape(NOTICES_URL) ?>/admin/action.php">
                                                <input type="hidden" name="csrf_token" value="<?= noticeEscape($csrfToken) ?>">
                                                <input type="hidden" name="notice_id" value="<?= (int) $notice['id'] ?>">
                                                <input type="hidden" name="action" value="publish">
                                                <button class="small-action" type="submit">Publish now</button>
                                            </form>
                                        <?php elseif ($notice['status'] === 'published'): ?>
                                            <form method="post" action="<?= noticeEscape(NOTICES_URL) ?>/admin/action.php">
                                                <input type="hidden" name="csrf_token" value="<?= noticeEscape($csrfToken) ?>">
                                                <input type="hidden" name="notice_id" value="<?= (int) $notice['id'] ?>">
                                                <input type="hidden" name="action" value="archive">
                                                <button class="small-action danger-action" type="submit">Archive</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <section class="category-admin">
            <div class="section-heading">
                <div><p class="eyebrow">ORGANIZATION</p><h2>Categories</h2></div>
                <a class="button button-outline" href="<?= noticeEscape(NOTICES_URL) ?>/admin/categories.php">Manage categories</a>
            </div>
            <p>Active categories: <?= count(array_filter($categories, static fn (array $category): bool => $category['status'] === 'active')) ?></p>
        </section>
    </main>
</body>
</html>
