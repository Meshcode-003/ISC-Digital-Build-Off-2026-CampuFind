<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/notices.php';
require_once dirname(__DIR__) . '/includes/csrf.php';

requireRole(['student', 'staff']);
$viewer = noticeViewer();

$searchInput = $_GET['q'] ?? '';
$search = is_string($searchInput) ? trim($searchInput) : '';
$categoryInput = $_GET['category'] ?? '';
$categoryId = is_string($categoryInput) || is_int($categoryInput)
    ? filter_var($categoryInput, FILTER_VALIDATE_INT)
    : false;
$priorityInput = $_GET['priority'] ?? '';
$priority = is_string($priorityInput) ? $priorityInput : '';
$periodInput = $_GET['period'] ?? '';
$period = is_string($periodInput) ? $periodInput : '';
$pageInput = $_GET['page'] ?? 1;
$page = is_string($pageInput) || is_int($pageInput)
    ? filter_var($pageInput, FILTER_VALIDATE_INT)
    : false;
$page = $page !== false && $page > 0 ? $page : 1;
$pageSize = 10;

if ($categoryId === false || $categoryId < 1) {
    $categoryId = null;
}
if (!in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) {
    $priority = '';
}
if (!in_array($period, ['today', 'week', 'month'], true)) {
    $period = '';
}

$categories = $pdo->query(
    "SELECT id, category_name
     FROM notice_categories
     WHERE status = 'active'
     ORDER BY category_name"
)->fetchAll();

$conditions = [noticeVisibilitySql()];
$params = noticeVisibilityParams($viewer);
if ($search !== '') {
    $conditions[] = '(
        n.title LIKE :search_title
        OR n.body LIKE :search_body
        OR n.summary LIKE :search_summary
        OR c.category_name LIKE :search_category
        OR EXISTS (
            SELECT 1
            FROM notice_departments nd
            INNER JOIN departments d ON d.id = nd.department_id
            WHERE nd.notice_id = n.id
              AND d.department_name LIKE :search_department
        )
    )';
    $term = '%' . $search . '%';
    $params['search_title'] = $term;
    $params['search_body'] = $term;
    $params['search_summary'] = $term;
    $params['search_category'] = $term;
    $params['search_department'] = $term;
}
if ($categoryId !== null) {
    $conditions[] = 'n.category_id = :category_id';
    $params['category_id'] = $categoryId;
}
if ($priority !== '') {
    $conditions[] = 'n.priority = :priority';
    $params['priority'] = $priority;
}
if ($period === 'today') {
    $conditions[] = 'DATE(n.published_at) = CURRENT_DATE';
} elseif ($period === 'week') {
    $conditions[] = 'n.published_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 7 DAY)';
} elseif ($period === 'month') {
    $conditions[] = 'n.published_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 30 DAY)';
}
$where = implode(' AND ', $conditions);

$countStatement = $pdo->prepare(
    "SELECT COUNT(*)
     FROM notices n
     INNER JOIN notice_categories c ON c.id = n.category_id
     WHERE {$where}"
);
$countStatement->execute($params);
$total = (int) $countStatement->fetchColumn();
$pages = max(1, (int) ceil($total / $pageSize));
$page = min($page, $pages);
$offset = ($page - 1) * $pageSize;

$listStatement = $pdo->prepare(
    "SELECT n.id, n.title, n.summary, n.body, n.audience, n.priority,
            n.is_pinned, n.published_at, n.expires_at,
            c.category_name, u.first_name, u.last_name,
            EXISTS (
                SELECT 1 FROM notice_reads nr
                WHERE nr.notice_id = n.id AND nr.user_id = :viewer_id
            ) AS has_read
     FROM notices n
     INNER JOIN notice_categories c ON c.id = n.category_id
     INNER JOIN users u ON u.id = n.created_by
     WHERE {$where}
     ORDER BY n.is_pinned DESC,
              FIELD(n.priority, 'urgent', 'high', 'normal', 'low'),
              n.published_at DESC, n.id DESC
     LIMIT {$pageSize} OFFSET {$offset}"
);
$listStatement->execute($params + ['viewer_id' => (int) $_SESSION['user_id']]);
$notices = $listStatement->fetchAll();

