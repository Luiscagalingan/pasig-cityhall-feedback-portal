<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/request_security.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/sentiment.php';
require_once __DIR__ . '/dashboard.php';
require_once __DIR__ . '/layout.php';

date_default_timezone_set(APP_TIMEZONE);

if (session_status() !== PHP_SESSION_ACTIVE) {
    try {
        $sessionPath = ensure_runtime_directory('sessions');
    } catch (Throwable) {
        error_log('Configured application storage is unavailable or not writable.');
        http_response_code(503);
        exit('Service temporarily unavailable. Runtime storage could not be initialized.');
    }
    ini_set('session.save_path', $sessionPath);
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'httponly' => true,
        'secure' => request_is_https(),
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}
