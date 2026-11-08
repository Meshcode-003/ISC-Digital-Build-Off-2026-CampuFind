<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $requestId = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT);
    $action = mentorshipInputString($_POST, 'action');
    $response = trim(mentorshipInputString($_POST, 'response'));

    if (!is_int($requestId) || $requestId < 1) {
        $errors[] = 'A valid request is required.';
    } elseif (!in_array($action, ['accept', 'decline', 'cancel', 'complete'], true)) {
        $errors[] = 'Choose a valid request action.';
    } elseif (mb_strlen($response) > 2000) {
        $errors[] = 'The response must be no more than 2,000 characters.';
    } else {
        try {
            $pdo->beginTransaction();
            $statement = $pdo->prepare(
                'SELECT id, student_user_id, mentor_user_id, status
                 FROM mentorship_requests
                 WHERE id = :request_id
                 FOR UPDATE'
            );
            $statement->execute(['request_id' => $requestId]);
            $request = $statement->fetch();
            if (!$request) {
                $errors[] = 'Request not found.';
            } else {
                $isStudent = (int) $request['student_user_id'] === $mentorshipUserId;
                $isMentor = (int) $request['mentor_user_id'] === $mentorshipUserId;
                $validTransition = (
                    ($action === 'accept' && $isMentor && $request['status'] === 'pending')
                    || ($action === 'decline' && $isMentor && $request['status'] === 'pending')
                    || ($action === 'cancel' && $isStudent && $request['status'] === 'pending')
                    || ($action === 'complete' && ($isStudent || $isMentor) && $request['status'] === 'accepted')
                );

                if (!$validTransition) {
                    $errors[] = 'You cannot perform that action on this request.';
                } else {
                    $newStatus = [
                        'accept' => 'accepted',
                        'decline' => 'declined',
                        'cancel' => 'cancelled',
                        'complete' => 'completed',
                    ][$action];
                    $update = $pdo->prepare(
                        'UPDATE mentorship_requests
                         SET status = :status,
                             mentor_response = :response,
                             responded_at = CURRENT_TIMESTAMP
                         WHERE id = :request_id'
                    );
                    $update->execute([
                        'status' => $newStatus,
                        'response' => $response !== '' ? $response : null,
                        'request_id' => $requestId,
                    ]);

                    if ($action === 'accept') {
                        $createConversation = $pdo->prepare(
                            'INSERT INTO mentorship_conversations (mentorship_request_id)
                             VALUES (:request_id)
                             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
                        );
                        $createConversation->execute(['request_id' => $requestId]);
                    }

                    $recipientId = $isStudent
                        ? (int) $request['mentor_user_id']
                        : (int) $request['student_user_id'];
                    $titles = [
                        'accepted' => 'Mentorship request accepted',
                        'declined' => 'Mentorship request declined',
                        'cancelled' => 'Mentorship request cancelled',
                        'completed' => 'Mentorship connection completed',
                    ];
                    mentorshipNotify($recipientId, $titles[$newStatus], 'A mentorship request has been ' . $newStatus . '.');
                    $pdo->commit();
                    mentorshipSetFlash('The request has been ' . $newStatus . '.');
                    mentorshipRedirect('requests.php');
                }
            }
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }
}

$incomingStatement = $pdo->prepare(
    'SELECT mr.*, u.first_name, u.last_name, u.email
     FROM mentorship_requests mr
     INNER JOIN users u ON u.id = mr.student_user_id
     WHERE mr.mentor_user_id = :user_id
     ORDER BY mr.created_at DESC
     LIMIT 100'
);
$incomingStatement->execute(['user_id' => $mentorshipUserId]);
$incoming = $incomingStatement->fetchAll();

$outgoingStatement = $pdo->prepare(
    'SELECT mr.*, u.first_name, u.last_name, mp.headline
     FROM mentorship_requests mr
     INNER JOIN users u ON u.id = mr.mentor_user_id
     INNER JOIN mentor_profiles mp ON mp.user_id = mr.mentor_user_id
     WHERE mr.student_user_id = :user_id
     ORDER BY mr.created_at DESC
     LIMIT 100'
);
$outgoingStatement->execute(['user_id' => $mentorshipUserId]);
$outgoing = $outgoingStatement->fetchAll();

