<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$root = sys_get_temp_dir() . '/pasig-logout-test-' . bin2hex(random_bytes(6));
if (!mkdir($root, 0700, true) && !is_dir($root)) {
    throw new RuntimeException('Unable to create isolated session directory.');
}

ini_set('session.save_path', $root);
ini_set('session.use_cookies', '0');
ini_set('session.use_strict_mode', '0');
session_name('pasig_feedback_session_test');

try {
    session_start();
    $_SESSION['user_id'] = 123;
    $_SESSION['last_activity'] = time();
    $authenticatedId = session_id();
    session_write_close();

    session_id($authenticatedId);
    session_start();
    if ((int)($_SESSION['user_id'] ?? 0) !== 123) {
        throw new RuntimeException('Authenticated test session was not established.');
    }

    logout_user();
    if (session_status() !== PHP_SESSION_NONE || session_id() !== '') {
        throw new RuntimeException('Logout retained the destroyed session identifier.');
    }

    // This is the anonymous flash session created by logout.php. It must use a
    // different identifier and must not inherit authentication state.
    session_start();
    $anonymousId = session_id();
    if ($anonymousId === '' || hash_equals($authenticatedId, $anonymousId)) {
        throw new RuntimeException('Anonymous logout session reused the authenticated identifier.');
    }
    if (isset($_SESSION['user_id'])) {
        throw new RuntimeException('Anonymous logout session retained authentication state.');
    }
    session_destroy();
    session_id('');

    // Explicitly replay the formerly authenticated cookie. The destroyed
    // server-side session must be empty, so current_user() exits before any DB
    // lookup and the protected-page guard will redirect to login.
    session_id($authenticatedId);
    session_start();
    if (current_user() !== null) {
        throw new RuntimeException('Destroyed session identifier still authenticates.');
    }
    session_destroy();
    session_id('');

    echo "PASS: logout destroys authentication, rotates the anonymous session ID, and rejects old-session replay.\n";
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
    foreach (glob($root . '/sess_*') ?: [] as $file) @unlink($file);
    @rmdir($root);
}
