<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/csrf.php';

requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

requireCsrfToken();

$itemId = filter_input(INPUT_POST, 'item_id', FILTER_VALIDATE_INT);
if (!$itemId || $itemId < 1) {
    http_response_code(400);
    exit('Invalid item ID.');
}

try {
    $pdo->beginTransaction();

    $itemStatement = $pdo->prepare(
        'SELECT id FROM items WHERE id = :id FOR UPDATE'
    );
    $itemStatement->execute(['id' => $itemId]);

    if (!$itemStatement->fetch()) {
        throw new RuntimeException('Item not found.');
    }

    $claimStatement = $pdo->prepare(
        'SELECT COUNT(*) FROM claims WHERE item_id = :item_id'
    );
    $claimStatement->execute(['item_id' => $itemId]);

    $reportStatement = $pdo->prepare(
        'SELECT COUNT(*) FROM lost_item_reports WHERE item_id = :item_id'
    );
    $reportStatement->execute(['item_id' => $itemId]);

    if ((int) $claimStatement->fetchColumn() > 0
        || (int) $reportStatement->fetchColumn() > 0) {
        throw new RuntimeException(
            'This item has claim or report history and cannot be deleted.'
        );
    }

    $deleteStatement = $pdo->prepare('DELETE FROM items WHERE id = :id');
    $deleteStatement->execute(['id' => $itemId]);

    if ($deleteStatement->rowCount() !== 1) {
        throw new RuntimeException('The item could not be deleted.');
    }

    $pdo->commit();
    header('Location: manage-items.php?message=deleted');
    exit;
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if ($exception instanceof PDOException) {
        error_log('CampusFind item deletion failed: ' . $exception->getMessage());
        http_response_code(500);
        exit('Unable to delete the item right now.');
    }

    http_response_code(400);
    exit(htmlspecialchars(
        $exception->getMessage(),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    ));
}
