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
    $from = trim((string) getenv('PASIG_MAIL_FROM'));
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) return false;
    $publicBase = rtrim(trim((string) getenv('PASIG_PUBLIC_BASE_URL')), '/');
    $publicUrl = filter_var($publicBase, FILTER_VALIDATE_URL);
    $publicParts = $publicUrl ? parse_url($publicBase) : false;
    $publicHost = is_array($publicParts) ? strtolower((string)($publicParts['host'] ?? '')) : '';
    if (!$publicParts || !in_array(strtolower((string)($publicParts['scheme'] ?? '')), ['https', 'http'], true)
        || empty($publicParts['host']) || isset($publicParts['user']) || isset($publicParts['pass'])
        || isset($publicParts['query']) || isset($publicParts['fragment'])
        || (strtolower((string)$publicParts['scheme']) !== 'https' && !in_array($publicHost, ['localhost', '127.0.0.1'], true))) return false;
    $link = $publicBase . app_url('verify-email.php') . '#' . rawurlencode($token);
    $subject = 'Verify your Pasig City Hall Feedback Portal account';
    $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeLink = htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $body = '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#1b2638;line-height:1.6">'
        . '<h2>Pasig City Hall Feedback Portal</h2><p>Hello ' . $safeName . ',</p>'
        . '<p>An administrator created an authorized account for you. Verify your email address to activate it.</p>'
        . '<p><a href="' . $safeLink . '" style="display:inline-block;padding:12px 18px;background:#1769d2;color:#fff;text-decoration:none;border-radius:6px">Verify email address</a></p>'
        . '<p>This single-use link expires in 24 hours. If you did not expect this account invitation, ignore this message.</p>'
        . '</body></html>';
    $headers = [
        'From' => $from,
        'MIME-Version' => '1.0',
        'Content-Type' => 'text/html; charset=UTF-8',
        'X-Mailer' => 'PHP/' . PHP_VERSION,
    ];
    try {
        return @mail($email, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body,
            implode("\r\n", array_map(static fn(string $key, string $value): string => $key . ': ' . $value, array_keys($headers), $headers)));
    } catch (Throwable) {
        return false;
    }
}

function issue_verification_email(int $userId): bool
{
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id,email,full_name,status,email_verification_required,email_verified_at,verification_sent_at,verification_send_count FROM users WHERE id=? LIMIT 1");
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
    if (send_account_verification_email((string)$user['email'], (string)$user['full_name'], $token)) return true;
    $pdo->prepare('UPDATE users SET verification_token_hash=NULL,verification_expires_at=NULL WHERE id=? AND verification_token_hash=?')->execute([$userId, $hash]);
    return false;
}
