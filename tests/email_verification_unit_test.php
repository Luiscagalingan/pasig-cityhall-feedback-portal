<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/email_verification.php';

function expect_email_verification(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

putenv('PASIG_GOV_EMAIL_DOMAINS');
expect_email_verification(approved_government_email_domains() === [], 'missing allowlist disables invitations');
expect_email_verification(!is_approved_government_email('staff@example.com'), 'missing allowlist rejects valid-looking email');

putenv('PASIG_GOV_EMAIL_DOMAINS=EXAMPLE.COM,example.org,not a domain');
expect_email_verification(approved_government_email_domains() === ['example.com', 'example.org'], 'domains normalize and malformed entries are ignored');
expect_email_verification(is_approved_government_email('  Officer@Example.COM '), 'case and surrounding whitespace normalize before validation');
expect_email_verification(!is_approved_government_email('officer@unapproved.example'), 'unapproved domain is rejected');
expect_email_verification(!is_approved_government_email('not-an-email'), 'malformed email is rejected');
expect_email_verification(normalized_government_email(' Officer@Example.COM ') === 'officer@example.com', 'email normalization is consistent');

putenv('PASIG_GOV_EMAIL_DOMAINS');
echo "PASS: approved-domain configuration and email validation.\n";
