# Pasig City Hall Service Satisfaction Monitoring System

PHP + MySQL/XAMPP portal for CHD with expandable office management, office-scoped dashboards, CSV historical-data import, TF-IDF + Linear SVM sentiment analysis, human review, notifications, action approval, and 90/10 scoring with comments and ratings-only scoring without comments.

## Major modules

- Separate public survey and authorized login
- CSWDO as the initial active office; no HRDO module
- Automatic survey/dashboard support for offices created by the administrator
- Administrator all-active-office overview; Office Head/Staff office-only scope
- Administrator Manage Heads and Manage Staff by office
- Office Head-only Manage Staff, CSV preview/import, rollback, and Sentiment Review
- Optional English/Filipino/Taglish comments with shorthand/slang normalization; blank comments use rating-based sentiment
- TF-IDF word/character features + LinearSVC
- Chapter 2 normalized score presented on an equivalent 0–100 scale: 90% structured ratings + 10% comment sentiment (100% ratings without comments), with 33.5 and 66.5 classification thresholds
- Low-confidence/fallback review queue, manual correction, original-label preservation
- Reviewed training-candidate export for controlled retraining
- Needs Action → In Progress → Pending Approval → Completed workflow
- Staff completion request and Office Head approval
- Notifications, pagination, search, date-filtered reports, demographics, services, concern terms
- Login lockout, inactivity timeout, temporary-password change, CSRF, prepared statements, audit logs
- CSV duplicate/MIME validation, rejected-row download, formula-injection-safe export
- Feedback void/restore and full SQL backup download

## Fresh installation

Import `database/schema.sql`.

## Upgrade from the earlier supplied version

Replace the files, then import once:

Run the applicable migrations in numerical order through
`database/migrations/010_rename_initial_office_to_cswdo.sql`.

## Security and retention operations

- Public submissions use exact-duplicate blocking, a 60-second cooldown, and a three-submission hourly limit based on a one-way client hash.
- Retention periods are declared in `config/app.php` and are applied by an administrator from **System & Audit > Retention Policy**.
- Backups are downloaded only as AES-256-GCM encrypted `.pasigbak` files and are not stored by the portal.
- Decrypt an authorized backup from the project directory with `C:\xampp\php\php.exe tools\decrypt_backup.php backup.pasigbak restored.sql`, then import the SQL through an authorized database administrator.
- Keep backup passphrases separate from backup files. The configured recommended backup retention is 30 days.


## Verification

Run `tests/run_smoke_test.bat`. Administrator → System & Audit must show **SVM Bridge: Working**.

The included 300-comment model is a functional development/demo model. Final research evaluation still requires an approved, de-identified, manually labeled CHD dataset.

## Distribution scope

Research documents and training/evaluation scripts were removed. Keep the supplied model and metrics for runtime predictions. See INSTALL.txt for setup.

After updating scoring, run `php tools/recalculate_feedback_scores.php` to recalculate existing feedback scores. Raw answers, sentiment reviews, and action history are preserved. Run `php tests/scoring_test.php` to verify both scoring paths.
