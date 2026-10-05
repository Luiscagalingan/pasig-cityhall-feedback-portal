# Developer Notes

This guide explains the parts of the portal that are easy to break during maintenance. It documents intent and boundaries; the source code remains the authority for exact behavior.

## Request lifecycle

1. A page loads `includes/bootstrap.php`.
2. Bootstrap loads configuration, database access, shared functions, authentication, sentiment, dashboards, and layout helpers.
3. Protected pages call `require_login()` before reading or changing office data.
4. Every state-changing form must use `POST`, include `csrf_token()`, and call `verify_csrf()` before processing input.
5. Database input must use prepared statements. HTML output containing data must pass through `e()`.

Do not move authorization checks below database queries or page output. Access must be rejected before scoped data is returned.

## Roles and data boundaries

- `admin` can view all active offices and performs system-wide administration.
- `office_head` monitors only the assigned office and has intentionally read-only business access.
- `office_staff` works only inside the assigned office and can perform the explicitly allowed workflows.

Historical retired roles may remain in audit rows but cannot authenticate. When adding a page, enforce both the role and the office ID in the SQL query. A hidden form field is not an authorization boundary because clients can change it.

## Feedback scoring

The four ratings use values 1 through 4. `compute_feedback_scores()` converts their average to a 0–100 normalized score:

```text
normalized rating = ((average rating - 1) / 3) * 100
```

When a comment exists, the final result uses 90% normalized rating and 10% sentiment. Without a comment, it uses 100% normalized rating. Keep these two paths separate; assigning a neutral comment score to an absent comment would incorrectly lower or raise rating-only feedback.

Interpretation boundaries are:

- below 33.5: Negative
- 33.5 through below 66.5: Neutral
- 66.5 and above: Positive

After changing the formula, run `tools/recalculate_feedback_scores.php` only after taking an authorized database backup, then run `tests/scoring_test.php`.

## Dataset import transaction

`office/data.php` has four operations: manual entry, preview, confirm, and rollback.

- Preview parses the upload, validates every row, computes duplicate fingerprints, and writes a temporary JSON file. It does not insert feedback.
- Confirm reloads a preview owned by the current user, starts a transaction, inserts the batch and valid feedback, creates needed actions, stores rejected-row metadata, and commits.
- Rollback deletes feedback and generated actions for one office-scoped batch but retains the batch history.
- On any exception inside a transaction, the catch block rolls it back.

The fingerprint intentionally includes office, timestamp/date, demographics, service, ratings, comment, assistant, and supplied identity fields. Changing its field order changes duplicate detection for new records.

Preview files are credentials-adjacent temporary data. They belong under the web-denied `storage` directory, are bound to the creating user, use random tokens, and expire according to `IMPORT_PREVIEW_RETENTION_HOURS`.

## ML sentiment path

```text
comment
  -> Filipino/Taglish normalization
  -> saved TF-IDF feature transformer
  -> saved LinearSVC classifier
  -> label + operational confidence
  -> low-confidence human-review decision
```

The PHP bridge passes input through standard input and accepts only JSON with an allowed label and a finite confidence value from 0 to 1. It uses argument arrays rather than shell command strings so comments and paths cannot become shell syntax.

The softmax confidence is derived from SVM decision margins. It is useful for ranking review priority but is not a calibrated probability. Do not display it as “X% scientifically certain.” Predictions below `LOW_CONFIDENCE_THRESHOLD`, plus every fallback prediction, require human review.

If Python or the model fails, the PHP lexicon fallback keeps submissions working. The source remains `fallback`; never relabel fallback output as SVM output. Diagnostic details go only to `storage/svm_bridge.log`, which is denied from HTTP access.

The `.joblib` artifact must come from a trusted training process. Joblib uses Python object deserialization and must never load a user-uploaded model.

## Code style for future changes

- Use four spaces and one statement per line.
- Put braces on their own line for functions and control blocks.
- Prefer early returns over deeply nested conditions.
- Name values by meaning (`$officeId`, `$reviewStatus`), not by type (`$data1`).
- Extract repeated business rules into `includes/`; keep page files focused on request handling and rendering.
- Comment why a rule exists, not what an obvious statement does.
- Keep SQL readable and parameterized. Never concatenate request data into SQL.
- Break large HTML templates into logical lines, but preserve escaping with `e()`.
- Do not catch `Throwable` silently unless failure is intentionally non-blocking, such as optional notifications. Add a comment explaining that choice.

## Required verification

Run:

```bat
tests\run_smoke_test.bat
```

For authorization or workflow changes, also run:

```powershell
.\.venv\Scripts\python.exe tests\action_management_test.py
.\.venv\Scripts\python.exe tests\client_count_workflow_test.py
```

The Python tests create disposable databases and must never point to the production database.
