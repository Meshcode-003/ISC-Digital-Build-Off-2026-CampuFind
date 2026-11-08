<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';

$profileStatement = $pdo->prepare(
    'SELECT headline, bio, status, accepting_requests
     FROM mentor_profiles
     WHERE user_id = :user_id'
);
$profileStatement->execute(['user_id' => $mentorshipUserId]);
$profile = $profileStatement->fetch();
$errors = [];

if (!$profile) {
    mentorshipPageStart('My Mentor Profile');
    ?>
    <h1>My Mentor Profile</h1>
    <section class="panel">
        <p class="muted">An approved mentor application is required before you can manage a public profile.</p>
        <a class="button" href="apply.php">Apply to become a mentor</a>
    </section>
    <?php
    mentorshipPageEnd();
    exit;
}

$expertiseStatement = $pdo->prepare(
    'SELECT expertise_name, description
     FROM mentor_expertise
     WHERE mentor_user_id = :user_id
     ORDER BY expertise_name'
);
$expertiseStatement->execute(['user_id' => $mentorshipUserId]);
$expertiseRows = $expertiseStatement->fetchAll();
$preferenceStatement = $pdo->prepare(
    'SELECT whatsapp_enabled
     FROM mentor_contact_preferences
     WHERE user_id = :user_id'
);
$preferenceStatement->execute(['user_id' => $mentorshipUserId]);
$whatsAppEnabled = (bool) $preferenceStatement->fetchColumn();
$normalizedPhone = mentorshipNormalizePhone($mentorshipUser['phone']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $action = mentorshipInputString($_POST, 'action');

    if ($action === 'save_profile') {
        $headline = trim(mentorshipInputString($_POST, 'headline'));
        $bio = trim(mentorshipInputString($_POST, 'bio'));
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
                $updateProfile = $pdo->prepare(
                    'UPDATE mentor_profiles
                     SET headline = :headline, bio = :bio
                     WHERE user_id = :user_id'
                );
                $updateProfile->execute([
                    'headline' => $headline,
                    'bio' => $bio,
                    'user_id' => $mentorshipUserId,
                ]);
                $deleteExpertise = $pdo->prepare(
                    'DELETE FROM mentor_expertise WHERE mentor_user_id = :user_id'
                );
                $deleteExpertise->execute(['user_id' => $mentorshipUserId]);
                $insertExpertise = $pdo->prepare(
                    'INSERT INTO mentor_expertise (mentor_user_id, expertise_name, description)
                     VALUES (:user_id, :expertise_name, :description)'
                );
                foreach ($expertise as $area) {
                    $insertExpertise->execute([
                        'user_id' => $mentorshipUserId,
                        'expertise_name' => $area['name'],
                        'description' => $area['description'] !== '' ? $area['description'] : null,
                    ]);
                }
                $pdo->commit();
                mentorshipSetFlash('Your mentor profile has been updated.');
                mentorshipRedirect('profile.php');
            } catch (PDOException $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($exception->getCode() === '23000') {
                    $errors[] = 'Expertise areas must be unique.';
                } else {
                    throw $exception;
                }
            }
        }
        $profile['headline'] = $headline;
        $profile['bio'] = $bio;
        $expertiseRows = array_values($expertise);
    } elseif ($action === 'save_preferences') {
        $acceptingRequests = ($_POST['accepting_requests'] ?? '') === '1' ? 1 : 0;
        $whatsAppEnabled = ($_POST['whatsapp_enabled'] ?? '') === '1';
        if ($whatsAppEnabled && $normalizedPhone === null) {
            $errors[] = 'Add a valid phone number to your CampusFind account before enabling WhatsApp contact.';
        } else {
            $pdo->beginTransaction();
            $updateProfile = $pdo->prepare(
                'UPDATE mentor_profiles
                 SET accepting_requests = :accepting_requests
                 WHERE user_id = :user_id'
            );
            $updateProfile->execute([
                'accepting_requests' => $acceptingRequests,
                'user_id' => $mentorshipUserId,
            ]);
            $savePreference = $pdo->prepare(
                'INSERT INTO mentor_contact_preferences (user_id, whatsapp_enabled)
                 VALUES (:user_id, :whatsapp_enabled)
                 ON DUPLICATE KEY UPDATE whatsapp_enabled = VALUES(whatsapp_enabled)'
            );
            $savePreference->execute([
                'user_id' => $mentorshipUserId,
                'whatsapp_enabled' => $whatsAppEnabled ? 1 : 0,
            ]);
            $updateVisibility = $pdo->prepare(
                'UPDATE mentor_profiles
                 SET status = :status
                 WHERE user_id = :user_id'
            );
            $updateVisibility->execute([
                'status' => ($_POST['profile_active'] ?? '') === '1' ? 'active' : 'inactive',
                'user_id' => $mentorshipUserId,
            ]);
            $pdo->commit();
            mentorshipSetFlash('Your mentor contact preferences have been saved.');
            mentorshipRedirect('profile.php');
        }
    } else {
        http_response_code(400);
        exit('Invalid profile action.');
    }
}

