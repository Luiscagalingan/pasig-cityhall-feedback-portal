# Authorized user email verification setup

New Office Staff and Office Head accounts remain pending until the recipient verifies the email address. Existing accounts are grandfathered: `email_verification_required` defaults to `0`, and the migration does not change their status.

## Required configuration

Set these environment variables in the PHP application runtime:

- `PASIG_GOV_EMAIL_DOMAINS`: comma-separated allowlist of approved government email domains, without `@` (for example, `domain-one.gov,domain-two.gov`). **The project does not establish the official Pasig City Government email domain. Obtain confirmation from the system owner before setting this value.** Account invitations are disabled until at least one valid domain is configured.
- `PASIG_MAIL_FROM`: sender address authorized by the configured mail transport.
- `PASIG_PUBLIC_BASE_URL`: absolute public site origin, such as `https://feedback.example.gov.ph` (use `http://localhost` only for local development). It is combined with the existing `APP_BASE_URL` path to create an absolute verification link. Invitations remain pending if this value is absent or invalid.
- Docker SMTP transport: set `PASIG_SMTP_HOST`, `PASIG_SMTP_PORT` (defaults to 587), `PASIG_SMTP_USER`, `PASIG_SMTP_PASSWORD`, and `PASIG_SMTP_TLS_MODE` (`starttls` or `tls`). Also set `PASIG_MAIL_FROM`. The entrypoint configures the bundled sendmail-compatible `msmtp` transport at container startup. SMTP credentials stay in runtime environment variables; the temporary msmtp config uses `passwordeval` and is mode 0600. Do not put credentials in Docker build arguments or commit them.

Verification messages use the bundled `PHPMailer-master` library with authenticated SMTP. Set the SMTP variables above in the PHP runtime environment; `PASIG_SMTP_TLS_MODE` accepts `starttls` (normally port 587) or `tls` (normally port 465). SMTP credentials are not stored in source files. The Docker runtime also configures its sendmail-compatible transport when these variables are present, but PHPMailer connects directly to SMTP. Without valid SMTP settings or a reachable relay, sending fails and the account remains pending. PHPMailer reporting a successful SMTP handoff does not guarantee mailbox delivery.

Ensure that `PASIG_PUBLIC_BASE_URL` and the existing `APP_BASE_URL` point to the deployed application and that the recipient can reach the site. Tokens are random 256-bit values; only SHA-256 hashes are stored, links expire after 24 hours, and resend is limited to once per minute and five sends per rolling day per account.

## Database

Apply `database/migrations/2026_10_29_authorized_user_email_verification.sql` to an existing database. It adds verification state and `password_changed_at` without changing existing account records. Fresh installs using `database/schema.sql` or `database/railway_schema.sql` include these columns.

Administrators can retry invitations from the Pending Verification tab on the Staff and Office Heads pages. A failed mail handoff leaves the account pending and cannot activate it.
