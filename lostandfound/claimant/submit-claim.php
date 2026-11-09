<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/config.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/csrf.php';

requireRole(['student', 'staff']);

$userId = (int) $_SESSION['user_id'];
$itemIdInput = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? ($_POST['item_id'] ?? null)
    : ($_GET['item_id'] ?? null);
$itemId = filter_var($itemIdInput, FILTER_VALIDATE_INT);

if (!$itemId || $itemId < 1) {
    http_response_code(400);
    exit('Invalid item selected.');
}

$itemStatement = $pdo->prepare(
    "SELECT i.id, i.user_id, i.item_name, i.description, i.location,
            i.date_occurred, i.status, c.category_name,
            (
                SELECT im.image_path
                FROM item_images im
                WHERE im.item_id = i.id
                ORDER BY im.is_primary DESC, im.id ASC
                LIMIT 1
            ) AS image_path
     FROM items i
     LEFT JOIN categories c ON c.id = i.category_id
     WHERE i.id = :item_id
       AND i.item_type = 'lost'
     LIMIT 1"
);
$itemStatement->execute(['item_id' => $itemId]);
$item = $itemStatement->fetch();

if (!$item || $item['status'] !== 'approved') {
    http_response_code(404);
    exit('This lost item is not available for claims.');
}

if ((int) $item['user_id'] === $userId) {
    http_response_code(403);
    exit('You cannot submit a claim for your own report.');
}

$userStatement = $pdo->prepare(
    'SELECT first_name, last_name, email, phone
     FROM users
     WHERE id = :user_id
     LIMIT 1'
);
$userStatement->execute(['user_id' => $userId]);
$claimant = $userStatement->fetch();

if (!$claimant) {
    http_response_code(403);
    exit('Your account could not be verified.');
}

$existingStatement = $pdo->prepare(
    "SELECT id, status
     FROM claims
     WHERE item_id = :item_id
       AND claimant_id = :claimant_id
       AND status IN ('pending', 'approved')
     LIMIT 1"
);
$existingStatement->execute([
    'item_id' => $itemId,
    'claimant_id' => $userId,
]);
$existingClaim = $existingStatement->fetch();

$error = '';
$success = false;
$claimDescription = '';
$proofDescription = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();

    $claimDescriptionInput = $_POST['claim_description'] ?? '';
    $proofDescriptionInput = $_POST['proof_description'] ?? '';
    $claimDescription = is_string($claimDescriptionInput)
        ? trim($claimDescriptionInput)
        : '';
    $proofDescription = is_string($proofDescriptionInput)
        ? trim($proofDescriptionInput)
        : '';

    if ($existingClaim) {
        $error = 'You already have a pending or approved claim for this item.';
    } elseif (
        $claimDescription === ''
        || $proofDescription === ''
        || mb_strlen($claimDescription) > 5000
        || mb_strlen($proofDescription) > 5000
    ) {
        $error = 'Provide both a description and ownership evidence (up to 5000 characters each).';
    } else {
        try {
            $pdo->beginTransaction();

            $lockStatement = $pdo->prepare(
                "SELECT id, user_id, status
                 FROM items
                 WHERE id = :item_id
                   AND item_type = 'lost'
                 FOR UPDATE"
            );
            $lockStatement->execute(['item_id' => $itemId]);
            $lockedItem = $lockStatement->fetch();

            if (
                !$lockedItem
                || $lockedItem['status'] !== 'approved'
                || (int) $lockedItem['user_id'] === $userId
            ) {
                throw new RuntimeException('This item is no longer available for claims.');
            }

            $duplicateStatement = $pdo->prepare(
                "SELECT id
                 FROM claims
                 WHERE item_id = :item_id
                   AND claimant_id = :claimant_id
                   AND status IN ('pending', 'approved')
                 LIMIT 1"
            );
            $duplicateStatement->execute([
                'item_id' => $itemId,
                'claimant_id' => $userId,
            ]);

            if ($duplicateStatement->fetchColumn()) {
                throw new RuntimeException(
                    'You already have a pending or approved claim for this item.'
                );
            }

            $claimStatement = $pdo->prepare(
                "INSERT INTO claims (
                    item_id,
                    claimant_id,
                    claim_description,
                    proof_description,
                    status
                 ) VALUES (
                    :item_id,
                    :claimant_id,
                    :claim_description,
                    :proof_description,
                    'pending'
                 )"
            );
            $claimStatement->execute([
                'item_id' => $itemId,
                'claimant_id' => $userId,
                'claim_description' => $claimDescription,
                'proof_description' => $proofDescription,
            ]);

            $pdo->commit();
            header('Location: my-claims.php?submitted=1');
            exit;
        } catch (RuntimeException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $exception->getMessage();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Lost item claim submission error: ' . $exception->getMessage());
            http_response_code(500);
            $error = 'Unable to submit your claim right now. Please try again.';
        }
    }
}

