<?php

declare(strict_types=1);

function generateCsrfToken(): string
{
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function requireCsrfToken(): void
{
    $submittedToken = $_POST['csrf_token'] ?? null;
    $sessionToken = $_SESSION['csrf_token'] ?? null;

    if (
        !is_string($submittedToken)
        || !is_string($sessionToken)
        || !hash_equals($sessionToken, $submittedToken)
    ) {
        http_response_code(403);
        exit('Invalid security token. Refresh the page and try again.');
    }
}
