Client-count request tracking
============================

For an existing database missing request tracking, apply
`015_client_count_requests.sql`, then apply `016_annual_client_counts.sql` once. It adds
`client_count_requests` and does not update or delete users, feedback, actions,
notifications, labels, or scores. The fresh-install schema includes this table.
Do not run the fresh-install schema against an existing database.

Each request snapshots the assigned office. An active Administrator answers it
using eligible assisted-survey feedback in that office for the requested calendar
year. Ownership matches feedback.imported_by_user_id to the requester account ID,
with source='assisted_survey', non-void feedback, and nonblank assisted_by.
Text-only legacy ownership is excluded. See 016_annual_client_counts.md for
calendar boundaries and legacy NULL-year handling. The stored answer
is a snapshot; future feedback does not change it. A staff transfer requires a
new request. The request lock, conditional update, notification, and audit entry
share one transaction. No username, name, email, or fixed account ID grants access.

Old requests existed only as notifications and cannot be reliably paired with
historical replies. These notifications remain unchanged. Staff must submit a
new tracked request rather than have the migration invent pending/answered states.

Existing databases may retain a historical Supervisor enum value for compatibility.
Fresh installs allow only admin, office_head, and office_staff. Historical rows
remain visible in the unfiltered audit view as Legacy role (retired), without a
retired-role selector. Retired accounts cannot log in or continue existing sessions. Administrators must review
any legacy account individually before assigning a supported role. No accounts
are automatically converted or deleted. Historical migrations 011 and 013 are
retained as history; do NOT rerun them to deploy this change (013 deletes users).

Office Heads can view office data but cannot change business records. Personal
account maintenance and their own notification read/delete controls remain.
Administrators manage accounts and client-count responses. Action transition SQL
is unchanged; retired-role permissions and stale interface wording were removed.

Regression commands (from the project root):

    python -B tests/client_count_workflow_test.py
    C:\xampp\php\php.exe tests/security_static_test.php
    C:\xampp\php\php.exe tests/survey_rate_limit_test.php
    C:\xampp\php\php.exe tests/scoring_test.php

The HTTP test uses a randomly named isolated MySQL database, test accounts, test
feedback, and a temporary PHP server/session directory. It deletes only that test
database on completion. It requires permission to create/drop a test database.
PHP_BIN and PASIG_DB_HOST/PORT/USER/PASS can configure the test environment.
