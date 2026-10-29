<?php
declare(strict_types=1);

function approved_government_email_domains(): array
{
    $configured = trim((string) getenv('PASIG_GOV_EMAIL_DOMAINS'));
    if ($configured === '') return [];
    $domains = array_map(static fn(string $domain): string => strtolower(trim(ltrim($domain, '@'))), explode(',', $configured));
    $domains = array_values(array_unique(array_filter($domains, static fn(string $domain): bool =>
        $domain !== '' && preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain) === 1
    )));
    return array_values(array_diff($domains, personal_email_domains()));
}

function personal_email_domains(): array
{
    return ['gmail.com', 'googlemail.com', 'yahoo.com', 'yahoo.co.uk', 'yahoo.com.ph', 'yahoo.com.au', 'yahoo.ca', 'yahoo.fr', 'yahoo.de', 'yahoo.co.in', 'yahoo.co.jp', 'outlook.com', 'outlook.co.uk', 'hotmail.com', 'hotmail.co.uk', 'live.com', 'live.co.uk', 'msn.com', 'icloud.com', 'me.com', 'aol.com', 'proton.me', 'protonmail.com', 'gmx.com', 'mail.com', 'yandex.com'];
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

function configured_verification_mailer(): ?\PHPMailer\PHPMailer\PHPMailer
{
    $smtpHost = trim((string)getenv('PASIG_SMTP_HOST'));
    $smtpUser = trim((string)getenv('PASIG_SMTP_USER'));
    $smtpPassword = (string)getenv('PASIG_SMTP_PASSWORD');
    $smtpPort = (int)(getenv('PASIG_SMTP_PORT') ?: 587);
    $tlsMode = strtolower(trim((string)(getenv('PASIG_SMTP_TLS_MODE') ?: 'starttls')));
    $from = trim((string)getenv('PASIG_MAIL_FROM'));
    if ($smtpHost === '' || $smtpUser === '' || $smtpPassword === '' || $smtpPort < 1 || $smtpPort > 65535
        || !in_array($tlsMode, ['starttls', 'tls'], true) || !filter_var($from, FILTER_VALIDATE_EMAIL)) return null;
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
        $mailer->CharSet = 'UTF-8';
        $mailer->setFrom($from, 'Pasig City Hall Feedback Portal');
        return $mailer;
    } catch (Throwable) {
        return null;
    }
}

function send_head_email_code(string $email, string $name, string $code): bool
{
    $mailer = configured_verification_mailer();
    if (!$mailer) return false;
    $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $mailer->addAddress($email, $name);
    $mailer->Subject = 'Verify Office Head Email Address';
    $mailer->isHTML(true);
    $mailer->Body = '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#1b2638;line-height:1.6"><main style="max-width:560px;margin:24px auto;padding:24px;background:#fff;border:1px solid #dbe3ef;border-radius:10px"><h2>Verify your email address</h2><p>Hello ' . $safeName . ',</p><p>Enter this code in the Office Head account form to confirm this email address:</p><p style="font-size:30px;font-weight:bold;letter-spacing:8px">' . $code . '</p><p>This code expires in 10 minutes and can be used once. If you did not expect this message, you can ignore it.</p><p>Pasig City Hall Feedback Portal</p></main></body></html>';
    $mailer->AltBody = "Hello {$name},\n\nYour Office Head email verification code is {$code}. It expires in 10 minutes and can be used once.\n\nPasig City Hall Feedback Portal";
    try { return $mailer->send(); } catch (Throwable) { return false; }
}