$escape = static fn ($value): string => htmlspecialchars(
    (string) ($value ?? ''),
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$imageUrl = !empty($item['image_path'])
    ? '../' . ltrim((string) $item['image_path'], '/')
    : '';
$claimantName = trim($claimant['first_name'] . ' ' . $claimant['last_name']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Claim <?= $escape($item['item_name']) ?> | CampusFind</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 30px 15px; font-family: Arial, sans-serif; background: #f4f6f9; color: #263238; }
        .claim-container { max-width: 720px; margin: 20px auto; padding: 30px; background: #fff; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,.08); }
        h1 { margin-top: 0; }
        .selected-item { margin: 22px 0; padding: 18px; border-radius: 8px; background: #f1f3f5; }
        .selected-item img { width: 100%; max-height: 260px; object-fit: contain; border-radius: 8px; }
        .account-details { color: #5b6570; }
        label { display: block; margin-top: 18px; font-weight: bold; }
        textarea { width: 100%; min-height: 110px; margin-top: 7px; padding: 12px; border: 1px solid #ccc; border-radius: 6px; font: inherit; resize: vertical; }
        button { width: 100%; margin-top: 24px; padding: 14px; border: 0; border-radius: 6px; background: #d4a017; color: white; font-size: 16px; font-weight: bold; cursor: pointer; }
        .message { margin: 15px 0; padding: 12px; border-radius: 6px; }
        .error { background: #ffe5e5; color: #b00020; }
        .success { background: #e1f7e7; color: #176b2c; }
        .back-link { display: inline-block; margin: 20px 16px 0 0; color: #174e38; }
    </style>
</head>
<body>
<main class="claim-container">
    <h1>Claim this lost item</h1>
    <p>Provide details that help an administrator verify that the item belongs to you.</p>

    <section class="selected-item" aria-label="Selected item">
        <?php if ($imageUrl !== ''): ?>
            <img src="<?= $escape($imageUrl) ?>" alt="<?= $escape($item['item_name']) ?>">
        <?php else: ?>
            <p>No photograph available.</p>
        <?php endif; ?>
        <h2><?= $escape($item['item_name']) ?></h2>
        <p><strong>Category:</strong> <?= $escape($item['category_name'] ?? 'Uncategorised') ?></p>
        <p><strong>Location:</strong> <?= $escape($item['location']) ?></p>
        <p><strong>Date lost:</strong> <?= $escape($item['date_occurred']) ?></p>
    </section>

    <p class="account-details">
        Submitting as <?= $escape($claimantName !== '' ? $claimantName : 'your CampusFind account') ?>
        (<?= $escape($claimant['email']) ?><?= !empty($claimant['phone']) ? ' · ' . $escape($claimant['phone']) : '' ?>).
    </p>

    <?php if ($error !== ''): ?>
        <p class="message error" role="alert"><?= $escape($error) ?></p>
    <?php endif; ?>

    <?php if ($existingClaim): ?>
        <p class="message">
            You already have a <?= $escape($existingClaim['status']) ?> claim for this item.
            <a href="my-claims.php">View your claims</a>.
        </p>
    <?php else: ?>
        <form method="post">
            <input type="hidden" name="item_id" value="<?= (int) $itemId ?>">
            <input type="hidden" name="csrf_token" value="<?= $escape(generateCsrfToken()) ?>">

            <label for="claim_description">Describe the item you lost</label>
            <textarea id="claim_description" name="claim_description" maxlength="5000" required><?= $escape($claimDescription) ?></textarea>

            <label for="proof_description">Ownership evidence</label>
            <textarea id="proof_description" name="proof_description" maxlength="5000" required placeholder="Include unique features or details only the owner would know."><?= $escape($proofDescription) ?></textarea>

            <button type="submit">Submit ownership claim</button>
        </form>
    <?php endif; ?>

    <a class="back-link" href="../lost-items.php">Back to lost items</a>
    <a class="back-link" href="my-claims.php">View my claims</a>
</main>
</body>
</html>
