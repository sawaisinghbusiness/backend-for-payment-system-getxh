<?php
// ============================================================
// Database connection — returns a singleton PDO instance.
// Credentials are read from environment variables (loaded via
// bootstrap.php which reads the .env file using phpdotenv).
// ============================================================

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function getDB(): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $host   = getenv('DB_HOST')   ?: '127.0.0.1';
    $port   = getenv('DB_PORT')   ?: '3306';
    $dbname = getenv('DB_NAME')   ?: 'upi_wallet';
    $user   = getenv('DB_USER')   ?: 'root';
    $pass   = getenv('DB_PASS')   ?: '';

    $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    $pdo = new PDO($dsn, $user, $pass, $options);
    return $pdo;
}
