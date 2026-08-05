<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
$user = require_login(['admin']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Encrypted backups must be requested from System & Audit.'); }
verify_csrf();
$passphrase = (string)($_POST['backup_passphrase'] ?? '');
$confirmation = (string)($_POST['backup_passphrase_confirm'] ?? '');
if (strlen($passphrase) < 12 || $passphrase !== $confirmation) {
    set_flash('error', 'Backup passphrases must match and contain at least 12 characters.');
    redirect('admin/system.php');
}
if (!function_exists('openssl_encrypt')) {
    set_flash('error', 'OpenSSL is required to create an encrypted backup.');
    redirect('admin/system.php');
}

$pdo = db();
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$sql = "-- Pasig Feedback Portal encrypted database backup\n-- Generated: ".date('c')."\nSET FOREIGN_KEY_CHECKS=0;\nUSE pasig_feedback_portal;\n\n";
foreach ($tables as $table) {
    $safe = str_replace('`', '``', (string)$table);
    $create = $pdo->query("SHOW CREATE TABLE `{$safe}`")->fetch();
    $createSql = $create['Create Table'] ?? array_values($create)[1] ?? '';
    $sql .= "DROP TABLE IF EXISTS `{$safe}`;\n{$createSql};\n\n";
    $stmt = $pdo->query("SELECT * FROM `{$safe}`");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $cols = array_map(fn($c) => '`'.str_replace('`', '``', (string)$c).'`', array_keys($row));
        $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), array_values($row));
        $sql .= 'INSERT INTO `'.$safe.'` ('.implode(',', $cols).') VALUES ('.implode(',', $vals).");\n";
    }
    $sql .= "\n";
}
$triggers = $pdo->query('SHOW TRIGGERS')->fetchAll();
foreach ($triggers as $trigger) {
    $name = (string)$trigger['Trigger'];
    $show = $pdo->query('SHOW CREATE TRIGGER `'.str_replace('`', '``', $name).'`')->fetch();
    $triggerSql = $show['SQL Original Statement'] ?? $show['Create Trigger'] ?? '';
    if ($triggerSql !== '') $sql .= "DROP TRIGGER IF EXISTS `".str_replace('`', '``', $name)."`;\nDELIMITER $$\n{$triggerSql}$$\nDELIMITER ;\n\n";
}
$sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

$salt = random_bytes(16);
$iv = random_bytes(12);
$key = hash_pbkdf2('sha256', $passphrase, $salt, 120000, 32, true);
$tag = '';
$encrypted = openssl_encrypt($sql, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
if ($encrypted === false) throw new RuntimeException('Backup encryption failed.');
$header = json_encode(['version'=>1,'cipher'=>'aes-256-gcm','kdf'=>'pbkdf2-sha256','iterations'=>120000,'salt'=>base64_encode($salt),'iv'=>base64_encode($iv),'tag'=>base64_encode($tag)], JSON_UNESCAPED_SLASHES);
$payload = "PASIG-ENC-BACKUP-V1\n{$header}\n".base64_encode($encrypted);
audit((int)$user['id'], 'encrypted_database_backup_export', 'Downloaded AES-256-GCM backup containing '.count($tables).' tables');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="pasig-feedback-'.date('Y-m-d-His').'.pasigbak"');
header('Content-Length: '.strlen($payload));
header('Cache-Control: no-store, private');
echo $payload;
exit;
