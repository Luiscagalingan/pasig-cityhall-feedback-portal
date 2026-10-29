<?php
declare(strict_types=1);

final class VerificationEmailDeliveryException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message, public readonly int $httpStatus = 502)
    {
        parent::__construct($message);
    }
}

final class HeadEmailCodeValidationException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message, public readonly ?int $retryAfterSeconds = null)
    {
        parent::__construct($message);
    }
}

function approved_government_email_domains(): array
{
    $configured = trim((string) getenv('PASIG_GOV_EMAIL_DOMAINS'));
    if ($configured === '') return [];
    $domains = array_map(static fn(string $domain): string => strtolower(trim(ltrim($domain, '@'))), explode(',', $configured));
    $domains = array_values(array_unique(array_filter($domains, static fn(string $domain): bool =>
        $domain !== '' && preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain) === 1
    )));
    return $domains;
}

function normalized_government_email(string $email): string
{
    return mb_strtolower(trim($email), 'UTF-8');
}

function is_approved_government_email(string $email): bool
{
    $email = normalized_government_email($email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email, 'UTF-8') > 160) return false;
    $domains = approved_government_email_domains();
    if (!$domains) return false;
    $domain = strtolower(substr(strrchr($email, '@') ?: '', 1));
    return in_array($domain, $domains, true);
}

/**
 * OTP rows created before UTC timestamps were adopted used the database session
 * timezone (currently Asia/Manila). created_at is a TIMESTAMP, so a legacy
 * sent_at matches its session-local value; new sent_at values are UTC DATETIMEs.
 * Normalize legacy values on read without changing any stored records.
 */
function office_head_otp_utc_sql(string $column): string
{
    if (!in_array($column, ['sent_at', 'expires_at'], true)) {
        throw new InvalidArgumentException('Unsupported OTP timestamp column.');
    }
    $legacyRow = 'ABS(TIMESTAMPDIFF(SECOND,sent_at,created_at))<=2';
    $sessionOffsetMinutes = 'TIMESTAMPDIFF(MINUTE,UTC_TIMESTAMP(),NOW())';
    return "(CASE WHEN {$legacyRow} THEN DATE_SUB({$column},INTERVAL {$sessionOffsetMinutes} MINUTE) ELSE {$column} END)";
}

function configured_verification_mailer(?string $requestId = null): ?\PHPMailer\PHPMailer\PHPMailer
{
    $logContext = '[email-transport]' . ($requestId ? ' request=' . $requestId : '');
    $smtpHost = trim((string)getenv('PASIG_SMTP_HOST'));
    $smtpUser = trim((string)getenv('PASIG_SMTP_USER'));
    $smtpPassword = (string)getenv('PASIG_SMTP_PASSWORD');
    $smtpPort = (int)(getenv('PASIG_SMTP_PORT') ?: 587);
    $tlsMode = strtolower(trim((string)(getenv('PASIG_SMTP_TLS_MODE') ?: 'starttls')));
    $from = trim((string)getenv('PASIG_MAIL_FROM'));
    if ($smtpHost === '' || $smtpUser === '' || $smtpPassword === '' || $smtpPort < 1 || $smtpPort > 65535
        || !in_array($tlsMode, ['starttls', 'tls'], true) || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
        error_log($logContext . ' stage=smtp_config category=missing_or_invalid_configuration');
        return null;
    }
    // Port 587 is message submission with STARTTLS. Accept the common generic
    // value "tls" on that port, but never send in cleartext.
    if ($smtpPort === 587 && $tlsMode === 'tls') {
        $tlsMode = 'starttls';
        error_log($logContext . ' stage=smtp_config category=587_normalized_to_starttls');
    }
    if ($smtpPort === 465 && $tlsMode !== 'tls') {
        error_log($logContext . ' stage=smtp_config category=465_requires_implicit_tls');
        return null;
    }
    try {
        require_once dirname(__DIR__) . '/PHPMailer-master/src/Exception.php';
        require_once dirname(__DIR__) . '/PHPMailer-master/src/PHPMailer.php';
        require_once dirname(__DIR__) . '/PHPMailer-master/src/SMTP.php';
        $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host = $smtpHost;
        $mailer->SMTPAuth = true;
        $mailer->Username = $smtpUser;
        $mailer->Password = $smtpPassword;
        $mailer->Port = $smtpPort;
        $mailer->SMTPSecure = $tlsMode === 'tls'
            ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
            : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mailer->Timeout = 10;
        $mailer->SMTPKeepAlive = false;
        $mailer->SMTPDebug = 0;
        $mailer->getSMTPInstance()->Timelimit = 12;
        $mailer->CharSet = 'UTF-8';
        $mailer->setFrom($from, 'Service Satisfaction System');
        return $mailer;
    } catch (Throwable) {
        error_log($logContext . ' stage=smtp_config category=mailer_initialization_failure');
        return null;
    }
}

