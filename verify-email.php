<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

$success = false;
$submitted = $_SERVER['REQUEST_METHOD'] === 'POST';
$httpStatus = 200;
$token = strtolower(trim((string)($_POST['token'] ?? '')));
$message = 'This verification link is invalid, expired, or has already been used.';
if ($submitted) {
    verify_csrf();
}
if ($submitted && preg_match('/^[a-f0-9]{64}$/', $token) === 1) {
    try {
        $stmt = db()->prepare("UPDATE users SET email_verified_at=NOW(),status='active',verification_token_hash=NULL,verification_expires_at=NULL WHERE verification_token_hash=? AND verification_expires_at>NOW() AND status='pending' AND email_verification_required=1 AND email_verified_at IS NULL AND (role='admin' OR EXISTS (SELECT 1 FROM offices o WHERE o.id=users.office_id AND o.status='active'))");
        $stmt->execute([hash('sha256', $token)]);
        if ($stmt->rowCount() === 1) {
            $success = true;
            $message = 'Your email address is verified and your account is active. You can now sign in.';
        }
    } catch (Throwable) {
        $httpStatus = 503;
        $message = 'We could not complete verification right now. Try the link again later or contact your administrator.';
    }
}
if ($submitted && !$success && $httpStatus === 200) $httpStatus = 400;
http_response_code($httpStatus);
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, private');
    header('Referrer-Policy: no-referrer');
}
render_public_start('Email Verification');
?>
<main class="verification-result"><section class="card"><h1><?= $success ? 'Email verified' : ($submitted ? 'Unable to verify email' : 'Confirm your email address') ?></h1><p><?= $success ? e('Your email address is verified and your account is active. You can now sign in.') : ($submitted ? e($message) : 'Confirm the email address for your Pasig City Hall Feedback Portal account to activate it.') ?></p><?php if (!$submitted): ?><form method="post" data-email-verification-form><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="token" value=""><button class="btn" type="submit">Confirm Email</button><p class="help" data-verification-link-error hidden>This verification link is invalid or missing. Open the complete link from your email.</p></form><?php elseif ($success): ?><a class="btn" href="<?= e(app_url('login.php')) ?>">Go to sign in</a><?php else: ?><a class="btn secondary" href="<?= e(app_url('login.php')) ?>">Go to sign in</a><?php endif; ?></section></main>
<?php if (!$submitted): ?><script>(()=>{const token=location.hash.slice(1);history.replaceState(null,'',location.pathname);const input=document.querySelector('[data-email-verification-form] input[name="token"]');const button=document.querySelector('[data-email-verification-form] button');if(/^[a-f0-9]{64}$/i.test(token)){input.value=token.toLowerCase()}else{button.disabled=true;document.querySelector('[data-verification-link-error]').hidden=false}})()</script><?php endif; ?>
<?php render_public_end(); ?>
