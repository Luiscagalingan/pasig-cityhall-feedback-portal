-- Pasig City Hall Service Satisfaction Monitoring System
-- Fresh-install schema with workflow, security, review, notifications, and CSV data-quality upgrades.
CREATE DATABASE IF NOT EXISTS pasig_feedback_portal CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE pasig_feedback_portal;

SET FOREIGN_KEY_CHECKS=0;
DROP TRIGGER IF EXISTS trg_one_active_head_insert;
DROP TRIGGER IF EXISTS trg_one_active_head_update;
DROP TABLE IF EXISTS client_count_requests;
DROP TABLE IF EXISTS training_candidates;
DROP TABLE IF EXISTS public_submission_log;
DROP TABLE IF EXISTS login_attempts;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS import_rejected_rows;
DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS actions;
DROP TABLE IF EXISTS feedback;
DROP TABLE IF EXISTS import_batches;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS offices;
SET FOREIGN_KEY_CHECKS=1;

CREATE TABLE offices (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  code VARCHAR(20) NOT NULL UNIQUE,
  description VARCHAR(500) NULL,
  status ENUM('active','archived') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_office_status (status)
) ENGINE=InnoDB;

CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  office_id INT UNSIGNED NULL,
  full_name VARCHAR(160) NOT NULL,
  username VARCHAR(80) NOT NULL UNIQUE,
  email VARCHAR(160) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','office_head','office_staff') NOT NULL,
  status ENUM('active','archived') NOT NULL DEFAULT 'active',
  created_by_user_id INT UNSIGNED NULL,
  last_login_at DATETIME NULL,
  failed_login_attempts INT UNSIGNED NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_users_office FOREIGN KEY (office_id) REFERENCES offices(id) ON DELETE SET NULL,
  CONSTRAINT fk_users_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_user_scope (office_id, role, status),
  INDEX idx_user_lock (locked_until)
) ENGINE=InnoDB;

