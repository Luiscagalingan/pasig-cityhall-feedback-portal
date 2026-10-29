<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
$admin = require_login(['admin']);
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Use the form to request or verify a code.']);
    exit;
}
verify_csrf();
$action = (string)($_POST['action'] ?? '');
$email = normalized_government_email((string)($_POST['email'] ?? ''));
try {
    if (!approved_government_email_domains()) throw new RuntimeException('Account invitations are unavailable until approved email domains are configured.');
    if (!is_approved_government_email($email)) throw new RuntimeException('Enter a valid email at an approved government or organization domain.');
    if ($action === 'send_email_code') {
        $name = trim((string)($_POST['full_name'] ?? ''));
        $username = trim((string)($_POST['username'] ?? ''));
        $officeId = post_int('office_id');
        if ($name === '' || mb_strlen($name) > 160 || !preg_match('/^[A-Za-z0-9._-]{3,80}$/', $username) || !$officeId) {
            throw new RuntimeException('Complete the office, full name, username, and approved email before requesting a code.');
        }
        $office = db()->prepare("SELECT id FROM offices WHERE id=? AND status='active'");
        $office->execute([$officeId]);
        if (!$office->fetchColumn()) throw new RuntimeException('Select an active office.');
        $dupe = db()->prepare('SELECT id FROM users WHERE username=? OR email=? LIMIT 1');
        $dupe->execute([$username, $email]);
        if ($dupe->fetchColumn()) throw new RuntimeException('That username or email address is already in use.');
        $ref = begin_head_email_code((int)$admin['id'], $email, $name);
        echo json_encode(['ok' => true, 'challenge' => $ref, 'message' => 'Code sent. Check the email inbox and enter the six-digit code.']);
        exit;
    }
    if ($action === 'verify_email_code') {
        $ok = verify_head_email_code((int)$admin['id'], $email, (string)($_POST['challenge'] ?? ''), trim((string)($_POST['code'] ?? '')));
        if (!$ok) throw new RuntimeException('That code is invalid, expired, or has reached its attempt limit. Request a new code and try again.');
        echo json_encode(['ok' => true, 'message' => 'Code confirmed. You can now create the Office Head account.']);
        exit;
    }
    throw new RuntimeException('Unknown email verification action.');
} catch (Throwable $e) {
    if ($e instanceof RuntimeException) {
        http_response_code(400);
        $message = $e->getMessage();
    } else {
        error_log('Office Head email-code request failed.');
        http_response_code(503);
        $message = 'The verification service is temporarily unavailable. Try again later.';
    }
    echo json_encode(['ok' => false, 'message' => $message], JSON_INVALID_UTF8_SUBSTITUTE);
}
