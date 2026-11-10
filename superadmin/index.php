<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';

// Only super administrators can access this area.
requireRole(['super_admin']);

// Load the Super Admin dashboard.
require_once __DIR__ . '/dashboard.php';