function verification_email_transport(): string
{
    $transport = strtolower(trim((string)(getenv('PASIG_EMAIL_TRANSPORT') ?: 'resend')));
    return in_array($transport, ['resend', 'smtp'], true) ? $transport : 'invalid';
}

/** Send one transactional message; Resend is the default and SMTP is opt-in only. */
function deliver_verification_email(string $email, string $name, string $subject, string $html, string $text, ?string $requestId = null): bool
{
    $context = '[email-transport]' . ($requestId ? ' request=' . $requestId : '');
    $transport = verification_email_transport();
    if ($transport === 'invalid') {
        error_log($context . ' stage=transport_config category=unsupported_transport');
        throw new VerificationEmailDeliveryException('unsupported_transport', 'Email delivery is not configured correctly. Set PASIG_EMAIL_TRANSPORT to resend or smtp.', 503);
    }
    if ($transport === 'resend') {
        $apiKey = trim((string)getenv('RESEND_API_KEY'));
        $from = trim((string)getenv('RESEND_FROM_EMAIL'));
        $fromAddress = $from;
        if (preg_match('/^.{1,100}\s+<([^<>\s]+)>$/u', $from, $fromMatch) === 1) $fromAddress = $fromMatch[1];
        if ($apiKey === '' || preg_match('/[\r\n]/', $apiKey) || $from === '' || strlen($from) > 254 || !filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
            error_log($context . ' stage=resend_config category=missing_or_invalid_configuration');
            throw new VerificationEmailDeliveryException('resend_configuration', 'Email delivery is not configured. Set RESEND_API_KEY and a valid RESEND_FROM_EMAIL that belongs to a verified Resend domain.', 503);
        }
        $resendFrom = 'Service Satisfaction System <' . $fromAddress . '>';
        $payload = json_encode(['from' => $resendFrom, 'to' => [$email], 'subject' => $subject, 'html' => $html, 'text' => $text], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($payload === false) {
            error_log($context . ' stage=resend_request category=payload_encoding_failure');
            throw new VerificationEmailDeliveryException('request_encoding', 'The email could not be prepared. Please try again later.', 503);
        }
        $httpContext = stream_context_create([
            'http' => ['method' => 'POST', 'header' => "Authorization: Bearer {$apiKey}\r\nContent-Type: application/json\r\nAccept: application/json\r\n", 'content' => $payload, 'timeout' => 12, 'ignore_errors' => true, 'protocol_version' => 1.1],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false],
        ]);
        $response = @file_get_contents('https://api.resend.com/emails', false, $httpContext);
        $status = 0;
        foreach (($http_response_header ?? []) as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/i', $header, $match)) $status = (int)$match[1];
        }
        if ($status >= 200 && $status < 300 && $response !== false) {
            error_log($context . ' stage=resend_send category=accepted_by_api');
            return true;
        }
        $category = 'api_request_failed';
        $message = 'The email service could not accept the message. Please try again later.';
        $httpStatus = 502;
        if ($status === 401 || $status === 403) {
            $category = 'api_authentication_failed';
            $message = 'The email service rejected its API credentials. Check RESEND_API_KEY in Railway.';
            $httpStatus = 503;
        } elseif ($status === 422) {
            $category = 'sender_or_recipient_rejected';
            $message = 'The email service rejected the sender or recipient. Verify RESEND_FROM_EMAIL in Resend and confirm the sender domain is verified.';
        } elseif ($status === 429) {
            $category = 'api_rate_limited';
            $message = 'The email service is temporarily rate limiting requests. Please wait and try again.';
        } elseif ($status >= 500) {
            $category = 'api_provider_failure';
        } elseif ($status === 0) {
            $lastError = error_get_last();
            $details = strtolower((string)($lastError['message'] ?? ''));
            if (str_contains($details, 'timed out')) {
                $category = 'https_timeout';
                $message = 'The email service did not respond in time. Please try again shortly.';
            } elseif (preg_match('/ssl|tls|certificate|crypto|handshake/', $details)) {
                $category = 'https_tls_failure';
                $message = 'A secure connection to the email service failed. Please try again later.';
            } else {
                $category = 'https_connection_failure';
                $message = 'Could not connect securely to the email service. Please try again later.';
            }
        }
        error_log($context . ' stage=resend_send category=' . $category . ($status ? ' http_status=' . $status : ''));
        throw new VerificationEmailDeliveryException($category, $message, $httpStatus);
    }

    $mailer = configured_verification_mailer($requestId);
    if (!$mailer) {
        throw new VerificationEmailDeliveryException('smtp_configuration', 'SMTP email delivery is not configured. Check the SMTP settings.', 503);
    }
    try {
        $mailer->addAddress($email, $name);
        $mailer->Subject = $subject;
        $mailer->isHTML(true);
        $mailer->Body = $html;
        $mailer->AltBody = $text;
        $sent = $mailer->send();
        if (!$sent) throw new RuntimeException((string)$mailer->ErrorInfo);
        error_log($context . ' stage=smtp_send category=accepted_by_smtp');
        return true;
    } catch (Throwable $e) {
        $category = smtp_failure_category($e->getMessage());
        error_log($context . ' stage=smtp_send category=' . $category);
        throw new VerificationEmailDeliveryException($category, 'SMTP could not send the email. Check the SMTP settings and try again.', 502);
    }
}

