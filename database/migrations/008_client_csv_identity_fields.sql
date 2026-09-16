USE pasig_feedback_portal;

-- Client-supplied CSV fields retained with feedback records.
ALTER TABLE feedback
  ADD COLUMN assisted_by VARCHAR(255) NULL AFTER comment,
  ADD COLUMN client_number VARCHAR(100) NULL AFTER assisted_by,
  ADD COLUMN surname VARCHAR(100) NULL AFTER client_number,
  ADD COLUMN given_name VARCHAR(100) NULL AFTER surname,
  ADD COLUMN middle_name VARCHAR(100) NULL AFTER given_name,
  ADD INDEX idx_feedback_client_number (client_number);
