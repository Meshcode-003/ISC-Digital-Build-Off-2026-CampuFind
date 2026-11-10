<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';

$requestId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!is_int($requestId) || $requestId < 1) {
    http_response_code(400);
    exit('A valid mentorship request is required.');
}

$requestStatement = $pdo->prepare(
    'SELECT mr.id, mr.student_user_id, mr.mentor_user_id, mr.status,
            student.first_name AS student_first_name, student.last_name AS student_last_name,
            mentor.first_name AS mentor_first_name, mentor.last_name AS mentor_last_name,
            c.id AS conversation_id
     FROM mentorship_requests mr
     INNER JOIN users student ON student.id = mr.student_user_id
     INNER JOIN users mentor ON mentor.id = mr.mentor_user_id
     LEFT JOIN mentorship_conversations c ON c.mentorship_request_id = mr.id
     WHERE mr.id = :request_id'
);
$requestStatement->execute(['request_id' => $requestId]);
$request = $requestStatement->fetch();
$isParticipant = $request && (
    (int) $request['student_user_id'] === $mentorshipUserId
    || (int) $request['mentor_user_id'] === $mentorshipUserId
);
if (!$isParticipant || !in_array($request['status'], ['accepted', 'completed'], true)) {
    http_response_code(403);
    exit('Messages are available only to participants in accepted mentorship connections.');
}

$conversationId = $request['conversation_id'];
if ($conversationId === null) {
    $create = $pdo->prepare(
        'INSERT INTO mentorship_conversations (mentorship_request_id)
         VALUES (:request_id)
         ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
    );
    $create->execute(['request_id' => $requestId]);
    $conversationId = (int) $pdo->lastInsertId();
}
$conversationId = (int) $conversationId;

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $message = trim(mentorshipInputString($_POST, 'message'));
    if ($message === '' || mb_strlen($message) > 4000) {
        $errors[] = 'A message is required and must be no more than 4,000 characters.';
    } else {
        $otherUserId = (int) $request['student_user_id'] === $mentorshipUserId
            ? (int) $request['mentor_user_id']
            : (int) $request['student_user_id'];
        $pdo->beginTransaction();
        try {
            $insert = $pdo->prepare(
                'INSERT INTO mentorship_messages (conversation_id, sender_user_id, message)
                 VALUES (:conversation_id, :sender_user_id, :message)'
            );
            $insert->execute([
                'conversation_id' => $conversationId,
                'sender_user_id' => $mentorshipUserId,
                'message' => $message,
            ]);
            $updateConversation = $pdo->prepare(
                'UPDATE mentorship_conversations SET updated_at = CURRENT_TIMESTAMP WHERE id = :id'
            );
            $updateConversation->execute(['id' => $conversationId]);
            if (!mentorshipConversationIsActive($pdo, $conversationId, $otherUserId)) {
                createNotification(
                    $pdo,
                    $otherUserId,
                    'New mentorship message',
                    'You have a new message in your mentorship conversation.',
                    'message',
                    APP_BASE_PATH . '/mentorship/conversation.php?id=' . $requestId,
                    'mentorship-conversation:' . $conversationId,
                    refreshExisting: true
                );
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
        mentorshipRedirect('conversation.php?id=' . $requestId);
    }
}

$messageStatement = $pdo->prepare(
    'SELECT m.id, m.sender_user_id, m.message, m.created_at,
            u.first_name, u.last_name
     FROM mentorship_messages m
     INNER JOIN users u ON u.id = m.sender_user_id
     WHERE m.conversation_id = :conversation_id
     ORDER BY m.id DESC
     LIMIT 100'
);
$messageStatement->execute(['conversation_id' => (int) $conversationId]);
$messages = array_reverse($messageStatement->fetchAll());
$otherName = (int) $request['student_user_id'] === $mentorshipUserId
    ? trim($request['mentor_first_name'] . ' ' . $request['mentor_last_name'])
    : trim($request['student_first_name'] . ' ' . $request['student_last_name']);

mentorshipPageStart('Mentorship Messages');
?>
<p><a class="mentor-link" href="requests.php">← Requests</a></p>
<h1>Conversation with <?= mentorshipEscape($otherName) ?></h1>
<?php foreach ($errors as $error): ?>
    <div class="flash flash-error" role="alert"><?= mentorshipEscape($error) ?></div>
<?php endforeach; ?>

<section class="panel">
    <div id="message-list" class="message-list" aria-live="polite">
        <?php foreach ($messages as $message): ?>
            <article class="message <?= (int) $message['sender_user_id'] === $mentorshipUserId ? 'message-own' : '' ?>" data-message-id="<?= (int) $message['id'] ?>">
                <strong><?= mentorshipEscape(trim($message['first_name'] . ' ' . $message['last_name'])) ?></strong>
                <div><?= nl2br(mentorshipEscape($message['message'])) ?></div>
                <small><?= mentorshipEscape($message['created_at']) ?></small>
            </article>
        <?php endforeach; ?>
        <?php if (!$messages): ?><p class="muted" id="empty-messages">Start the conversation with a message.</p><?php endif; ?>
    </div>
    <form class="form-grid" method="post">
        <input type="hidden" name="csrf_token" value="<?= mentorshipEscape(generateCsrfToken()) ?>">
        <label>Message
            <textarea name="message" maxlength="4000" required></textarea>
        </label>
        <button class="button" type="submit">Send message</button>
    </form>
</section>

<script>
(() => {
    const list = document.getElementById('message-list');
    const csrf = <?= json_encode(generateCsrfToken(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    let lastId = Math.max(0, ...Array.from(list.querySelectorAll('[data-message-id]'), item => Number(item.dataset.messageId)));
    let polling = false;

    async function poll() {
        if (polling || document.visibilityState !== 'visible') return;
        polling = true;
        try {
            const body = new URLSearchParams({
                csrf_token: csrf,
                request_id: <?= (int) $requestId ?>,
                after: lastId
            });
            const response = await fetch('messages.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body
            });
            if (!response.ok) return;
            const data = await response.json();
            for (const message of data.messages) {
                if (message.id <= lastId) continue;
                const article = document.createElement('article');
                article.className = 'message' + (message.sender_user_id === <?= $mentorshipUserId ?> ? ' message-own' : '');
                article.dataset.messageId = message.id;
                const author = document.createElement('strong');
                author.textContent = message.sender_name;
                const content = document.createElement('div');
                content.textContent = message.message;
                const time = document.createElement('small');
                time.textContent = message.created_at;
                article.append(author, content, time);
                document.getElementById('empty-messages')?.remove();
                list.append(article);
                lastId = message.id;
            }
            list.scrollTop = list.scrollHeight;
        } catch (error) {
            console.error('Unable to refresh mentorship messages.', error);
        } finally {
            polling = false;
        }
    }
    poll();
    window.setInterval(poll, 8000);
})();
</script>
<?php mentorshipPageEnd(); ?>
