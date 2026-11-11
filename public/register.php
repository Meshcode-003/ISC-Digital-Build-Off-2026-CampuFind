<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/notifications.php';

if (isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

$error = '';
$form = [
    'first_name' => '',
    'last_name' => '',
    'username' => '',
    'email' => '',
    'phone' => '',
    'role' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();

    foreach ($form as $field => $_) {
        $value = $_POST[$field] ?? '';
        $form[$field] = is_string($value) ? trim($value) : '';
    }
    $passwordInput = $_POST['password'] ?? '';
    $password = is_string($passwordInput) ? $passwordInput : '';

    if (
        $form['first_name'] === ''
        || $form['last_name'] === ''
        || $form['username'] === ''
        || $form['email'] === ''
        || $form['role'] === ''
        || $password === ''
    ) {
        $error = 'Please fill in all required fields.';
    } elseif (
        strlen($form['first_name']) > 100
        || strlen($form['last_name']) > 100
        || strlen($form['username']) > 100
        || strlen($form['email']) > 150
        || strlen($form['phone']) > 15
    ) {
        $error = 'One or more fields exceed the allowed length.';
    } elseif (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif (!in_array($form['role'], ['student', 'staff'], true)) {
        $error = 'Choose a valid account type.';
    } else {
        $statement = $pdo->prepare(
            'SELECT id FROM users WHERE username = :username OR email = :email LIMIT 1'
        );
        $statement->execute([
            'username' => $form['username'],
            'email' => $form['email'],
        ]);

        if ($statement->fetch()) {
            $error = 'That username or email is already in use.';
        } else {
            $roleStatement = $pdo->prepare(
                'SELECT id FROM roles WHERE role_name = :role_name LIMIT 1'
            );
            $roleStatement->execute(['role_name' => $form['role']]);
            $roleId = $roleStatement->fetchColumn();

            if ($roleId === false) {
                $error = 'That account type is currently unavailable.';
            } else {
                try {
                    $pdo->beginTransaction();
                    $insert = $pdo->prepare(
                        'INSERT INTO users
                            (role_id, first_name, last_name, username, email, phone, password_hash)
                         VALUES
                            (:role_id, :first_name, :last_name, :username, :email, :phone, :password_hash)'
                    );
                    $insert->execute([
                        'role_id' => $roleId,
                        'first_name' => $form['first_name'],
                        'last_name' => $form['last_name'],
                        'username' => $form['username'],
                        'email' => $form['email'],
                        'phone' => $form['phone'] !== '' ? $form['phone'] : null,
                        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    ]);
                    $newUserId = (int) $pdo->lastInsertId();
                    notifySuperAdministrators(
                        $pdo,
                        'New CampusFind registration',
                        $form['first_name'] . ' ' . $form['last_name'] . ' registered as a ' . $form['role'] . '.',
                        SUPERADMIN_URL . '/dashboard.php#users',
                        'user-registration:' . $newUserId,
                        null,
                        'registration'
                    );
                    $pdo->commit();

                    header('Location: ' . BASE_URL . '/login.php?registered=1');
                    exit;
                } catch (PDOException $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    if ($exception->getCode() !== '23000') {
                        throw $exception;
                    }

                    $error = 'That username or email is already in use.';
                }
            }
        }
    }
}

$escape = static fn (string $value): string => htmlspecialchars(
    $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create account | <?= $escape(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/auth.css">
    <script src="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/campusfind-theme.js"></script>
    <link rel="stylesheet" href="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/campusfind-theme.css">
</head>
<body>
    <main class="auth-card">
        <p class="eyebrow">CAMPUSFIND</p>
        <h1>Create your account</h1>
        <p class="intro">Join CampusFind to report or claim lost items.</p>

        <?php if ($error !== ''): ?>
            <p class="error" role="alert"><?= $escape($error) ?></p>
        <?php endif; ?>

        <form method="post" action="<?= BASE_URL ?>/register.php">
            <input type="hidden" name="csrf_token" value="<?= $escape(generateCsrfToken()) ?>">

            <div class="form-grid">
                <div>
                    <label for="first_name">First name</label>
                    <input id="first_name" name="first_name" type="text" maxlength="100"
                           autocomplete="given-name" value="<?= $escape($form['first_name']) ?>" required>
                </div>
                <div>
                    <label for="last_name">Last name</label>
                    <input id="last_name" name="last_name" type="text" maxlength="100"
                           autocomplete="family-name" value="<?= $escape($form['last_name']) ?>" required>
                </div>
                <div>
                    <label for="username">Username</label>
                    <input id="username" name="username" type="text" maxlength="100"
                           autocomplete="username" value="<?= $escape($form['username']) ?>" required>
                </div>
                <div>
                    <label for="email">Email</label>
                    <input id="email" name="email" type="email" maxlength="150"
                           autocomplete="email" value="<?= $escape($form['email']) ?>" required>
                </div>
                <div>
                    <label for="phone">Phone <span class="optional">(optional)</span></label>
                    <input id="phone" name="phone" type="tel" maxlength="15"
                           autocomplete="tel" value="<?= $escape($form['phone']) ?>">
                </div>
                <div>
                    <label for="role">Account type</label>
                    <select id="role" name="role" required>
                        <option value="">Select an account type</option>
                        <option value="student" <?= $form['role'] === 'student' ? 'selected' : '' ?>>Student</option>
                        <option value="staff" <?= $form['role'] === 'staff' ? 'selected' : '' ?>>Staff</option>
                    </select>
                </div>
                <div class="full-width">
                    <label for="password">Password</label>
                    <input id="password" name="password" type="password" minlength="8"
                           autocomplete="new-password" required>
                </div>
            </div>

            <button type="submit">Create account</button>
        </form>

        <p class="auth-footer">Already registered? <a href="<?= BASE_URL ?>/login.php">Sign in</a></p>
    </main>
</body>
</html>
