<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not Found\n");
}

require_once dirname(__DIR__) . '/config/config.php';

function readBootstrapValue(string $prompt): string
{
    fwrite(STDOUT, $prompt);
    $value = fgets(STDIN);

    return $value === false ? '' : trim($value);
}

echo "CampusFind — Create the first Super Admin\n\n";

$firstName = readBootstrapValue('First name: ');
$lastName = readBootstrapValue('Last name: ');
$username = readBootstrapValue('Username: ');
$email = readBootstrapValue('Email: ');
$phone = readBootstrapValue('Phone (optional): ');
$password = getenv('CAMPUSFIND_SUPERADMIN_PASSWORD');

if (
    $firstName === ''
    || $lastName === ''
    || $username === ''
    || $email === ''
    || !is_string($password)
    || $password === ''
) {
    fwrite(
        STDERR,
        "Required details are missing. Set CAMPUSFIND_SUPERADMIN_PASSWORD in the process environment and retry.\n"
    );
    exit(1);
}

if (
    mb_strlen($firstName) > 100
    || mb_strlen($lastName) > 100
    || strlen($username) > 100
    || mb_strlen($email) > 150
    || mb_strlen($phone) > 15
    || !preg_match('/^[A-Za-z0-9._-]{3,100}$/', $username)
    || !filter_var($email, FILTER_VALIDATE_EMAIL)
    || ($phone !== '' && !preg_match('/^\+?[0-9 ()-]{7,15}$/', $phone))
    || strlen($password) < 12
    || strlen($password) > 4096
) {
    fwrite(STDERR, "One or more values are invalid. Password must be at least 12 characters.\n");
    exit(1);
}

$pdo->beginTransaction();
try {
    $roleStatement = $pdo->prepare(
        'SELECT id FROM roles WHERE role_name = \'super_admin\' FOR UPDATE'
    );
    $roleStatement->execute();
    $roleId = $roleStatement->fetchColumn();
    if ($roleId === false) {
        throw new RuntimeException('The super_admin role is not configured in the database.');
    }

    $existingStatement = $pdo->prepare(
        'SELECT id FROM users WHERE role_id = :role_id LIMIT 1 FOR UPDATE'
    );
    $existingStatement->execute(['role_id' => $roleId]);
    if ($existingStatement->fetchColumn()) {
        throw new RuntimeException('A Super Admin already exists; bootstrap is disabled.');
    }

    $insertUser = $pdo->prepare(
        'INSERT INTO users
            (role_id, first_name, last_name, username, email, phone, password_hash)
         VALUES
            (:role_id, :first_name, :last_name, :username, :email, :phone, :password_hash)'
    );
    $insertUser->execute([
        'role_id' => $roleId,
        'first_name' => $firstName,
        'last_name' => $lastName,
        'username' => $username,
        'email' => $email,
        'phone' => $phone !== '' ? $phone : null,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    ]);
    $userId = (int) $pdo->lastInsertId();

    $activity = $pdo->prepare(
        'INSERT INTO activity (user_id, action, description)
         VALUES (:user_id, :action, :description)'
    );
    $activity->execute([
        'user_id' => $userId,
        'action' => 'SUPER_ADMIN_BOOTSTRAPPED',
        'description' => 'Created the initial Super Admin account via the command-line bootstrap.',
    ]);

    $pdo->commit();
    fwrite(STDOUT, "Super Admin created successfully for username: {$username}\n");
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
} finally {
    putenv('CAMPUSFIND_SUPERADMIN_PASSWORD');
}
