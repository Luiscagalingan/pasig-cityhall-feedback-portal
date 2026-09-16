<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
$office = fetch_office_by_code((string)($_GET['office'] ?? $_POST['office_code'] ?? ''));
if (!$office) { http_response_code(404); exit('The selected office survey is unavailable.'); }
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $submissionLimit = survey_submission_limit();
    if (!$submissionLimit['allowed']) {
        if ((int)$submissionLimit['hourly_count'] >= SURVEY_SUBMISSION_HOURLY_LIMIT) {
            $errors[] = 'Naabot na ang maximum na tatlong submission sa loob ng isang oras. Subukan muli mamaya.';
        } else {
            $errors[] = 'Maghintay muna ng ' . max(1, (int)$submissionLimit['wait_seconds']) . ' segundo bago muling magsumite.';
        }
    }
    $visitDate = (string)($_POST['visit_date'] ?? '');
    $sex = trim((string)($_POST['sex'] ?? ''));
    $age = (int)($_POST['age'] ?? 0);
    $clientType = trim((string)($_POST['client_type'] ?? ''));
    $service = trim((string)($_POST['service_received'] ?? ''));
    $comment = trim((string)($_POST['comment'] ?? ''));
    $ratings = [$_POST['timeliness'] ?? null, $_POST['client_handling'] ?? null, $_POST['quality'] ?? null, $_POST['overall'] ?? null];
    if (!$visitDate || strtotime($visitDate) === false || $visitDate > date('Y-m-d')) $errors[] = 'Enter a valid service date that is not in the future.';
    if (!in_array($sex, ['Female','Male','Prefer not to say'], true)) $errors[] = 'Select a valid sex option.';
    if ($age < 18) $errors[] = 'Hindi maaaring sumagot ang edad 17 pababa. Ang survey na ito ay para lamang sa edad 18 pataas.';
    elseif ($age > 120) $errors[] = 'Maglagay ng wastong edad mula 18 hanggang 120.';
    if (!in_array($clientType, ['Pasigueño','Non-Pasigueño','City Government Employee'], true)) $errors[] = 'Select a valid client classification.';
    if ($service === '') $errors[] = 'Enter the transaction or service received.';
    if (array_filter($ratings, fn($r) => !valid_rating($r))) $errors[] = 'Answer all four service ratings.';
    if (!isset($_POST['consent'])) $errors[] = 'Confirm that you agree to the privacy notice and feedback processing.';

    if (!$errors) {
        $fingerprint = feedback_fingerprint((int)$office['id'], ['visit_date'=>$visitDate,'sex'=>$sex,'age'=>$age,'client_type'=>$clientType,'service'=>$service,'ratings'=>$ratings,'comment'=>$comment]);
        $duplicate = db()->prepare('SELECT id FROM feedback WHERE office_id=? AND record_fingerprint=? AND is_void=0 LIMIT 1');
        $duplicate->execute([(int)$office['id'], $fingerprint]);
        if ($duplicate->fetchColumn()) {
            $errors[] = 'Naisumite na ang kaparehong feedback. Hindi na ito muling isinave upang maiwasan ang duplicate response.';
        }
    }

    if (!$errors) {
        $prediction = feedback_sentiment_prediction($ratings, $comment);
        $scores = compute_feedback_scores($ratings, $prediction['label'], $comment);
        $reviewStatus = prediction_review_status($prediction);
        $stmt = db()->prepare("INSERT INTO feedback
            (office_id,visit_date,sex,age,client_type,service_received,timeliness_rating,client_handling_rating,quality_rating,overall_rating,comment,
             sentiment,original_sentiment,sentiment_confidence,sentiment_source,review_status,model_version,average_rating,rating_percent,comment_score,final_score,
             source,record_fingerprint,consent_version,submitted_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'public_survey',?,?,NOW())");
        $stmt->execute([(int)$office['id'],$visitDate,$sex,$age,$clientType,$service,(int)$ratings[0],(int)$ratings[1],(int)$ratings[2],(int)$ratings[3],$comment,
            $prediction['label'],$prediction['label'],$prediction['confidence'],$prediction['source'],$reviewStatus,model_version(),$scores['average_rating'],$scores['rating_percent'],$scores['comment_score'],$scores['final_score'],$fingerprint,PRIVACY_NOTICE_VERSION]);
        $feedbackId = (int)db()->lastInsertId();
        record_public_submission((int)$office['id'], $feedbackId);
        create_action_if_needed($feedbackId, (int)$office['id'], $prediction['label'], $scores['final_score'], $comment);
        if ($reviewStatus === 'needs_review') {
            notify_office_heads((int)$office['id'], 'review', 'Sentiment review required', 'Feedback #' . $feedbackId . ' has low confidence or used the fallback classifier.', 'review.php');
            notify_admins('review', 'Sentiment review required', 'Feedback #' . $feedbackId . ' requires human review.', 'review.php');
        }
        audit(null, 'public_feedback_submit', 'Office ' . $office['code'] . ', feedback #' . $feedbackId . ', source=' . $prediction['source']);
        set_flash('success', 'Thank you. Your feedback was submitted successfully.');
        redirect('');
    }
}
render_public_start('Feedback Survey', 'public-body survey-page');
?>
<nav class="public-nav"><a class="public-brand" href="<?= e(app_url()) ?>"><img class="seal" src="<?= e(app_url('assets/images/241304413_194220316131017_8817860418863376271_n.jpg')) ?>" alt="Pasig Public Information Office logo"><div><strong>City Government of Pasig</strong><small>Public Feedback Survey</small></div></a><div class="public-actions"><a class="btn secondary public-back" href="<?= e(app_url()) ?>"><span aria-hidden="true">←</span> Back to Office Selection</a></div></nav>
<div class="survey-shell"><div class="survey-banner"><span class="eyebrow"><?= e($office['code']) ?> FEEDBACK</span><h1>Ibahagi ang inyong feedback</h1><p class="muted survey-intro">Tumatagal lamang nang 2–3 minuto. Gagamitin ang inyong sagot upang mapabuti ang serbisyo ng CSWDO.</p></div>
<form method="post" class="survey-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="office_code" value="<?= e($office['code']) ?>">
<?php if ($errors): ?><div class="error-box"><strong>Please correct the following:</strong><br><?= implode('<br>', array_map('e',$errors)) ?></div><?php endif; ?>
<h2 class="section-title">1. Detalye ng pagbisita</h2><div class="form-row three"><div class="form-group"><label>Petsa ng serbisyo <span class="required">*</span></label><input type="date" name="visit_date" value="<?= e($_POST['visit_date'] ?? date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>" required></div><div class="form-group"><label>Kasarian <span class="required">*</span></label><select name="sex" required><option value="">Pumili</option><?php foreach(['Female','Male','Prefer not to say'] as $v): ?><option <?= (($_POST['sex'] ?? '')===$v)?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?></select></div><div class="form-group"><label>Edad (18+) <span class="required">*</span></label><input type="number" min="18" max="120" name="age" value="<?= e($_POST['age'] ?? '') ?>" inputmode="numeric" required><div class="help">Para sa edad 18–120 lamang.</div></div></div>
<div class="form-row"><div class="form-group"><label>Uri ng kliyente <span class="required">*</span></label><select name="client_type" required><option value="">Pumili</option><?php foreach(['Pasigueño','Non-Pasigueño','City Government Employee'] as $v): ?><option <?= (($_POST['client_type'] ?? '')===$v)?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?></select></div><div class="form-group"><label>Serbisyong natanggap <span class="required">*</span></label><input name="service_received" value="<?= e($_POST['service_received'] ?? '') ?>" placeholder="Halimbawa: Pagkuha ng permit" required></div></div>
<h2 class="section-title">2. I-rate ang serbisyo</h2><p class="muted survey-instruction">Piliin ang sagot na pinakamalapit sa inyong karanasan.</p><div class="rating-guide" aria-label="Rating guide"><span><b>1</b>Lubos na hindi sang-ayon</span><span><b>2</b>Hindi sang-ayon</span><span><b>3</b>Sang-ayon</span><span><b>4</b>Lubos na sang-ayon</span></div><div class="rating-grid">
<?php $items=['timeliness'=>'Bilis ng serbisyo','client_handling'=>'Pakikitungo ng kawani','quality'=>'Kalidad ng serbisyo','overall'=>'Kabuuang kasiyahan']; foreach($items as $name=>$label): ?><div class="rating-item"><strong class="rating-question"><?= e($label) ?> <span class="required">*</span></strong><div class="rating-options"><?php for($i=1;$i<=4;$i++): ?><label><input type="radio" name="<?= e($name) ?>" value="<?= $i ?>" <?= ((int)($_POST[$name] ?? 0)===$i)?'checked':'' ?> required><span><?= $i ?></span></label><?php endfor; ?></div></div><?php endforeach; ?></div>
<h2 class="section-title">3. Komento <span class="muted">(opsyonal)</span></h2><div class="form-group"><label>Komento o mungkahi</label><textarea name="comment" maxlength="3000" placeholder="Ibahagi ang inyong karanasan o mungkahi."><?= e($_POST['comment'] ?? '') ?></textarea><div class="help">Kung blangko, ang ratings ang gagamitin. Huwag maglagay ng personal na impormasyon.</div></div>
<details class="privacy-notice"><summary>Privacy notice (<?= e(PRIVACY_NOTICE_VERSION) ?>)</summary><p>Gagamitin lamang ang inyong sagot para mapabuti ang serbisyo. Huwag maglagay ng pangalan, numero ng telepono, medical diagnosis, o sensitibong impormasyon. Ang feedback ay pananatilihin nang hanggang <?= FEEDBACK_RETENTION_MONTHS ?> buwan. Privacy contact: <?= e(PRIVACY_CONTACT) ?>. <a href="<?= e(app_url('privacy.php')) ?>"><strong>Buong privacy policy</strong></a></p></details>
<label class="checkbox-row"><input type="checkbox" name="consent" value="1" required><span>Nabasa ko ang privacy notice at kusang-loob kong ipinapasa ang feedback na ito.</span></label><button class="btn survey-submit" type="submit">Ipasa ang feedback</button></form></div>
<?php render_public_end(); ?>
