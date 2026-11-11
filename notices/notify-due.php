<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/notices.php';

$statement = $pdo->query(
    "SELECT id
     FROM notices
     WHERE status = 'published'
       AND published_at IS NOT NULL
       AND published_at <= CURRENT_TIMESTAMP
       AND (expires_at IS NULL OR expires_at >= CURRENT_TIMESTAMP)
     ORDER BY id"
);
$noticeIds = $statement->fetchAll(PDO::FETCH_COLUMN);

foreach ($noticeIds as $noticeId) {
    $pdo->beginTransaction();
    try {
        notifyNoticeAudience((int) $noticeId);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}

echo 'Processed ', count($noticeIds), ' published notices.', PHP_EOL;
