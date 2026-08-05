USE pasig_feedback_portal;

CREATE TABLE IF NOT EXISTS public_submission_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_hash CHAR(64) NOT NULL,
  office_id INT UNSIGNED NOT NULL,
  feedback_id BIGINT UNSIGNED NULL,
  submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_public_log_office FOREIGN KEY (office_id) REFERENCES offices(id) ON DELETE CASCADE,
  CONSTRAINT fk_public_log_feedback FOREIGN KEY (feedback_id) REFERENCES feedback(id) ON DELETE CASCADE,
  INDEX idx_public_client_date (client_hash, submitted_at),
  INDEX idx_public_date (submitted_at)
) ENGINE=InnoDB;

-- Initial retention cleanup; the same rules are available in Admin > System & Audit.
DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 90 DAY);
DELETE FROM notifications WHERE created_at < DATE_SUB(NOW(), INTERVAL 365 DAY);
DELETE FROM public_submission_log WHERE submitted_at < DATE_SUB(NOW(), INTERVAL 90 DAY);
