Monthly output and verified annual counts
========================================

Apply 016_annual_client_counts.sql once after migration 015 on an existing
database. It adds requested_year and a generated pending_year plus a unique
staff/year key for pending requests. Answered requests release that unique slot.
The fresh-install schema includes these columns; do not also apply 016 there.

Existing requests retain NULL requested_year and their original count snapshots.
They are labeled "Legacy - no year", not treated as verified annual counts.
Legacy pending requests cannot be answered with an invented year; staff should
submit a new annual request. No historical notifications or feedback are changed.
Staff request history is scoped to their account ID, so their own legacy
snapshots remain accessible after an office transfer. Live output and new annual
counts remain scoped to the assigned office; a transfer requires a new request.

Both views use feedback.imported_by_user_id as the assisting account ID only
when source='assisted_survey'. They require the account's assigned office,
non-void feedback, an active office, and a nonblank Assisted By value. CSV importer
IDs identify uploaders, not assistants; CSV/text-only/unknown-owner records are
excluded without rewriting them. Public feedback and other staff are excluded.

Periods use feedback.visit_date (service date), not submission timestamp.
Staff Client Output defaults on every request to the current calendar month in
APP_TIMEZONE (Asia/Manila). The month selector uses YYYY-MM. The SQL includes the
first and last service date of that month, including leap day when applicable.
Average Daily Output means total divided by days with eligible records. Empty
months show zeros. No records are deleted or reset at month boundaries.

Annual counts use >= January 1 of requested_year and < January 1 of the next year.
The year comes from the saved request when answering, never the response form.
The verified count is a snapshot at answer time, not a promise that future
submissions will not change that year's live total. Future/empty periods can
return zero. Supported years: 1900 through 9998.

Administrator and Office Head output retains its existing wider filters.
Office Heads cannot write business records or process requests.

Tests:
    python -B tests/client_count_workflow_test.py
    C:\xampp\php\php.exe tests/output_periods_test.php

The HTTP workflow test runs PHP and MySQL against a disposable test database.
