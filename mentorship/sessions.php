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
            mentor.first_name AS mentor_first_name, mentor.last_name AS mentor_last_name
     FROM mentorship_requests mr
     INNER JOIN users student ON student.id = mr.student_user_id
     INNER JOIN users mentor ON mentor.id = mr.mentor_user_id
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
    exit('Sessions are available only to participants in accepted mentorship connections.');
}

$otherUserId = (int) $request['student_user_id'] === $mentorshipUserId
    ? (int) $request['mentor_user_id']
    : (int) $request['student_user_id'];
$otherName = (int) $request['student_user_id'] === $mentorshipUserId
    ? trim($request['mentor_first_name'] . ' ' . $request['mentor_last_name'])
    : trim($request['student_first_name'] . ' ' . $request['student_last_name']);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $action = mentorshipInputString($_POST, 'action');

    if ($action === 'schedule' && $request['status'] === 'accepted') {
        $title = trim(mentorshipInputString($_POST, 'title'));
        $description = trim(mentorshipInputString($_POST, 'description'));
        $scheduledInput = mentorshipInputString($_POST, 'scheduled_at');
        $duration = filter_var($_POST['duration_minutes'] ?? null, FILTER_VALIDATE_INT);
        $method = mentorshipInputString($_POST, 'meeting_method');
        $location = trim(mentorshipInputString($_POST, 'location_or_meeting_link'));
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $scheduledInput);
        $dateErrors = DateTimeImmutable::getLastErrors();
        $validDate = $date !== false
            && ($dateErrors === false || ($dateErrors['warning_count'] === 0 && $dateErrors['error_count'] === 0))
            && $date->format('Y-m-d\TH:i') === $scheduledInput;

        if ($title === '' || mb_strlen($title) > 150) {
            $errors[] = 'Enter a session title of up to 150 characters.';
        }
        if (mb_strlen($description) > 3000) {
            $errors[] = 'Session details must be no more than 3,000 characters.';
        }
        if (!$validDate || $date < new DateTimeImmutable('now')) {
            $errors[] = 'Choose a valid date and time in the future.';
        }
        if (!is_int($duration) || $duration < 15 || $duration > 480) {
            $errors[] = 'Session duration must be between 15 and 480 minutes.';
        }
        if (!in_array($method, ['platform', 'whatsapp', 'physical'], true)) {
            $errors[] = 'Choose a valid meeting method.';
        }
        if (($method === 'physical' || $method === 'platform') && $location === '') {
            $errors[] = 'Provide a location or meeting link.';
        }
        if (mb_strlen($location) > 500) {
            $errors[] = 'The location or meeting link must be no more than 500 characters.';
        }
        if ($method === 'platform' && (
            filter_var($location, FILTER_VALIDATE_URL) === false
            || !str_starts_with(strtolower($location), 'https://')
        )) {
            $errors[] = 'Online session links must be valid HTTPS URLs.';
        }
        if ($method === 'whatsapp' && (int) $request['mentor_user_id'] !== $mentorshipUserId) {
            $preference = $pdo->prepare(
                'SELECT whatsapp_enabled FROM mentor_contact_preferences WHERE user_id = :user_id'
            );
            $preference->execute(['user_id' => (int) $request['mentor_user_id']]);
            if (!(bool) $preference->fetchColumn()) {
                $errors[] = 'The mentor has not enabled WhatsApp contact.';
            }
        }

        if (!$errors) {
            $pdo->beginTransaction();
            try {
                $insert = $pdo->prepare(
                    'INSERT INTO mentorship_sessions
                        (mentorship_request_id, created_by, title, description, scheduled_at,
                         duration_minutes, meeting_method, location_or_meeting_link)
                     VALUES
                        (:request_id, :created_by, :title, :description, :scheduled_at,
                         :duration, :method, :location)'
                );
                $insert->execute([
                    'request_id' => $requestId,
                    'created_by' => $mentorshipUserId,
                    'title' => $title,
                    'description' => $description !== '' ? $description : null,
                    'scheduled_at' => $date->format('Y-m-d H:i:s'),
                    'duration' => $duration,
                    'method' => $method,
                    'location' => $location !== '' ? $location : null,
                ]);
                $sessionId = (int) $pdo->lastInsertId();
                createNotification(
                    $pdo,
                    $otherUserId,
                    'Mentorship session scheduled',
                    'A session has been scheduled in your mentorship connection.',
                    'mentorship',
                    APP_BASE_PATH . '/mentorship/sessions.php?id=' . $requestId,
                    'mentorship-session:' . $sessionId . ':scheduled'
                );
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
            mentorshipSetFlash('The mentorship session has been scheduled.');
            mentorshipRedirect('sessions.php?id=' . $requestId);
        }
    } elseif (in_array($action, ['complete', 'cancel'], true)) {
        $sessionId = filter_input(INPUT_POST, 'session_id', FILTER_VALIDATE_INT);
        if (!is_int($sessionId) || $sessionId < 1) {
            $errors[] = 'A valid session is required.';
        } else {
            $pdo->beginTransaction();
            $lock = $pdo->prepare(
                'SELECT id, created_by, status
                 FROM mentorship_sessions
                 WHERE id = :session_id AND mentorship_request_id = :request_id
                 FOR UPDATE'
            );
            $lock->execute(['session_id' => $sessionId, 'request_id' => $requestId]);
            $session = $lock->fetch();
            if (!$session || $session['status'] !== 'scheduled') {
                $errors[] = 'This session is no longer scheduled.';
            } elseif ($action === 'cancel' && (int) $session['created_by'] !== $mentorshipUserId) {
                $errors[] = 'Only the person who scheduled a session can cancel it.';
            } else {
                $newStatus = $action === 'complete' ? 'completed' : 'cancelled';
                $update = $pdo->prepare(
                    'UPDATE mentorship_sessions SET status = :status WHERE id = :session_id'
                );
                $update->execute(['status' => $newStatus, 'session_id' => $sessionId]);
                createNotification(
                    $pdo,
                    $otherUserId,
                    'Mentorship session ' . $newStatus,
                    'A scheduled session has been ' . $newStatus . '.',
                    'mentorship',
                    APP_BASE_PATH . '/mentorship/sessions.php?id=' . $requestId,
                    'mentorship-session:' . $sessionId . ':' . $newStatus
                );
                $pdo->commit();
                mentorshipSetFlash('The session has been ' . $newStatus . '.');
                mentorshipRedirect('sessions.php?id=' . $requestId);
            }
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    } else {
        http_response_code(400);
        exit('Invalid session action or connection is no longer active.');
    }
}

