# Authorized user email verification setup

New Office Staff and Office Head accounts remain pending until the recipient verifies the email address. Existing accounts are grandfathered: `email_verification_required` defaults to `0`, and the migration does not change their status.

## Required configuration

Set these environment variables in the PHP application runtime:

- `PASIG_GOV_EMAIL_DOMAINS`: comma-separated allowlist of approved government email domains, without `@` (for example, `domain-one.gov,domain-two.gov`). **The project does not establish the official Pasig City Government email domain. Obtain confirmation from the system owner before setting this value.** Account invitations are disabled until at least one valid domain is configured.
- `PASIG_MAIL_FROM`: sender address authorized by the configured mail transport.
- `PASIG_PUBLIC_BASE_URL`: absolute public site origin, such as `https://feedback.example.gov.ph` (use `http://localhost` only for local development). It is combined with the existing `APP_BASE_URL` path to create an absolute verification link. Invitations remain pending if this value is absent or invalid.
- Docker SMTP transport: set `PASIG_SMTP_HOST`, `PASIG_SMTP_PORT` (defaults to 587), `PASIG_SMTP_USER`, `PASIG_SMTP_PASSWORD`, and `PASIG_SMTP_TLS_MODE` (`starttls` or `tls`). Also set `PASIG_MAIL_FROM`. The entrypoint configures the bundled sendmail-compatible `msmtp` transport at container startup. SMTP credentials stay in runtime environment variables; the temporary msmtp config uses `passwordeval` and is mode 0600. Do not put credentials in Docker build arguments or commit them.

Verification messages use PHP's `mail()` function. The supplied Docker image includes an `msmtp` sendmail-compatible transport, configured only when the SMTP variables above are present. Without them, mail handoff fails and the account remains pending. On Windows/XAMPP, PHP's built-in SMTP settings provide host, port, and sender fields but no SMTP authentication fields; use `sendmail_path` with a trusted SMTP-capable sendmail replacement for an authenticated TLS relay. Keep relay credentials in protected server configuration/environment variables, not in the repository. `mail()` returning `true` means the transport accepted the message for handoff, not that the recipient's mailbox received it.

Ensure that `PASIG_PUBLIC_BASE_URL` and the existing `APP_BASE_URL` point to the deployed application and that the recipient can reach the site. Tokens are random 256-bit values; only SHA-256 hashes are stored, links expire after 24 hours, and resend is limited to once per minute and five sends per rolling day per account.

## Database

Apply `database/migrations/2026_10_29_authorized_user_email_verification.sql` to an existing database. Fresh installs using `database/schema.sql` or `database/railway_schema.sql` already include the verification columns.

Administrators can retry invitations from the Pending Verification tab on the Staff and Office Heads pages. A failed mail handoff leaves the account pending and cannot activate it.
