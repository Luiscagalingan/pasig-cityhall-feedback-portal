ALTER TABLE users
  MODIFY status ENUM('active','archived','pending') NOT NULL DEFAULT 'active',
  ADD COLUMN email_verification_required TINYINT(1) NOT NULL DEFAULT 0 AFTER must_change_password,
  ADD COLUMN password_changed_at DATETIME NULL AFTER must_change_password,
  ADD COLUMN email_verified_at DATETIME NULL AFTER email_verification_required,
  ADD COLUMN verification_token_hash CHAR(64) NULL AFTER email_verified_at,
  ADD COLUMN verification_expires_at DATETIME NULL AFTER verification_token_hash,
  ADD COLUMN verification_sent_at DATETIME NULL AFTER verification_expires_at,
  ADD COLUMN verification_send_count TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER verification_sent_at,
  ADD UNIQUE KEY uq_user_verification_token_hash (verification_token_hash),
  ADD INDEX idx_user_verification_state (status,email_verification_required,email_verified_at);
