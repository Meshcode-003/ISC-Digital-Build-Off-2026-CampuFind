<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';

function requireLogin(): void
{
    $userId = $_SESSION['user_id'] ?? null;
    if (!is_string($userId) && !is_int($userId)) {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }

    if (!ctype_digit((string) $userId)) {
        $_SESSION = [];
        session_destroy();
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
}

function requireRole(array $allowedRoles): void
{
    requireLogin();

    global $pdo;

    $statement = $pdo->prepare(
        'SELECT u.role_id, r.role_name
         FROM users u
         INNER JOIN roles r ON r.id = u.role_id
         WHERE u.id = :user_id
           AND u.account_status = \'active\'
         LIMIT 1'
    );
    $statement->execute(['user_id' => (int) $_SESSION['user_id']]);
    $user = $statement->fetch();

    if (
        !$user
        || !is_string($user['role_name'])
        || !in_array($user['role_name'], $allowedRoles, true)
    ) {
        http_response_code(403);
        exit('You do not have permission to access this page.');
    }

    $_SESSION['role_id'] = (string) $user['role_id'];
    $_SESSION['role_name'] = $user['role_name'];
}

function requireAdmin(): void
{
    requireRole(['admin', 'super_admin']);
}

function getAdminModuleKeys(int $userId): array
{
    if ($userId < 1) {
        throw new InvalidArgumentException('A valid administrator user ID is required.');
    }

    global $pdo;

    $statement = $pdo->prepare(
        'SELECT module_key
         FROM module_admin_assignments
         WHERE user_id = :user_id
         ORDER BY module_key'
    );
    $statement->execute(['user_id' => $userId]);

    $moduleKeys = $statement->fetchAll(PDO::FETCH_COLUMN);
    $allowedModuleKeys = ['lost_items', 'notices', 'mentorship'];

    return array_values(array_filter(
        $moduleKeys,
        static fn ($moduleKey): bool => is_string($moduleKey)
            && in_array($moduleKey, $allowedModuleKeys, true)
    ));
}

function adminModuleDestination(string $moduleKey): string
{
    $destinations = [
        'lost_items' => '/campusfind/lostandfound/admin/dashboard.php',
        'notices' => '/campusfind/notices/admin/',
        'mentorship' => '/campusfind/mentorship/admin/applications.php',
    ];

    if (!isset($destinations[$moduleKey])) {
        throw new InvalidArgumentException('Unknown administrator module.');
    }

    return $destinations[$moduleKey];
}

function authenticatedHomeUrl(string $roleName, int $userId): string
{
    if ($roleName === 'super_admin') {
        return SUPERADMIN_URL . '/';
    }

    if (in_array($roleName, ['student', 'staff'], true)) {
        return BASE_URL . '/dashboard.php';
    }

    if ($roleName !== 'admin') {
        throw new InvalidArgumentException('Unknown CampusFind account role.');
    }

    $moduleKeys = getAdminModuleKeys($userId);

    if (count($moduleKeys) === 1) {
        return adminModuleDestination($moduleKeys[0]);
    }

    return BASE_URL . '/admin-modules.php';
}

function redirectToAuthenticatedHome(string $roleName, int $userId): never
{
    if (!in_array($roleName, ['student', 'staff', 'admin', 'super_admin'], true)) {
        http_response_code(403);
        exit('Your account role is not authorized to access this page.');
    }

    header('Location: ' . authenticatedHomeUrl($roleName, $userId));
    exit;
}

function hasModuleAdminAssignment(string $moduleKey, int $userId): bool
{
    $moduleKeys = ['lost_items', 'notices', 'mentorship'];
    if (!in_array($moduleKey, $moduleKeys, true)) {
        throw new InvalidArgumentException('Unknown administrator module.');
    }

    global $pdo;

    $statement = $pdo->prepare(
        'SELECT 1
         FROM module_admin_assignments
         WHERE user_id = :user_id
           AND module_key = :module_key
         LIMIT 1'
    );
    $statement->execute([
        'user_id' => $userId,
        'module_key' => $moduleKey,
    ]);

    return (bool) $statement->fetchColumn();
}

function requireModuleAdmin(string $moduleKey): void
{
    $moduleKeys = ['lost_items', 'notices', 'mentorship'];
    if (!in_array($moduleKey, $moduleKeys, true)) {
        throw new InvalidArgumentException('Unknown administrator module.');
    }

    requireRole(['admin', 'super_admin']);

    if ($_SESSION['role_name'] === 'super_admin') {
        return;
    }

    if (!hasModuleAdminAssignment($moduleKey, (int) $_SESSION['user_id'])) {
        http_response_code(403);
        exit('You do not have permission to access this module.');
    }
}

function requireModuleAccess(string $moduleKey): void
{
    $moduleKeys = ['lost_items', 'notices', 'mentorship'];
    if (!in_array($moduleKey, $moduleKeys, true)) {
        throw new InvalidArgumentException('Unknown CampusFind module.');
    }

    requireRole(['student', 'staff', 'admin', 'super_admin']);

    if (
        $_SESSION['role_name'] === 'admin'
        && !hasModuleAdminAssignment($moduleKey, (int) $_SESSION['user_id'])
    ) {
        http_response_code(403);
        exit('You do not have permission to access this module.');
    }
}