CREATE TABLE import_batches (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  office_id INT UNSIGNED NOT NULL,
  uploaded_by_user_id INT UNSIGNED NULL,
  original_filename VARCHAR(255) NOT NULL,
  total_rows INT UNSIGNED NOT NULL DEFAULT 0,
  imported_rows INT UNSIGNED NOT NULL DEFAULT 0,
  rejected_rows INT UNSIGNED NOT NULL DEFAULT 0,
  duplicate_rows INT UNSIGNED NOT NULL DEFAULT 0,
  preview_token VARCHAR(64) NULL,
  error_summary TEXT NULL,
  rolled_back_at DATETIME NULL,
  rolled_back_by_user_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_import_office FOREIGN KEY (office_id) REFERENCES offices(id) ON DELETE RESTRICT,
  CONSTRAINT fk_import_user FOREIGN KEY (uploaded_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_import_rollback_user FOREIGN KEY (rolled_back_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_import_scope (office_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE feedback (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  office_id INT UNSIGNED NOT NULL,
  visit_date DATE NOT NULL,
  sex ENUM('Female','Male','Prefer not to say') NOT NULL,
  age TINYINT UNSIGNED NOT NULL,
  client_type ENUM('Pasigueño','Non-Pasigueño','City Government Employee') NOT NULL,
  service_received VARCHAR(255) NOT NULL,
  timeliness_rating TINYINT UNSIGNED NOT NULL,
  client_handling_rating TINYINT UNSIGNED NOT NULL,
  quality_rating TINYINT UNSIGNED NOT NULL,
  overall_rating TINYINT UNSIGNED NOT NULL,
  comment TEXT NOT NULL,
  assisted_by VARCHAR(255) NULL,
  client_number VARCHAR(100) NULL,
  surname VARCHAR(100) NULL,
  given_name VARCHAR(100) NULL,
  middle_initial CHAR(1) NULL,
  sentiment ENUM('positive','neutral','negative') NOT NULL,
  original_sentiment ENUM('positive','neutral','negative') NULL,
  sentiment_confidence DECIMAL(6,4) NOT NULL DEFAULT 0,
  sentiment_source ENUM('svm','fallback','manual','empty','rating') NOT NULL DEFAULT 'svm',
  review_status ENUM('not_required','needs_review','reviewed') NOT NULL DEFAULT 'not_required',
  reviewed_by_user_id INT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  reviewer_notes TEXT NULL,
  model_version VARCHAR(80) NULL,
  average_rating DECIMAL(4,2) NOT NULL,
  rating_percent DECIMAL(6,2) NOT NULL COMMENT 'Chapter 2 normalized rating expressed from 0 to 100',
  comment_score DECIMAL(6,2) NOT NULL COMMENT 'Positive=100, neutral=50, negative=0',
  final_score DECIMAL(6,2) NOT NULL COMMENT '0 to 100 equivalent of the Chapter 2 weighted index',
  source ENUM('public_survey','csv_import','manual_entry','assisted_survey') NOT NULL DEFAULT 'public_survey',
  is_void TINYINT(1) NOT NULL DEFAULT 0,
  void_reason TEXT NULL,
  voided_by_user_id INT UNSIGNED NULL,
  voided_at DATETIME NULL,
  record_fingerprint CHAR(64) NULL,
  import_batch_id BIGINT UNSIGNED NULL,
  consent_version VARCHAR(30) NULL,
  imported_by_user_id INT UNSIGNED NULL,
  submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_feedback_office FOREIGN KEY (office_id) REFERENCES offices(id) ON DELETE RESTRICT,
  CONSTRAINT fk_feedback_importer FOREIGN KEY (imported_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_feedback_reviewer FOREIGN KEY (reviewed_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_feedback_voider FOREIGN KEY (voided_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_feedback_import_batch FOREIGN KEY (import_batch_id) REFERENCES import_batches(id) ON DELETE SET NULL,
  CONSTRAINT chk_ratings CHECK (timeliness_rating BETWEEN 1 AND 4 AND client_handling_rating BETWEEN 1 AND 4 AND quality_rating BETWEEN 1 AND 4 AND overall_rating BETWEEN 1 AND 4),
  INDEX idx_feedback_scope_date (office_id, visit_date),
  INDEX idx_feedback_sentiment (sentiment),
  INDEX idx_feedback_final_score (final_score),
  INDEX idx_feedback_review (office_id, review_status, is_void),
  INDEX idx_feedback_fingerprint (office_id, record_fingerprint),
  INDEX idx_feedback_client_number (client_number),
  INDEX idx_feedback_import_batch (import_batch_id)
) ENGINE=InnoDB;

CREATE TABLE public_submission_log (
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

CREATE TABLE actions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  feedback_id BIGINT UNSIGNED NULL,
  office_id INT UNSIGNED NOT NULL,
  assigned_to_user_id INT UNSIGNED NULL,
  title VARCHAR(255) NOT NULL,
  details TEXT NULL,
  resolution_notes TEXT NULL,
  completion_requested_by_user_id INT UNSIGNED NULL,
  completion_requested_at DATETIME NULL,
  approved_by_user_id INT UNSIGNED NULL,
  approved_at DATETIME NULL,
  status ENUM('needs_action','in_progress','pending_approval','completed') NOT NULL DEFAULT 'needs_action',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  CONSTRAINT fk_actions_feedback FOREIGN KEY (feedback_id) REFERENCES feedback(id) ON DELETE SET NULL,
  CONSTRAINT fk_actions_office FOREIGN KEY (office_id) REFERENCES offices(id) ON DELETE RESTRICT,
  CONSTRAINT fk_actions_assignee FOREIGN KEY (assigned_to_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_action_requester FOREIGN KEY (completion_requested_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_action_approver FOREIGN KEY (approved_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_actions_scope (office_id, status),
  INDEX idx_actions_pending (office_id, status, updated_at)
) ENGINE=InnoDB;

CREATE TABLE import_rejected_rows (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  import_batch_id BIGINT UNSIGNED NOT NULL,
  row_number INT UNSIGNED NOT NULL,
  raw_row LONGTEXT NULL,
  error_message TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_rejected_batch FOREIGN KEY (import_batch_id) REFERENCES import_batches(id) ON DELETE CASCADE,
  INDEX idx_rejected_batch (import_batch_id, row_number)
) ENGINE=InnoDB;

CREATE TABLE notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  office_id INT UNSIGNED NULL,
  sender_user_id INT UNSIGNED NULL,
  type VARCHAR(40) NOT NULL,
  title VARCHAR(180) NOT NULL,
  message TEXT NOT NULL,
  link_url VARCHAR(255) NULL,
  read_at DATETIME NULL,
  expires_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_notification_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_notification_office FOREIGN KEY (office_id) REFERENCES offices(id) ON DELETE CASCADE,
  CONSTRAINT fk_notification_sender FOREIGN KEY (sender_user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_notification_unread (user_id, read_at, created_at),
  INDEX idx_notification_expiry (expires_at)
) ENGINE=InnoDB;

CREATE TABLE login_attempts (
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

CREATE TABLE training_candidates (
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

CREATE TABLE audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  action VARCHAR(100) NOT NULL,
  details TEXT NULL,
  ip_address VARCHAR(45) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_audit_date (created_at),
  INDEX idx_audit_user (user_id)
) ENGINE=InnoDB;

DELIMITER $$
CREATE TRIGGER trg_one_active_head_insert BEFORE INSERT ON users FOR EACH ROW
BEGIN
  IF NEW.role='office_head' AND NEW.status='active' AND NEW.office_id IS NOT NULL
     AND EXISTS(SELECT 1 FROM users WHERE office_id=NEW.office_id AND role='office_head' AND status='active') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Only one active Office Head is allowed per office.';
  END IF;
END$$
CREATE TRIGGER trg_one_active_head_update BEFORE UPDATE ON users FOR EACH ROW
BEGIN
  IF NEW.role='office_head' AND NEW.status='active' AND NEW.office_id IS NOT NULL
     AND EXISTS(SELECT 1 FROM users WHERE office_id=NEW.office_id AND role='office_head' AND status='active' AND id<>NEW.id) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Only one active Office Head is allowed per office.';
  END IF;
END$$
DELIMITER ;

INSERT INTO offices (id,name,code,description,status) VALUES
(1,'Pasig City Social Welfare and Development Office','CSWDO','Pasig City Social Welfare and Development Office client feedback survey.','active');

-- Demo credentials remain: admin/Admin123!, cswdo_head/Head123!, cswdo_staff/Staff123!
INSERT INTO users (id,office_id,full_name,username,email,password_hash,role,status,created_by_user_id,must_change_password) VALUES
(1,NULL,'System Administrator','admin','admin@pasig.local','$2y$12$00agB687GSWTK8nVf42KAeeAYH48yHhVGd8o7a9JgZ1MXbB0UE5z6','admin','active',NULL,0),
(2,1,'CSWDO Office Head','cswdo_head','cswdo.head@pasig.local','$2y$12$OV08K08m6fxl6pETw3fT2.w.Nib4nUkRd54qtjcEI5A0aHXNUXupy','office_head','active',1,0),
(3,1,'CSWDO Office Staff','cswdo_staff','cswdo.staff@pasig.local','$2y$12$ITyXyYqirwuxnX8RLKtXquZQdPBbfCjJVXKbs1uoI/quuUwEyO6BS','office_staff','active',2,0),
(4,1,'Cecil','cecil','cecil.staff@pasig.local','$2y$10$lPTmIx4ex1lhhP9aYzq89OdC5fx8CqQIdRorhqnKSSwEo4B2yceKa','office_staff','active',2,1),
(5,1,'Rhea','rhea','rhea.staff@pasig.local','$2y$10$lPTmIx4ex1lhhP9aYzq89OdC5fx8CqQIdRorhqnKSSwEo4B2yceKa','office_staff','active',2,1),
(6,1,'EMS','ems','ems.staff@pasig.local','$2y$10$lPTmIx4ex1lhhP9aYzq89OdC5fx8CqQIdRorhqnKSSwEo4B2yceKa','office_staff','active',2,1),
(7,1,'John','john','john.staff@pasig.local','$2y$10$lPTmIx4ex1lhhP9aYzq89OdC5fx8CqQIdRorhqnKSSwEo4B2yceKa','office_staff','active',2,1),
(8,1,'Kenneth','kenneth','kenneth.staff@pasig.local','$2y$10$lPTmIx4ex1lhhP9aYzq89OdC5fx8CqQIdRorhqnKSSwEo4B2yceKa','office_staff','active',2,1),
(9,1,'Uno','uno','uno.staff@pasig.local','$2y$10$lPTmIx4ex1lhhP9aYzq89OdC5fx8CqQIdRorhqnKSSwEo4B2yceKa','office_staff','active',2,1),
(10,1,'Alex','alex','alex.staff@pasig.local','$2y$10$lPTmIx4ex1lhhP9aYzq89OdC5fx8CqQIdRorhqnKSSwEo4B2yceKa','office_staff','active',2,1);

-- Additive migration. Does not alter users, feedback, or historical notifications.
CREATE TABLE IF NOT EXISTS client_count_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  staff_user_id INT UNSIGNED NOT NULL,
  office_id INT UNSIGNED NOT NULL,
  requested_year SMALLINT UNSIGNED NULL,
  status ENUM('pending','answered') NOT NULL DEFAULT 'pending',
  requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  answered_count INT UNSIGNED NULL,
  answered_at DATETIME NULL,
  answered_by_user_id INT UNSIGNED NULL,
  CONSTRAINT fk_count_staff FOREIGN KEY (staff_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_count_office FOREIGN KEY (office_id) REFERENCES offices(id) ON DELETE RESTRICT,
  CONSTRAINT fk_count_admin FOREIGN KEY (answered_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  INDEX idx_count_staff (staff_user_id, office_id, requested_at),
  INDEX idx_count_status (status, requested_at),
  pending_year SMALLINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN status='pending' THEN requested_year ELSE NULL END) STORED,
  UNIQUE KEY uq_pending_staff_year (staff_user_id, pending_year)
) ENGINE=InnoDB;
