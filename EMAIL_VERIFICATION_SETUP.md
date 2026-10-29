# Authorized user email verification setup

New Office Staff and Office Head accounts remain pending until the recipient verifies the email address. Existing accounts are grandfathered: `email_verification_required` defaults to `0`, and the migration does not change their status.

## Railway and Resend setup

Railway Trial restricts outbound SMTP. Use Resend's HTTPS API for both Office Head six-digit OTP messages and account onboarding / verification-link messages. The application defaults to Resend; it does not fall back to SMTP after an API error.

Add these variables to the Railway service that runs PHP, then redeploy:

- `RESEND_API_KEY`: a Resend API key stored as a Railway secret.
- `RESEND_FROM_EMAIL`: the verified sender email address on a domain verified in Resend, for example `no-reply@your-verified-domain.example`. The application formats the From header as `Service Satisfaction System <configured-address>`. Replace the example with a real address on a domain you control. Resend requires the sender domain to be verified and its DNS records to be configured successfully before it can send. Do not assume a Gmail mailbox such as `satisfactionservice90@gmail.com` can be used as the From address; use it only if Resend explicitly verifies and allows that sender identity.
- `PASIG_EMAIL_TRANSPORT`: optional; set to `resend` to make the selection explicit. If omitted, the application uses `resend`. Only set this to `smtp` in a deployment where outbound SMTP is known to be available and the SMTP settings are configured.

Also configure the application settings below. `PASIG_GOV_EMAIL_DOMAINS` controls allowed **recipient** domains, while `RESEND_FROM_EMAIL` is the verified **sender** identity; they serve different purposes. Gmail can remain an allowed recipient when explicitly included, e.g. `pasigcity.gov.ph,gmail.com`.

- `PASIG_GOV_EMAIL_DOMAINS`: comma-separated allowlist of approved recipient domains, without `@` (for example, `domain-one.gov,domain-two.gov`). The project does not establish the official Pasig City Government email domain. Obtain confirmation from the system owner before setting this value. Account invitations are disabled until at least one valid domain is configured.
- `PASIG_PUBLIC_BASE_URL`: absolute public site origin, such as `https://feedback.example.gov.ph` (use `http://localhost` only for local development). It is combined with the existing `APP_BASE_URL` path to create an absolute verification link. Invitations remain pending if this value is absent or invalid.

In Resend, add the sending domain under **Domains**, publish the DNS records Resend provides, and wait for verification. Create an API key with sending access and store it only in Railway's `RESEND_API_KEY` variable. The API request uses HTTPS with certificate verification and a 12-second timeout. Logs record safe failure categories and API acceptance status only; they do not record API keys, OTPs, verification tokens, or raw provider responses. An API acceptance response confirms that Resend accepted the request, not that it reached the recipient's inbox.

## Optional SMTP transport

PHPMailer remains available for local development or a host where SMTP egress is supported. Set `PASIG_EMAIL_TRANSPORT=smtp` explicitly and configure `PASIG_SMTP_HOST`, `PASIG_SMTP_PORT`, `PASIG_SMTP_USER`, `PASIG_SMTP_PASSWORD`, `PASIG_SMTP_TLS_MODE` (`starttls` or `tls`), and `PASIG_MAIL_FROM`. For Gmail SMTP, use port `587` with `starttls`, or port `465` with implicit `tls`. The mailer maps `tls` on port `587` to STARTTLS for compatibility. SMTP credentials stay in runtime environment variables; never put them in Docker build arguments or commit them. This transport is not attempted automatically when Resend fails.

## Verification behavior

Office Head creation requires the admin to send and verify a six-digit code before creating the account. The code challenge is tied to the admin session and exact email address, stored as hashes, expires after 10 minutes, allows five attempts, and is consumed during account creation. Sending is limited to once per minute and five sends per email per day. Recipient domains are accepted only when explicitly listed in `PASIG_GOV_EMAIL_DOMAINS`, including personal providers if explicitly configured. This recipient allowlist is separate from the email sender identity.

The post-creation account verification email remains in place. The six-digit code confirms the recipient before account creation; the existing single-use verification link and mandatory first-login password change continue unchanged. Office Head onboarding emails continue to include the existing temporary password `password123!`; the database continues storing only the secure password hash.

Administrators can retry invitations from the Pending Verification tab on the Staff and Office Heads pages. A failed provider handoff leaves the account pending and cannot activate it.

## Database

Apply `database/migrations/2026_10_29_authorized_user_email_verification.sql` to an existing database. It adds verification state and `password_changed_at` without changing existing account records. Fresh installs using `database/schema.sql` or `database/railway_schema.sql` include these columns.

Also apply `database/migrations/2026_10_29_office_head_email_codes.sql` to enable the admin-side six-digit email confirmation before creating an Office Head. Fresh installs include the challenge table. No schema changes are required for the Resend transport.
