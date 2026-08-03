USE pasig_feedback_portal;

-- Align stored results with the Chapter 2 formula:
-- normalized rating = (average rating - 2.5) / 1.5
-- sentiment = positive:+1, neutral:0, negative:-1
-- final = normalized rating * 0.60 + sentiment * 0.40
-- The database/UI stores the mathematically equivalent 0..100 score:
-- displayed score = (Chapter 2 index + 1) * 50.
ALTER TABLE feedback
  MODIFY rating_percent DECIMAL(6,2) NOT NULL COMMENT 'Chapter 2 normalized rating expressed from 0 to 100',
  MODIFY comment_score DECIMAL(6,2) NOT NULL COMMENT 'Positive=100, neutral=50, negative=0',
  MODIFY final_score DECIMAL(6,2) NOT NULL COMMENT '0 to 100 equivalent of the Chapter 2 weighted index';

UPDATE feedback
SET rating_percent = ROUND(((average_rating - 1) / 3) * 100, 2),
    comment_score = CASE sentiment
      WHEN 'positive' THEN 100
      WHEN 'negative' THEN 0
      ELSE 50
    END,
    final_score = ROUND(
      ((((average_rating - 1) / 3) * 100) * 0.60) +
      ((CASE sentiment WHEN 'positive' THEN 100 WHEN 'negative' THEN 0 ELSE 50 END) * 0.40),
      2
    );
