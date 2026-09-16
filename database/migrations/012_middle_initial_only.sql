USE pasig_feedback_portal;

-- Safe to run once even if the column was already renamed.
SET @rename_middle_initial_sql = (
  SELECT IF(
    COUNT(*) = 1,
    'ALTER TABLE feedback CHANGE COLUMN middle_name middle_initial CHAR(1) NULL',
    'SELECT ''middle_initial is already configured'' AS status'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'feedback' AND COLUMN_NAME = 'middle_name'
);
PREPARE rename_middle_initial FROM @rename_middle_initial_sql;
EXECUTE rename_middle_initial;
DEALLOCATE PREPARE rename_middle_initial;
