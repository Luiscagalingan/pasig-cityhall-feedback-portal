USE pasig_feedback_portal;

ALTER TABLE feedback
  MODIFY source ENUM('public_survey','csv_import','manual_entry','assisted_survey') NOT NULL DEFAULT 'public_survey';