function begin_head_email_code(int $adminId, string $email, string $name): string
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM office_head_email_codes WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)');
        $lock = $pdo->prepare('SELECT id FROM users WHERE id=? AND role=\'admin\' AND status=\'active\' FOR UPDATE');
        $lock->execute([$adminId]);
        if (!$lock->fetchColumn()) throw new RuntimeException('Your admin session is no longer active.');
        $rate = $pdo->prepare('SELECT MAX(sent_at) last_sent,COUNT(*) send_count FROM office_head_email_codes WHERE admin_user_id=? AND email=? AND sent_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR)');
        $rate->execute([$adminId, $email]);
        $limits = $rate->fetch() ?: [];
        if (!empty($limits['last_sent']) && strtotime((string)$limits['last_sent']) > time() - 60) throw new RuntimeException('Wait one minute before requesting another code.');
        if ((int)($limits['send_count'] ?? 0) >= 5) throw new RuntimeException('The code resend limit is five per email address per day. Try again later.');
        $pdo->prepare('UPDATE office_head_email_codes SET consumed_at=NOW() WHERE admin_user_id=? AND email=? AND consumed_at IS NULL')->execute([$adminId, $email]);
        $reference = bin2hex(random_bytes(32));
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $insert = $pdo->prepare('INSERT INTO office_head_email_codes(challenge_hash,admin_user_id,email,code_hash,sent_at,expires_at) VALUES(?,?,?,?,NOW(),DATE_ADD(NOW(),INTERVAL 10 MINUTE))');
        $insert->execute([hash('sha256', $reference), $adminId, $email, password_hash($code, PASSWORD_DEFAULT)]);
        $pdo->commit();
        if (send_head_email_code($email, $name, $code)) return $reference;
        $pdo->prepare('UPDATE office_head_email_codes SET code_hash=NULL,consumed_at=NOW() WHERE challenge_hash=?')->execute([hash('sha256', $reference)]);
        throw new RuntimeException('Could not send the code. Check SMTP configuration and try again later.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function verify_head_email_code(int $adminId, string $email, string $reference, string $code): bool
{
    if (preg_match('/^[a-f0-9]{64}$/', $reference) !== 1 || preg_match('/^\d{6}$/', $code) !== 1) return false;
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT id,code_hash,attempts,expires_at,verified_at FROM office_head_email_codes WHERE challenge_hash=? AND admin_user_id=? AND email=? AND consumed_at IS NULL FOR UPDATE');
        $stmt->execute([hash('sha256', $reference), $adminId, $email]);
        $challenge = $stmt->fetch();
        if (!$challenge || strtotime((string)$challenge['expires_at']) <= time() || (int)$challenge['attempts'] >= 5 || empty($challenge['code_hash'])) {
            $pdo->commit();
            return false;
        }
        if ($challenge['verified_at'] !== null) { $pdo->commit(); return true; }
        if (!password_verify($code, (string)$challenge['code_hash'])) {
            $pdo->prepare('UPDATE office_head_email_codes SET attempts=attempts+1,consumed_at=IF(attempts+1>=5,NOW(),consumed_at) WHERE id=?')->execute([(int)$challenge['id']]);
            $pdo->commit();
            return false;
        }
        $pdo->prepare('UPDATE office_head_email_codes SET verified_at=NOW() WHERE id=? AND verified_at IS NULL')->execute([(int)$challenge['id']]);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function consume_head_email_code(PDO $pdo, int $adminId, string $email, string $reference): bool
{
    if (preg_match('/^[a-f0-9]{64}$/', $reference) !== 1) return false;
    $stmt = $pdo->prepare('UPDATE office_head_email_codes SET consumed_at=NOW() WHERE challenge_hash=? AND admin_user_id=? AND email=? AND verified_at IS NOT NULL AND expires_at>NOW() AND consumed_at IS NULL');
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
    $subject = 'Office Head Account Created – Verify Your Email';
    $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeOffice = htmlspecialchars((string)($GLOBALS['verification_mail_office_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeUsername = htmlspecialchars((string)($GLOBALS['verification_mail_username'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $isHead = !empty($GLOBALS['verification_mail_is_head']);
    $roleLabel = $isHead ? 'Office Head' : 'Office Staff';
    $credentialBlock = $isHead ? '<br><b>Temporary Password:</b> password123!' : '';
    $plainPassword = $isHead ? "Temporary Password: password123!\n" : '';
    $safeLink = htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $body = '<!doctype html><html><body style="margin:0;background:#f3f5f9;font-family:Arial,sans-serif;color:#1b2638;line-height:1.6"><main style="max-width:600px;margin:24px auto;padding:28px;background:#fff;border-radius:10px"><h2>Pasig City Hall Feedback Portal</h2><p>Hello ' . $safeName . ',</p><p>Your ' . $roleLabel . ' account has been created.</p><p><b>Assigned Office:</b> ' . $safeOffice . '<br><b>Username:</b> ' . $safeUsername . $credentialBlock . '</p><p>Verify your email before signing in.</p><p><a href="' . $safeLink . '" style="display:inline-block;padding:12px 18px;background:#1769d2;color:#fff;text-decoration:none;border-radius:6px">Verify Email Address</a></p><p>After verification, sign in with your account credentials. ' . ($isHead ? 'You must change the temporary password before accessing your dashboard. ' : '') . 'This single-use link expires in 24 hours.</p><p>Regards,<br>System Administration</p></main></body></html>';
    $plain = "Hello {$name},\n\nYour {$roleLabel} account has been created.\nAssigned Office: " . ($GLOBALS['verification_mail_office_name'] ?? '') . "\nUsername: " . ($GLOBALS['verification_mail_username'] ?? '') . "\n" . $plainPassword . "\nVerify your email: {$link}\n\n" . ($isHead ? "After verification, sign in and change your temporary password before accessing your dashboard.\n\n" : '') . "This link expires in 24 hours.\n\nRegards,\nSystem Administration";
    try {
        $mailer = configured_verification_mailer();
        if (!$mailer) return false;
        $mailer->addAddress($email, $name); $mailer->Subject = $subject; $mailer->isHTML(true);
        $mailer->Body = $body; $mailer->AltBody = $plain;
        return $mailer->send();
    } catch (Throwable) {
        return false;
    }
}

function issue_verification_email(int $userId): bool
{
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
