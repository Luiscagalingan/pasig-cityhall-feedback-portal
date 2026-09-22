-- Additive migration. Does not alter users, feedback, or historical notifications.
CREATE TABLE IF NOT EXISTS client_count_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  staff_user_id INT UNSIGNED NOT NULL,
  office_id INT UNSIGNED NOT NULL,
  status ENUM('pending','answered') NOT NULL DEFAULT 'pending',
  requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  answered_count INT UNSIGNED NULL,
  answered_at DATETIME NULL,
  answered_by_user_id INT UNSIGNED NULL,
  CONSTRAINT fk_count_staff FOREIGN KEY (staff_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_count_office FOREIGN KEY (office_id) REFERENCES offices(id) ON DELETE RESTRICT,
  CONSTRAINT fk_count_admin FOREIGN KEY (answered_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  INDEX idx_count_staff (staff_user_id, office_id, requested_at),
  INDEX idx_count_status (status, requested_at)
) ENGINE=InnoDB;
