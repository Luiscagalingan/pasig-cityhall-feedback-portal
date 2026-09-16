USE pasig_feedback_portal;

-- Demo record for the Sentiment Review workflow.
-- Safe to run more than once: the marker prevents duplicate demo rows.
INSERT INTO feedback (
    office_id,
    visit_date,
    sex,
    age,
    client_type,
    service_received,
    timeliness_rating,
    client_handling_rating,
    quality_rating,
    overall_rating,
    comment,
    sentiment,
    original_sentiment,
    sentiment_confidence,
    sentiment_source,
    review_status,
    model_version,
    average_rating,
    rating_percent,
    comment_score,
    final_score,
    source,
    record_fingerprint,
    consent_version,
    submitted_at
)
SELECT
    o.id,
    CURDATE(),
    'Female',
    35,
    'Pasigueño',
    'Medical consultation - Sentiment Review Demo',
    3,
    3,
    3,
    3,
    'Maayos naman ang staff pero matagal ang pila at hindi malinaw kung saan susunod.',
    'neutral',
    'neutral',
    0.4200,
    'fallback',
    'needs_review',
    'demo-low-confidence-v1',
    3.00,
    66.67,
    50.00,
    60.00,
    'manual_entry',
    SHA2(CONCAT('SENTIMENT-REVIEW-DEMO-', o.id), 256),
    'demo-v1',
    NOW()
FROM offices o
WHERE o.code = 'CSWDO'
  AND NOT EXISTS (
      SELECT 1
      FROM feedback f
      WHERE f.record_fingerprint = SHA2(CONCAT('SENTIMENT-REVIEW-DEMO-', o.id), 256)
  )
LIMIT 1;

-- Verification: this should return one row with review_status = needs_review.
SELECT
    f.id,
    o.code AS office,
    f.service_received,
    f.sentiment,
    f.sentiment_confidence,
    f.sentiment_source,
    f.review_status,
    f.final_score
FROM feedback f
JOIN offices o ON o.id = f.office_id
WHERE f.record_fingerprint = SHA2(CONCAT('SENTIMENT-REVIEW-DEMO-', o.id), 256);
