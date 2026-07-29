<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = getenv('PASIG_DB_HOST') ?: '127.0.0.1';
    $port = getenv('PASIG_DB_PORT') ?: '3306';
    $name = getenv('PASIG_DB_NAME') ?: 'pasig_feedback_portal';
    $user = getenv('PASIG_DB_USER') ?: 'root';
    $pass = getenv('PASIG_DB_PASS') ?: '';

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}
