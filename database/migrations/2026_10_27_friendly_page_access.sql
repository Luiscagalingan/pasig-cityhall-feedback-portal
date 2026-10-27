-- Replace legacy raw URL + HTTP method descriptions with readable page names.
UPDATE audit_logs
SET details = CASE
  WHEN details LIKE '%/admin/dashboard.php%' THEN 'Opened Admin Dashboard'
  WHEN details LIKE '%/office/dashboard.php%' THEN 'Opened Office Dashboard'
  WHEN details LIKE '%/admin/feedback.php%' OR details LIKE '%/office/feedback.php%' THEN 'Opened Feedback Records'
  WHEN details LIKE '%/review.php%' THEN 'Opened Sentiment Review'
  WHEN details LIKE '%/admin/actions.php%' OR details LIKE '%/office/actions.php%' THEN 'Opened Action Management'
  WHEN details LIKE '%/rating-distribution.php%' THEN 'Opened Rating Distribution'
  WHEN details LIKE '%/admin/system.php%' OR details LIKE '%/admin/backup.php%' THEN 'Opened System & Audit'
  WHEN details LIKE '%/office/audit.php%' THEN 'Opened Office Audit'
  WHEN details LIKE '%/admin/client-counts.php%' THEN 'Opened Requested Client Counts'
  WHEN details LIKE '%/office/client-count-request.php%' THEN 'Opened Client Count Request'
  WHEN details LIKE '%/office/data.php%' THEN 'Opened CSV Data Update'
  WHEN details LIKE '%/admin/reports.php%' OR details LIKE '%/office/reports.php%' THEN 'Opened Reports & Export'
  WHEN details LIKE '%/admin/offices.php%' THEN 'Opened Office Management'
  WHEN details LIKE '%/admin/heads.php%' THEN 'Opened Head Accounts'
  WHEN details LIKE '%/admin/staff.php%' THEN 'Opened Staff Accounts'
  WHEN details LIKE '%/admin/client-output.php%' OR details LIKE '%/office/client-output.php%' THEN 'Opened Client Output'
  WHEN details LIKE '%/admin/feedback-insights.php%' OR details LIKE '%/office/feedback-insights.php%' THEN 'Opened Feedback Insights'
  WHEN details LIKE '%/notifications.php%' THEN 'Opened Announcements & Updates'
  WHEN details LIKE '%/account.php%' THEN 'Opened My Account'
  WHEN details LIKE '%/office/assisted-survey.php%' THEN 'Opened Assisted Client Survey'
  WHEN details LIKE '%/survey.php%' THEN 'Opened Public Survey'
  ELSE details
END
WHERE action = 'page_access'
  AND (
    details LIKE '%/admin/%'
    OR details LIKE '%/office/%'
    OR details LIKE '%/review.php%'
    OR details LIKE '%/rating-distribution.php%'
    OR details LIKE '%/notifications.php%'
    OR details LIKE '%/account.php%'
    OR details LIKE '%/survey.php%'
  );
