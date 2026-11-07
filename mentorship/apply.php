<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';

if (!in_array($mentorshipUser['role_name'], ['student', 'staff'], true)) {
    http_response_code(403);
    exit('Only student and staff accounts can apply to become a mentor.');
}

$errors = [];
$applicationStatement = $pdo->prepare(
    'SELECT id, headline, bio, status, rejection_reason, created_at, reviewed_at
     FROM mentor_applications
     WHERE user_id = :user_id
     ORDER BY created_at DESC
     LIMIT 1'
);
$applicationStatement->execute(['user_id' => $mentorshipUserId]);
$application = $applicationStatement->fetch();
$profileStatement = $pdo->prepare(
    'SELECT user_id FROM mentor_profiles WHERE user_id = :user_id'
);
$profileStatement->execute(['user_id' => $mentorshipUserId]);
$isMentor = (bool) $profileStatement->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isMentor) {
    requireCsrfToken();
    $headline = trim(mentorshipInputString($_POST, 'headline'));
    $bio = trim(mentorshipInputString($_POST, 'bio'));
    $qualifications = trim(mentorshipInputString($_POST, 'qualifications'));
    $experience = trim(mentorshipInputString($_POST, 'experience'));
    $expertiseLines = preg_split('/\R/u', trim(mentorshipInputString($_POST, 'expertise'))) ?: [];
    $expertise = [];

    foreach ($expertiseLines as $line) {
        $parts = array_map('trim', explode('|', $line, 2));
        $name = $parts[0] ?? '';
        $description = $parts[1] ?? '';
        if ($name !== '') {
            $expertise[mb_strtolower($name)] = [
                'name' => $name,
                'description' => $description,
            ];
        }
    }

    if ($headline === '' || mb_strlen($headline) > 160) {
        $errors[] = 'Enter a headline of up to 160 characters.';
    }
    if (mb_strlen($bio) < 30 || mb_strlen($bio) > 5000) {
        $errors[] = 'Your biography must be between 30 and 5,000 characters.';
    }
    if (mb_strlen($qualifications) < 10 || mb_strlen($qualifications) > 3000) {
        $errors[] = 'Describe your qualifications in 10 to 3,000 characters.';
    }
    if (mb_strlen($experience) > 3000) {
        $errors[] = 'Experience details must be no more than 3,000 characters.';
    }
    if (count($expertise) === 0 || count($expertise) > 20) {
        $errors[] = 'Add between 1 and 20 expertise areas, one per line.';
    }
    foreach ($expertise as $area) {
        if (mb_strlen($area['name']) > 100 || mb_strlen($area['description']) > 255) {
            $errors[] = 'Each expertise name must be at most 100 characters and its description at most 255 characters.';
            break;
        }
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $pendingStatement = $pdo->prepare(
                'SELECT id FROM mentor_applications
                 WHERE user_id = :user_id AND status = \'pending\'
                 FOR UPDATE'
            );
            $pendingStatement->execute(['user_id' => $mentorshipUserId]);
            if ($pendingStatement->fetchColumn()) {
                $errors[] = 'You already have an application awaiting review.';
                $pdo->rollBack();
            } else {
                $insertApplication = $pdo->prepare(
                    'INSERT INTO mentor_applications
                        (user_id, headline, bio, qualifications, experience)
                     VALUES (:user_id, :headline, :bio, :qualifications, :experience)'
                );
                $insertApplication->execute([
                    'user_id' => $mentorshipUserId,
                    'headline' => $headline,
                    'bio' => $bio,
                    'qualifications' => $qualifications,
                    'experience' => $experience !== '' ? $experience : null,
                ]);
                $applicationId = (int) $pdo->lastInsertId();
                $insertExpertise = $pdo->prepare(
                    'INSERT INTO mentor_application_expertise (application_id, expertise_name, description)
                     VALUES (:application_id, :expertise_name, :description)'
                );
                foreach ($expertise as $area) {
                    $insertExpertise->execute([
                        'application_id' => $applicationId,
                        'expertise_name' => $area['name'],
                        'description' => $area['description'] !== '' ? $area['description'] : null,
                    ]);
                }
                $pdo->commit();
                mentorshipSetFlash('Your mentor application has been submitted for review.');
                mentorshipRedirect('apply.php');
            }
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($exception->getCode() === '23000') {
                $errors[] = 'You already have a pending mentor application.';
            } else {
                throw $exception;
            }
        }
    }
}

