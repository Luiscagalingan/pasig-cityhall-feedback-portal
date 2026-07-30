<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
$office = fetch_office_by_code((string)($_GET['office'] ?? $_POST['office_code'] ?? ''));
if (!$office) { http_response_code(404); exit('The selected office survey is unavailable.'); }
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $visitDate = (string)($_POST['visit_date'] ?? '');
    $sex = trim((string)($_POST['sex'] ?? ''));
    $age = (int)($_POST['age'] ?? 0);
    $clientType = trim((string)($_POST['client_type'] ?? ''));
    $service = trim((string)($_POST['service_received'] ?? ''));
    $comment = trim((string)($_POST['comment'] ?? ''));
    $ratings = [$_POST['timeliness'] ?? null, $_POST['client_handling'] ?? null, $_POST['quality'] ?? null, $_POST['overall'] ?? null];
    if (!$visitDate || strtotime($visitDate) === false || $visitDate > date('Y-m-d')) $errors[] = 'Enter a valid service date that is not in the future.';
    if (!in_array($sex, ['Female','Male','Prefer not to say'], true)) $errors[] = 'Select a valid sex option.';
    if ($age < 1 || $age > 120) $errors[] = 'Enter a valid age.';
    if (!in_array($clientType, ['Pasigueño','Non-Pasigueño','City Government Employee'], true)) $errors[] = 'Select a valid client classification.';
    if ($service === '') $errors[] = 'Enter the transaction or service received.';
    if (array_filter($ratings, fn($r) => !valid_rating($r))) $errors[] = 'Answer all four service ratings.';
    if (mb_strlen($comment) < 5) $errors[] = 'The written comment is required and must be meaningful.';
    if (!isset($_POST['consent'])) $errors[] = 'Confirm that you agree to the privacy notice and feedback processing.';

    if (!$errors) {
        $prediction = predict_sentiment($comment);
        $scores = compute_feedback_scores($ratings, $prediction['label']);
        $reviewStatus = prediction_review_status($prediction);
        $fingerprint = feedback_fingerprint((int)$office['id'], ['visit_date'=>$visitDate,'sex'=>$sex,'age'=>$age,'client_type'=>$clientType,'service'=>$service,'ratings'=>$ratings,'comment'=>$comment]);
        $stmt = db()->prepare("INSERT INTO feedback
            (office_id,visit_date,sex,age,client_type,service_received,timeliness_rating,client_handling_rating,quality_rating,overall_rating,comment,
             sentiment,original_sentiment,sentiment_confidence,sentiment_source,review_status,model_version,average_rating,rating_percent,comment_score,final_score,
             source,record_fingerprint,consent_version,submitted_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'public_survey',?,?,NOW())");
        $stmt->execute([(int)$office['id'],$visitDate,$sex,$age,$clientType,$service,(int)$ratings[0],(int)$ratings[1],(int)$ratings[2],(int)$ratings[3],$comment,
            $prediction['label'],$prediction['label'],$prediction['confidence'],$prediction['source'],$reviewStatus,model_version(),$scores['average_rating'],$scores['rating_percent'],$scores['comment_score'],$scores['final_score'],$fingerprint,PRIVACY_NOTICE_VERSION]);
        $feedbackId = (int)db()->lastInsertId();
        create_action_if_needed($feedbackId, (int)$office['id'], $prediction['label'], $scores['final_score'], $comment);
        if ($reviewStatus === 'needs_review') {
            notify_office_heads((int)$office['id'], 'review', 'Sentiment review required', 'Feedback #' . $feedbackId . ' has low confidence or used the fallback classifier.', 'review.php');
            notify_admins('review', 'Sentiment review required', 'Feedback #' . $feedbackId . ' requires human review.', 'review.php');
        }
        audit(null, 'public_feedback_submit', 'Office ' . $office['code'] . ', feedback #' . $feedbackId . ', source=' . $prediction['source']);
        set_flash('success', 'Thank you. Your feedback was submitted successfully.');
        redirect('survey.php?office=' . urlencode($office['code']));
    }
}
render_public_start('Feedback Survey');
?>
<nav class="public-nav"><a class="public-brand" href="<?= e(app_url()) ?>"><img class="seal" src="<?= e(app_url('assets/images/241304413_194220316131017_8817860418863376271_n.jpg')) ?>" alt="Pasig Public Information Office logo"><div><strong>City Government of Pasig</strong><small>Public Feedback Survey</small></div></a><div class="public-actions"><button class="theme-toggle" data-theme-toggle><?= icon('sun') ?><span>Light</span></button><a class="btn secondary text-link" href="<?= e(app_url('login.php')) ?>">Authorized Login</a></div></nav>
<div class="survey-shell"><div class="survey-banner"><span class="eyebrow"><?= e($office['code']) ?> FEEDBACK FORM</span><h1><?= e($office['name']) ?></h1><p class="muted">Rate the service you received. Your written comment is required and will be classified by the trained SVM sentiment model.</p></div>
<form method="post" class="survey-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="office_code" value="<?= e($office['code']) ?>">
<?php if ($errors): ?><div class="error-box"><strong>Please correct the following:</strong><br><?= implode('<br>', array_map('e',$errors)) ?></div><?php endif; ?>
<h2 class="section-title">Client and transaction details</h2><div class="form-row three"><div class="form-group"><label>Date of Service <span class="required">*</span></label><input type="date" name="visit_date" value="<?= e($_POST['visit_date'] ?? date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>" required></div><div class="form-group"><label>Sex <span class="required">*</span></label><select name="sex" required><option value="">Select</option><?php foreach(['Female','Male','Prefer not to say'] as $v): ?><option <?= (($_POST['sex'] ?? '')===$v)?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?></select></div><div class="form-group"><label>Age <span class="required">*</span></label><input type="number" min="1" max="120" name="age" value="<?= e($_POST['age'] ?? '') ?>" required></div></div>
<div class="form-row"><div class="form-group"><label>Client Classification <span class="required">*</span></label><select name="client_type" required><option value="">Select</option><?php foreach(['Pasigueño','Non-Pasigueño','City Government Employee'] as $v): ?><option <?= (($_POST['client_type'] ?? '')===$v)?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?></select></div><div class="form-group"><label>Transaction or Service Received <span class="required">*</span></label><input name="service_received" value="<?= e($_POST['service_received'] ?? '') ?>" required></div></div>
<h2 class="section-title">Service ratings</h2><p class="help">1 = Strongly Disagree, 2 = Disagree, 3 = Agree, 4 = Strongly Agree</p><div class="rating-grid">
<?php $items=['timeliness'=>'Timeliness — the time required to process the request or transaction.','client_handling'=>'Client Handling — courtesy and professionalism shown by personnel.','quality'=>'Quality of Service — accuracy, completeness, and reliability of the service.','overall'=>'Overall Satisfaction — your overall satisfaction with the service received.']; foreach($items as $name=>$label): ?><div class="rating-item"><strong><?= e($label) ?> <span class="required">*</span></strong><div class="rating-options"><?php for($i=1;$i<=4;$i++): ?><label><input type="radio" name="<?= e($name) ?>" value="<?= $i ?>" <?= ((int)($_POST[$name] ?? 0)===$i)?'checked':'' ?> required><span><?= $i ?> · <?= ['','Strongly Disagree','Disagree','Agree','Strongly Agree'][$i] ?></span></label><?php endfor; ?></div></div><?php endforeach; ?></div>
<h2 class="section-title">Required written comment</h2><div class="form-group"><label>Comment or Suggestion <span class="required">*</span></label><textarea name="comment" minlength="5" maxlength="3000" required placeholder="Describe your experience in English, Filipino, or Taglish."><?= e($_POST['comment'] ?? '') ?></textarea><div class="help">Low-confidence or fallback classifications are queued for authorized human review. The original comment is always retained.</div></div>
<div class="privacy-notice"><strong>Privacy Notice (Version <?= e(PRIVACY_NOTICE_VERSION) ?>)</strong><p>Your sex, age, client classification, service ratings, and written comment are collected only for service-quality monitoring, analysis, reporting, and action management. Do not include names, phone numbers, medical diagnoses, or other unnecessary identifiers. Authorized users can access only data within their assigned role and office scope.</p></div>
<label class="checkbox-row"><input type="checkbox" name="consent" value="1" required><span>I have read the privacy notice and voluntarily submit this feedback for service-quality monitoring.</span></label><button class="btn" type="submit">Submit Feedback</button></form></div>
<?php render_public_end(); ?>
