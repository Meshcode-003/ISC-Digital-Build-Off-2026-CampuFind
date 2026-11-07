<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/config.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/csrf.php';

requireRole(['super_admin']);

$moduleOptions = [
    'lost_items' => [
        'label' => 'Lost Items',
        'description' => 'Manage Lost & Found reports, items, and claims.',
    ],
    'notices' => [
        'label' => 'Campus Notices',
        'description' => 'Manage campus announcement content.',
    ],
    'mentorship' => [
        'label' => 'Mentorship',
        'description' => 'Review mentor applications and manage mentorship.',
    ],
];

$form = [
    'first_name' => '',
    'last_name' => '',
    'username' => '',
    'email' => '',
    'phone' => '',
];
$selectedModules = [];
$errors = [];
$success = $_SESSION['admin_registration_success'] ?? '';
unset($_SESSION['admin_registration_success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();

    foreach ($form as $field => $_) {
        $value = $_POST[$field] ?? '';
        $form[$field] = is_string($value) ? trim($value) : '';
    }
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $passwordConfirmation = is_string($_POST['password_confirmation'] ?? null)
        ? $_POST['password_confirmation']
        : '';
    $rawModules = $_POST['modules'] ?? [];
    if (is_array($rawModules)) {
        foreach ($rawModules as $module) {
            if (is_string($module) && array_key_exists($module, $moduleOptions)) {
                $selectedModules[$module] = $module;
            }
        }
    }
    $selectedModules = array_values($selectedModules);

    if (
        $form['first_name'] === ''
        || $form['last_name'] === ''
        || $form['username'] === ''
        || $form['email'] === ''
        || $password === ''
        || $passwordConfirmation === ''
    ) {
        $errors[] = 'Complete all required fields.';
    }
    if (
        mb_strlen($form['first_name']) > 100
        || mb_strlen($form['last_name']) > 100
        || strlen($form['username']) > 100
        || mb_strlen($form['email']) > 150
        || mb_strlen($form['phone']) > 15
    ) {
        $errors[] = 'One or more fields exceed the allowed length.';
    }
    if (!preg_match('/^[A-Za-z0-9._-]{3,100}$/', $form['username'])) {
        $errors[] = 'Username must be 3–100 characters and use only letters, numbers, dots, underscores, or hyphens.';
    }
    if ($form['email'] !== '' && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address.';
    }
    if ($form['phone'] !== '' && !preg_match('/^\+?[0-9 ()-]{7,15}$/', $form['phone'])) {
        $errors[] = 'Enter a valid phone number or leave it blank.';
    }
    if (strlen($password) < 12) {
        $errors[] = 'Password must be at least 12 characters.';
    } elseif (strlen($password) > 4096) {
        $errors[] = 'Password is too long.';
    }
    if ($password !== $passwordConfirmation) {
        $errors[] = 'Password and confirmation do not match.';
    }
    if (count($selectedModules) === 0) {
        $errors[] = 'Select at least one administrative module.';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $actorStatement = $pdo->prepare(
                'SELECT r.role_name, u.account_status
                 FROM users u
                 INNER JOIN roles r ON r.id = u.role_id
                 WHERE u.id = :user_id
                 FOR UPDATE'
            );
            $actorStatement->execute(['user_id' => (int) $_SESSION['user_id']]);
            $actor = $actorStatement->fetch();
            if (!$actor || $actor['role_name'] !== 'super_admin' || $actor['account_status'] !== 'active') {
                $pdo->rollBack();
                http_response_code(403);
                exit('Only an active Super Admin can register administrators.');
            }

            $roleStatement = $pdo->prepare(
                'SELECT id FROM roles WHERE role_name = \'admin\' LIMIT 1'
            );
            $roleStatement->execute();
            $adminRoleId = $roleStatement->fetchColumn();
            if ($adminRoleId === false) {
                throw new RuntimeException('The admin role is not configured in the database.');
            }

            $insertUser = $pdo->prepare(
                'INSERT INTO users
                    (role_id, first_name, last_name, username, email, phone, password_hash)
                 VALUES
                    (:role_id, :first_name, :last_name, :username, :email, :phone, :password_hash)'
            );
            $insertUser->execute([
                'role_id' => $adminRoleId,
                'first_name' => $form['first_name'],
                'last_name' => $form['last_name'],
                'username' => $form['username'],
                'email' => $form['email'],
                'phone' => $form['phone'] !== '' ? $form['phone'] : null,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);
            $adminUserId = (int) $pdo->lastInsertId();

            $insertAssignment = $pdo->prepare(
                'INSERT INTO module_admin_assignments (user_id, module_key, assigned_by)
                 VALUES (:user_id, :module_key, :assigned_by)'
            );
            foreach ($selectedModules as $module) {
                $insertAssignment->execute([
                    'user_id' => $adminUserId,
                    'module_key' => $module,
                    'assigned_by' => (int) $_SESSION['user_id'],
                ]);
            }

            $moduleNames = array_map(
                static fn (string $module): string => $moduleOptions[$module]['label'],
                $selectedModules
            );
            $description = sprintf(
                'Registered administrator "%s" (user ID %d) with module assignments: %s.',
                $form['username'],
                $adminUserId,
                implode(', ', $moduleNames)
            );
            $insertActivity = $pdo->prepare(
                'INSERT INTO activity (user_id, action, description, ip_address, user_agent)
                 VALUES (:user_id, :action, :description, :ip_address, :user_agent)'
            );
            $insertActivity->execute([
                'user_id' => (int) $_SESSION['user_id'],
                'action' => 'ADMIN_CREATED',
                'description' => $description,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => isset($_SERVER['HTTP_USER_AGENT'])
                    ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 500)
                    : null,
            ]);

            $pdo->commit();
            $_SESSION['admin_registration_success'] = 'Administrator account and module assignments created successfully.';
            header('Location: create.php');
            exit;
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($exception->getCode() === '23000') {
                $duplicate = $pdo->prepare(
                    'SELECT id FROM users
                     WHERE username = :username OR email = :email
                     LIMIT 1'
                );
                $duplicate->execute([
                    'username' => $form['username'],
                    'email' => $form['email'],
                ]);
                if ($duplicate->fetchColumn()) {
                    $errors[] = 'The username or email is already in use.';
                } else {
                    throw $exception;
                }
            } else {
                throw $exception;
            }
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }
}

$escape = static fn (string $value): string => htmlspecialchars(
    $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$displayName = trim(
    (string) ($_SESSION['first_name'] ?? '') . ' '
    . (string) ($_SESSION['last_name'] ?? '')
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Register administrator | <?= $escape(APP_NAME) ?></title>
    <link rel="stylesheet" href="superadmin.css">
</head>
<body>
    <header class="superadmin-header">
        <a class="brand" href="/campusfind/index.html">CampusFind</a>
        <div class="header-actions">
            <span><?= $escape($displayName !== '' ? $displayName : 'Super Admin') ?></span>
            <form method="post" action="<?= $escape(BASE_URL) ?>/logout.php">
                <input type="hidden" name="csrf_token" value="<?= $escape(generateCsrfToken()) ?>">
                <button class="text-button" type="submit">Sign out</button>
            </form>
        </div>
    </header>

    <main class="page-main">
        <p class="eyebrow">SUPER ADMINISTRATION</p>
        <h1>Register an administrator</h1>
        <p class="intro">Create an account and choose which CampusFind modules it will be assigned to.</p>

        <?php if ($success !== ''): ?>
            <div class="alert alert-success" role="status"><?= $escape((string) $success) ?></div>
        <?php endif; ?>
        <?php if ($errors): ?>
            <div class="alert alert-error" role="alert">
                <ul class="error-list">
                    <?php foreach ($errors as $error): ?>
                        <li><?= $escape($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <section class="form-card" aria-label="Administrator details">
            <form class="form-grid" method="post" action="create.php">
                <input type="hidden" name="csrf_token" value="<?= $escape(generateCsrfToken()) ?>">
                <div class="field">
                    <label for="first_name">First name</label>
                    <input id="first_name" name="first_name" maxlength="100" autocomplete="given-name" required value="<?= $escape($form['first_name']) ?>">
                </div>
                <div class="field">
                    <label for="last_name">Last name</label>
                    <input id="last_name" name="last_name" maxlength="100" autocomplete="family-name" required value="<?= $escape($form['last_name']) ?>">
                </div>
                <div class="field">
                    <label for="username">Username</label>
                    <input id="username" name="username" maxlength="100" autocomplete="username" required value="<?= $escape($form['username']) ?>">
                </div>
                <div class="field">
                    <label for="email">Email address</label>
                    <input id="email" name="email" type="email" maxlength="150" autocomplete="email" required value="<?= $escape($form['email']) ?>">
                </div>
                <div class="field field-full">
                    <label for="phone">Phone number <span>(optional)</span></label>
                    <input id="phone" name="phone" type="tel" maxlength="15" autocomplete="tel" value="<?= $escape($form['phone']) ?>">
                </div>
                <div class="field">
                    <label for="password">Temporary password</label>
                    <input id="password" name="password" type="password" minlength="12" maxlength="4096" autocomplete="new-password" required>
                </div>
                <div class="field">
                    <label for="password_confirmation">Confirm password</label>
                    <input id="password_confirmation" name="password_confirmation" type="password" minlength="12" maxlength="4096" autocomplete="new-password" required>
                </div>
                <p class="password-note">Use at least 12 characters. Provide the temporary password to the administrator securely.</p>

                <fieldset class="module-picker">
                    <legend>Administrative modules</legend>
                    <div class="module-options">
                        <?php foreach ($moduleOptions as $module => $details): ?>
                            <label class="module-option">
                                <input
                                    type="checkbox"
                                    name="modules[]"
                                    value="<?= $escape($module) ?>"
                                    <?= in_array($module, $selectedModules, true) ? 'checked' : '' ?>
                                >
                                <span>
                                    <strong><?= $escape($details['label']) ?></strong>
                                    <small><?= $escape($details['description']) ?></small>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>

                <div class="submit-row">
                    <button class="primary-button" type="submit">Create administrator</button>
                </div>
            </form>
        </section>
    </main>
</body>
</html>
