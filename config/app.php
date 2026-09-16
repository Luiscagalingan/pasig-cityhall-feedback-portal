<?php
declare(strict_types=1);

const APP_NAME = 'Pasig City Hall Service Satisfaction Monitoring System';
const APP_SHORT_NAME = 'City Hall Feedback Portal';
const APP_BASE_URL = '/pasig-cityhall-feedback-portal';
const APP_TIMEZONE = 'Asia/Manila';
const SESSION_NAME = 'pasig_feedback_session';

// Use the project's Python environment so PHP and CLI share ML dependencies.
// Override with PASIG_PYTHON_BIN when deployed with a different environment.
$pythonLocalPath = __DIR__ . '/../.venv/'
    . (PHP_OS_FAMILY === 'Windows' ? 'Scripts/python.exe' : 'bin/python');
define('PYTHON_BIN', getenv('PASIG_PYTHON_BIN') ?: $pythonLocalPath);
const SVM_PREDICT_SCRIPT = __DIR__ . '/../ml/predict.py';

// With comments: 90% normalized ratings + 10% sentiment.
// Without comments: 100% normalized ratings (25% per question).
const RATING_WEIGHT = 0.90;
const COMMENT_WEIGHT = 0.10;
// Equivalent 0..100 presentation of the Chapter 2 normalized index.
const ACTION_SCORE_THRESHOLD = 33.5;

// Predictions below this value, and every fallback prediction, require human review.
const LOW_CONFIDENCE_THRESHOLD = 0.60;

// Authentication protection.
const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_IP_MAX_ATTEMPTS = 20;
const LOGIN_LOCK_MINUTES = 15;
const SESSION_IDLE_TIMEOUT = 1800; // 30 minutes

const PRIVACY_NOTICE_VERSION = '2026-07-29';

// Public survey abuse controls.
const SURVEY_SUBMISSION_COOLDOWN_SECONDS = 60;
const SURVEY_SUBMISSION_HOURLY_LIMIT = 3;

// Institutional defaults; update these when the approved retention schedule changes.
const FEEDBACK_RETENTION_MONTHS = 60;
const AUDIT_RETENTION_MONTHS = 6;
const LOGIN_ATTEMPT_RETENTION_DAYS = 90;
const NOTIFICATION_RETENTION_DAYS = 365;
const IMPORT_PREVIEW_RETENTION_HOURS = 24;
const BACKUP_RETENTION_DAYS = 30;
const PRIVACY_CONTACT = 'Pasig City Hall Data Protection / System Administrator';
