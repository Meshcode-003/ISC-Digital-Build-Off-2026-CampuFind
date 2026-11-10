<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/notices.php';
require_once dirname(__DIR__, 2) . '/includes/csrf.php';

requireModuleAdmin('notices');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}
requireCsrfToken();

$noticeId = filter_var($_POST['notice_id'] ?? null, FILTER_VALIDATE_INT);
$action = $_POST['action'] ?? '';
if (!$noticeId || $noticeId < 1 || !is_string($action)) {
    http_response_code(400);
    exit('Invalid notice action.');
}

$updates = [
    'publish' => "UPDATE notices SET status = 'published', published_at = CURRENT_TIMESTAMP WHERE id = :id AND status = 'draft' AND (expires_at IS NULL OR expires_at >= CURRENT_TIMESTAMP)",
    'archive' => "UPDATE notices SET status = 'archived' WHERE id = :id AND status <> 'archived'",
    'pin' => 'UPDATE notices SET is_pinned = 1 WHERE id = :id',
    'unpin' => 'UPDATE notices SET is_pinned = 0 WHERE id = :id',
];
if (!isset($updates[$action])) {
    http_response_code(400);
    exit('Unsupported notice action.');
}

$pdo->beginTransaction();
try {
    $statement = $pdo->prepare($updates[$action]);
    $statement->execute(['id' => $noticeId]);
    if ($action === 'publish') {
        notifyNoticeAudience((int) $noticeId);
    }
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Notice action failed: ' . $exception->getMessage());
    throw $exception;
}

if ($statement->rowCount() === 0) {
    $exists = $pdo->prepare('SELECT 1 FROM notices WHERE id = :id');
    $exists->execute(['id' => $noticeId]);
    if (!$exists->fetchColumn()) {
        http_response_code(404);
        exit('Notice not found.');
    }
    if ($action === 'publish') {
        $expiredDraft = $pdo->prepare(
            "SELECT 1 FROM notices
             WHERE id = :id AND status = 'draft'
               AND expires_at < CURRENT_TIMESTAMP"
        );
        $expiredDraft->execute(['id' => $noticeId]);
        if ($expiredDraft->fetchColumn()) {
            http_response_code(422);
            exit('Update the expiry date before publishing this expired draft.');
        }
    }
}

header('Location: ' . NOTICES_URL . '/admin/');
exit;
