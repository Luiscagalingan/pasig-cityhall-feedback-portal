-- Preserve existing audit rows while adding actor, module, office, and result snapshots.
ALTER TABLE audit_logs
  ADD COLUMN username VARCHAR(80) NULL AFTER user_id,
  ADD COLUMN user_role VARCHAR(30) NULL AFTER username,
  ADD COLUMN assigned_office_id INT UNSIGNED NULL AFTER user_role,
  ADD COLUMN assigned_office_name VARCHAR(160) NULL AFTER assigned_office_id,
  ADD COLUMN module VARCHAR(80) NOT NULL DEFAULT 'System' AFTER action,
  ADD COLUMN result ENUM('success','failed') NOT NULL DEFAULT 'success' AFTER ip_address,
  ADD INDEX idx_audit_scope (user_role,assigned_office_id,module,result,created_at);

-- Enrich existing rows without removing or rewriting their original descriptions or timestamps.
UPDATE audit_logs a
LEFT JOIN users u ON u.id=a.user_id
LEFT JOIN offices o ON o.id=u.office_id
SET a.username=COALESCE(a.username,u.username),
    a.user_role=COALESCE(a.user_role,u.role,IF(a.user_id IS NULL,'public',NULL)),
    a.assigned_office_id=COALESCE(a.assigned_office_id,u.office_id),
    a.assigned_office_name=COALESCE(a.assigned_office_name,o.name),
    a.result=CASE WHEN a.action IN ('login_failed','login_blocked','login_ip_blocked','access_denied') THEN 'failed' ELSE a.result END,
    a.module=CASE
      WHEN a.action IN ('login','login_failed','login_blocked','login_ip_blocked','logout','session_timeout') THEN 'Authentication'
      WHEN a.action='page_access' AND a.details LIKE '%admin/dashboard.php%' THEN 'Overview'
      WHEN a.action='page_access' AND a.details LIKE '%office/dashboard.php%' THEN 'Overview'
      WHEN a.action='page_access' AND a.details LIKE '%feedback.php%' THEN 'Feedback Records'
      WHEN a.action='page_access' AND a.details LIKE '%review.php%' THEN 'Sentiment Review'
      WHEN a.action='page_access' AND a.details LIKE '%actions.php%' THEN 'Action Management'
      WHEN a.action='page_access' AND a.details LIKE '%rating-distribution.php%' THEN 'Rating Distribution'
      WHEN a.action='page_access' AND a.details LIKE '%system.php%' THEN 'System & Audit'
      WHEN a.action LIKE 'csv_%' THEN 'CSV Data Update'
      WHEN a.action LIKE 'head_%' THEN 'Head Accounts'
      WHEN a.action LIKE 'admin_staff_%' THEN 'Staff Accounts'
      WHEN a.action LIKE 'office_%' THEN 'Office Management'
      WHEN a.action LIKE 'report_%' OR a.action LIKE '%export%' THEN 'Reports & Export'
      WHEN a.action LIKE '%action%' THEN 'Action Management'
      WHEN a.action IN ('sentiment_review','feedback_void','feedback_unvoid') THEN 'Sentiment Review'
      WHEN a.action LIKE '%feedback%' THEN 'Feedback Records'
      ELSE a.module END;
