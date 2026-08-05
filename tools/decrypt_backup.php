<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit("Run this tool from the command line.\n");
if ($argc !== 3) exit("Usage: php tools/decrypt_backup.php <backup.pasigbak> <output.sql>\n");
$input = (string)$argv[1];
$output = (string)$argv[2];
if (!is_file($input)) exit("Backup file not found.\n");
$handle = fopen('php://stdin', 'r');
fwrite(STDOUT, 'Backup passphrase: ');
$passphrase = trim((string)fgets($handle));
$parts = explode("\n", (string)file_get_contents($input), 3);
if (count($parts) !== 3 || $parts[0] !== 'PASIG-ENC-BACKUP-V1') exit("Invalid backup format.\n");
$meta = json_decode($parts[1], true);
if (!is_array($meta)) exit("Invalid backup metadata.\n");
$salt = base64_decode((string)($meta['salt'] ?? ''), true);
$iv = base64_decode((string)($meta['iv'] ?? ''), true);
$tag = base64_decode((string)($meta['tag'] ?? ''), true);
$ciphertext = base64_decode($parts[2], true);
if ($salt === false || $iv === false || $tag === false || $ciphertext === false) exit("Invalid encrypted payload.\n");
$key = hash_pbkdf2('sha256', $passphrase, $salt, (int)($meta['iterations'] ?? 120000), 32, true);
$sql = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
if ($sql === false) exit("Incorrect passphrase or damaged backup.\n");
if (file_put_contents($output, $sql) === false) exit("Unable to write output file.\n");
fwrite(STDOUT, "Decrypted SQL written to {$output}\n");