$displayName = trim(
    (string) ($_SESSION['first_name'] ?? '') . ' '
    . (string) ($_SESSION['last_name'] ?? '')
);
$filters = [
    'q' => $search,
    'category' => $categoryId,
    'priority' => $priority,
    'period' => $period,
];
$pageUrl = static function (int $number) use ($filters): string {
    return NOTICES_URL . '/?' . http_build_query($filters + ['page' => $number]);
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Campus Notices | <?= noticeEscape(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/modules.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/notifications.css">
    <link rel="stylesheet" href="<?= noticeEscape(NOTICES_URL) ?>/notices.css">
</head>
<body>
    <header class="notice-header">
        <a class="brand" href="../">CampusFind</a>
        <nav class="header-actions" aria-label="Account navigation">
            <?php renderNotificationBell($pdo, (int) $_SESSION['user_id']); ?>
            <span class="user-name"><?= noticeEscape($displayName !== '' ? $displayName : 'CampusFind user') ?></span>
            <form method="post" action="<?= BASE_URL ?>/logout.php">
                <input type="hidden" name="csrf_token" value="<?= noticeEscape(generateCsrfToken()) ?>">
                <button class="link-button" type="submit">Sign out</button>
            </form>
        </nav>
    </header>

    <main class="notice-main">
        <a class="back-link" href="../">← All CampusFind services</a>
        <section class="page-heading">
            <p class="eyebrow">CAMPUSFIND · COMMUNITY UPDATES</p>
            <h1>Campus Notices</h1>
            <p>Find clear, timely announcements from across campus.</p>
        </section>

        <form class="filter-panel" method="get" action="<?= noticeEscape(NOTICES_URL) ?>/">
            <label class="search-field">
                <span>Search notices</span>
                <input type="search" name="q" value="<?= noticeEscape($search) ?>"
                       placeholder="Search title or message">
            </label>
            <label>
                <span>Category</span>
                <select name="category">
                    <option value="">All categories</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= (int) $category['id'] ?>"
                            <?= $categoryId === (int) $category['id'] ? 'selected' : '' ?>>
                            <?= noticeEscape($category['category_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span>Priority</span>
                <select name="priority">
                    <option value="">Any priority</option>
                    <option value="urgent" <?= $priority === 'urgent' ? 'selected' : '' ?>>Urgent</option>
                    <option value="high" <?= $priority === 'high' ? 'selected' : '' ?>>Important</option>
                    <option value="normal" <?= $priority === 'normal' ? 'selected' : '' ?>>Normal</option>
                    <option value="low" <?= $priority === 'low' ? 'selected' : '' ?>>Low</option>
                </select>
            </label>
            <label>
                <span>Published</span>
                <select name="period">
                    <option value="">Any time</option>
                    <option value="today" <?= $period === 'today' ? 'selected' : '' ?>>Today</option>
                    <option value="week" <?= $period === 'week' ? 'selected' : '' ?>>Past 7 days</option>
                    <option value="month" <?= $period === 'month' ? 'selected' : '' ?>>Past 30 days</option>
                </select>
            </label>
            <button class="button button-primary" type="submit">Search</button>
        </form>

        <section aria-labelledby="latest-title">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">LATEST UPDATES</p>
                    <h2 id="latest-title">Recent notices</h2>
                </div>
                <span class="result-count"><?= $total ?> <?= $total === 1 ? 'notice' : 'notices' ?></span>
            </div>

            <?php if (!$notices): ?>
                <div class="empty-state">
                    <h3>No notices found</h3>
                    <p>Try a different search or check back later for campus updates.</p>
                </div>
            <?php else: ?>
                <div class="notice-list">
                    <?php foreach ($notices as $notice): ?>
                        <article class="notice-card<?= (int) $notice['is_pinned'] === 1 ? ' is-pinned' : '' ?>">
                            <div class="card-topline">
                                <span class="category-pill"><?= noticeEscape($notice['category_name']) ?></span>
                                <span class="priority-pill priority-<?= noticeEscape($notice['priority']) ?>">
                                    <?= noticeEscape(noticePriorityLabel($notice['priority'])) ?>
                                </span>
                                <?php if ((int) $notice['is_pinned'] === 1): ?>
                                    <span class="pinned-label">Pinned</span>
                                <?php endif; ?>
                                <?php if ((int) $notice['has_read'] === 1): ?>
                                    <span class="read-label">Read</span>
                                <?php endif; ?>
                            </div>
                            <h3><a href="<?= noticeEscape(NOTICES_URL) ?>/view.php?id=<?= (int) $notice['id'] ?>">
                                <?= noticeEscape($notice['title']) ?>
                            </a></h3>
                            <p class="notice-summary"><?= noticeEscape($notice['summary'] ?: noticeExcerpt($notice['body'])) ?></p>
                            <div class="notice-meta">
                                <span><?= noticeEscape($notice['category_name']) ?></span>
                                 <span aria-hidden="true">·</span>
                                 <span>Posted by <?= noticeEscape(trim($notice['first_name'] . ' ' . $notice['last_name'])) ?></span>
                                <span aria-hidden="true">·</span>
                                <time datetime="<?= noticeEscape(noticeFormatDateTime($notice['published_at'], 'Y-m-d\TH:i:s')) ?>">
                                    <?= noticeEscape(noticeFormatDateTime($notice['published_at'], 'M j, Y')) ?>
                                </time>
                                <?php if ($notice['expires_at'] !== null): ?>
                                    <span aria-hidden="true">·</span>
                                    <span>Until <?= noticeEscape(noticeFormatDateTime($notice['expires_at'], 'M j, Y')) ?></span>
                                <?php endif; ?>
                            </div>
                            <a class="read-more" href="<?= noticeEscape(NOTICES_URL) ?>/view.php?id=<?= (int) $notice['id'] ?>">
                                Read notice <span aria-hidden="true">→</span>
                            </a>
                        </article>
                    <?php endforeach; ?>
                </div>
                <?php if ($pages > 1): ?>
                    <nav class="pagination" aria-label="Notice pages">
                        <?php if ($page > 1): ?>
                            <a href="<?= noticeEscape($pageUrl($page - 1)) ?>">Previous</a>
                        <?php endif; ?>
                        <span>Page <?= $page ?> of <?= $pages ?></span>
                        <?php if ($page < $pages): ?>
                            <a href="<?= noticeEscape($pageUrl($page + 1)) ?>">Next</a>
                        <?php endif; ?>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>