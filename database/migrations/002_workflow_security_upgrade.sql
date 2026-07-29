-- Run this ONCE in phpMyAdmin after replacing the portal files.
USE pasig_feedback_portal;

ALTER TABLE users
  ADD COLUMN failed_login_attempts INT UNSIGNED NOT NULL DEFAULT 0 AFTER last_login_at,
  ADD COLUMN locked_until DATETIME NULL AFTER failed_login_attempts,
  ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER locked_until,
  ADD INDEX idx_user_lock (locked_until);

ALTER TABLE import_batches
  ADD COLUMN duplicate_rows INT UNSIGNED NOT NULL DEFAULT 0 AFTER rejected_rows,
  ADD COLUMN preview_token VARCHAR(64) NULL AFTER duplicate_rows,
  ADD COLUMN rolled_back_at DATETIME NULL AFTER error_summary,
  ADD COLUMN rolled_back_by_user_id INT UNSIGNED NULL AFTER rolled_back_at;

ALTER TABLE feedback
  ADD COLUMN original_sentiment ENUM('positive','neutral','negative') NULL AFTER sentiment,
  ADD COLUMN review_status ENUM('not_required','needs_review','reviewed') NOT NULL DEFAULT 'not_required' AFTER sentiment_source,
  ADD COLUMN reviewed_by_user_id INT UNSIGNED NULL AFTER review_status,
  ADD COLUMN reviewed_at DATETIME NULL AFTER reviewed_by_user_id,
  ADD COLUMN reviewer_notes TEXT NULL AFTER reviewed_at,
  ADD COLUMN model_version VARCHAR(80) NULL AFTER reviewer_notes,
  ADD COLUMN is_void TINYINT(1) NOT NULL DEFAULT 0 AFTER source,
  ADD COLUMN void_reason TEXT NULL AFTER is_void,
  ADD COLUMN voided_by_user_id INT UNSIGNED NULL AFTER void_reason,
  ADD COLUMN voided_at DATETIME NULL AFTER voided_by_user_id,
  ADD COLUMN record_fingerprint CHAR(64) NULL AFTER voided_at,
  ADD COLUMN import_batch_id BIGINT UNSIGNED NULL AFTER record_fingerprint,
  ADD COLUMN consent_version VARCHAR(30) NULL AFTER import_batch_id,
  ADD INDEX idx_feedback_review (office_id, review_status, is_void),
  ADD INDEX idx_feedback_fingerprint (office_id, record_fingerprint),
  ADD INDEX idx_feedback_import_batch (import_batch_id);

UPDATE feedback SET original_sentiment=sentiment WHERE original_sentiment IS NULL;
UPDATE feedback
SET review_status='needs_review'
WHERE is_void=0 AND (sentiment_source<>'svm' OR sentiment_confidence<0.6000) AND review_status='not_required';

ALTER TABLE feedback
  ADD CONSTRAINT fk_feedback_reviewer FOREIGN KEY (reviewed_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_feedback_voider FOREIGN KEY (voided_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_feedback_import_batch FOREIGN KEY (import_batch_id) REFERENCES import_batches(id) ON DELETE SET NULL;

ALTER TABLE import_batches
  ADD CONSTRAINT fk_import_rollback_user FOREIGN KEY (rolled_back_by_user_id) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE actions
  MODIFY status ENUM('needs_action','in_progress','pending_approval','completed') NOT NULL DEFAULT 'needs_action',
  ADD COLUMN completion_requested_by_user_id INT UNSIGNED NULL AFTER resolution_notes,
  ADD COLUMN completion_requested_at DATETIME NULL AFTER completion_requested_by_user_id,
  ADD COLUMN approved_by_user_id INT UNSIGNED NULL AFTER completion_requested_at,
  ADD COLUMN approved_at DATETIME NULL AFTER approved_by_user_id,
  ADD INDEX idx_actions_pending (office_id, status, updated_at),
  ADD CONSTRAINT fk_action_requester FOREIGN KEY (completion_requested_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_action_approver FOREIGN KEY (approved_by_user_id) REFERENCES users(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS import_rejected_rows (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  import_batch_id BIGINT UNSIGNED NOT NULL,
  row_number INT UNSIGNED NOT NULL,
  raw_row LONGTEXT NULL,
  error_message TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_rejected_batch FOREIGN KEY (import_batch_id) REFERENCES import_batches(id) ON DELETE CASCADE,
  INDEX idx_rejected_batch (import_batch_id, row_number)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  office_id INT UNSIGNED NULL,
  type VARCHAR(40) NOT NULL,
  title VARCHAR(180) NOT NULL,
  message TEXT NOT NULL,
  link_url VARCHAR(255) NULL,
  read_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_notification_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_notification_office FOREIGN KEY (office_id) REFERENCES offices(id) ON DELETE CASCADE,
  INDEX idx_notification_unread (user_id, read_at, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS login_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  identity VARCHAR(160) NOT NULL,
  user_id INT UNSIGNED NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  successful TINYINT(1) NOT NULL DEFAULT 0,
  attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_login_attempt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_login_identity_date (identity, attempted_at),
  INDEX idx_login_ip_date (ip_address, attempted_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS training_candidates (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  feedback_id BIGINT UNSIGNED NOT NULL,
  comment_text TEXT NOT NULL,
  approved_label ENUM('positive','neutral','negative') NOT NULL,
  approved_by_user_id INT UNSIGNED NULL,
  approved_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  exported_at DATETIME NULL,
  CONSTRAINT fk_training_feedback FOREIGN KEY (feedback_id) REFERENCES feedback(id) ON DELETE CASCADE,
  CONSTRAINT fk_training_approver FOREIGN KEY (approved_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uq_training_feedback (feedback_id),
  INDEX idx_training_export (exported_at, approved_at)
) ENGINE=InnoDB;

DROP TRIGGER IF EXISTS trg_one_active_head_insert;
DROP TRIGGER IF EXISTS trg_one_active_head_update;
DELIMITER $$
CREATE TRIGGER trg_one_active_head_insert
BEFORE INSERT ON users FOR EACH ROW
BEGIN
  IF NEW.role='office_head' AND NEW.status='active' AND NEW.office_id IS NOT NULL
     AND EXISTS(SELECT 1 FROM users WHERE office_id=NEW.office_id AND role='office_head' AND status='active') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Only one active Office Head is allowed per office.';
  END IF;
END$$
CREATE TRIGGER trg_one_active_head_update
BEFORE UPDATE ON users FOR EACH ROW
BEGIN
  IF NEW.role='office_head' AND NEW.status='active' AND NEW.office_id IS NOT NULL
     AND EXISTS(SELECT 1 FROM users WHERE office_id=NEW.office_id AND role='office_head' AND status='active' AND id<>NEW.id) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Only one active Office Head is allowed per office.';
  END IF;
END$$
DELIMITER ;

-- Existing demo accounts remain usable. New accounts created by the updated system are forced to change temporary passwords.
