<?php
declare(strict_types=1);

function current_user(): ?array
{
    $id = (int)($_SESSION['user_id'] ?? 0);
    if ($id < 1) return null;

    $last = (int)($_SESSION['last_activity'] ?? time());
    if (time() - $last > SESSION_IDLE_TIMEOUT) {
        audit($id, 'session_timeout', 'Session expired after inactivity');
        logout_user();
        return null;
    }
    $_SESSION['last_activity'] = time();

    $stmt = db()->prepare(
        "SELECT u.*, o.name AS office_name, o.code AS office_code, o.status AS office_status
         FROM users u LEFT JOIN offices o ON o.id=u.office_id WHERE u.id=? LIMIT 1"
    );
    $stmt->execute([$id]);
    $user = $stmt->fetch();
    if (!$user || $user['status'] !== 'active' || (($user['role'] !== 'admin') && $user['office_status'] !== 'active')) {
        logout_user();
        return null;
    }
    return $user;
}

function record_login_attempt(string $identity, bool $success, ?int $userId = null): void
{
    try {
        $stmt = db()->prepare('INSERT INTO login_attempts (identity, user_id, ip_address, user_agent, successful) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$identity, $userId, $_SERVER['REMOTE_ADDR'] ?? null, mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255), $success ? 1 : 0]);
    } catch (Throwable) {}
}

function login_error_message(): string
{
    $message = (string)($_SESSION['login_error'] ?? 'Invalid credentials, archived account, inactive office, or temporarily locked account.');
    unset($_SESSION['login_error']);
    return $message;
}

function login_user(string $identity, string $password): bool
{
    $identity = trim($identity);
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    try {
        $lockWindow = max(1, (int)LOGIN_LOCK_MINUTES);
        $ipCheck = db()->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip_address=? AND successful=0 AND attempted_at >= DATE_SUB(NOW(), INTERVAL {$lockWindow} MINUTE)");
        $ipCheck->execute([$ip]);
        if ((int)$ipCheck->fetchColumn() >= LOGIN_IP_MAX_ATTEMPTS) {
            audit(null, 'login_ip_blocked', 'Temporarily blocked excessive failed logins from ' . $ip);
            $_SESSION['login_error'] = 'Too many failed attempts from this device or network. Try again later.';
            return false;
        }
    } catch (Throwable) {}
    $stmt = db()->prepare(
        "SELECT u.*, o.status AS office_status FROM users u
         LEFT JOIN offices o ON o.id=u.office_id WHERE (u.username=? OR u.email=?) LIMIT 1"
    );
    $stmt->execute([$identity, $identity]);
    $user = $stmt->fetch();

    if ($user && !empty($user['locked_until']) && strtotime((string)$user['locked_until']) > time()) {
        record_login_attempt($identity, false, (int)$user['id']);
        audit((int)$user['id'], 'login_blocked', 'Attempt while account was temporarily locked');
        $_SESSION['login_error'] = 'Too many failed attempts. Try again after ' . date('h:i A', strtotime((string)$user['locked_until'])) . '.';
        return false;
    }

    $valid = $user
        && $user['status'] === 'active'
        && ($user['role'] === 'admin' || $user['office_status'] === 'active')
        && password_verify($password, (string)$user['password_hash']);

    if (!$valid) {
        if ($user) {
            $attempts = (int)$user['failed_login_attempts'] + 1;
            $lockedUntil = $attempts >= LOGIN_MAX_ATTEMPTS ? date('Y-m-d H:i:s', strtotime('+' . LOGIN_LOCK_MINUTES . ' minutes')) : null;
            db()->prepare('UPDATE users SET failed_login_attempts=?, locked_until=? WHERE id=?')->execute([$attempts, $lockedUntil, (int)$user['id']]);
            audit((int)$user['id'], 'login_failed', 'Failed login attempt ' . $attempts . ($lockedUntil ? '; account temporarily locked' : ''));
        } else {
            audit(null, 'login_failed', 'Unknown identity: ' . mb_substr($identity, 0, 100));
        }
        record_login_attempt($identity, false, $user ? (int)$user['id'] : null);
        $_SESSION['login_error'] = 'Invalid credentials, archived account, inactive office, or temporarily locked account.';
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['last_activity'] = time();
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    db()->prepare('UPDATE users SET last_login_at=NOW(), failed_login_attempts=0, locked_until=NULL WHERE id=?')->execute([(int)$user['id']]);
    record_login_attempt($identity, true, (int)$user['id']);
    audit((int)$user['id'], 'login', 'Successful login');
    return true;
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool)$params['secure'], (bool)$params['httponly']);
    }
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
}

function require_login(array $roles = []): array
{
    $user = current_user();
    if (!$user) {
        if (!empty($_SESSION)) set_flash('info', 'Your session expired. Please sign in again.');
        redirect('login.php');
    }
    if ($roles && !in_array($user['role'], $roles, true)) {
        http_response_code(403);
        exit('You do not have permission to access this page.');
    }
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (!empty($user['must_change_password']) && !in_array($script, ['account.php', 'logout.php'], true)) {
        set_flash('info', 'Change your temporary password before continuing.');
        redirect('account.php');
    }
    return $user;
}

function dashboard_path(array $user): string
{
    return ($user['role'] ?? '') === 'admin' ? 'admin/dashboard.php' : 'office/dashboard.php';
}
