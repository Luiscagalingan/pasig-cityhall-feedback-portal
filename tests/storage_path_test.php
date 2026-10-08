<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/storage.php';
$root = sys_get_temp_dir() . '/pasig-storage-' . bin2hex(random_bytes(5));
putenv('PASIG_STORAGE_PATH=' . $root);
try {
    $sessions = ensure_runtime_directory('sessions');
    $previews = ensure_runtime_directory('import_previews');
    if (!is_dir($sessions) || !is_dir($previews)) throw new RuntimeException('Directories missing');
    try { app_storage_path('../escape'); throw new RuntimeException('Traversal accepted'); } catch (InvalidArgumentException) {}
    $blocked = $root . '/not-a-directory'; file_put_contents($blocked, 'x'); putenv('PASIG_STORAGE_PATH=' . $blocked);
    try { ensure_runtime_directory('sessions'); throw new RuntimeException('Unwritable storage accepted'); } catch (RuntimeException) {}
    @unlink($blocked); putenv('PASIG_STORAGE_PATH=' . $root);
    echo "PASS: configurable storage directories and traversal rejection.\n";
} finally {
    @rmdir($sessions); @rmdir($previews); @rmdir($root);
}