$profile['accepting_requests'] = (int) $profile['accepting_requests'];
mentorshipPageStart('My Mentor Profile');
?>
<h1>My Mentor Profile</h1>
<p class="page-intro">Keep your mentor details current. Only active mentors accepting requests appear in discovery.</p>

<?php foreach ($errors as $error): ?>
    <div class="flash flash-error" role="alert"><?= mentorshipEscape($error) ?></div>
<?php endforeach; ?>

<form class="panel form-grid" method="post">
    <input type="hidden" name="csrf_token" value="<?= mentorshipEscape(generateCsrfToken()) ?>">
    <input type="hidden" name="action" value="save_profile">
    <label>Headline
        <input name="headline" maxlength="160" required value="<?= mentorshipEscape((string) $profile['headline']) ?>">
    </label>
    <label>Biography
        <textarea name="bio" minlength="30" maxlength="5000" required><?= mentorshipEscape((string) $profile['bio']) ?></textarea>
    </label>
    <label>Areas of expertise (one per line; optionally add “ | description”)
        <textarea name="expertise" required><?php
        foreach ($expertiseRows as $area) {
            echo mentorshipEscape($area['expertise_name'] ?? $area['name']);
            if (!empty($area['description'])) {
                echo ' | ' . mentorshipEscape($area['description']);
            }
            echo "\n";
        }
        ?></textarea>
    </label>
    <button class="button" type="submit">Save mentor profile</button>
</form>

<form class="panel form-grid" method="post">
    <h2>Availability and contact</h2>
    <input type="hidden" name="csrf_token" value="<?= mentorshipEscape(generateCsrfToken()) ?>">
    <input type="hidden" name="action" value="save_preferences">
    <label>
        <span><input type="checkbox" name="profile_active" value="1" <?= $profile['status'] === 'active' ? 'checked' : '' ?>> Show my mentor profile in discovery</span>
    </label>
    <label>
        <span><input type="checkbox" name="accepting_requests" value="1" <?= $profile['accepting_requests'] ? 'checked' : '' ?>> Accepting mentorship requests</span>
    </label>
    <label>
        <span><input type="checkbox" name="whatsapp_enabled" value="1" <?= $whatsAppEnabled ? 'checked' : '' ?>> Let students contact me on WhatsApp</span>
        <span class="muted">Your number is not shown on your profile. The WhatsApp option appears only when enabled here and will open a direct chat.</span>
    </label>
    <?php if ($normalizedPhone === null): ?>
        <p class="muted">No valid phone number is on your CampusFind account. Add an international phone number (or Kenyan 07… number) before enabling WhatsApp.</p>
    <?php endif; ?>
    <button class="button" type="submit">Save preferences</button>
</form>
<?php mentorshipPageEnd(); ?>
