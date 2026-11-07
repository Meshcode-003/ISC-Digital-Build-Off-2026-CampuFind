<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_common.php';
requireAdmin();

$applicationId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!is_int($applicationId) || $applicationId < 1) {
    http_response_code(400);
    exit('A valid application ID is required.');
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $decision = mentorshipInputString($_POST, 'decision');
    $reason = trim(mentorshipInputString($_POST, 'rejection_reason'));
    if (!in_array($decision, ['approved', 'rejected'], true)) {
        $errors[] = 'Choose approve or reject.';
    } elseif ($decision === 'rejected' && ($reason === '' || mb_strlen($reason) > 2000)) {
        $errors[] = 'Provide a rejection reason of up to 2,000 characters.';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $lock = $pdo->prepare(
                'SELECT a.*, u.first_name, u.last_name, u.account_status, r.role_name
                 FROM mentor_applications a
                 INNER JOIN users u ON u.id = a.user_id
                 INNER JOIN roles r ON r.id = u.role_id
                 WHERE a.id = :application_id
                 FOR UPDATE'
            );
            $lock->execute(['application_id' => $applicationId]);
            $lockedApplication = $lock->fetch();
            if (!$lockedApplication || $lockedApplication['status'] !== 'pending') {
                $pdo->rollBack();
                $errors[] = 'This application has already been reviewed or no longer exists.';
            } else {
                $applicantId = (int) $lockedApplication['user_id'];
                if ($decision === 'approved' && (
                    $lockedApplication['account_status'] !== 'active'
                    || !in_array($lockedApplication['role_name'], ['student', 'staff'], true)
                )) {
                    $pdo->rollBack();
                    $errors[] = 'Only active student and staff accounts can be approved as mentors.';
                } elseif ($decision === 'approved') {
                    $profileCheck = $pdo->prepare(
                        'SELECT user_id FROM mentor_profiles WHERE user_id = :user_id FOR UPDATE'
                    );
                    $profileCheck->execute(['user_id' => $applicantId]);
                    if ($profileCheck->fetchColumn()) {
                        $pdo->rollBack();
                        $errors[] = 'This applicant already has a mentor profile; the application was not changed.';
                    } else {
                        $createProfile = $pdo->prepare(
                            'INSERT INTO mentor_profiles (user_id, headline, bio, status, accepting_requests)
                             VALUES (:user_id, :headline, :bio, \'active\', 1)'
                        );
                        $createProfile->execute([
                            'user_id' => $applicantId,
                            'headline' => $lockedApplication['headline'],
                            'bio' => $lockedApplication['bio'],
                        ]);
                        $copyExpertise = $pdo->prepare(
                            'INSERT INTO mentor_expertise (mentor_user_id, expertise_name, description)
                             SELECT :mentor_user_id, expertise_name, description
                             FROM mentor_application_expertise
                             WHERE application_id = :application_id'
                        );
                        $copyExpertise->execute([
                            'mentor_user_id' => $applicantId,
                            'application_id' => $applicationId,
                        ]);
                    }
                }

                if (!$errors) {
                    $update = $pdo->prepare(
                        'UPDATE mentor_applications
                         SET status = :status,
                             reviewed_by = :reviewed_by,
                             reviewed_at = CURRENT_TIMESTAMP,
                             rejection_reason = :rejection_reason
                         WHERE id = :application_id AND status = \'pending\''
                    );
                    $update->execute([
                        'status' => $decision,
                        'reviewed_by' => $mentorshipUserId,
                        'rejection_reason' => $decision === 'rejected' ? $reason : null,
                        'application_id' => $applicationId,
                    ]);
                    mentorshipNotify(
                        $applicantId,
                        $decision === 'approved' ? 'Mentor application approved' : 'Mentor application update',
                        $decision === 'approved'
                            ? 'Your mentor application was approved. Your profile is now available to manage.'
                            : 'Your mentor application was not approved. Review the feedback in your application page.'
                    );
                    $pdo->commit();
                    mentorshipSetFlash('The application has been ' . $decision . '.');
                    mentorshipRedirect('applications.php');
                }
            }
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }
}

$applicationStatement = $pdo->prepare(
    'SELECT a.*, u.first_name, u.last_name, u.email, u.username,
            reviewer.first_name AS reviewer_first_name,
            reviewer.last_name AS reviewer_last_name
     FROM mentor_applications a
     INNER JOIN users u ON u.id = a.user_id
     LEFT JOIN users reviewer ON reviewer.id = a.reviewed_by
     WHERE a.id = :application_id'
);
$applicationStatement->execute(['application_id' => $applicationId]);
$application = $applicationStatement->fetch();
if (!$application) {
    http_response_code(404);
    exit('Application not found.');
}
$expertiseStatement = $pdo->prepare(
    'SELECT expertise_name, description
     FROM mentor_application_expertise
     WHERE application_id = :application_id
     ORDER BY expertise_name'
);
$expertiseStatement->execute(['application_id' => $applicationId]);
$expertise = $expertiseStatement->fetchAll();

mentorshipPageStart('Review Mentor Application');
?>
<p><a class="mentor-link" href="applications.php">← All applications</a></p>
<h1>Review application</h1>
<?php foreach ($errors as $error): ?>
    <div class="flash flash-error" role="alert"><?= mentorshipEscape($error) ?></div>
<?php endforeach; ?>

<section class="panel">
    <h2><?= mentorshipEscape(trim($application['first_name'] . ' ' . $application['last_name'])) ?></h2>
    <p class="muted"><?= mentorshipEscape($application['email']) ?> · <?= mentorshipEscape($application['username']) ?></p>
    <p><strong>Status:</strong> <span class="status-label"><?= mentorshipEscape($application['status']) ?></span></p>
    <h3><?= mentorshipEscape($application['headline']) ?></h3>
    <p><?= nl2br(mentorshipEscape($application['bio'])) ?></p>
    <h3>Qualifications</h3>
    <p><?= nl2br(mentorshipEscape($application['qualifications'])) ?></p>
    <?php if ($application['experience']): ?>
        <h3>Experience</h3>
        <p><?= nl2br(mentorshipEscape($application['experience'])) ?></p>
    <?php endif; ?>
    <h3>Expertise</h3>
    <ul>
        <?php foreach ($expertise as $area): ?>
            <li><strong><?= mentorshipEscape($area['expertise_name']) ?></strong><?= $area['description'] ? ' — ' . mentorshipEscape($area['description']) : '' ?></li>
        <?php endforeach; ?>
    </ul>
</section>

<?php if ($application['status'] === 'pending'): ?>
    <form class="panel form-grid" method="post">
        <input type="hidden" name="csrf_token" value="<?= mentorshipEscape(generateCsrfToken()) ?>">
        <label>Rejection feedback (required when rejecting)
            <textarea name="rejection_reason" maxlength="2000"><?= mentorshipEscape(mentorshipInputString($_POST, 'rejection_reason')) ?></textarea>
        </label>
        <div class="button-row">
            <button class="button" type="submit" name="decision" value="approved">Approve application</button>
            <button class="button button-danger" type="submit" name="decision" value="rejected">Reject with feedback</button>
        </div>
    </form>
<?php elseif ($application['reviewed_at']): ?>
    <section class="panel">
        <p class="muted">Reviewed <?= mentorshipEscape($application['reviewed_at']) ?> by <?= mentorshipEscape(trim(($application['reviewer_first_name'] ?? '') . ' ' . ($application['reviewer_last_name'] ?? ''))) ?>.</p>
        <?php if ($application['rejection_reason']): ?>
            <p><?= nl2br(mentorshipEscape($application['rejection_reason'])) ?></p>
        <?php endif; ?>
    </section>
<?php endif; ?>
<?php mentorshipPageEnd(); ?>
