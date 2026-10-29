CREATE TABLE office_head_email_codes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  challenge_hash CHAR(64) NOT NULL UNIQUE,
  admin_user_id INT UNSIGNED NOT NULL,
  email VARCHAR(160) NOT NULL,
  code_hash VARCHAR(255) NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  sent_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  verified_at DATETIME NULL,
  consumed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_head_email_code_admin FOREIGN KEY (admin_user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_head_email_code_rate (admin_user_id,email,sent_at),
  INDEX idx_head_email_code_expiry (expires_at,consumed_at)
) ENGINE=InnoDB;
