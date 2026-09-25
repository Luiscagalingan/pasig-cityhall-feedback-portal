USE pasig_feedback_portal;

ALTER TABLE feedback
  ADD COLUMN source_timestamp DATETIME NULL AFTER visit_date,
  ADD COLUMN assisted_by_user_id INT UNSIGNED NULL AFTER assisted_by,
  ADD CONSTRAINT fk_feedback_assisted_user FOREIGN KEY (assisted_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  ADD INDEX idx_feedback_assisted_user (assisted_by_user_id, office_id, visit_date);
