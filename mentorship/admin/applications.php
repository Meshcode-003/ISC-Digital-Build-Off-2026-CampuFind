<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_common.php';
requireAdmin();

$status = mentorshipInputString($_GET, 'status');
$allowedStatuses = ['pending', 'approved', 'rejected', 'all'];
if (!in_array($status, $allowedStatuses, true)) {
    $status = 'pending';
}

$where = $status === 'all' ? '' : 'WHERE a.status = :status';
$statement = $pdo->prepare(
    "SELECT a.id, a.headline, a.status, a.created_at, a.reviewed_at,
            u.first_name, u.last_name, u.email
     FROM mentor_applications a
     INNER JOIN users u ON u.id = a.user_id
     {$where}
     ORDER BY CASE WHEN a.status = 'pending' THEN 0 ELSE 1 END, a.created_at ASC
     LIMIT 100"
);
if ($status !== 'all') {
    $statement->execute(['status' => $status]);
} else {
    $statement->execute();
}
$applications = $statement->fetchAll();

mentorshipPageStart('Mentor Applications');
?>
<p><a class="mentor-link" href="../index.php">← Mentorship</a></p>
<h1>Mentor applications</h1>
<p class="page-intro">Review qualifications and expertise before approving a public mentor profile.</p>

<nav class="pagination" aria-label="Application status">
    <?php foreach ($allowedStatuses as $filter): ?>
        <a class="mentor-link" href="?status=<?= rawurlencode($filter) ?>"><?= mentorshipEscape(ucfirst($filter)) ?></a>
    <?php endforeach; ?>
</nav>

<section class="panel table-wrap">
    <?php if (!$applications): ?>
        <p class="muted">No applications in this view.</p>
    <?php else: ?>
        <table class="data-table">
            <thead>
            <tr><th>Applicant</th><th>Headline</th><th>Status</th><th>Submitted</th><th>Review</th></tr>
            </thead>
            <tbody>
            <?php foreach ($applications as $application): ?>
                <tr>
                    <td><?= mentorshipEscape(trim($application['first_name'] . ' ' . $application['last_name'])) ?><br><span class="muted"><?= mentorshipEscape($application['email']) ?></span></td>
                    <td><?= mentorshipEscape($application['headline']) ?></td>
                    <td><span class="status-label"><?= mentorshipEscape($application['status']) ?></span></td>
                    <td><?= mentorshipEscape($application['created_at']) ?></td>
                    <td><a class="mentor-link" href="review.php?id=<?= (int) $application['id'] ?>">View application</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
<?php mentorshipPageEnd(); ?>
