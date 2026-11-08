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
        'SELECT r.role_name
         FROM users u
         INNER JOIN roles r ON r.id = u.role_id
         WHERE u.id = :user_id
           AND u.account_status = \'active\'
         LIMIT 1'
    );
    $statement->execute(['user_id' => (int) $_SESSION['user_id']]);
    $roleName = $statement->fetchColumn();

    if (!is_string($roleName) || !in_array($roleName, $allowedRoles, true)) {
        http_response_code(403);
        exit('You do not have permission to access this page.');
    }

    $_SESSION['role_name'] = $roleName;
}

function requireAdmin(): void
{
    requireRole(['admin', 'super_admin']);
}
