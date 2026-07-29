# Pasig City Hall Service Satisfaction Monitoring System

A PHP + MySQL/XAMPP-ready feedback portal for the City Health Department, with expandable office management, office-scoped dashboards, CSV historical-data import, TF-IDF + Linear SVM sentiment analysis, and 60/40 weighted scoring.

## Main modules

- Separate public survey (`index.php` and `survey.php`) and authorized login (`login.php`)
- Initial City Health Department office with code `CHO`; no HRDO module
- Administrator consolidated dashboard and office comparison
- Add Office + initial Office Head in one transaction
- Manage Heads: create, edit, archive, reactivate, delete
- Manage Staff: separated by office buttons, with full account actions
- Office-specific dashboards: heads and staff never see another office's records
- Office Head-only CSV upload and Manage Staff navigation
- Required English/Filipino/Taglish comment with SVM classification
- 60% structured ratings + 40% comment sentiment final score
- Automatic action items for negative feedback or final scores below 60%
- Feedback filters, demographics, print/PDF layout, detailed CSV exports
- Administrator system-health page and audit-log viewer
- CSRF protection, password hashing, self-service password changes, prepared statements, audit logs, archived-account blocking

## Quick setup

Read `INSTALL.txt`, import `database/schema.sql`, then install/retrain the Python model using `ml/install_and_train.bat`.

## Folder structure

```text
pasig-cityhall-feedback-portal/
├── admin/        Administrator modules
├── office/       Office Head and Staff modules
├── assets/       CSS, JavaScript, CSV guide image
├── config/       Application and database settings
├── database/     Main schema and optional demo data
├── docs/         Scope, methodology, and upload guide
├── includes/     Security, layout, dashboard, and SVM bridge
├── ml/           Dataset, model, training, and prediction scripts
├── index.php     Public feedback landing page
├── survey.php    Public office feedback form
└── login.php     Authorized account login
```

The included model is a functional development/demo model, not the final research model.
