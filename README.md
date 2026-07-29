# Pasig City Hall Service Satisfaction Monitoring System

PHP + MySQL/XAMPP portal for CHD with expandable office management, office-scoped dashboards, CSV historical-data import, TF-IDF + Linear SVM sentiment analysis, human review, notifications, action approval, and 60/40 weighted scoring.

## Major modules

- Separate public survey and authorized login
- CHD/CHO as the initial active office; no HRDO module
- Automatic survey/dashboard support for offices created by the administrator
- Administrator all-active-office overview; Office Head/Staff office-only scope
- Administrator Manage Heads and Manage Staff by office
- Office Head-only Manage Staff, CSV preview/import, rollback, and Sentiment Review
- Required English/Filipino/Taglish comments with shorthand/slang normalization
- TF-IDF word/character features + LinearSVC
- 60% structured ratings + 40% comment sentiment
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

`database/migrations/002_workflow_security_upgrade.sql`

Read `UPGRADE_INSTRUCTIONS.txt` before opening the updated site.

## Verification

Run `tests/run_smoke_test.bat`. Administrator → System & Audit must show **SVM Bridge: Working**.

The included 300-comment model is a functional development/demo model. Final research evaluation still requires an approved, de-identified, manually labeled CHD dataset.
