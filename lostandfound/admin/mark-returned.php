<?php
declare(strict_types=1);

// Load authentication, configuration and database connection.
require_once dirname(__DIR__, 2) . '/includes/auth.php';

requireAdmin();

// Only allow POST requests.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

// Verify CSRF token.
$submittedToken = $_POST['csrf_token'] ?? '';

if (
    !is_string($submittedToken) ||
    !isset($_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $submittedToken)
) {
    http_response_code(403);
    exit('Invalid request token. Please return and try again.');
}

// Validate item ID.
$itemId = filter_input(INPUT_POST, 'item_id', FILTER_VALIDATE_INT);

if (!$itemId || $itemId < 1) {
    http_response_code(400);
    exit('Invalid item ID.');
}

// Get the logged-in administrator's ID.
$adminId = filter_var(
    $_SESSION['user_id'] ?? null,
    FILTER_VALIDATE_INT
);

if (!$adminId || $adminId < 1) {
    http_response_code(403);
    exit('Administrator session is invalid.');
}

try {

    // Start database transaction.
    $pdo->beginTransaction();

    // Find and lock the item.
    $itemStmt = $pdo->prepare("
        SELECT id, item_type, status
        FROM items
        WHERE id = ?
        FOR UPDATE
    ");

    $itemStmt->execute([$itemId]);

    $item = $itemStmt->fetch();

    if (!$item) {
        throw new RuntimeException('Item not found.');
    }

    if ($item['item_type'] !== 'lost' || $item['status'] !== 'approved') {
        throw new RuntimeException(
            'Only an approved lost item can be marked as recovered.'
        );
    }

    // Prevent duplicate recovery.
    if ($item['status'] === 'recovered') {
        throw new RuntimeException(
            'This item has already been marked as recovered.'
        );
    }

    // Find and lock approved claims for this item.
    $claimStmt = $pdo->prepare("
        SELECT id, claimant_id
        FROM claims
        WHERE item_id = ?
        AND status = 'approved'
        FOR UPDATE
    ");

    $claimStmt->execute([$itemId]);

    $approvedClaims = $claimStmt->fetchAll();

    // There must be exactly one approved claim.
    if (count($approvedClaims) === 0) {
        throw new RuntimeException(
            'Cannot recover this item because it has no approved claim.'
        );
    }

    if (count($approvedClaims) > 1) {
        throw new RuntimeException(
            'Multiple approved claims exist. Please resolve them before recovery.'
        );
    }

    // Identify the approved claim.
    $approvedClaimId = (int) $approvedClaims[0]['id'];

    // Record the physical handover.
    $updateStmt = $pdo->prepare("
        UPDATE items
        SET
            status = 'recovered',
            recovered_at = NOW(),
            recovered_by = ?,
            recovered_claim_id = ?
        WHERE id = ?
        AND status <> 'recovered'
    ");

    $updateStmt->execute([
        $adminId,
        $approvedClaimId,
        $itemId
    ]);

    if ($updateStmt->rowCount() !== 1) {
        throw new RuntimeException(
            'The item could not be updated. Please try again.'
        );
    }

    // Complete transaction.
    $pdo->commit();

    // Redirect after successful recovery.
    header('Location: manage-items.php?message=recovered');
    exit;

} catch (Throwable $exception) {

    // Undo database changes if anything fails.
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if ($exception instanceof RuntimeException) {
        $message = $exception->getMessage();
    } else {
        error_log(
            'CampusFind recovery error: ' . $exception->getMessage()
        );

        $message = 'An unexpected error occurred while recording the handover.';
    }

    http_response_code(400);

    exit(
        htmlspecialchars(
            $message,
            ENT_QUOTES,
            'UTF-8'
        )
    );
}
