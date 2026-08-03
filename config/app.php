<?php
declare(strict_types=1);

const APP_NAME = 'Pasig City Hall Service Satisfaction Monitoring System';
const APP_SHORT_NAME = 'City Hall Feedback Portal';
const APP_BASE_URL = '/pasig-cityhall-feedback-portal';
const APP_TIMEZONE = 'Asia/Manila';
const SESSION_NAME = 'pasig_feedback_session';

// Python 3.12 installed on this XAMPP computer.
// Override with PASIG_PYTHON_BIN environment variable when deployed elsewhere.
define('PYTHON_BIN', getenv('PASIG_PYTHON_BIN') ?: 'C:/Users/PC/AppData/Local/Programs/Python/Python312/python.exe');
const SVM_PREDICT_SCRIPT = __DIR__ . '/../ml/predict.py';

// Final weighted score = 60% structured ratings + 40% comment sentiment.
const RATING_WEIGHT = 0.60;
const COMMENT_WEIGHT = 0.40;
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
