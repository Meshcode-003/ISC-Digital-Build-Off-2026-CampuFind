<?php

declare(strict_types=1);

require_once __DIR__ . '/env.php';

loadEnv(dirname(__DIR__) . '/.env');

define('APP_NAME', getenv('APP_NAME') ?: 'CampusFind');
define('BASE_URL', '/ISC-Digital-Build-Off-2026-CampuFind/public');
define('SUPERADMIN_URL', '/ISC-Digital-Build-Off-2026-CampuFind/superadmin');

$isHttps = (
    !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'
) || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
ini_set('session.use_strict_mode', '1');
session_start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');

require_once __DIR__ . '/database.php';
