<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
render_public_start('Privacy and Data Governance');
?>
<nav class="public-nav"><a class="public-brand" href="<?= e(app_url()) ?>"><img class="seal" src="<?= e(app_url('assets/images/241304413_194220316131017_8817860418863376271_n.jpg')) ?>" alt="Pasig Public Information Office logo"><div><strong>City Government of Pasig</strong><small>Privacy and Data Governance</small></div></a><div class="public-actions"><a class="btn secondary public-back" href="<?= e(app_url()) ?>">&larr; Return to Portal</a></div></nav>
<main class="survey-shell"><section class="survey-banner"><span class="eyebrow">PRIVACY NOTICE <?= e(PRIVACY_NOTICE_VERSION) ?></span><h1>How feedback data is protected</h1><p class="muted survey-intro">This policy explains the purpose, scope, safeguards, retention, and rights connected to the public service feedback portal.</p></section>
<section class="survey-form policy-content">
<h2>Purpose and lawful service function</h2><p>Feedback is collected only to measure service quality, produce authorized reports, identify concerns requiring action, and support approved research evaluation. It is not used for advertising or unrelated profiling.</p>
<h2>Information collected</h2><p>The portal collects service date, sex, age, client type, service received, four service ratings, and a written comment. Do not include names, contact numbers, diagnoses, account numbers, or other unnecessary identifiers.</p>
<h2>Eligibility and consent</h2><p>Only adults aged 18 to 120 may submit the public survey. Submission is voluntary and requires affirmative consent to the current privacy notice.</p>
<h2>Access and disclosure</h2><p>Office users can access only records assigned to their active office. Administrators have consolidated access for system governance. Role checks, office-ID scoping, sessions, CSRF protection, audit logs, and prepared database statements protect access.</p>
<h2>Automated classification</h2><p>The SVM model classifies comments as positive, neutral, or negative. Low-confidence and fallback results are marked and queued for authorized human review. The original comment and original prediction are retained for accountability.</p>
<h2>Abuse prevention</h2><p>Exact duplicate responses are blocked. A one-way hash derived from network/browser information is temporarily used to enforce submission cooldown and hourly limits; the portal does not store that value as a respondent identity.</p>
<h2>Retention and secure disposal</h2><p>Feedback is retained for <?= FEEDBACK_RETENTION_MONTHS ?> months, audit logs for <?= AUDIT_RETENTION_MONTHS ?> months, login attempts for <?= LOGIN_ATTEMPT_RETENTION_DAYS ?> days, notifications for <?= NOTIFICATION_RETENTION_DAYS ?> days, and temporary import previews for <?= IMPORT_PREVIEW_RETENTION_HOURS ?> hours. Authorized maintenance removes expired data. Downloaded backups must be encrypted and should be disposed of after <?= BACKUP_RETENTION_DAYS ?> days unless an approved schedule requires otherwise.</p>
<h2>Data-subject requests and incidents</h2><p>Questions, correction requests, deletion requests, suspected unauthorized access, and privacy incidents must be reported to <?= e(PRIVACY_CONTACT) ?>. Requests are reviewed against legal, research, audit, and records-management obligations before action is taken.</p>
<h2>Important limitation</h2><p>This system implements technical safeguards, but institutional approval, privacy-impact assessment, authorized personnel assignments, and incident-response coordination remain responsibilities of the deploying organization.</p>
</section></main>
<?php render_public_end(); ?>
