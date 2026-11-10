<?php
require_once __DIR__ . '/_common.php';

$query = trim(mentorshipInputString($_GET, 'q'));
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
$page = is_int($page) && $page > 0 ? $page : 1;
$pageSize = 12;
$offset = ($page - 1) * $pageSize;
$where = "mp.status = 'active'
          AND mp.accepting_requests = 1
          AND u.account_status = 'active'
          AND r.role_name IN ('student', 'staff')";
$parameters = [];

if ($query !== '') {
    $where .= ' AND (CONCAT_WS(\' \', u.first_name, u.last_name) LIKE :search_name
                     OR mp.headline LIKE :search_headline
                     OR mp.bio LIKE :search_bio
                     OR EXISTS (
                         SELECT 1 FROM mentor_expertise search_expertise
                         WHERE search_expertise.mentor_user_id = mp.user_id
                           AND search_expertise.expertise_name LIKE :search_expertise
                     ))';
    $search = '%' . $query . '%';
    $parameters = [
        'search_name' => $search,
        'search_headline' => $search,
        'search_bio' => $search,
        'search_expertise' => $search,
    ];
}

$countStatement = $pdo->prepare(
    "SELECT COUNT(DISTINCT mp.user_id)
     FROM mentor_profiles mp
     INNER JOIN users u ON u.id = mp.user_id
     INNER JOIN roles r ON r.id = u.role_id
     WHERE {$where}"
);
$countStatement->execute($parameters);
$total = (int) $countStatement->fetchColumn();
$totalPages = max(1, (int) ceil($total / $pageSize));
$page = min($page, $totalPages);
$offset = ($page - 1) * $pageSize;

$mentorStatement = $pdo->prepare(
    "SELECT mp.user_id, mp.headline, mp.bio,
            u.first_name, u.last_name,
            GROUP_CONCAT(DISTINCT me.expertise_name ORDER BY me.expertise_name SEPARATOR '||') AS expertise
     FROM mentor_profiles mp
     INNER JOIN users u ON u.id = mp.user_id
     INNER JOIN roles r ON r.id = u.role_id
     LEFT JOIN mentor_expertise me ON me.mentor_user_id = mp.user_id
     WHERE {$where}
     GROUP BY mp.user_id, mp.headline, mp.bio, u.first_name, u.last_name
     ORDER BY u.first_name, u.last_name
     LIMIT :page_size OFFSET :offset"
);
foreach ($parameters as $key => $value) {
    $mentorStatement->bindValue(':' . $key, $value, PDO::PARAM_STR);
}
$mentorStatement->bindValue(':page_size', $pageSize, PDO::PARAM_INT);
$mentorStatement->bindValue(':offset', $offset, PDO::PARAM_INT);
$mentorStatement->execute();
$mentors = $mentorStatement->fetchAll();

mentorshipPageStart('Find a Mentor');
?>
<p class="eyebrow">MENTORSHIP</p>
<h1>Find a Mentor</h1>
<p class="page-intro">Explore campus mentors by name, experience, or area of expertise. Send a request to start a connection.</p>

<form class="search-form" method="get" role="search">
    <label class="sr-only" for="mentor-search">Search mentors</label>
    <input id="mentor-search" name="q" value="<?= mentorshipEscape($query) ?>" maxlength="100" placeholder="Search a name or expertise">
    <button class="button" type="submit">Search</button>
</form>

<?php if ($mentorshipUser['role_name'] !== 'admin' && $mentorshipUser['role_name'] !== 'super_admin'): ?>
    <p><a class="mentor-link" href="apply.php">Interested in mentoring? Apply to become a mentor</a></p>
<?php endif; ?>

<?php if (!$mentors): ?>
    <section class="panel">
        <p class="muted">No mentors match your search yet. Try another term or check back later.</p>
    </section>
<?php else: ?>
    <div class="mentor-grid">
        <?php foreach ($mentors as $mentor): ?>
            <?php
            $fullName = trim($mentor['first_name'] . ' ' . $mentor['last_name']);
            $expertise = $mentor['expertise'] ? explode('||', $mentor['expertise']) : [];
            $phone = null;
            if ((int) $mentor['user_id'] !== $mentorshipUserId) {
                $phoneStatement = $pdo->prepare(
                    'SELECT u.phone
                     FROM users u
                     INNER JOIN mentor_contact_preferences cp ON cp.user_id = u.id
                     WHERE u.id = :user_id AND cp.whatsapp_enabled = 1'
                );
                $phoneStatement->execute(['user_id' => (int) $mentor['user_id']]);
                $phone = mentorshipNormalizePhone($phoneStatement->fetchColumn() ?: null);
            }
            ?>
            <article class="mentor-card">
                <h2><?= mentorshipEscape($fullName) ?></h2>
                <p class="mentor-meta"><?= mentorshipEscape($mentor['headline'] ?? 'Campus mentor') ?></p>
                <p><?= nl2br(mentorshipEscape($mentor['bio'] ?? '')) ?></p>
                <?php if ($expertise): ?>
                    <ul class="tag-list" aria-label="Areas of expertise">
                        <?php foreach ($expertise as $area): ?>
                            <li><?= mentorshipEscape($area) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if ((int) $mentor['user_id'] !== $mentorshipUserId): ?>
                    <form class="form-grid" method="post" action="request.php">
                        <input type="hidden" name="csrf_token" value="<?= mentorshipEscape(generateCsrfToken()) ?>">
                        <input type="hidden" name="mentor_user_id" value="<?= (int) $mentor['user_id'] ?>">
                        <label>
                            Introduce yourself
                            <textarea name="message" minlength="10" maxlength="2000" required placeholder="What would you like guidance with?"></textarea>
                        </label>
                        <button class="button" type="submit">Request mentorship</button>
                    </form>
                <?php endif; ?>
                <?php if ($phone !== null): ?>
                    <p><a class="mentor-link" href="https://wa.me/<?= mentorshipEscape($phone) ?>" target="_blank" rel="noopener noreferrer">Contact on WhatsApp (mentor opted in)</a></p>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
    <?php if ($totalPages > 1): ?>
        <nav class="pagination" aria-label="Mentor pages">
            <?php if ($page > 1): ?>
                <a class="mentor-link" href="?q=<?= rawurlencode($query) ?>&amp;page=<?= $page - 1 ?>">Previous</a>
            <?php endif; ?>
            <span>Page <?= $page ?> of <?= $totalPages ?></span>
            <?php if ($page < $totalPages): ?>
                <a class="mentor-link" href="?q=<?= rawurlencode($query) ?>&amp;page=<?= $page + 1 ?>">Next</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>
<?php mentorshipPageEnd(); ?>
