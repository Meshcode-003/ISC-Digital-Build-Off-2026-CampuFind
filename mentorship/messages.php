<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}
requireCsrfToken();

$requestId = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT);
$afterId = filter_input(INPUT_POST, 'after', FILTER_VALIDATE_INT);
if (!is_int($requestId) || $requestId < 1 || !is_int($afterId) || $afterId < 0) {
    http_response_code(400);
    exit('Invalid message cursor.');
}

$requestStatement = $pdo->prepare(
    'SELECT id, student_user_id, mentor_user_id, status
     FROM mentorship_requests
     WHERE id = :request_id'
);
$requestStatement->execute(['request_id' => $requestId]);
$request = $requestStatement->fetch();
$isParticipant = $request && (
    (int) $request['student_user_id'] === $mentorshipUserId
    || (int) $request['mentor_user_id'] === $mentorshipUserId
);
if (!$isParticipant || !in_array($request['status'], ['accepted', 'completed'], true)) {
    http_response_code(403);
    exit('You cannot access this conversation.');
}

$conversationStatement = $pdo->prepare(
    'SELECT id FROM mentorship_conversations WHERE mentorship_request_id = :request_id'
);
$conversationStatement->execute(['request_id' => $requestId]);
$conversationId = $conversationStatement->fetchColumn();
if (!$conversationId) {
    http_response_code(404);
    exit('Conversation not found.');
}

$messageStatement = $pdo->prepare(
    'SELECT m.id, m.sender_user_id, m.message, m.created_at,
            CONCAT(u.first_name, \' \', u.last_name) AS sender_name
     FROM mentorship_messages m
     INNER JOIN users u ON u.id = m.sender_user_id
     WHERE m.conversation_id = :conversation_id
       AND m.id > :after_id
     ORDER BY m.id ASC
     LIMIT 50'
);
$messageStatement->bindValue(':conversation_id', (int) $conversationId, PDO::PARAM_INT);
$messageStatement->bindValue(':after_id', $afterId, PDO::PARAM_INT);
$messageStatement->execute();
$messages = $messageStatement->fetchAll();

if ($messages) {
    $lastMessageId = (int) end($messages)['id'];
    $readStatement = $pdo->prepare(
        'UPDATE mentorship_messages
         SET read_at = CURRENT_TIMESTAMP
         WHERE conversation_id = :conversation_id
           AND sender_user_id <> :user_id
           AND read_at IS NULL
           AND id <= :last_message_id'
    );
    $readStatement->execute([
        'conversation_id' => (int) $conversationId,
        'user_id' => $mentorshipUserId,
        'last_message_id' => $lastMessageId,
    ]);
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(
    ['messages' => $messages],
    JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
