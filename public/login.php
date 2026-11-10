<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';

if (isset($_SESSION['user_id'])) {
    $sessionUserId = filter_var($_SESSION['user_id'], FILTER_VALIDATE_INT);
    if ($sessionUserId !== false && $sessionUserId > 0) {
        $sessionUserStatement = $pdo->prepare(
            'SELECT u.role_id, r.role_name
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE u.id = :user_id
               AND u.account_status = \'active\'
             LIMIT 1'
        );
        $sessionUserStatement->execute(['user_id' => $sessionUserId]);
        $sessionUser = $sessionUserStatement->fetch();

        if ($sessionUser && is_string($sessionUser['role_name'])) {
            $_SESSION['role_id'] = (string) $sessionUser['role_id'];
            $_SESSION['role_name'] = $sessionUser['role_name'];
            redirectToAuthenticatedHome($sessionUser['role_name'], $sessionUserId);
        }
    }

    $_SESSION = [];
    session_destroy();
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$error = '';
$login = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();

    $loginInput = $_POST['login'] ?? '';
    $passwordInput = $_POST['password'] ?? '';
    $login = is_string($loginInput) ? trim($loginInput) : '';
    $password = is_string($passwordInput) ? $passwordInput : '';

    if ($login === '' || $password === '') {
        $error = 'Enter your username or email and password.';
    } else {
        $statement = $pdo->prepare(
            'SELECT u.id, u.role_id, u.first_name, u.last_name, u.username,
                    u.email, u.account_status, u.password_hash, r.role_name
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE u.username = :username OR u.email = :email
             LIMIT 1'
        );
        $statement->execute([
            'username' => $login,
            'email' => $login,
        ]);
        $user = $statement->fetch();

        if (
            !$user
            || !password_verify($password, $user['password_hash'])
        ) {
            $error = 'Invalid username/email or password.';
        } elseif ($user['account_status'] !== 'active') {
            $error = 'Your account is not active. Please contact an administrator.';
        } else {
            $update = $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id');
            $update->execute(['id' => $user['id']]);

            session_regenerate_id(true);
            $_SESSION['user_id'] = (string) $user['id'];
            $_SESSION['role_id'] = (string) $user['role_id'];
            $_SESSION['role_name'] = $user['role_name'];
            $_SESSION['first_name'] = $user['first_name'];
            $_SESSION['last_name'] = $user['last_name'];
            $_SESSION['username'] = (string) $user['username'];
            unset($_SESSION['csrf_token']);

            redirectToAuthenticatedHome((string) $user['role_name'], (int) $user['id']);
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
    <title>Sign in | <?= $escape(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/auth.css">
</head>
<body>
    <main class="auth-card">
        <p class="eyebrow">CAMPUSFIND</p>
        <h1>Welcome back</h1>
        <p class="intro">Sign in once to access your CampusFind services.</p>

        <?php if (isset($_GET['registered'])): ?>
            <p class="notice" role="status">Your account is ready. Sign in to continue.</p>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <p class="error" role="alert"><?= $escape($error) ?></p>
        <?php endif; ?>

        <form method="post" action="<?= BASE_URL ?>/login.php">
            <input type="hidden" name="csrf_token" value="<?= $escape(generateCsrfToken()) ?>">
            <label for="login">Username or email</label>
            <input id="login" name="login" type="text" maxlength="150" autocomplete="username"
                   value="<?= $escape($login) ?>" required>

            <label for="password">Password</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>

            <button type="submit">Sign in</button>
        </form>

        <p class="auth-footer">New to CampusFind? <a href="<?= BASE_URL ?>/register.php">Create an account</a></p>
    </main>
</body>
</html>