mentorshipPageStart('Mentorship Requests');
?>
<h1>Mentorship requests</h1>
<p class="page-intro">Track requests you have sent and manage requests for your mentor profile.</p>
<?php foreach ($errors as $error): ?>
    <div class="flash flash-error" role="alert"><?= mentorshipEscape($error) ?></div>
<?php endforeach; ?>

<div class="two-column">
    <section class="panel">
        <h2>Requests for me</h2>
        <?php if (!$incoming): ?>
            <p class="muted">No incoming requests.</p>
        <?php endif; ?>
        <?php foreach ($incoming as $request): ?>
            <article class="panel">
                <h3><?= mentorshipEscape(trim($request['first_name'] . ' ' . $request['last_name'])) ?></h3>
                <p class="mentor-meta"><?= mentorshipEscape($request['created_at']) ?> · <span class="status-label"><?= mentorshipEscape($request['status']) ?></span></p>
                <p><?= nl2br(mentorshipEscape($request['message'])) ?></p>
                <?php if ($request['mentor_response']): ?><p><strong>Response:</strong> <?= nl2br(mentorshipEscape($request['mentor_response'])) ?></p><?php endif; ?>
                <?php if ($request['status'] === 'pending'): ?>
                    <form class="form-grid" method="post">
                        <input type="hidden" name="csrf_token" value="<?= mentorshipEscape(generateCsrfToken()) ?>">
                        <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
                        <label>Optional response
                            <textarea name="response" maxlength="2000"></textarea>
                        </label>
                        <div class="button-row">
                            <button class="button" name="action" value="accept">Accept</button>
                            <button class="button button-danger" name="action" value="decline">Decline</button>
                        </div>
                    </form>
                <?php elseif (in_array($request['status'], ['accepted', 'completed'], true)): ?>
                    <p class="button-row">
                        <a class="button" href="conversation.php?id=<?= (int) $request['id'] ?>">Open messages</a>
                        <a class="button button-secondary" href="sessions.php?id=<?= (int) $request['id'] ?>">View sessions</a>
                    </p>
                    <?php if ($request['status'] === 'accepted'): ?>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= mentorshipEscape(generateCsrfToken()) ?>">
                            <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
                            <button class="button button-secondary" name="action" value="complete">Mark completed</button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </section>

    <section class="panel">
        <h2>Requests I sent</h2>
        <?php if (!$outgoing): ?>
            <p class="muted">You have not sent a request yet.</p>
        <?php endif; ?>
        <?php foreach ($outgoing as $request): ?>
            <article class="panel">
                <h3><?= mentorshipEscape(trim($request['first_name'] . ' ' . $request['last_name'])) ?></h3>
                <p class="mentor-meta"><?= mentorshipEscape($request['headline'] ?? '') ?> · <span class="status-label"><?= mentorshipEscape($request['status']) ?></span></p>
                <p><?= nl2br(mentorshipEscape($request['message'])) ?></p>
                <?php if ($request['mentor_response']): ?><p><strong>Response:</strong> <?= nl2br(mentorshipEscape($request['mentor_response'])) ?></p><?php endif; ?>
                <?php if ($request['status'] === 'pending'): ?>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= mentorshipEscape(generateCsrfToken()) ?>">
                        <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
                        <button class="button button-secondary" name="action" value="cancel">Cancel request</button>
                    </form>
                <?php elseif (in_array($request['status'], ['accepted', 'completed'], true)): ?>
                    <p class="button-row">
                        <a class="button" href="conversation.php?id=<?= (int) $request['id'] ?>">Open messages</a>
                        <a class="button button-secondary" href="sessions.php?id=<?= (int) $request['id'] ?>">View sessions</a>
                    </p>
                    <?php if ($request['status'] === 'accepted'): ?>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= mentorshipEscape(generateCsrfToken()) ?>">
                            <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
                            <button class="button button-secondary" name="action" value="complete">Mark completed</button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </section>
</div>
<?php mentorshipPageEnd(); ?>
