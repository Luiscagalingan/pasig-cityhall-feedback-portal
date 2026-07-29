# UAT and ISO/IEC 25010 Test Plan

This template records actual testing results; it does not replace testing by users and evaluators.

## Functional UAT

1. Public user submits complete CHD feedback in English, Filipino, and Taglish.
2. Required comments and all four ratings block incomplete submission.
3. Administrator adds a new office and initial Office Head.
4. Office Head sees only the assigned office and can create staff.
5. Staff cannot see CSV Upload, Sentiment Review, or Manage Staff.
6. CSV preview detects invalid rows and exact duplicates before confirmation.
7. Rejected rows download correctly and a confirmed batch can be rolled back.
8. Low-confidence/fallback predictions appear in Sentiment Review.
9. Verified sentiment recalculates the 60/40 final score and preserves the original label.
10. Staff submits completion; Office Head approves or returns it.
11. Archived users and offices cannot log in.
12. Five failed logins temporarily lock the account.
13. Date filters change both report cards and detailed rows.
14. SQL backup downloads and restores through phpMyAdmin.

## ISO/IEC 25010 Evaluation Areas

- Functional suitability: completeness, correctness, appropriateness
- Performance efficiency: response time, CSV processing time, resource use
- Compatibility: Chrome, Edge, Firefox, mobile layouts, XAMPP environment
- Usability: learnability, operability, error prevention, accessibility
- Reliability: availability, recoverability, batch rollback, database backup
- Security: authentication, authorization, confidentiality, auditability
- Maintainability: modularity, analyzability, modifiability, testability
- Portability: installation, replacement, environment configuration

For every item, record the tester, date, test data, expected result, actual result, pass/fail, evidence, and corrective action.
