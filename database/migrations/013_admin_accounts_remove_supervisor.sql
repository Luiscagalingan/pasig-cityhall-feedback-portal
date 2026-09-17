USE pasig_feedback_portal;
SET FOREIGN_KEY_CHECKS=0;
DELETE FROM users WHERE role='supervisor';
SET FOREIGN_KEY_CHECKS=1;
ALTER TABLE users MODIFY role ENUM('admin','office_head','office_staff') NOT NULL;
INSERT INTO users (full_name, username, email, password_hash, role, status, must_change_password)
VALUES ('Sir Uno','uno','uno@pasig.gov.ph','$2y$10$J3yICV7oTsT1s7SmN3NWsufGN6KXa0bnx..0CAqJ5iI2S1AvFgcuO','admin','active',0),
('Ma’am Chock','chock','chock@pasig.gov.ph','$2y$10$J3yICV7oTsT1s7SmN3NWsufGN6KXa0bnx..0CAqJ5iI2S1AvFgcuO','admin','active',0)
ON DUPLICATE KEY UPDATE full_name=VALUES(full_name),role='admin',status='active',must_change_password=0;
