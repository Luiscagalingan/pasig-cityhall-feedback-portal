<?php
declare(strict_types=1);

$root = sys_get_temp_dir() . '/pasig-cookie-test-' . bin2hex(random_bytes(6));
putenv('PASIG_STORAGE_PATH=' . $root);
putenv('PASIG_FORCE_HTTPS=1');

try {
    require_once __DIR__ . '/../includes/bootstrap.php';

    $params = session_get_cookie_params();
    $checks = [
        'session.use_only_cookies' => ini_get('session.use_only_cookies') === '1',
        'session.use_strict_mode' => ini_get('session.use_strict_mode') === '1',
        'session.use_trans_sid disabled' => ini_get('session.use_trans_sid') === '0',
        'session cookie lifetime' => $params['lifetime'] === 0,
        'session cookie path' => $params['path'] === '/',
        'session cookie Secure' => $params['secure'] === true,
        'session cookie HttpOnly' => $params['httponly'] === true,
        'session cookie SameSite' => ($params['samesite'] ?? '') === 'Lax',
    ];

    foreach ($checks as $label => $passed) {
        if (!$passed) throw new RuntimeException('FAIL: ' . $label);
        echo 'PASS: ' . $label . PHP_EOL;
    }
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
    foreach (['sessions', 'import_previews', 'logs'] as $directory) @rmdir($root . '/' . $directory);
    @rmdir($root);
    putenv('PASIG_STORAGE_PATH');
    putenv('PASIG_FORCE_HTTPS');
}