$expertiseStatement = $pdo->prepare(
    'SELECT expertise_name, description
     FROM mentor_application_expertise
     WHERE application_id = :application_id
     ORDER BY id'
);
if ($application) {
    $expertiseStatement->execute(['application_id' => (int) $application['id']]);
    $applicationExpertise = $expertiseStatement->fetchAll();
} else {
    $applicationExpertise = [];
}

mentorshipPageStart('Become a Mentor');
?>
<h1>Become a Mentor</h1>
<p class="page-intro">Share your experience with students. Applications are reviewed by CampusFind administrators before a profile becomes discoverable.</p>

<?php foreach ($errors as $error): ?>
    <div class="flash flash-error" role="alert"><?= mentorshipEscape($error) ?></div>
<?php endforeach; ?>

<?php if ($isMentor): ?>
    <section class="panel">
        <h2>You are an approved mentor</h2>
        <p class="muted">Manage your profile, expertise, and contact preferences from your mentor profile.</p>
        <a class="button" href="profile.php">Manage profile</a>
    </section>
<?php elseif ($application && $application['status'] === 'pending'): ?>
    <section class="panel">
        <h2>Application under review</h2>
        <p class="muted">Submitted <?= mentorshipEscape($application['created_at']) ?>. We’ll notify you when it has been reviewed.</p>
    </section>
<?php elseif ($application && $application['status'] === 'approved'): ?>
    <section class="panel">
        <h2>Application approved</h2>
        <p class="muted">Your mentor profile is ready to manage.</p>
        <a class="button" href="profile.php">Manage profile</a>
    </section>
<?php else: ?>
    <?php if ($application && $application['status'] === 'rejected'): ?>
        <section class="panel">
            <h2>Previous application not approved</h2>
            <?php if ($application['rejection_reason']): ?>
                <p><?= nl2br(mentorshipEscape($application['rejection_reason'])) ?></p>
            <?php endif; ?>
            <p class="muted">You may submit an updated application.</p>
        </section>
    <?php endif; ?>
    <form class="panel form-grid" method="post">
        <input type="hidden" name="csrf_token" value="<?= mentorshipEscape(generateCsrfToken()) ?>">
        <label>Mentor headline
            <input name="headline" maxlength="160" required value="<?= mentorshipEscape(mentorshipInputString($_POST, 'headline')) ?>" placeholder="e.g. Final-year engineering student">
        </label>
        <label>Biography
            <textarea name="bio" minlength="30" maxlength="5000" required><?= mentorshipEscape(mentorshipInputString($_POST, 'bio')) ?></textarea>
        </label>
        <label>Qualifications and relevant training
            <textarea name="qualifications" minlength="10" maxlength="3000" required><?= mentorshipEscape(mentorshipInputString($_POST, 'qualifications')) ?></textarea>
        </label>
        <label>Relevant experience (optional)
            <textarea name="experience" maxlength="3000"><?= mentorshipEscape(mentorshipInputString($_POST, 'experience')) ?></textarea>
        </label>
        <label>Areas of expertise (one per line; optionally add “ | description”)
            <textarea name="expertise" required placeholder="Career planning | CV and interview guidance&#10;Web development"><?= mentorshipEscape(mentorshipInputString($_POST, 'expertise')) ?></textarea>
        </label>
        <button class="button" type="submit">Submit application</button>
    </form>
<?php endif; ?>
<?php mentorshipPageEnd(); ?>
