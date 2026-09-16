USE pasig_feedback_portal;

-- Empty comments are stored as an empty string. Their sentiment is derived
-- from the four required service ratings and identified with source='rating'.
ALTER TABLE feedback
  MODIFY sentiment_source ENUM('svm','fallback','manual','empty','rating') NOT NULL DEFAULT 'svm';