function send_head_email_code(string $email, string $name, string $code, ?string $requestId = null): bool
{
    $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $html = '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#1b2638;line-height:1.6"><main style="max-width:560px;margin:24px auto;padding:24px;background:#fff;border:1px solid #dbe3ef;border-radius:10px"><h2>Service Satisfaction System</h2><p>Hello ' . $safeName . ',</p><p>Enter this code in the Office Head account form to verify your email address:</p><p style="font-size:30px;font-weight:bold;letter-spacing:8px">' . $code . '</p><p>This code expires in 10 minutes and can be used once. If you did not expect this message, you can ignore it.</p><p>Regards,<br>Service Satisfaction System</p></main></body></html>';
    $text = "Hello {$name},\n\nYour Office Head email verification code is {$code}. It expires in 10 minutes and can be used once. If you did not request it, you can ignore this email.\n\nRegards,\nService Satisfaction System";
    return deliver_verification_email($email, $name, 'Service Satisfaction System - Verify Office Head Email', str_replace(['Verify your email address', 'Enter this code in the Office Head account form to verify your email address:', 'Pasig City Hall Feedback Portal'], ['Service Satisfaction System', 'Enter this code in the Office Head account form to verify your email address:', 'Regards,<br>Service Satisfaction System'], $html), $text, $requestId);
}

function smtp_failure_category(string $details): string
{
    $details = strtolower($details);
    if (preg_match('/authenticat|authentication|\bauth\b|\b535\b|5\.7\.8/', $details)) return 'authentication_failure';
    if (preg_match('/timed?\s*out|timeout|deadline exceeded/', $details)) return 'connection_timeout';
    if (preg_match('/starttls|\btls\b|\bssl\b|certificate|handshake|crypto/', $details)) return 'tls_failure';
    if (preg_match('/could not connect|connection refused|network is unreachable|failed to connect|connect\(\).*failed|smtp connect|socket/', $details)) return 'connection_failure';
    return 'smtp_send_failure';
}

