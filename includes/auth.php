<?php
declare(strict_types=1);

function current_user(): ?array
{
    $id = (int)($_SESSION['user_id'] ?? 0);
    if ($id < 1) return null;

    $stmt = db()->prepare(
        "SELECT u.*, o.name AS office_name, o.code AS office_code, o.status AS office_status
         FROM users u
         LEFT JOIN offices o ON o.id=u.office_id
         WHERE u.id=? LIMIT 1"
    );
    $stmt->execute([$id]);
    $user = $stmt->fetch();

    if (!$user || $user['status'] !== 'active' || (($user['role'] !== 'admin') && $user['office_status'] !== 'active')) {
        logout_user();
        return null;
    }
    return $user;
}

function login_user(string $identity, string $password): bool
{
    $stmt = db()->prepare(
        "SELECT u.*, o.status AS office_status
         FROM users u LEFT JOIN offices o ON o.id=u.office_id
         WHERE (u.username=? OR u.email=?) LIMIT 1"
    );
    $stmt->execute([$identity, $identity]);
    $user = $stmt->fetch();

    if (!$user || $user['status'] !== 'active') return false;
    if ($user['role'] !== 'admin' && $user['office_status'] !== 'active') return false;
    if (!password_verify($password, $user['password_hash'])) return false;

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    db()->prepare('UPDATE users SET last_login_at=NOW() WHERE id=?')->execute([(int)$user['id']]);
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
    if (!$user) redirect('login.php');
    if ($roles && !in_array($user['role'], $roles, true)) {
        http_response_code(403);
        exit('You do not have permission to access this page.');
    }
    return $user;
}

function dashboard_path(array $user): string
{
    return ($user['role'] ?? '') === 'admin' ? 'admin/dashboard.php' : 'office/dashboard.php';
}
