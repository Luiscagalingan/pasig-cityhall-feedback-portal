-- Role-scoped announcements and seven-day notification lifecycle.
ALTER TABLE notifications
  ADD COLUMN sender_user_id INT UNSIGNED NULL AFTER office_id,
  ADD COLUMN expires_at DATETIME NULL AFTER read_at,
  ADD CONSTRAINT fk_notification_sender FOREIGN KEY (sender_user_id) REFERENCES users(id) ON DELETE SET NULL,
  ADD INDEX idx_notification_expiry (expires_at);

