<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/config.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/csrf.php';
require_once dirname(__DIR__, 2) . '/includes/notifications.php';

requireRole(['student', 'staff']);

$statement = $pdo->prepare(
    'SELECT cl.id, cl.claim_description, cl.proof_description, cl.status,
            cl.rejection_reason, cl.reviewed_at, cl.created_at,
            i.id AS item_id, i.item_name, i.location, i.date_occurred,
            i.status AS item_status, c.category_name
     FROM claims cl
     INNER JOIN items i ON i.id = cl.item_id
     LEFT JOIN categories c ON c.id = i.category_id
     WHERE cl.claimant_id = :claimant_id
     ORDER BY cl.created_at DESC'
);
$statement->execute(['claimant_id' => (int) $_SESSION['user_id']]);
$claims = $statement->fetchAll();

$escape = static fn ($value): string => htmlspecialchars(
    (string) ($value ?? ''),
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$submitted = isset($_GET['submitted']) && $_GET['submitted'] === '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Claims | CampusFind</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/notifications.css">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 28px 16px; font-family: Arial, sans-serif; background: #f4f6f9; color: #263238; }
        main { max-width: 1000px; margin: auto; }
        .header, .claim { padding: 22px; background: #fff; border-radius: 10px; box-shadow: 0 3px 12px rgba(0,0,0,.05); }
        .header { margin-bottom: 20px; }
        .claim { margin-bottom: 14px; }
        .links { display: flex; flex-wrap: wrap; gap: 16px; margin-top: 16px; }
        .links a { color: #174e38; }
        .status { display: inline-block; padding: 5px 10px; border-radius: 20px; background: #e8f0fe; text-transform: capitalize; }
        .pending { background: #fff1ce; }
        .approved { background: #d9f7e4; }
        .rejected { background: #ffe0e0; }
        .cancelled { background: #e0eaff; }
        .notice { padding: 12px; border-radius: 6px; background: #e1f7e7; color: #176b2c; }
        .empty { padding: 28px; text-align: center; background: white; border-radius: 10px; }
    </style>
</head>
<body>
<main>
    <header class="header">
        <h1>My ownership claims</h1>
        <p>Track administrator decisions about items you have claimed.</p>
        <nav class="links" aria-label="Lost and Found">
            <?php renderNotificationBell($pdo, (int) $_SESSION['user_id']); ?>
            <a href="../index.php">Lost &amp; Found home</a>
            <a href="../lost-items.php">Browse lost items</a>
        </nav>
    </header>

    <?php if ($submitted): ?>
        <p class="notice" role="status">Your claim was submitted and is pending administrator review.</p>
    <?php endif; ?>

    <?php if (!$claims): ?>
        <p class="empty">You have not submitted any claims yet.</p>
    <?php else: ?>
        <?php foreach ($claims as $claim): ?>
            <article class="claim">
                <h2><?= $escape($claim['item_name']) ?></h2>
                <p>
                    <span class="status <?= $escape($claim['status']) ?>"><?= $escape($claim['status']) ?></span>
                    · Submitted <?= $escape($claim['created_at']) ?>
                </p>
                <p><strong>Category:</strong> <?= $escape($claim['category_name'] ?? 'Uncategorised') ?></p>
                <p><strong>Location:</strong> <?= $escape($claim['location']) ?></p>
                <p><strong>Your description:</strong> <?= nl2br($escape($claim['claim_description'])) ?></p>
                <p><strong>Ownership evidence:</strong> <?= nl2br($escape($claim['proof_description'])) ?></p>
                <?php if ($claim['status'] === 'rejected' && !empty($claim['rejection_reason'])): ?>
                    <p><strong>Administrator feedback:</strong> <?= nl2br($escape($claim['rejection_reason'])) ?></p>
                <?php endif; ?>
                <?php if (!empty($claim['reviewed_at'])): ?>
                    <p><strong>Reviewed:</strong> <?= $escape($claim['reviewed_at']) ?></p>
                <?php endif; ?>
                <?php if ($claim['item_status'] === 'recovered'): ?>
                    <p>This item has been marked as recovered.</p>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    <?php endif; ?>
</main>
</body>
</html>