function begin_head_email_code(int $adminId, string $email, string $name, ?string $requestId = null): string
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM office_head_email_codes WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)');
        $lock = $pdo->prepare('SELECT id FROM users WHERE id=? AND role=\'admin\' AND status=\'active\' FOR UPDATE');
        $lock->execute([$adminId]);
        if (!$lock->fetchColumn()) throw new HeadEmailCodeValidationException('admin_inactive', 'Your admin session is no longer active.');
        $sentUtc = office_head_otp_utc_sql('sent_at');
        $rate = $pdo->prepare("SELECT MAX({$sentUtc}) last_sent,COUNT(*) send_count,GREATEST(0,60-TIMESTAMPDIFF(SECOND,MAX({$sentUtc}),UTC_TIMESTAMP())) cooldown_remaining FROM office_head_email_codes WHERE admin_user_id=? AND email=? AND {$sentUtc}>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 24 HOUR)");
        $rate->execute([$adminId, $email]);
        $limits = $rate->fetch() ?: [];
        $cooldownRemaining = (int)($limits['cooldown_remaining'] ?? 0);
        if (!empty($limits['last_sent']) && $cooldownRemaining > 0) {
            error_log('[office-head-otp]' . ($requestId ? ' request=' . $requestId : '') . ' stage=resend_cooldown category=blocked remaining_seconds=' . $cooldownRemaining);
            throw new HeadEmailCodeValidationException('resend_cooldown', 'A new code can be requested after the resend cooldown.', $cooldownRemaining);
        }
        error_log('[office-head-otp]' . ($requestId ? ' request=' . $requestId : '') . ' stage=resend_cooldown category=allowed remaining_seconds=0');
        if ((int)($limits['send_count'] ?? 0) >= 5) {
            error_log('[office-head-otp]' . ($requestId ? ' request=' . $requestId : '') . ' stage=rate_limit category=daily_limit_reached');
            throw new HeadEmailCodeValidationException('daily_send_limit', 'The code resend limit is five per email address per day. Try again later.');
        }
        $pdo->prepare('UPDATE office_head_email_codes SET consumed_at=UTC_TIMESTAMP() WHERE admin_user_id=? AND email=? AND consumed_at IS NULL')->execute([$adminId, $email]);
        $reference = bin2hex(random_bytes(32));
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $insert = $pdo->prepare('INSERT INTO office_head_email_codes(challenge_hash,admin_user_id,email,code_hash,sent_at,expires_at) VALUES(?,?,?,?,UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 10 MINUTE))');
        $insert->execute([hash('sha256', $reference), $adminId, $email, password_hash($code, PASSWORD_DEFAULT)]);
        $pdo->commit();
        error_log('[office-head-otp]' . ($requestId ? ' request=' . $requestId : '') . ' stage=otp_generated category=challenge_stored valid_for_seconds=600 created_at_utc=' . gmdate('Y-m-d\\TH:i:s\\Z'));
        try {
            send_head_email_code($email, $name, $code, $requestId);
            $activate = $pdo->prepare('UPDATE office_head_email_codes SET sent_at=UTC_TIMESTAMP(),expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 10 MINUTE) WHERE challenge_hash=? AND consumed_at IS NULL');
            $activate->execute([hash('sha256', $reference)]);
            $expiresUtc = office_head_otp_utc_sql('expires_at');
            $activeCheck = $pdo->prepare("SELECT id FROM office_head_email_codes WHERE challenge_hash=? AND code_hash IS NOT NULL AND consumed_at IS NULL AND {$expiresUtc}>UTC_TIMESTAMP()");
            $activeCheck->execute([hash('sha256', $reference)]);
            if (!$activeCheck->fetchColumn()) throw new VerificationEmailDeliveryException('challenge_activation_failed', 'The code could not be activated. Please request a new code.', 503);
            error_log('[office-head-otp]' . ($requestId ? ' request=' . $requestId : '') . ' stage=otp_activated category=delivery_accepted valid_for_seconds=600 created_at_utc=' . gmdate('Y-m-d\\TH:i:s\\Z'));
            return $reference;
        } catch (VerificationEmailDeliveryException $e) {
            $pdo->prepare('UPDATE office_head_email_codes SET code_hash=NULL,consumed_at=UTC_TIMESTAMP() WHERE challenge_hash=?')->execute([hash('sha256', $reference)]);
            throw $e;
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function verify_head_email_code(int $adminId, string $email, string $reference, string $code, ?string $requestId = null): string
{
    if (preg_match('/^[a-f0-9]{64}$/', $reference) !== 1 || preg_match('/^\d{6}$/', $code) !== 1) {
        error_log('[office-head-otp]' . ($requestId ? ' request=' . $requestId : '') . ' stage=otp_verification category=malformed_request');
        return 'invalid';
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $expiresUtc = office_head_otp_utc_sql('expires_at');
        $stmt = $pdo->prepare("SELECT id,code_hash,attempts,expires_at,verified_at,TIMESTAMPDIFF(SECOND,UTC_TIMESTAMP(),{$expiresUtc}) seconds_remaining FROM office_head_email_codes WHERE challenge_hash=? AND admin_user_id=? AND email=? AND consumed_at IS NULL FOR UPDATE");
        $stmt->execute([hash('sha256', $reference), $adminId, $email]);
        $challenge = $stmt->fetch();
        if (!$challenge) {
            $pdo->commit();
            error_log('[office-head-otp]' . ($requestId ? ' request=' . $requestId : '') . ' stage=otp_verification category=challenge_missing_consumed_or_invalid');
            return 'invalid';
        }
        $remaining = (int)$challenge['seconds_remaining'];
        if ($remaining <= 0) {
            $pdo->commit();
            error_log('[office-head-otp]' . ($requestId ? ' request=' . $requestId : '') . ' stage=otp_expiration category=expired remaining_seconds=' . $remaining . ' checked_at_utc=' . gmdate('Y-m-d\\TH:i:s\\Z'));
            return 'expired';
        }
        if ((int)$challenge['attempts'] >= 5 || empty($challenge['code_hash'])) {
            $pdo->commit();
            error_log('[office-head-otp]' . ($requestId ? ' request=' . $requestId : '') . ' stage=otp_verification category=attempt_limit_reached');
            return 'locked';
        }
        if ($challenge['verified_at'] !== null) {
            $pdo->commit();
            error_log('[office-head-otp]' . ($requestId ? ' request=' . $requestId : '') . ' stage=otp_verification category=already_verified otp_seconds_remaining=' . $remaining);
            return 'verified';
        }
        if (!password_verify($code, (string)$challenge['code_hash'])) {
            $pdo->prepare('UPDATE office_head_email_codes SET attempts=attempts+1,consumed_at=IF(attempts+1>=5,UTC_TIMESTAMP(),consumed_at) WHERE id=?')->execute([(int)$challenge['id']]);
            $pdo->commit();
            $remainingAttempts = max(0, 4 - (int)$challenge['attempts']);
            error_log('[office-head-otp]' . ($requestId ? ' request=' . $requestId : '') . ' stage=otp_verification category=incorrect_code attempts_remaining=' . $remainingAttempts . ' otp_seconds_remaining=' . $remaining);
            return $remainingAttempts === 0 ? 'locked' : 'incorrect';
        }
        $pdo->prepare('UPDATE office_head_email_codes SET verified_at=UTC_TIMESTAMP() WHERE id=? AND verified_at IS NULL')->execute([(int)$challenge['id']]);
        $pdo->commit();
        error_log('[office-head-otp]' . ($requestId ? ' request=' . $requestId : '') . ' stage=otp_verification category=verified otp_seconds_remaining=' . $remaining . ' checked_at_utc=' . gmdate('Y-m-d\\TH:i:s\\Z'));
        return 'verified';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function consume_head_email_code(PDO $pdo, int $adminId, string $email, string $reference): bool
{
    if (preg_match('/^[a-f0-9]{64}$/', $reference) !== 1) return false;
    $expiresUtc = office_head_otp_utc_sql('expires_at');
    $stmt = $pdo->prepare("UPDATE office_head_email_codes SET consumed_at=UTC_TIMESTAMP() WHERE challenge_hash=? AND admin_user_id=? AND email=? AND verified_at IS NOT NULL AND {$expiresUtc}>UTC_TIMESTAMP() AND consumed_at IS NULL");
    $stmt->execute([hash('sha256', $reference), $adminId, $email]);
    return $stmt->rowCount() === 1;
}

function send_account_verification_email(string $email, string $name, string $token): bool
{
    $publicBase = rtrim(trim((string) getenv('PASIG_PUBLIC_BASE_URL')), '/');
    $publicUrl = filter_var($publicBase, FILTER_VALIDATE_URL);
    $publicParts = $publicUrl ? parse_url($publicBase) : false;
    $publicHost = is_array($publicParts) ? strtolower((string)($publicParts['host'] ?? '')) : '';
    if (!$publicParts || !in_array(strtolower((string)($publicParts['scheme'] ?? '')), ['https', 'http'], true)
        || empty($publicParts['host']) || isset($publicParts['user']) || isset($publicParts['pass'])
        || isset($publicParts['query']) || isset($publicParts['fragment'])
        || (strtolower((string)$publicParts['scheme']) !== 'https' && !in_array($publicHost, ['localhost', '127.0.0.1'], true))) return false;
    $link = $publicBase . app_url('verify-email.php') . '#' . rawurlencode($token);
    $subject = 'Service Satisfaction System - Verify Your Email';
    $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeOffice = htmlspecialchars((string)($GLOBALS['verification_mail_office_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeUsername = htmlspecialchars((string)($GLOBALS['verification_mail_username'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $isHead = !empty($GLOBALS['verification_mail_is_head']);
    $roleLabel = $isHead ? 'Office Head' : 'Office Staff';
    $credentialBlock = $isHead ? '<br><b>Temporary Password:</b> password123!' : '';
    $plainPassword = $isHead ? "Temporary Password: password123!\n" : '';
    $safeLink = htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $body = '<!doctype html><html><body style="margin:0;background:#f3f5f9;font-family:Arial,sans-serif;color:#1b2638;line-height:1.6"><main style="max-width:600px;margin:24px auto;padding:28px;background:#fff;border-radius:10px"><h2>Service Satisfaction System</h2><p>Hello ' . $safeName . ',</p><p>Your ' . $roleLabel . ' account has been created.</p><p><b>Assigned Office:</b> ' . $safeOffice . '<br><b>Username:</b> ' . $safeUsername . $credentialBlock . '</p><p>Verify your email before signing in.</p><p><a href="' . $safeLink . '" style="display:inline-block;padding:12px 18px;background:#1769d2;color:#fff;text-decoration:none;border-radius:6px">Verify Email Address</a></p><p>After verification, sign in with your account credentials. ' . ($isHead ? 'You must change the temporary password before accessing your dashboard. ' : '') . 'This single-use link expires in 24 hours.</p><p>Regards,<br>Service Satisfaction System</p></main></body></html>';
    $plain = "Hello {$name},\n\nYour {$roleLabel} account has been created.\nAssigned Office: " . ($GLOBALS['verification_mail_office_name'] ?? '') . "\nUsername: " . ($GLOBALS['verification_mail_username'] ?? '') . "\n" . $plainPassword . "\nVerify your email: {$link}\n\n" . ($isHead ? "After verification, sign in and change your temporary password before accessing your dashboard.\n\n" : '') . "This link expires in 24 hours.\n\nRegards,\nService Satisfaction System";
    try {
        return deliver_verification_email($email, $name, $subject, $body, $plain);
    } catch (VerificationEmailDeliveryException $e) {
        $GLOBALS['verification_email_delivery_error'] = $e->getMessage();
        return false;
    }
}

function issue_verification_email(int $userId): bool
{
    $GLOBALS['verification_email_delivery_error'] = '';
    $pdo = db();
    $stmt = $pdo->prepare("SELECT u.id,u.email,u.full_name,u.username,u.role,u.status,u.email_verification_required,u.email_verified_at,u.verification_sent_at,u.verification_send_count,o.name AS office_name FROM users u LEFT JOIN offices o ON o.id=u.office_id WHERE u.id=? LIMIT 1");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user || !(int)$user['email_verification_required'] || $user['email_verified_at'] !== null || $user['status'] !== 'pending') return false;
    $lastSent = $user['verification_sent_at'] ? strtotime((string)$user['verification_sent_at']) : false;
    if ($lastSent && $lastSent > time() - 60) return false;
    $count = (int)$user['verification_send_count'];
    if ($lastSent && $lastSent > time() - 86400 && $count >= 5) return false;
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $update = $pdo->prepare("UPDATE users SET verification_send_count=IF(verification_sent_at IS NULL OR verification_sent_at < DATE_SUB(NOW(), INTERVAL 24 HOUR),1,verification_send_count+1),verification_token_hash=?,verification_expires_at=DATE_ADD(NOW(), INTERVAL 24 HOUR),verification_sent_at=NOW() WHERE id=? AND status='pending' AND email_verification_required=1 AND email_verified_at IS NULL AND (verification_sent_at IS NULL OR verification_sent_at<=DATE_SUB(NOW(), INTERVAL 60 SECOND)) AND (verification_sent_at IS NULL OR verification_sent_at<=DATE_SUB(NOW(), INTERVAL 24 HOUR) OR verification_send_count<5)");
    $update->execute([$hash, $userId]);
    if ($update->rowCount() !== 1) return false;
    $GLOBALS['verification_mail_office_name'] = (string)($user['office_name'] ?? '');
    $GLOBALS['verification_mail_username'] = (string)$user['username'];
    $GLOBALS['verification_mail_is_head'] = $user['role'] === 'office_head';
    $sent = send_account_verification_email((string)$user['email'], (string)$user['full_name'], $token);
    unset($GLOBALS['verification_mail_office_name'], $GLOBALS['verification_mail_username'], $GLOBALS['verification_mail_is_head']);
    if ($sent) return true;
    $pdo->prepare('UPDATE users SET verification_token_hash=NULL,verification_expires_at=NULL WHERE id=? AND verification_token_hash=?')->execute([$userId, $hash]);
    return false;
}
