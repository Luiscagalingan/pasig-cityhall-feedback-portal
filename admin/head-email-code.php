<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
$admin = require_login(['admin']);
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');
$requestId = bin2hex(random_bytes(8));
header('X-Request-ID: ' . $requestId);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    error_log('[office-head-otp] request=' . $requestId . ' stage=method category=not_post');
    echo json_encode(['ok' => false, 'message' => 'Use the form to request or verify a code.', 'request_id' => $requestId]);
    exit;
}
if (!hash_equals(csrf_token(), (string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(419);
    error_log('[office-head-otp] request=' . $requestId . ' stage=csrf category=token_rejected');
    echo json_encode(['ok' => false, 'message' => 'Your security token expired. Refresh the page and try again.', 'request_id' => $requestId]);
    exit;
}
$action = (string)($_POST['action'] ?? '');
$email = normalized_government_email((string)($_POST['email'] ?? ''));
$stage = 'domain_configuration';
try {
    if (!approved_government_email_domains()) throw new HeadEmailCodeValidationException('domains_unconfigured', 'Account invitations are unavailable until approved email domains are configured.');
    $stage = 'recipient_domain';
    if (!is_approved_government_email($email)) throw new HeadEmailCodeValidationException('domain_rejected', 'Enter a valid email at an approved government or organization domain.');
    if ($action === 'send_email_code') {
        $stage = 'required_fields';
        $name = trim((string)($_POST['full_name'] ?? ''));
        $username = trim((string)($_POST['username'] ?? ''));
        $officeId = post_int('office_id');
        if ($name === '' || mb_strlen($name) > 160 || !preg_match('/^[A-Za-z0-9._-]{3,80}$/', $username) || !$officeId) {
            throw new HeadEmailCodeValidationException('required_fields_invalid', 'Complete the office, full name, username, and approved email before requesting a code.');
        }
        $stage = 'office_lookup';
        $office = db()->prepare("SELECT id FROM offices WHERE id=? AND status='active'");
        $office->execute([$officeId]);
        if (!$office->fetchColumn()) throw new HeadEmailCodeValidationException('office_inactive', 'Select an active office.');
        $stage = 'duplicate_email';
        $dupe = db()->prepare('SELECT id FROM users WHERE email=? LIMIT 1');
        $dupe->execute([$email]);
        if ($dupe->fetchColumn()) throw new HeadEmailCodeValidationException('duplicate_email', 'That email address is already in use.');
        $stage = 'challenge_send';
        $ref = begin_head_email_code((int)$admin['id'], $email, $name, $requestId);
        echo json_encode(['ok' => true, 'challenge' => $ref, 'message' => 'Code sent. Check the email inbox and enter the six-digit code.', 'request_id' => $requestId]);
        exit;
    }
    if ($action === 'verify_email_code') {
        $stage = 'otp_verification';
        $ok = verify_head_email_code((int)$admin['id'], $email, (string)($_POST['challenge'] ?? ''), trim((string)($_POST['code'] ?? '')));
        if (!$ok) throw new HeadEmailCodeValidationException('code_invalid_expired_or_locked', 'That code is invalid, expired, or has reached its attempt limit. Request a new code and try again.');
        echo json_encode(['ok' => true, 'message' => 'Code confirmed. You can now create the Office Head account.', 'request_id' => $requestId]);
        exit;
    }
    $stage = 'action';
    throw new HeadEmailCodeValidationException('unknown_action', 'Unknown email verification action.');
} catch (Throwable $e) {
    if ($e instanceof VerificationEmailDeliveryException) {
        http_response_code($e->httpStatus);
        $message = $e->getMessage();
        error_log('[office-head-otp] request=' . $requestId . ' stage=email_delivery category=' . $e->reason);
    } elseif ($e instanceof HeadEmailCodeValidationException) {
        http_response_code(400);
        $message = $e->getMessage();
        error_log('[office-head-otp] request=' . $requestId . ' stage=' . $stage . ' category=' . $e->reason);
    } elseif ($e instanceof PDOException) {
        error_log('[office-head-otp] request=' . $requestId . ' stage=' . $stage . ' category=database_failure');
        http_response_code(503);
        $message = 'The verification service is temporarily unavailable. Try again later.';
    } elseif ($e instanceof RuntimeException) {
        http_response_code(400);
        $message = $e->getMessage();
        error_log('[office-head-otp] request=' . $requestId . ' stage=' . $stage . ' category=request_rejected');
    } else {
        error_log('[office-head-otp] request=' . $requestId . ' stage=' . $stage . ' category=internal_error');
        http_response_code(503);
        $message = 'The verification service is temporarily unavailable. Try again later.';
    }
    echo json_encode(['ok' => false, 'message' => $message, 'request_id' => $requestId], JSON_INVALID_UTF8_SUBSTITUTE);
}
