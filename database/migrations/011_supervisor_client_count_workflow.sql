USE pasig_feedback_portal;

ALTER TABLE users
  MODIFY role ENUM('admin','office_head','supervisor','office_staff') NOT NULL;

UPDATE users SET role='supervisor' WHERE username='uno';
