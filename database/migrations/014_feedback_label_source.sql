USE pasig_feedback_portal;

ALTER TABLE feedback
  ADD COLUMN label_source ENUM('auto_rating','svm_prediction','fallback','verified_manual') NOT NULL DEFAULT 'svm_prediction' AFTER sentiment_source;

UPDATE feedback
SET label_source = CASE
  WHEN review_status='reviewed' THEN 'verified_manual'
  WHEN sentiment_source='fallback' THEN 'fallback'
  WHEN sentiment_source='rating' THEN 'auto_rating'
  ELSE 'svm_prediction'
END
WHERE label_source='svm_prediction';

UPDATE feedback
SET sentiment = CASE
  WHEN overall_rating >= 4 THEN 'positive'
  WHEN overall_rating = 3 THEN 'neutral'
  ELSE 'negative'
END,
label_source='auto_rating',
sentiment_source='rating',
review_status='not_required'
WHERE source='csv_import' AND review_status<>'reviewed';

DROP TRIGGER IF EXISTS trg_csv_auto_rating_label;
DELIMITER //
CREATE TRIGGER trg_csv_auto_rating_label BEFORE INSERT ON feedback
FOR EACH ROW
BEGIN
  IF NEW.source='csv_import' THEN
    SET NEW.sentiment = CASE
      WHEN NEW.overall_rating >= 4 THEN 'positive'
      WHEN NEW.overall_rating = 3 THEN 'neutral'
      ELSE 'negative'
    END;
    SET NEW.original_sentiment = NEW.sentiment;
    SET NEW.sentiment_source = 'rating';
    SET NEW.label_source = 'auto_rating';
    SET NEW.review_status = 'not_required';
  END IF;
END//
DELIMITER ;
