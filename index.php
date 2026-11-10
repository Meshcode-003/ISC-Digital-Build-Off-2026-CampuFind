<?php

declare(strict_types=1);

if (file_exists(__DIR__ . '/index.html')) {
    include __DIR__ . '/index.html';
    exit;
}

header('Location: /campusfind/public/login.php');
exit;
