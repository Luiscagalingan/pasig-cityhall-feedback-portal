USE pasig_feedback_portal;

-- Initial staff accounts. All have a temporary password of password123!
-- and must set a new password after their first successful login.
INSERT IGNORE INTO users (office_id,full_name,username,email,password_hash,role,status,created_by_user_id,must_change_password)
SELECT o.id,'Cecil','cecil','cecil.staff@pasig.local','$2y$10$lPTmIx4ex1lhhP9aYzq89OdC5fx8CqQIdRorhqnKSSwEo4B2yceKa','office_staff','active',NULL,1 FROM offices o WHERE o.status='active' ORDER BY o.id LIMIT 1;
INSERT IGNORE INTO users (office_id,full_name,username,email,password_hash,role,status,created_by_user_id,must_change_password)
SELECT o.id,'Rhea','rhea','rhea.staff@pasig.local','$2y$10$lPTmIx4ex1lhhP9aYzq89OdC5fx8CqQIdRorhqnKSSwEo4B2yceKa','office_staff','active',NULL,1 FROM offices o WHERE o.status='active' ORDER BY o.id LIMIT 1;
INSERT IGNORE INTO users (office_id,full_name,username,email,password_hash,role,status,created_by_user_id,must_change_password)
SELECT o.id,'EMS','ems','ems.staff@pasig.local','$2y$10$lPTmIx4ex1lhhP9aYzq89OdC5fx8CqQIdRorhqnKSSwEo4B2yceKa','office_staff','active',NULL,1 FROM offices o WHERE o.status='active' ORDER BY o.id LIMIT 1;
INSERT IGNORE INTO users (office_id,full_name,username,email,password_hash,role,status,created_by_user_id,must_change_password)
SELECT o.id,'John','john','john.staff@pasig.local','$2y$10$lPTmIx4ex1lhhP9aYzq89OdC5fx8CqQIdRorhqnKSSwEo4B2yceKa','office_staff','active',NULL,1 FROM offices o WHERE o.status='active' ORDER BY o.id LIMIT 1;
INSERT IGNORE INTO users (office_id,full_name,username,email,password_hash,role,status,created_by_user_id,must_change_password)
SELECT o.id,'Kenneth','kenneth','kenneth.staff@pasig.local','$2y$10$lPTmIx4ex1lhhP9aYzq89OdC5fx8CqQIdRorhqnKSSwEo4B2yceKa','office_staff','active',NULL,1 FROM offices o WHERE o.status='active' ORDER BY o.id LIMIT 1;
INSERT IGNORE INTO users (office_id,full_name,username,email,password_hash,role,status,created_by_user_id,must_change_password)
SELECT o.id,'Uno','uno','uno.staff@pasig.local','$2y$10$lPTmIx4ex1lhhP9aYzq89OdC5fx8CqQIdRorhqnKSSwEo4B2yceKa','office_staff','active',NULL,1 FROM offices o WHERE o.status='active' ORDER BY o.id LIMIT 1;
INSERT IGNORE INTO users (office_id,full_name,username,email,password_hash,role,status,created_by_user_id,must_change_password)
SELECT o.id,'Alex','alex','alex.staff@pasig.local','$2y$10$lPTmIx4ex1lhhP9aYzq89OdC5fx8CqQIdRorhqnKSSwEo4B2yceKa','office_staff','active',NULL,1 FROM offices o WHERE o.status='active' ORDER BY o.id LIMIT 1;
