<?php
// ============================================================
// bootstrap.php — loads .env into environment variables
// Requires: composer install (vlucas/phpdotenv)
// ============================================================

declare(strict_types=1);

// ── Production error handling ─────────────────────────────────
// Never display errors to users — log everything, show nothing.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);
ini_set('log_errors', '1');

$autoloader = __DIR__ . '/vendor/autoload.php';

if (!file_exists($autoloader)) {
    // Composer not installed yet — fail clearly
    http_response_code(500);
    error_log('[bootstrap] vendor/autoload.php not found. Run: composer install');
    die('Server configuration error. Please contact the administrator.');
}

require_once $autoloader;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad(); // safeLoad: no error if .env file is missing (uses server env vars)

// Validate that critical variables are present
$dotenv->required([
    'DB_HOST',
    'DB_NAME',
    'DB_USER',
])->notEmpty();
