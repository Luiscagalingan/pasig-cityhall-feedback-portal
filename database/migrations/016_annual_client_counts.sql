-- Existing unscoped requests keep NULL years; do not relabel old snapshots.
ALTER TABLE client_count_requests
  ADD COLUMN requested_year SMALLINT UNSIGNED NULL AFTER office_id,
  ADD COLUMN pending_year SMALLINT UNSIGNED GENERATED ALWAYS AS
    (CASE WHEN status='pending' THEN requested_year ELSE NULL END) STORED,
  ADD UNIQUE KEY uq_pending_staff_year (staff_user_id, pending_year);
