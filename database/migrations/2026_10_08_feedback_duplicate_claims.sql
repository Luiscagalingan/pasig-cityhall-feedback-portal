CREATE TABLE IF NOT EXISTS feedback_duplicate_claims (
  office_id INT UNSIGNED NOT NULL,
  record_fingerprint CHAR(64) NOT NULL,
  feedback_id BIGINT UNSIGNED NULL,
  claimed_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  PRIMARY KEY (office_id,record_fingerprint),
  INDEX idx_feedback_claim_expiry (expires_at),
  CONSTRAINT fk_feedback_claim_office FOREIGN KEY (office_id) REFERENCES offices(id) ON DELETE CASCADE,
  CONSTRAINT fk_feedback_claim_feedback FOREIGN KEY (feedback_id) REFERENCES feedback(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS public_submission_rate_limits (
  client_hash CHAR(64) PRIMARY KEY,
  window_started_at DATETIME NOT NULL,
  submission_count SMALLINT UNSIGNED NOT NULL,
  last_submit_at DATETIME NOT NULL,
  INDEX idx_public_rate_window (window_started_at)
) ENGINE=InnoDB;

INSERT INTO feedback_duplicate_claims(office_id,record_fingerprint,feedback_id,claimed_at,expires_at)
SELECT f.office_id,f.record_fingerprint,MAX(f.id),MAX(f.submitted_at),DATE_ADD(MAX(f.submitted_at),INTERVAL 1440 MINUTE)
FROM feedback f
WHERE f.is_void=0 AND f.record_fingerprint IS NOT NULL AND f.record_fingerprint<>''
  AND f.submitted_at>=DATE_SUB(NOW(),INTERVAL 1440 MINUTE)
GROUP BY f.office_id,f.record_fingerprint
ON DUPLICATE KEY UPDATE feedback_id=VALUES(feedback_id),claimed_at=VALUES(claimed_at),expires_at=VALUES(expires_at);
