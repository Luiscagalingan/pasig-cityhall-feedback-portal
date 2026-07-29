<?php
declare(strict_types=1);

const APP_NAME = 'Pasig City Hall Service Satisfaction Monitoring System';
const APP_SHORT_NAME = 'City Hall Feedback Portal';
const APP_BASE_URL = '/pasig-cityhall-feedback-portal';
const APP_TIMEZONE = 'Asia/Manila';
const SESSION_NAME = 'pasig_feedback_session';

const PYTHON_BIN = 'C:/Users/PC/AppData/Local/Programs/Python/Python312/python.exe';
const SVM_PREDICT_SCRIPT = __DIR__ . '/../ml/predict.py';

const RATING_WEIGHT = 0.60;
const COMMENT_WEIGHT = 0.40;

const ACTION_SCORE_THRESHOLD = 60.0;