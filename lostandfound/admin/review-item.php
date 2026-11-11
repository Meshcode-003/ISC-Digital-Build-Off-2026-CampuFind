<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/csrf.php';
require_once dirname(__DIR__, 2) . '/includes/notifications.php';

requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

requireCsrfToken();

$itemId = filter_var($_POST['item_id'] ?? null, FILTER_VALIDATE_INT);
$decisionInput = $_POST['decision'] ?? '';
$decision = is_string($decisionInput) ? $decisionInput : '';
$reasonInput = $_POST['rejection_reason'] ?? '';
$reason = is_string($reasonInput) ? trim($reasonInput) : '';

if (!$itemId || $itemId < 1) {
    http_response_code(400);
    exit('Invalid item ID.');
}

if (!in_array($decision, ['approved', 'rejected'], true)) {
    http_response_code(400);
    exit('Invalid review decision.');
}

if ($decision === 'rejected' && ($reason === '' || mb_strlen($reason) > 5000)) {
    header('Location: manage-items.php?error=' . rawurlencode(
        $reason === '' ? 'A rejection reason is required.' : 'The rejection reason is too long.'
    ));
    exit;
}

try {
    $pdo->beginTransaction();

    $itemStatement = $pdo->prepare(
        'SELECT id, user_id, item_name, item_type, status
         FROM items
         WHERE id = :item_id
         FOR UPDATE'
    );
    $itemStatement->execute(['item_id' => $itemId]);
    $item = $itemStatement->fetch();

    if (!$item || $item['item_type'] !== 'lost') {
        throw new RuntimeException('Lost item report not found.');
    }

    if ($item['status'] !== 'pending') {
        throw new RuntimeException('Only pending lost-item reports can be reviewed.');
    }

    $reviewStatement = $pdo->prepare(
        'UPDATE items
         SET status = :status,
             approved_by = :approved_by,
             rejection_reason = :rejection_reason
         WHERE id = :item_id
           AND status = \'pending\''
    );
    $reviewStatement->execute([
        'status' => $decision,
        'approved_by' => $decision === 'approved' ? (int) $_SESSION['user_id'] : null,
        'rejection_reason' => $decision === 'rejected' ? $reason : null,
        'item_id' => $itemId,
    ]);

    if ($reviewStatement->rowCount() !== 1) {
        throw new RuntimeException('The report changed while it was being reviewed. Refresh and try again.');
    }

    createNotification(
        $pdo,
        (int) $item['user_id'],
        $decision === 'approved' ? 'Lost item report approved' : 'Lost item report not approved',
        $decision === 'approved'
            ? 'Your report for "' . $item['item_name'] . '" is now visible in Lost & Found.'
            : 'Your report for "' . $item['item_name'] . '" was not approved. Reason: ' . $reason,
        $decision === 'approved' ? 'approval' : 'rejection',
        APP_BASE_PATH . '/lostandfound/lost-items.php',
        'lost-item-review:' . $itemId . ':' . $decision,
        $itemId
    );
    notifySuperAdministrators(
        $pdo,
        'Lost item report moderated',
        'An administrator ' . $decision . ' the lost item report for "' . $item['item_name'] . '".',
        APP_BASE_PATH . '/lostandfound/admin/manage-items.php',
        'lost-item-moderation:' . $itemId . ':' . $decision,
        (int) $_SESSION['user_id']
    );

    $pdo->commit();
    header('Location: manage-items.php?message=item_' . $decision);
    exit;
} catch (RuntimeException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    header('Location: manage-items.php?error=' . rawurlencode($exception->getMessage()));
    exit;
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Lost item review error: ' . $exception->getMessage());
    http_response_code(500);
    exit('Unable to save the item review. Please try again.');
}
