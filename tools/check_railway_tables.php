<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$url = trim((string)getenv('PASIG_IMPORT_DB_URL'));
$connection = parse_url($url);
if ($connection === false || ($connection['scheme'] ?? '') !== 'mysql') {
    throw new RuntimeException('Invalid PASIG_IMPORT_DB_URL.');
}

$pdo = new PDO(
    sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $connection['host'],
        $connection['port'],
        rawurldecode(ltrim($connection['path'], '/'))
    ),
    rawurldecode($connection['user']),
    rawurldecode($connection['pass']),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
if (!$tables) {
    echo "(no tables)\n";
    exit;
}
foreach ($tables as $table) {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        throw new RuntimeException('Unexpected table name.');
    }
    $count = $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    echo $table . ': ' . $count . " row(s)\n";
}

