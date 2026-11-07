<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}
requireCsrfToken();

$mentorId = filter_input(INPUT_POST, 'mentor_user_id', FILTER_VALIDATE_INT);
$message = trim(mentorshipInputString($_POST, 'message'));
if (!is_int($mentorId) || $mentorId < 1 || mb_strlen($message) < 10 || mb_strlen($message) > 2000) {
    mentorshipSetFlash('Choose a valid mentor and write a message between 10 and 2,000 characters.', 'error');
    mentorshipRedirect('index.php');
}
if ($mentorId === $mentorshipUserId) {
    mentorshipSetFlash('You cannot request mentorship from your own profile.', 'error');
    mentorshipRedirect('index.php');
}
if (!in_array($mentorshipUser['role_name'], ['student', 'staff'], true)) {
    http_response_code(403);
    exit('Only student and staff accounts can request mentorship.');
}

try {
    $pdo->beginTransaction();
    $mentorStatement = $pdo->prepare(
        'SELECT mp.user_id, u.account_status, r.role_name
         FROM mentor_profiles mp
         INNER JOIN users u ON u.id = mp.user_id
         INNER JOIN roles r ON r.id = u.role_id
         WHERE mp.user_id = :mentor_id
           AND mp.status = \'active\'
           AND mp.accepting_requests = 1
           AND u.account_status = \'active\'
           AND r.role_name IN (\'student\', \'staff\')
         FOR UPDATE'
    );
    $mentorStatement->execute(['mentor_id' => $mentorId]);
    if (!$mentorStatement->fetch()) {
        $pdo->rollBack();
        mentorshipSetFlash('This mentor is not currently accepting requests.', 'error');
        mentorshipRedirect('index.php');
    }

    $existingStatement = $pdo->prepare(
        'SELECT id FROM mentorship_requests
         WHERE student_user_id = :student_id
           AND mentor_user_id = :mentor_id
           AND status IN (\'pending\', \'accepted\')
         LIMIT 1'
    );
    $existingStatement->execute([
        'student_id' => $mentorshipUserId,
        'mentor_id' => $mentorId,
    ]);
    if ($existingStatement->fetchColumn()) {
        $pdo->rollBack();
        mentorshipSetFlash('You already have a pending or accepted request with this mentor.', 'error');
        mentorshipRedirect('requests.php');
    }

    $insert = $pdo->prepare(
        'INSERT INTO mentorship_requests (student_user_id, mentor_user_id, message)
         VALUES (:student_id, :mentor_id, :message)'
    );
    $insert->execute([
        'student_id' => $mentorshipUserId,
        'mentor_id' => $mentorId,
        'message' => $message,
    ]);
    mentorshipNotify(
        $mentorId,
        'New mentorship request',
        $mentorshipUser['first_name'] . ' ' . $mentorshipUser['last_name'] . ' sent you a mentorship request.'
    );
    $pdo->commit();
    mentorshipSetFlash('Your mentorship request has been sent.');
    mentorshipRedirect('requests.php');
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $exception;
}
