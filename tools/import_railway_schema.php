<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$connectionUrl = getenv('PASIG_IMPORT_DB_URL');
if ($connectionUrl !== false && $connectionUrl !== '') {
    $connectionUrl = trim($connectionUrl);
    $connection = parse_url($connectionUrl);
    if ($connection === false || ($connection['scheme'] ?? '') !== 'mysql') {
        fwrite(STDERR, "PASIG_IMPORT_DB_URL must be a valid mysql:// URL.\n");
        exit(2);
    }
    $host = (string)($connection['host'] ?? '');
    $port = (string)($connection['port'] ?? '');
    $user = rawurldecode((string)($connection['user'] ?? ''));
    $password = rawurldecode((string)($connection['pass'] ?? ''));
    $database = rawurldecode(ltrim((string)($connection['path'] ?? ''), '/'));
} else {
    $host = $argv[1] ?? '';
    $port = $argv[2] ?? '';
    $user = $argv[3] ?? 'root';
    $database = $argv[4] ?? 'railway';
    $password = getenv('PASIG_IMPORT_DB_PASS');
}

if ($host === '' || !ctype_digit($port) || $password === false || $password === '') {
    fwrite(STDERR, "Set PASIG_IMPORT_DB_URL to MYSQL_PUBLIC_URL, or set PASIG_IMPORT_DB_PASS and pass HOST PORT [USER] [DATABASE].\n");
    exit(2);
}

$requestedSqlFile = trim((string)getenv('PASIG_IMPORT_SQL_FILE'));
$schemaPath = $requestedSqlFile !== '' ? $requestedSqlFile : __DIR__ . '/../database/railway_schema.sql';
$lines = file($schemaPath, FILE_IGNORE_NEW_LINES);
if ($lines === false) {
    throw new RuntimeException('Cannot read database/railway_schema.sql');
}

$pdo = new PDO(
    "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
    $user,
    $password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

if (getenv('PASIG_IMPORT_CLEAR_SEED') === 'YES') {
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    try {
        $pdo->exec('DELETE FROM users');
        $pdo->exec('DELETE FROM offices');
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }
    echo "Cleared fresh-install seed rows.\n";
}

$delimiter = ';';
$statement = '';
$executed = 0;

foreach ($lines as $line) {
    if (preg_match('/^\s*DELIMITER\s+(.+)\s*$/i', $line, $match)) {
        $delimiter = trim($match[1]);
        continue;
    }

    $statement .= $line . "\n";
    $trimmed = rtrim($statement);
    if ($trimmed === '' || !str_ends_with($trimmed, $delimiter)) {
        continue;
    }

    $sql = trim(substr($trimmed, 0, -strlen($delimiter)));
    $statement = '';
    if ($sql === '' || preg_match('/^(?:--[^\n]*\n?)*$/', $sql)) {
        continue;
    }

    $pdo->exec($sql);
    $executed++;
    echo "Executed statement {$executed}\n";
}

if (trim($statement) !== '') {
    throw new RuntimeException('Schema ended with an incomplete SQL statement.');
}

$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('offices', $tables, true) || !in_array('users', $tables, true)) {
    throw new RuntimeException('Import finished without required tables.');
}

echo 'Import complete. Tables: ' . implode(', ', $tables) . PHP_EOL;

