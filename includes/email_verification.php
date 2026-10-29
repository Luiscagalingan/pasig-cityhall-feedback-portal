<?php
declare(strict_types=1);

function approved_government_email_domains(): array
{
    $configured = trim((string) getenv('PASIG_GOV_EMAIL_DOMAINS'));
    if ($configured === '') return [];
    $domains = array_map(static fn(string $domain): string => strtolower(trim(ltrim($domain, '@'))), explode(',', $configured));
    return array_values(array_unique(array_filter($domains, static fn(string $domain): bool =>
        $domain !== '' && preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain) === 1
    )));
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

function send_account_verification_email(string $email, string $name, string $token): bool
{
    $smtpHost = trim((string)getenv('PASIG_SMTP_HOST'));
    $smtpUser = trim((string)getenv('PASIG_SMTP_USER'));
    $smtpPassword = (string)getenv('PASIG_SMTP_PASSWORD');
    $smtpPort = (int)(getenv('PASIG_SMTP_PORT') ?: 587);
    $tlsMode = strtolower(trim((string)(getenv('PASIG_SMTP_TLS_MODE') ?: 'starttls')));
    $from = trim((string) getenv('PASIG_MAIL_FROM'));
    if ($smtpHost === '' || $smtpUser === '' || $smtpPassword === '' || $smtpPort < 1 || $smtpPort > 65535
        || !in_array($tlsMode, ['starttls', 'tls'], true) || !filter_var($from, FILTER_VALIDATE_EMAIL)) return false;
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
        require_once dirname(__DIR__) . '/PHPMailer-master/src/Exception.php';
        require_once dirname(__DIR__) . '/PHPMailer-master/src/PHPMailer.php';
        require_once dirname(__DIR__) . '/PHPMailer-master/src/SMTP.php';
        $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mailer->isSMTP(); $mailer->Host = $smtpHost; $mailer->SMTPAuth = true;
        $mailer->Username = $smtpUser; $mailer->Password = $smtpPassword; $mailer->Port = $smtpPort;
        $mailer->SMTPSecure = $tlsMode === 'tls' ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mailer->CharSet = 'UTF-8'; $mailer->setFrom($from, 'Pasig City Hall Feedback Portal');
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