$sessionsStatement = $pdo->prepare(
    'SELECT s.*, u.first_name, u.last_name
     FROM mentorship_sessions s
     INNER JOIN users u ON u.id = s.created_by
     WHERE s.mentorship_request_id = :request_id
     ORDER BY s.scheduled_at DESC
     LIMIT 100'
);
$sessionsStatement->execute(['request_id' => $requestId]);
$sessions = $sessionsStatement->fetchAll();

mentorshipPageStart('Mentorship Sessions');
?>
<p><a class="mentor-link" href="requests.php">← Requests</a></p>
<h1>Sessions with <?= mentorshipEscape($otherName) ?></h1>
<p class="page-intro">Schedule and track meetings for this accepted mentorship connection.</p>
<?php foreach ($errors as $error): ?>
    <div class="flash flash-error" role="alert"><?= mentorshipEscape($error) ?></div>
<?php endforeach; ?>

<?php if ($request['status'] === 'accepted'): ?>
    <form class="panel form-grid" method="post">
        <h2>Schedule a session</h2>
        <input type="hidden" name="csrf_token" value="<?= mentorshipEscape(generateCsrfToken()) ?>">
        <input type="hidden" name="action" value="schedule">
        <label>Session title
            <input name="title" maxlength="150" required>
        </label>
        <label>Details (optional)
            <textarea name="description" maxlength="3000"></textarea>
        </label>
        <label>Date and time (CampusFind server local time)
            <input type="datetime-local" name="scheduled_at" required>
        </label>
        <label>Duration
            <select name="duration_minutes">
                <option value="30">30 minutes</option>
                <option value="60" selected>1 hour</option>
                <option value="90">90 minutes</option>
                <option value="120">2 hours</option>
            </select>
        </label>
        <label>Meeting method
            <select name="meeting_method">
                <option value="platform">Online meeting</option>
                <option value="whatsapp">WhatsApp call/chat</option>
                <option value="physical">In person</option>
            </select>
        </label>
        <label>Meeting link or location
            <input name="location_or_meeting_link" maxlength="500" placeholder="Required for an online or in-person session">
        </label>
        <button class="button" type="submit">Schedule session</button>
    </form>
<?php endif; ?>

<section class="panel">
    <h2>Scheduled sessions</h2>
    <?php if (!$sessions): ?><p class="muted">No sessions have been scheduled.</p><?php endif; ?>
    <?php foreach ($sessions as $session): ?>
        <article class="panel">
            <h3><?= mentorshipEscape($session['title']) ?></h3>
            <p class="mentor-meta"><?= mentorshipEscape($session['scheduled_at']) ?> · <?= (int) $session['duration_minutes'] ?> minutes · <?= mentorshipEscape($session['meeting_method']) ?> · <span class="status-label"><?= mentorshipEscape($session['status']) ?></span></p>
            <?php if ($session['description']): ?><p><?= nl2br(mentorshipEscape($session['description'])) ?></p><?php endif; ?>
            <?php if ($session['location_or_meeting_link']): ?>
                <?php if ($session['meeting_method'] === 'platform' && filter_var($session['location_or_meeting_link'], FILTER_VALIDATE_URL)): ?>
                    <p><a class="mentor-link" href="<?= mentorshipEscape($session['location_or_meeting_link']) ?>" target="_blank" rel="noopener noreferrer">Open meeting link</a></p>
                <?php else: ?>
                    <p><?= mentorshipEscape($session['location_or_meeting_link']) ?></p>
                <?php endif; ?>
            <?php endif; ?>
            <p class="muted">Scheduled by <?= mentorshipEscape(trim($session['first_name'] . ' ' . $session['last_name'])) ?></p>
            <?php if ($session['status'] === 'scheduled'): ?>
                <div class="button-row">
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= mentorshipEscape(generateCsrfToken()) ?>">
                        <input type="hidden" name="session_id" value="<?= (int) $session['id'] ?>">
                        <button class="button button-secondary" name="action" value="complete">Mark complete</button>
                    </form>
                    <?php if ((int) $session['created_by'] === $mentorshipUserId): ?>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= mentorshipEscape(generateCsrfToken()) ?>">
                            <input type="hidden" name="session_id" value="<?= (int) $session['id'] ?>">
                            <button class="button button-danger" name="action" value="cancel">Cancel session</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
</section>
<?php mentorshipPageEnd(); ?>
